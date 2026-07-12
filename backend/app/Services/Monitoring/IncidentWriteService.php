<?php

namespace App\Services\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operator-facing incident authoring seam: the human counterpart to the
 * automated {@see ThresholdEvaluator} persist path. It creates manual incidents,
 * drives the lifecycle (resolve / acknowledge / reopen), and appends timeline
 * updates, then hands the side effects to the shared {@see IncidentDispatcher}
 * exactly like the automated path does.
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

    protected const string DEFAULT_ACKNOWLEDGE_MESSAGE = 'Incident acknowledged; investigation in progress.';

    protected const string DEFAULT_REOPEN_MESSAGE = 'Incident reopened by operator.';

    public function __construct(
        protected ThresholdEvaluator $evaluator,
        protected IncidentDispatcher $incidentDispatcher,
    ) {}

    /**
     * Open a manual incident for the monitor, deduping against any incident the
     * monitor already has open so a human report never double-opens against an
     * in-flight automated incident.
     *
     * @param  Monitor  $monitor  The monitor the incident is about.
     * @param  IncidentSeverity  $severity  Operator-chosen severity, projected to impact.
     * @param  string  $title  Human-facing incident title.
     * @param  string  $author  Display label for the opening timeline note.
     * @param  string|null  $message  Optional opening note; when null no note is posted.
     * @return Incident The newly opened incident, or the existing active one on dedupe.
     */
    public function createManual(
        Monitor $monitor,
        IncidentSeverity $severity,
        string $title,
        string $author,
        ?string $message = null,
    ): Incident {
        $opened = false;

        // 1. Serialize against a concurrent automated open on the same monitor,
        //    then dedupe and create atomically inside the lock.
        $incident = $this->withMonitorLock($monitor, function () use (
            $monitor,
            $severity,
            $title,
            $author,
            $message,
            &$opened,
        ): Incident {
            return DB::transaction(function () use (
                $monitor,
                $severity,
                $title,
                $author,
                $message,
                &$opened,
            ): Incident {
                // 1a. A monitor with an already-active incident is not re-opened;
                //     the existing incident is returned untouched.
                $existing = $this->activeIncidentsForMonitor($monitor)->first();
                if ($existing !== null) {
                    return $existing;
                }

                // 1b. Create through the shared creator so the manual path lands
                //     the exact same row + pivot shape as the automated path.
                $incident = $this->evaluator->createIncident(
                    monitor: $monitor,
                    source: SignalSource::Manual,
                    check: null,
                    severity: $severity,
                    title: $title,
                );

                if ($message !== null) {
                    $this->appendUpdate($incident, IncidentStatus::Detected, $message, $author);
                }

                $opened = true;

                return $incident;
            });
        });

        // 2. Only a genuine open dispatches the off-lock side effects.
        if ($opened) {
            $this->dispatchOpened($monitor, $incident);
        }

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

        // 1. Row-lock and gate the transition inside the per-monitor lock so a
        //    concurrent resolve or an automated recovery cannot double-resolve.
        $current = $this->withMonitorLock($monitor, function () use (
            $incident,
            $author,
            $message,
            &$resolved,
        ): Incident {
            return DB::transaction(function () use ($incident, $author, $message, &$resolved): Incident {
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
                $this->appendUpdate(
                    $fresh,
                    IncidentStatus::Resolved,
                    $message ?? self::DEFAULT_RESOLVE_MESSAGE,
                    $author,
                );
                $resolved = true;

                return $fresh;
            });
        });

        // 2. Page + broadcast + cache-bust the recovery only when this call did it.
        if ($resolved) {
            $this->incidentDispatcher->dispatch($monitor, [
                'opened' => null,
                'resolved' => $current,
                'status_change' => null,
            ]);
        }

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

        return $this->withMonitorLock($monitor, function () use ($incident, $author, $message): Incident {
            return DB::transaction(function () use ($incident, $author, $message): Incident {
                $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->getKey());

                // Only a still-detected incident acknowledges; anything further
                // along (or terminal) is a no-op that adds no timeline note.
                if ($fresh->lifecycle !== IncidentStatus::Detected) {
                    return $fresh;
                }

                $fresh->update(['lifecycle' => IncidentStatus::Investigating]);
                $this->appendUpdate(
                    $fresh,
                    IncidentStatus::Investigating,
                    $message ?? self::DEFAULT_ACKNOWLEDGE_MESSAGE,
                    $author,
                );

                return $fresh;
            });
        });
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

        $current = $this->withMonitorLock($monitor, function () use (
            $incident,
            $author,
            $message,
            &$reopened,
        ): Incident {
            return DB::transaction(function () use ($incident, $author, $message, &$reopened): Incident {
                $fresh = Incident::query()->lockForUpdate()->findOrFail($incident->getKey());

                // Only a terminal incident reopens; an active one is a no-op.
                if ($fresh->lifecycle->isActive()) {
                    return $fresh;
                }

                $fresh->update([
                    'lifecycle' => IncidentStatus::Investigating,
                    'resolved_at' => null,
                ]);
                $this->appendUpdate(
                    $fresh,
                    IncidentStatus::Investigating,
                    $message ?? self::DEFAULT_REOPEN_MESSAGE,
                    $author,
                );
                $reopened = true;

                return $fresh;
            });
        });

        if ($reopened) {
            $this->dispatchOpened($monitor, $current);
        }

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

        return $this->appendUpdate(
            $fresh,
            $status ?? $fresh->lifecycle,
            $message,
            $author,
            $isPublic,
        );
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
     * Load the primary monitor an incident locks and dispatches against.
     *
     * @throws RuntimeException When the incident has no primary monitor (its
     *                          monitor was deleted), leaving nothing to lock on.
     */
    protected function monitorFor(Incident $incident): Monitor
    {
        $monitor = $incident->primaryMonitor;
        if ($monitor === null) {
            throw new RuntimeException(
                "Incident {$incident->getKey()} has no primary monitor to lock and dispatch against.",
            );
        }

        return $monitor;
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
    protected function withMonitorLock(Monitor $monitor, Closure $critical): Incident
    {
        $lock = Cache::lock("check-persist-monitor:{$monitor->id}", self::LOCK_TTL_SECONDS);
        $lock->block(self::MONITOR_LOCK_WAIT_SECONDS);

        try {
            return $critical();
        } finally {
            $lock->release();
        }
    }
}
