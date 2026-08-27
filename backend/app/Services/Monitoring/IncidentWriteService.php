<?php

namespace App\Services\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\SignalSource;
use App\Jobs\AnnounceIncident;
use App\Jobs\TranslateStatusPageText;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Models\User;
use App\Services\OnCall\EscalationDispatcher;
use App\Services\StatusPages\StatusPageCache;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operator-facing incident authoring seam: the human counterpart to the
 * automated {@see ThresholdEvaluator} persist path. It creates manual incidents,
 * drives the lifecycle (resolve / acknowledge / reopen), records ownership
 * ({@see self::assign}) and the postmortem ({@see self::savePostmortem}), and
 * appends timeline updates, then hands the side effects to the shared
 * {@see IncidentDispatcher} exactly like the automated path does.
 *
 * Concurrency is the load-bearing invariant. Every lifecycle mutation races the
 * automated evaluator (a probe landing at the same instant) and any other
 * operator, so each runs under the SAME per-monitor lock the persist path uses
 * ({@see CheckPersistenceService::withMonitorLock}, key
 * `check-persist-monitor:{id}`) with a row-level `lockForUpdate` re-read and an
 * `isActive()` idempotency gate. A double resolve therefore returns the terminal
 * state with no second update row and no second page. Unlike the automated
 * {@see ThresholdEvaluator::resolveIfRecovered}, an operator resolve does NOT
 * depend on monitor health: a human closes an incident even while the monitor is
 * still failing.
 *
 * The dispatch always runs OFF-lock, after the monitor lock and the transaction
 * both release: every notification the dispatcher fires is `ShouldQueue` and its
 * events are `ShouldDispatchAfterCommit`, so nothing may enqueue while the
 * critical section is held.
 *
 * {@see self::assign} and {@see self::savePostmortem} deliberately do NOT take
 * the per-monitor lock: they write no lifecycle field, so they race neither the
 * evaluator nor an automated recovery, and the lock would (a) serialize an
 * operator note behind an in-flight probe for nothing and (b) fail outright on
 * an incident whose primary monitor was deleted (there would be nothing to lock
 * on). They keep the rest of the contract: a `lockForUpdate` row re-read plus a
 * transaction, so the column change and its timeline note land atomically and
 * two parallel operators cannot interleave.
 */
class IncidentWriteService
{
    /**
     * Time-to-live (seconds) of the per-monitor lock. Matches the persist path
     * so a held lock self-heals after a crashed worker within the same window.
     */
    protected const int LOCK_TTL_SECONDS = 10;

    /**
     * Seconds an acquirer blocks for a concurrent holder (an in-flight probe or
     * a parallel operator write) to clear before giving up. Bounded so a stuck
     * holder cannot wedge a request, generous enough to outlast one write cycle.
     */
    protected const int MONITOR_LOCK_WAIT_SECONDS = 10;

    protected const string DEFAULT_RESOLVE_MESSAGE = 'Incident resolved by operator.';

    /**
     * The timeline note left when an incident is closed because the monitor it
     * belonged to was deleted. Deliberately does not say "resolved": nothing
     * recovered, the measurement stopped.
     */
    protected const string MONITOR_DELETED_MESSAGE =
        'Closed automatically: the monitor this incident belonged to was deleted, '
        .'so no further check can report on it.';

    /**
     * The timeline note left when an incident is closed because the METRIC it
     * was raised on was deleted. Same reasoning as the monitor one above: the
     * measurement stopped, nothing recovered.
     */
    protected const string METRIC_DELETED_MESSAGE =
        'Closed automatically: the metric this incident was raised on was deleted, '
        .'so no further reading can clear it.';

    /** Author label for a transition no person made. */
    protected const string SYSTEM_AUTHOR = 'Uptizm';

    protected const string DEFAULT_ACKNOWLEDGE_MESSAGE = 'Incident acknowledged; investigation in progress.';

    protected const string DEFAULT_REOPEN_MESSAGE = 'Incident reopened by operator.';

    protected const string UNASSIGN_MESSAGE = 'Incident unassigned.';

    protected const string POSTMORTEM_SAVED_MESSAGE = 'Postmortem draft saved (internal, not published).';

    protected const string POSTMORTEM_PUBLISHED_MESSAGE = 'Postmortem published to the public status page.';

    public function __construct(
        protected ThresholdEvaluator $evaluator,
        protected IncidentDispatcher $incidentDispatcher,
        protected StatusPageCache $statusPageCache,
    ) {}

    /**
     * Open a manual incident for the monitor, deduping against any incident the
     * monitor already has open so a human report never double-opens against an
     * in-flight automated incident.
     *
     * @param  Monitor  $monitor  The monitor the incident is about.
     * @param  IncidentSeverity  $severity  Operator-chosen severity, projected to impact.
     * @param  string  $title  Human-facing incident title, stored verbatim and never
     *                         re-rendered: an authored title carries no
     *                         {@see IncidentTitle} key, which is the reading that
     *                         makes `title_key IS NULL` mean "a human wrote this".
     * @param  string  $author  Display label for the opening timeline note.
     * @param  string|null  $message  Optional opening note; when null no note is posted.
     * @param  bool  $notify  Whether to announce the open to the affected status
     *                        pages' confirmed subscribers. The form has offered
     *                        this switch since the screen was written, promising
     *                        to "email everyone subscribed to the affected
     *                        components"; nothing kept that promise in either
     *                        position, because no incident ever reached a
     *                        subscriber.
     * @param  IncidentImpact|null  $impact  The customer-facing impact, when the
     *                                       operator chose one. Null keeps the
     *                                       projection from severity.
     * @return Incident The newly opened incident, or the existing active one on dedupe.
     */
    public function createManual(
        Monitor $monitor,
        IncidentSeverity $severity,
        string $title,
        string $author,
        ?string $message = null,
        bool $notify = true,
        ?IncidentImpact $impact = null,
    ): Incident {
        $opened = false;
        $posted = null;

        // 1. Serialize against a concurrent automated open on the same monitor,
        //    then dedupe and create atomically inside the lock. No incident to
        //    fall back to yet, and none needed: this path always holds a real
        //    monitor, so the lock always resolves to the monitor's own key.
        $incident = $this->withMonitorLock($monitor, null, function () use (
            $monitor,
            $severity,
            $title,
            $author,
            $message,
            $impact,
            &$opened,
            &$posted,
        ): Incident {
            return DB::transaction(function () use (
                $monitor,
                $severity,
                $title,
                $author,
                $message,
                $impact,
                &$opened,
                &$posted,
            ): Incident {
                // 1a. A monitor with an already-active incident is not re-opened;
                //     the existing incident is returned untouched.
                $existing = $this->activeIncidentsForMonitor($monitor)->first();
                if ($existing !== null) {
                    return $existing;
                }

                // 1b. Create through the shared creator so the manual path lands
                //     the exact same row + pivot shape as the automated path.
                //     No `titleKey` travels with it, deliberately: a human wrote
                //     this sentence in the language they chose, so there is no
                //     catalogue entry to re-render it from, and a null
                //     `title_key` is exactly what tells every localized surface
                //     to show the stored text untouched. Composing a key here
                //     would overwrite an operator's words with our own.
                $incident = $this->evaluator->createIncident(
                    monitor: $monitor,
                    source: SignalSource::Manual,
                    check: null,
                    severity: $severity,
                    title: $title,
                    impact: $impact,
                );

                if ($message !== null) {
                    $posted = $this->appendUpdate($incident, IncidentStatus::Detected, $message, $author);
                }

                $opened = true;

                return $incident;
            });
        });

        // 2. Only a genuine open dispatches the off-lock side effects.
        if ($opened) {
            $this->dispatchOpened($monitor, $incident);

            // Subscriber mail rides ONLY the manual path, and only on an
            // explicit yes. An automated open must never reach it: a flapping
            // monitor opens and resolves repeatedly, and each of those would be
            // outbound mail to third parties that nobody chose to send. The job
            // claims its own announce-once guard, so a re-dispatch here is
            // harmless.
            if ($notify) {
                AnnounceIncident::dispatch($incident);
            }

            // The TITLE is translated here and only here: a manual open is the
            // one path that writes an incident title a human authored, which is
            // what `title_key IS NULL` says about the row the creator just
            // returned. An automated open composes its title from a catalogue key
            // and is re-rendered per language already.
            $this->fanOutTranslations($incident, 'title');
        }

        $this->fanOutTranslations($posted, 'message');

        return $incident;
    }

    /**
     * Resolve an active incident on behalf of an operator, independent of the
     * monitor's live health. Idempotent: a resolve of an already-terminal
     * incident returns the current state with no second update and no page.
     *
     * @param  Incident  $incident  The incident to resolve.
     * @param  string  $author  Display label for the resolve timeline note.
     * @param  string|null  $message  Optional resolve note; defaults to a system phrase.
     * @return Incident The incident in its post-call (resolved) state.
     */
    public function resolve(Incident $incident, string $author, ?string $message = null): Incident
    {
        $monitor = $this->monitorFor($incident);
        $resolved = false;
        $posted = null;

        // 1. Row-lock and gate the transition inside the per-monitor lock so a
        //    concurrent resolve or an automated recovery cannot double-resolve.
        $current = $this->withMonitorLock($monitor, $incident, function () use (
            $incident,
            $author,
            $message,
            &$resolved,
            &$posted,
        ): Incident {
            return DB::transaction(function () use ($incident, $author, $message, &$resolved, &$posted): Incident {
                $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->getKey());

                // 1a. Idempotency gate: a terminal incident is returned unchanged.
                if (! $fresh->lifecycle->isActive()) {
                    return $fresh;
                }

                // 1b. Operator resolve ignores monitor health entirely.
                $fresh->update([
                    'lifecycle' => IncidentStatus::Resolved,
                    'resolved_at' => now(),
                ]);
                $posted = $this->appendUpdate(
                    $fresh,
                    IncidentStatus::Resolved,
                    $message ?? self::DEFAULT_RESOLVE_MESSAGE,
                    $author,
                );
                $resolved = true;

                return $fresh;
            });
        });

        // 2. Page + broadcast + cache-bust the recovery only when this call did
        //    it, and only when there is still a monitor to dispatch ABOUT. With
        //    the monitor deleted there is no component to page on, no status
        //    page carrying it (the assembler scopes to visible monitors) and no
        //    cache entry to bust, so the transition is recorded and nothing is
        //    announced.
        if ($resolved && $monitor !== null) {
            $this->incidentDispatcher->dispatch($monitor, [
                'opened' => null,
                'resolved' => $current,
                'status_change' => null,
            ]);

        }

        $this->fanOutTranslations($posted, 'message');

        return $current;
    }

    /**
     * Acknowledge a freshly-detected incident, moving it to investigating.
     * Idempotent: a non-detected (already-acknowledged or terminal) incident is
     * returned unchanged. Acknowledgement is an internal lifecycle nudge, so it
     * pages and broadcasts nothing.
     *
     * @param  Incident  $incident  The incident to acknowledge.
     * @param  string  $author  Display label for the acknowledge timeline note.
     * @param  string|null  $message  Optional note; defaults to a system phrase.
     * @return Incident The incident in its post-call state.
     */
    public function acknowledge(Incident $incident, string $author, ?string $message = null): Incident
    {
        $monitor = $this->monitorFor($incident);
        $posted = null;

        $current = $this->withMonitorLock($monitor, $incident, function () use ($incident, $author, $message, &$posted): Incident {
            return DB::transaction(function () use ($incident, $author, $message, &$posted): Incident {
                $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->getKey());

                // Only a still-detected incident acknowledges; anything further
                // along (or terminal) is a no-op that adds no timeline note.
                if ($fresh->lifecycle !== IncidentStatus::Detected) {
                    return $fresh;
                }

                $fresh->update(['lifecycle' => IncidentStatus::Investigating]);
                $posted = $this->appendUpdate(
                    $fresh,
                    IncidentStatus::Investigating,
                    $message ?? self::DEFAULT_ACKNOWLEDGE_MESSAGE,
                    $author,
                );

                return $fresh;
            });
        });

        $this->fanOutTranslations($posted, 'message');

        return $current;
    }

    /**
     * Reopen a resolved incident, returning it to the active investigating
     * state and clearing its resolution stamp. Idempotent: an already-active
     * incident is returned unchanged. A reopen re-activates the component, so it
     * dispatches the same open side effects as a fresh open (page + broadcast +
     * cache bust).
     *
     * @param  Incident  $incident  The incident to reopen.
     * @param  string  $author  Display label for the reopen timeline note.
     * @param  string|null  $message  Optional note; defaults to a system phrase.
     * @return Incident The incident in its post-call state.
     */
    public function reopen(Incident $incident, string $author, ?string $message = null): Incident
    {
        $monitor = $this->monitorFor($incident);
        $reopened = false;
        $posted = null;

        $current = $this->withMonitorLock($monitor, $incident, function () use (
            $incident,
            $author,
            $message,
            &$reopened,
            &$posted,
        ): Incident {
            return DB::transaction(function () use ($incident, $author, $message, &$reopened, &$posted): Incident {
                $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->getKey());

                // Only a terminal incident reopens; an active one is a no-op.
                if ($fresh->lifecycle->isActive()) {
                    return $fresh;
                }

                $fresh->update([
                    'lifecycle' => IncidentStatus::Investigating,
                    'resolved_at' => null,
                ]);
                $posted = $this->appendUpdate(
                    $fresh,
                    IncidentStatus::Investigating,
                    $message ?? self::DEFAULT_REOPEN_MESSAGE,
                    $author,
                );
                $reopened = true;

                return $fresh;
            });
        });

        // Nothing to announce when the monitor is gone, for the reason the
        // resolve path states. Reopening an orphaned incident is a strange thing
        // to want, but it is reachable and it must not fatal.
        if ($reopened && $monitor !== null) {
            $this->dispatchOpened($monitor, $current);
        }

        $this->fanOutTranslations($posted, 'message');

        return $current;
    }

    /**
     * Append an operator update to the incident's unified timeline without
     * changing its lifecycle. The update inherits the incident's current
     * lifecycle status unless an explicit one is given.
     *
     * @param  Incident  $incident  The incident to append to.
     * @param  string  $message  The timeline message body.
     * @param  string  $author  Display label for the note.
     * @param  bool  $isPublic  Whether the note renders on the public status page.
     * @param  IncidentStatus|null  $status  Override status; defaults to the current lifecycle.
     * @return IncidentUpdate The persisted timeline entry.
     */
    public function postUpdate(
        Incident $incident,
        string $message,
        string $author,
        bool $isPublic = true,
        ?IncidentStatus $status = null,
    ): IncidentUpdate {
        $fresh = $incident->fresh() ?? $incident;

        $posted = $this->appendUpdate(
            $fresh,
            $status ?? $fresh->lifecycle,
            $message,
            $author,
            $isPublic,
        );

        $this->fanOutTranslations($posted, 'message');

        return $posted;
    }

    /**
     * Assign the incident to a team member, or clear the assignment when
     * `$assignee` is null, appending an internal timeline note either way.
     * Idempotent: re-assigning the same user (or clearing an already-unassigned
     * incident) returns the incident unchanged and adds no note, so a UI that
     * echoes its own state back never litters the timeline.
     *
     * The CALLER owns the membership check (the assign FormRequest validates the
     * id against the team roster); this service only persists the decision.
     *
     * @param  Incident  $incident  The incident to (un)assign.
     * @param  User|null  $assignee  The responder taking ownership, or null to clear.
     * @param  string  $author  Display label for the assignment timeline note.
     * @return Incident The incident in its post-call state.
     */
    public function assign(Incident $incident, ?User $assignee, string $author): Incident
    {
        return DB::transaction(function () use ($incident, $assignee, $author): Incident {
            $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->getKey());
            $next = $assignee?->getKey();

            // Idempotency gate: an unchanged owner is a no-op, not a note.
            if ((string) $fresh->assigned_to_user_id === (string) $next) {
                return $fresh;
            }

            $fresh->update(['assigned_to_user_id' => $next]);
            $this->appendUpdate(
                $fresh,
                $fresh->lifecycle,
                $assignee === null
                    ? self::UNASSIGN_MESSAGE
                    : "Incident assigned to {$assignee->name}.",
                $author,
                isPublic: false,
            );

            return $fresh;
        });
    }

    /**
     * Save the incident's postmortem body and, when `$publish` is set, stamp its
     * publication time so the public status page starts rendering it.
     *
     * The publication stamp is written ONCE: publishing an already-published
     * postmortem edits the body but keeps the original `postmortem_published_at`,
     * so "published at" stays the moment customers could first read it rather
     * than the moment of the latest typo fix.
     *
     * @param  Incident  $incident  The incident the postmortem belongs to.
     * @param  string  $body  The postmortem body (Markdown, rendered downstream).
     * @param  bool  $publish  Whether this save also publishes it publicly.
     * @param  string  $author  Display label for the postmortem timeline note.
     * @return Incident The incident in its post-call state.
     */
    public function savePostmortem(Incident $incident, string $body, bool $publish, string $author): Incident
    {
        $current = DB::transaction(function () use ($incident, $body, $publish, $author): Incident {
            $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->getKey());
            $alreadyPublished = $fresh->postmortem_published_at !== null;

            $fresh->update([
                'postmortem_body' => $body,
                'postmortem_published_at' => $publish && ! $alreadyPublished
                    ? now()
                    : $fresh->postmortem_published_at,
            ]);

            // The note is internal on purpose: the postmortem reaches customers
            // through the status page's own postmortem block, not as a timeline
            // update that would duplicate the whole body on the public page.
            $this->appendUpdate(
                $fresh,
                $fresh->lifecycle,
                $publish ? self::POSTMORTEM_PUBLISHED_MESSAGE : self::POSTMORTEM_SAVED_MESSAGE,
                $author,
                isPublic: false,
            );

            return $fresh;
        });

        // Bust the containing pages' cached read models OFF-transaction whenever
        // the postmortem is publicly visible after this write, so a publish (or
        // an edit of a published body) shows up immediately instead of after the
        // 60s TTL. Mirrors the lifecycle bust in IncidentDispatcher.
        if ($current->postmortemIsPublished()) {
            $this->statusPageCache->invalidateForMonitors(
                $current->monitors()->pluck('monitors.id')->all(),
            );
        }

        // A DRAFT is translated too, deliberately. The translation is keyed to
        // the row, so it inherits the row's visibility and nothing becomes
        // readable that was not already; and translating only on publish would
        // mean the non-default languages read `pending` at exactly the moment the
        // postmortem goes live, which is when it is read.
        $this->fanOutTranslations($current, 'postmortem_body');

        return $current;
    }

    /**
     * Dispatch the shared open side effects (page gated on `alert_on_down`,
     * broadcast, status-page cache bust) off-lock for a just-opened incident.
     */
    protected function dispatchOpened(Monitor $monitor, Incident $incident): void
    {
        $this->incidentDispatcher->dispatch($monitor, [
            'opened' => $incident,
            'resolved' => null,
            'status_change' => null,
        ]);

    }

    /**
     * Queue a machine translation of one just-written field into every supported
     * language other than the one it was authored in.
     *
     * OFF-LOCK AND OFF-TRANSACTION, which is why every caller here threads the
     * written row out of its critical section by reference instead of calling
     * this from inside {@see self::appendUpdate()}: this class's standing rule is
     * that nothing enqueues while the per-monitor lock is held, and a fan-out
     * inside the lock would be the exception that erodes it. The job itself is
     * dispatched `afterCommit()` on top of that, so a later caller who does wrap
     * one of these in a transaction cannot feed a worker a row a rollback is
     * about to discard.
     *
     * Null is a first-class argument: three of the callers post a note only when
     * their idempotency gate opened, and answering "nothing was written" here
     * keeps that decision at the one place that made it.
     *
     * The source language is the deployment default. Nothing in the incident
     * domain carries a language of its own; the only language column on this
     * surface is `status_pages.locale`, whose null means exactly this default,
     * and the public read model treats the page's language as the authored one.
     *
     * {@see TranslateStatusPageText::fanOut()} owns every other guard (the closed
     * field set, a keyed title, an internal note, an empty value), so this is a
     * seam and not a second place to remember them.
     */
    protected function fanOutTranslations(Incident|IncidentUpdate|null $translatable, string $field): void
    {
        if ($translatable === null) {
            return;
        }

        TranslateStatusPageText::fanOut($translatable, $field, (string) config('app.default_locale'));
    }

    /**
     * Append a `human`-authored, non-autonomous update to the incident timeline.
     */
    protected function appendUpdate(
        Incident $incident,
        IncidentStatus $status,
        string $message,
        string $author,
        bool $isPublic = true,
    ): IncidentUpdate {
        return $incident->updates()->create([
            'actor' => 'human',
            'author' => $author,
            'status' => $status,
            'message' => $message,
            'is_public' => $isPublic,
            'autonomous' => false,
            'display_at' => now(),
        ]);
    }

    /**
     * Load the primary monitor an incident locks and dispatches against, or null
     * when it has none.
     *
     * It used to throw here, and the docblock outlived the change: an incident
     * whose monitor was deleted has no primary monitor, and that is a REACHABLE
     * state rather than a broken invariant, since `Monitor` soft-deletes and the
     * relation applies that scope. Throwing made such an incident unwritable
     * through every path at once, so it could not be resolved, acknowledged or
     * updated by hand either. Callers take the lock on the incident instead
     * ({@see self::withMonitorLock()}) and skip the dispatch, because there is
     * no component left to page about.
     */
    protected function monitorFor(Incident $incident): ?Monitor
    {
        // Null rather than a throw, because the null case is REACHABLE and not a
        // broken invariant: `Monitor` soft-deletes, the relation applies that
        // scope, and an incident whose monitor was deleted therefore has none.
        // Throwing here made such an incident unwritable through every path at
        // once, so it could not be resolved, acknowledged or updated by hand,
        // and it could not close by itself either (auto-resolve rides the next
        // check, which never comes). Three of production's eight open incidents
        // were in exactly that state.
        return $incident->primaryMonitor;
    }

    /**
     * Close every still-open incident that `$monitor` leaves with no live
     * monitor at all, silently.
     *
     * Called from {@see Monitor}'s `deleted` hook, so it covers every delete
     * path rather than the one controller. It runs AFTER the soft-delete on
     * purpose: `monitors()` applies the related model's scope, so the "is
     * anything of mine still alive" question only answers correctly once the
     * row being deleted is already excluded.
     *
     * WHY CLOSE AT ALL. An incident whose monitors are gone cannot end. Auto-
     * resolve rides the next check ({@see ThresholdEvaluator::resolveIfRecovered()})
     * and no check will ever arrive; it still counts as open on the dashboard;
     * and it stays pageable, since {@see EscalationDispatcher::pageStep()}
     * gates on lifecycle and maintenance and asks nothing about the monitor. On
     * production three of eight open incidents were in that state. Grafana takes
     * the same position for the same reason and says so in its own source: a
     * deleted alert rule resolves its firing instances so none is left orphaned.
     *
     * WHY SILENTLY. Grafana emits a resolved notification here; this does not.
     * "Resolved" would be a false sentence, because nothing recovered, we
     * stopped measuring, and paging a team about the consequence of a delete
     * they just performed is noise. The timeline entry carries the reason
     * instead, internal rather than public: a status-page reader was never told
     * this incident existed, since the assembler scopes to visible monitors.
     *
     * WHY ONLY THE FULLY ORPHANED ONES. An incident is many-to-many with
     * monitors. One still alive means the outage may still be running, and
     * closing it because a sibling component was deleted would retire something
     * nobody fixed.
     */
    public function closeOrphanedBy(Monitor $monitor): void
    {
        // The PIVOT directly, not the `monitors()` relation. This runs on
        // `deleted`, and that relation applies the soft-delete scope, so by the
        // time it is asked the monitor being deleted is already invisible to it:
        // the relation arm matched nothing and only the denormalised primary
        // hint did any work, leaving every incident this monitor joined as a
        // SECONDARY component open forever. The pivot carries no such scope.
        //
        // Grouped, because the two arms are alternatives to each other rather
        // than to anything a caller might add later.
        $attachedIds = DB::table('incident_monitors')
            ->where('monitor_id', $monitor->getKey())
            ->pluck('incident_id');

        $incidents = Incident::query()
            ->where(function ($query) use ($monitor, $attachedIds): void {
                $query->where('primary_monitor_id', $monitor->getKey())
                    ->orWhereIn('id', $attachedIds);
            })
            ->get();

        foreach ($incidents as $incident) {
            if (! $incident->lifecycle->isActive()) {
                continue;
            }

            if ($incident->monitors()->exists()) {
                continue;
            }

            DB::transaction(function () use ($incident): void {
                $fresh = Incident::query()->lockForUpdate()->find($incident->getKey());

                if ($fresh === null || ! $fresh->lifecycle->isActive()) {
                    return;
                }

                $fresh->update([
                    'lifecycle' => IncidentStatus::Resolved,
                    'resolved_at' => now(),
                ]);

                $this->appendSystemNote($fresh, self::MONITOR_DELETED_MESSAGE);
            });
        }
    }

    /**
     * Close the incident a deleted metric was raised on.
     *
     * One level down from {@see self::closeOrphanedBy()}, and the same hole. The
     * metric lane's auto-resolve asks whether the trailing run of frozen bands
     * for a metric KEY is clear, and a deleted metric produces no further
     * samples, so the run stays whatever it was at the breach and the answer is
     * no forever. Nothing else closes it either: `resolveIfRecovered` is scoped
     * to `trigger_metric_key IS NULL`, and the orphan close above only fires when
     * the MONITOR goes. The incident sat `detected` until somebody noticed.
     *
     * Two exclusions, both mirroring guards the evaluator already applies:
     *
     * - `ai_owned` incidents belong to the autonomous lane, whose
     *   `trigger_metric_key` is a SIGNAL name rather than a configured metric
     *   key, so a deleted metric that happens to share the name is not theirs.
     * - Scoped to the metric's own monitor, since a key is unique per monitor and
     *   two monitors may both measure `cpu`.
     *
     * Silent, like the orphan close: no page and no public update. A status-page
     * reader was never told this incident existed (the assembler scopes to
     * visible monitors), and paging a team about the consequence of an action it
     * just performed is noise.
     */
    public function closeOrphanedByMetric(MonitorMetric $metric): void
    {
        $incidents = Incident::query()
            ->where('primary_monitor_id', $metric->monitor_id)
            ->where('trigger_metric_key', $metric->key)
            ->where('ai_owned', false)
            ->active()
            ->get();

        foreach ($incidents as $incident) {
            DB::transaction(function () use ($incident): void {
                $fresh = Incident::query()->lockForUpdate()->find($incident->getKey());

                if ($fresh === null || ! $fresh->lifecycle->isActive()) {
                    return;
                }

                $fresh->update([
                    'lifecycle' => IncidentStatus::Resolved,
                    'resolved_at' => now(),
                ]);

                $this->appendSystemNote($fresh, self::METRIC_DELETED_MESSAGE);
            });
        }
    }

    /**
     * A timeline entry nobody authored, internal to the team.
     *
     * {@see self::appendUpdate()} stamps `actor: human` and `is_public: true`,
     * which are the right defaults for an operator's own note and the wrong ones
     * for a transition the system made on its own.
     */
    protected function appendSystemNote(Incident $incident, string $message): IncidentUpdate
    {
        return $incident->updates()->create([
            'actor' => 'system',
            'author' => self::SYSTEM_AUTHOR,
            'status' => IncidentStatus::Resolved,
            'message' => $message,
            'is_public' => false,
            'autonomous' => true,
            'display_at' => now(),
        ]);
    }

    /**
     * The monitor's currently-active incidents, filtered on the lifecycle enum's
     * own predicate. Replicates the dedupe read
     * {@see ThresholdEvaluator::hasActiveIncidentForMonitor} performs (a
     * protected method), keyed on the same denormalized primary-monitor hint.
     *
     * @return Collection<int, Incident>
     */
    protected function activeIncidentsForMonitor(Monitor $monitor): Collection
    {
        return Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->get()
            ->filter(fn (Incident $incident): bool => $incident->lifecycle->isActive())
            ->values();
    }

    /**
     * Run `$critical` while holding the per-monitor lock, blocking until it is
     * free (bounded by {@see self::MONITOR_LOCK_WAIT_SECONDS}) so an operator
     * write serializes against a concurrent probe persist and any parallel
     * operator on the same monitor. Releases the lock even when `$critical`
     * throws. Mirrors {@see CheckPersistenceService::withMonitorLock} on the same
     * lock key rather than calling that protected method.
     *
     * @param  Closure(): Incident  $critical
     */
    protected function withMonitorLock(?Monitor $monitor, ?Incident $incident, Closure $critical): Incident
    {
        // The monitor's key when there is one, because that is the lock the
        // CHECK pipeline takes and these writes have to serialise against it.
        // With no monitor there is no pipeline to race: no check will ever
        // arrive for a deleted monitor. The incident's own key still guards two
        // concurrent operators.
        $key = match (true) {
            $monitor !== null => "check-persist-monitor:{$monitor->id}",
            $incident !== null => "incident-write:{$incident->getKey()}",
            default => throw new RuntimeException('A locked incident write needs a monitor or an incident to key on.'),
        };

        $lock = Cache::lock($key, self::LOCK_TTL_SECONDS);
        $lock->block(self::MONITOR_LOCK_WAIT_SECONDS);

        try {
            return $critical();
        } finally {
            $lock->release();
        }
    }
}
