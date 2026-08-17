<?php

namespace App\Jobs;

use App\Enums\AiConfidence;
use App\Enums\AiMode;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Enums\IncidentStatus;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Models\AiSuggestion;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiIncidentOpener;
use App\Services\Ai\AnomalyCandidate;
use App\Services\Ai\AnomalyTriageGateway;
use App\Services\Ai\ResponseTimeAnomalyDetector;
use App\Services\Ai\TriagePayload;
use App\Services\Monitoring\IncidentDispatcher;
use App\Support\Ai\PromptLanguage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled anomaly sweep: the fan-out that turns raw response-time history into
 * triage work for every monitor under AI authority.
 *
 * Every ~2 minutes it scans the ai_mode in (suggest, auto) fleet, runs the pure
 * {@see ResponseTimeAnomalyDetector} over each monitor's recent response_ms
 * window, and acts on any candidate by the monitor's authority level:
 *
 *  - Suggest: the candidate is handed to {@see TriageAnomalyCandidate} on the
 *    `ai` queue, which labels it and writes an inbox suggestion for an operator.
 *  - Auto: the sweep labels it inline and, when the triage clears the confidence
 *    threshold AND the daily budget admits the spend, AUTO-ACCEPTS by opening an
 *    `ai_owned` incident via the shared {@see AiIncidentOpener} (the operator
 *    accept path minus the human gate) and stamping an autonomous opening update.
 *    Below the threshold or over budget it degrades to a pending suggestion and
 *    never auto-opens. `off` monitors are never touched.
 *
 * Dispatch-time dedupe guards both modes: a candidate whose `dedupe_key` already
 * has a live suggestion is dropped here, so re-scanning a still-open episode
 * every two minutes neither re-enqueues triage nor re-opens an incident.
 */
class SweepAiSuggestions implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Most recent checks pulled into the detector window per monitor. Bounds the
     * per-monitor scan cost and keeps the statistical baseline recent.
     *
     * @var int
     */
    private const WINDOW_SIZE = 120;

    /**
     * The lowest triage confidence that lets an `auto` monitor open an incident
     * without a human. High only: autonomous incident creation stays deliberately
     * conservative, so a merely-plausible label degrades to a suggestion instead.
     *
     * @var AiConfidence
     */
    private const AUTO_CONFIDENCE_THRESHOLD = AiConfidence::High;

    /**
     * Days a pending suggestion (the auto-mode degrade fallback) stays actionable
     * before it is considered stale. Mirrors {@see TriageAnomalyCandidate}.
     *
     * @var int
     */
    private const SUGGESTION_EXPIRES_AFTER_DAYS = 7;

    /**
     * Seconds for which only one copy of this sweep may run. Sized just under
     * the two-minute tick so an overlapping tick cannot double-enqueue while a
     * prior sweep is still fanning out, yet the lock frees before the next
     * legitimate tick.
     *
     * @var int
     */
    public $uniqueFor = 115;

    public function __construct()
    {
        $this->onQueue('ai');
    }

    /**
     * Sweep every AI-authorized monitor and act on each fresh anomaly by its
     * authority level (suggest fans out triage, auto acts inline).
     *
     * @param  ResponseTimeAnomalyDetector  $detector  The pure statistical detector.
     * @param  AnomalyTriageGateway  $gateway  The LLM boundary (faked in tests).
     * @param  AiBudget  $budget  The atomic per-team daily spend guard.
     * @param  AiIncidentOpener  $opener  The shared AI-owned incident creator.
     * @param  IncidentDispatcher  $dispatcher  The shared off-lock side-effect seam.
     */
    public function handle(
        ResponseTimeAnomalyDetector $detector,
        AnomalyTriageGateway $gateway,
        AiBudget $budget,
        AiIncidentOpener $opener,
        IncidentDispatcher $dispatcher,
    ): void {
        // Chunk the fleet so a large workspace never loads every monitor into
        // memory at once. Off monitors are excluded by the scope.
        Monitor::query()
            ->whereIn('ai_mode', [
                AiMode::Suggest,
                AiMode::Auto,
            ])
            ->each(function (Monitor $monitor) use ($detector, $gateway, $budget, $opener, $dispatcher): void {
                $this->sweepMonitor($monitor, $detector, $gateway, $budget, $opener, $dispatcher);
            });
    }

    /**
     * Score one monitor's recent window and, on a fresh candidate, act by the
     * monitor's AI authority: fan out triage (suggest) or auto-handle (auto).
     */
    private function sweepMonitor(
        Monitor $monitor,
        ResponseTimeAnomalyDetector $detector,
        AnomalyTriageGateway $gateway,
        AiBudget $budget,
        AiIncidentOpener $opener,
        IncidentDispatcher $dispatcher,
    ): void {
        // 1. Pull the bounded, oldest-to-newest response_ms window.
        $window = $this->loadWindow($monitor);
        if ($window->isEmpty()) {
            return;
        }

        // 2. Score it. A null result means no actionable anomaly (or cold-start
        //    with no configured bounds), so there is nothing to OPEN. It is also
        //    the only moment an incident this lane opened can be considered over,
        //    which is the other half of its lifecycle.
        $candidate = $detector->detect(
            $window->pluck('response_ms')->map(static fn (mixed $ms): int => (int) $ms)->all(),
            $this->buildConfig($monitor, $window),
        );
        if ($candidate === null) {
            $this->resolveRecoveredAnomalyIncident($monitor, $window, $dispatcher);

            return;
        }

        // 3. Dispatch-time dedupe: never re-act on an episode that already has a
        //    live suggestion. This blunts the two-minute re-scan re-dispatch and,
        //    for auto, stops a still-open episode re-opening a second incident.
        if (AiSuggestion::query()->where('dedupe_key', $candidate->dedupeKey)->exists()) {
            return;
        }

        // 4. Auto monitors triage and accept inline; suggest monitors fan out.
        if ($monitor->ai_mode === AiMode::Auto) {
            $this->sweepAuto($monitor, $candidate, $gateway, $budget, $opener, $dispatcher);

            return;
        }

        // 5. Suggest: hand the non-secret candidate DTO to the triage job on the
        //    ai queue, exactly as before.
        TriageAnomalyCandidate::dispatch((string) $monitor->id, $candidate->toArray())->onQueue('ai');
    }

    /**
     * Close an incident this lane opened, once the signal behind it is back
     * under the level it was raised against.
     *
     * The autonomous lane owns its own incidents end to end, and until now it
     * only owned the opening half: `ThresholdEvaluator` scopes itself off
     * `ai_owned` rows in three places (correctly, since their
     * `trigger_metric_key` is a signal name and not a configured metric key), and
     * nothing else in this lane ever wrote a resolve. Measured on production
     * 2026-08-17, an incident auto-opened from an EWMA fire that cleared its
     * control limit by 0.37% had been `detected` and `critical` for a day and a
     * half while the monitor answered in 45ms.
     *
     * Two conditions, and the first is the call site rather than a branch here:
     * this runs only where the detector found nothing in the monitor's whole
     * window, so the anomaly is already undetectable by its own standard. The
     * second is read back from the checks, so the note can state it.
     *
     * FAIL-CLOSED throughout. No active AI incident, no suggestion behind it, no
     * numeric level on its evidence, or too few readings to judge, and the
     * incident is left exactly as it is: for a human, which is today's behaviour,
     * so no branch here is ever worse than the state it replaces.
     *
     * @param  Collection<int, MonitorCheck>  $window  Oldest-to-newest, as loaded for the detector.
     */
    private function resolveRecoveredAnomalyIncident(
        Monitor $monitor,
        Collection $window,
        IncidentDispatcher $dispatcher,
    ): void {
        $incident = Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('ai_owned', true)
            ->active()
            ->orderBy('started_at')
            ->orderBy('id')
            ->first();

        if ($incident === null) {
            return;
        }

        $level = $this->raisedAgainstLevel($incident);
        if ($level === null) {
            return;
        }

        if (! $this->readingsAreUnder($monitor, $window, $level)) {
            return;
        }

        // The transition is idempotent under a row lock: two overlapping sweeps
        // (the unique lock frees before the next tick) must not both resolve it
        // and stamp two notes.
        $resolved = DB::transaction(function () use ($incident): ?Incident {
            $fresh = Incident::query()->lockForUpdate()->find($incident->getKey());

            if ($fresh === null || ! $fresh->lifecycle->isActive()) {
                return null;
            }

            $fresh->update([
                'lifecycle' => IncidentStatus::Resolved,
                'resolved_at' => now(),
            ]);

            return $fresh;
        });

        if ($resolved === null) {
            return;
        }

        // `system`, not `ai`: no model was asked, this lane's own arithmetic
        // decided it. Public and fanned out for the reason the metric lane's
        // recovery note is: a status page renders it like any other update, and
        // an untranslated one sits at `pending` in every non-default language.
        $note = $resolved->updates()->create([
            'actor' => 'system',
            'author' => 'System',
            'status' => IncidentStatus::Resolved,
            'message' => "Response time on {$monitor->name} returned to its normal range; incident auto-resolved.",
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now(),
        ]);

        TranslateStatusPageText::fanOut($note, 'message', (string) config('app.default_locale'));

        // The shared off-lock seam, exactly as the open path drives it, so a
        // resolve pages, broadcasts and busts the public cache like any other.
        $dispatcher->dispatch($monitor, [
            'opened' => null,
            'resolved' => $resolved,
            'status_change' => null,
        ]);
    }

    /**
     * The level the incident was raised against, or null when nothing numeric
     * stands behind it.
     *
     * Read from the suggestions linked to the incident rather than recomputed,
     * because the level has to be the one the episode was actually judged by. A
     * dedupe fold can link several suggestions to one incident, so the SMALLEST
     * threshold wins: it is the strictest bar, and closing on the loosest would
     * let a still-elevated signal end an episode a tighter fire had opened.
     */
    private function raisedAgainstLevel(Incident $incident): ?float
    {
        $levels = AiSuggestion::query()
            ->where('accepted_incident_id', $incident->id)
            ->pluck('evidence')
            ->map(static fn (mixed $evidence): mixed => is_array($evidence) ? ($evidence['threshold'] ?? null) : null)
            ->filter(static fn (mixed $threshold): bool => is_int($threshold) || is_float($threshold))
            ->map(static fn (mixed $threshold): float => (float) $threshold)
            ->all();

        return $levels === [] ? null : min($levels);
    }

    /**
     * True when the trailing run of readings all sit under [$level].
     *
     * The run length is the monitor's own {@see Monitor::$incident_threshold},
     * the same number the down and metric lanes count verdicts in, so one
     * setting governs how twitchy every lane is. A window holding fewer readings
     * than that cannot answer the question and is treated as a no.
     *
     * @param  Collection<int, MonitorCheck>  $window  Oldest-to-newest.
     */
    private function readingsAreUnder(Monitor $monitor, Collection $window, float $level): bool
    {
        $length = max(1, (int) ($monitor->incident_threshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD));
        $trailing = $window->slice(-$length)->values();

        if ($trailing->count() < $length) {
            return false;
        }

        return $trailing->every(
            static fn (MonitorCheck $check): bool => $check->response_ms !== null
                && (float) $check->response_ms < $level,
        );
    }

    /**
     * Auto mode: label the candidate under budget, and auto-accept it into an
     * incident only when the triage clears the confidence threshold. Over budget
     * or below threshold degrades to a pending suggestion and never auto-opens.
     */
    private function sweepAuto(
        Monitor $monitor,
        AnomalyCandidate $candidate,
        AnomalyTriageGateway $gateway,
        AiBudget $budget,
        AiIncidentOpener $opener,
        IncidentDispatcher $dispatcher,
    ): void {
        // 1. Spend one unit of the team's daily budget atomically. Over budget is
        //    not a failure: it degrades to a pending statistical suggestion (the
        //    anomaly still reaches the inbox), it never auto-opens.
        if (! $budget->tryConsume($monitor->team_id)) {
            $this->persistStatisticalSuggestion($monitor, $candidate);

            return;
        }

        // 2. Within budget: label via the LLM. A gateway failure degrades to a
        //    pending statistical suggestion so one bad monitor cannot abort the
        //    fleet sweep (the anomaly always stands; statistics are the truth).
        try {
            $result = $gateway->triage($this->buildTrustedPayload($monitor, $candidate));
        } catch (Throwable) {
            // Deliberate degrade, not a silent swallow: the exception detail is
            // withheld because it may echo probe payload.
            Log::warning('Autonomous AI triage degraded to a pending suggestion.', [
                'monitor_id' => $monitor->id,
            ]);
            $this->persistStatisticalSuggestion($monitor, $candidate);

            return;
        }

        // 3. Below the confidence threshold: keep the labeled anomaly as a pending
        //    inbox suggestion for an operator, do not auto-open.
        if (! $this->clearsAutoThreshold($result->confidence)) {
            $this->persistSuggestion($monitor, $candidate, [
                'severity' => $result->severity,
                'confidence' => $result->confidence,
                'source' => 'llm',
                'recommendation' => $result->recommendation,
            ], AiSuggestionStatus::Pending, null);

            return;
        }

        // 4. High confidence AND within budget: auto-accept. Create the accepted
        //    suggestion (the audit trail), open one ai_owned incident via the
        //    shared creator, link it back, and stamp the autonomous opening note.
        $incident = DB::transaction(function () use ($monitor, $candidate, $result, $opener): Incident {
            $suggestion = $this->persistSuggestion($monitor, $candidate, [
                'severity' => $result->severity,
                'confidence' => $result->confidence,
                'source' => 'llm',
                'recommendation' => $result->recommendation,
            ], AiSuggestionStatus::Accepted, null);
            $suggestion->setRelation('monitor', $monitor);

            $incident = $opener->open($suggestion);
            $suggestion->forceFill(['accepted_incident_id' => $incident->id])->save();

            // Stamp the autonomous opening only for a genuine open; an AI-lane
            // dedupe fold returns an already-open incident, so a second note
            // would misrepresent the folded suggestion as a fresh opening.
            if ($incident->wasRecentlyCreated) {
                $this->stampAutonomousOpening($incident);
            }

            return $incident;
        });

        // 5. Drive the shared off-lock side effects (page + broadcast +
        //    status-page cache bust + escalation) AFTER the transaction commits:
        //    every notification is ShouldQueue and both events are
        //    ShouldDispatchAfterCommit, so nothing may enqueue inside it. A
        //    deduped fold already dispatched on its original open, so it is
        //    skipped here.
        if ($incident->wasRecentlyCreated) {
            $dispatcher->dispatch($incident->primaryMonitor, [
                'opened' => $incident,
                'resolved' => null,
                'status_change' => null,
            ]);
        }
    }

    /**
     * Persist the degrade fallback: a deterministic statistical suggestion with a
     * confidence read straight off the anomaly's severity band. No LLM text.
     */
    private function persistStatisticalSuggestion(Monitor $monitor, AnomalyCandidate $candidate): void
    {
        $this->persistSuggestion($monitor, $candidate, [
            'severity' => $candidate->severity,
            'confidence' => $this->statisticalConfidence($candidate->severity),
            'source' => 'statistical',
            'recommendation' => $this->statisticalRecommendation($candidate),
        ], AiSuggestionStatus::Pending, null);
    }

    /**
     * Write exactly one suggestion, merging the source-specific fields over the
     * common statistical base.
     *
     * @param  array<string, mixed>  $sourceFields
     * @param  string|null  $incidentId  The accepted incident id, set only on the auto-accept path.
     */
    private function persistSuggestion(
        Monitor $monitor,
        AnomalyCandidate $candidate,
        array $sourceFields,
        AiSuggestionStatus $status,
        ?string $incidentId,
    ): AiSuggestion {
        return AiSuggestion::query()->create(array_merge([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => $candidate->signal,
            'method' => $candidate->method,
            'score' => $candidate->score,
            'evidence' => $candidate->evidence,
            'dedupe_key' => $candidate->dedupeKey,
            'status' => $status,
            'expires_at' => now()->addDays(self::SUGGESTION_EXPIRES_AFTER_DAYS),
            'accepted_incident_id' => $incidentId,
        ], $sourceFields));
    }

    /**
     * Stamp the incident's opening timeline entry as an autonomous AI action, so
     * the timeline records it was opened without human confirmation. Kept
     * internal (not public) because the AI action is unconfirmed.
     */
    private function stampAutonomousOpening(Incident $incident): void
    {
        $incident->updates()->create([
            'actor' => 'ai',
            'author' => 'Uptizm AI',
            'status' => IncidentStatus::Detected,
            'message' => 'Auto-opened from a high-confidence response-time anomaly without human confirmation.',
            'is_public' => false,
            'autonomous' => true,
            'display_at' => now(),
        ]);
    }

    /**
     * Build a trusted-evidence-only payload for the autonomous path. The auto
     * path deliberately omits all untrusted probe fields (error text, response
     * body, headers): it feeds the model ONLY our own statistical telemetry, so
     * no probe-controlled data can influence an autonomous incident decision.
     */
    private function buildTrustedPayload(Monitor $monitor, AnomalyCandidate $candidate): TriagePayload
    {
        $configured = is_array($monitor->regions) ? $monitor->regions : [];
        $knownRegions = array_values(array_unique(array_map(
            static fn (mixed $region): string => (string) $region,
            array_merge(array_keys($candidate->regionVotes), $configured),
        )));

        return new TriagePayload(
            monitorId: $monitor->id,
            signal: $candidate->signal,
            method: $candidate->method,
            score: $candidate->score,
            severity: $candidate->severity,
            evidence: $candidate->evidence,
            regionVotes: $candidate->regionVotes,
            knownCheckIds: [],
            knownMetricKeys: [],
            knownRegions: $knownRegions,
            // The auto arm of the same triage the job above runs; both feed
            // one operator-facing suggestion, so both name the language.
            language: PromptLanguage::nameFor($monitor->team?->preferredLocale()),
        );
    }

    /**
     * Whether a triage confidence clears the autonomous auto-open bar.
     */
    private function clearsAutoThreshold(AiConfidence $confidence): bool
    {
        return $this->confidenceRank($confidence) >= $this->confidenceRank(self::AUTO_CONFIDENCE_THRESHOLD);
    }

    /**
     * Rank a confidence so the ordinal (string-backed) enum can be compared.
     */
    private function confidenceRank(AiConfidence $confidence): int
    {
        return match ($confidence) {
            AiConfidence::High => 3,
            AiConfidence::Medium => 2,
            AiConfidence::Low => 1,
        };
    }

    /**
     * Map the anomaly's severity band to a degrade-path inbox confidence.
     */
    private function statisticalConfidence(string $severity): AiConfidence
    {
        return match ($severity) {
            'critical' => AiConfidence::High,
            'warn' => AiConfidence::Medium,
            default => AiConfidence::Low,
        };
    }

    /**
     * A deterministic, non-secret recommendation for the degrade fallback. It
     * cites no probe-controlled data and calls no model.
     */
    private function statisticalRecommendation(AnomalyCandidate $candidate): string
    {
        $method = strtoupper($candidate->method);

        return "Response time flagged by the {$method} detector. "
            .'Review the recent checks and confirm before acting on this anomaly.';
    }

    /**
     * Load the monitor's most recent response_ms checks, oldest-to-newest, as
     * the detector expects.
     *
     * @return Collection<int, MonitorCheck>
     */
    private function loadWindow(Monitor $monitor): Collection
    {
        return MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->whereNotNull('response_ms')
            ->orderByDesc('checked_at')
            ->limit(self::WINDOW_SIZE)
            ->get([
                'response_ms',
                'checked_at',
            ])
            ->reverse()
            ->values();
    }

    /**
     * Build the detector config from the monitor: its region, any configured
     * static bounds, and the window span so the cold-start window-age gate can
     * be evaluated.
     *
     * @param  Collection<int, MonitorCheck>  $window
     * @return array<string, mixed>
     */
    private function buildConfig(Monitor $monitor, Collection $window): array
    {
        $bounds = $this->resolveBounds($monitor);

        return [
            'monitor_id' => (string) $monitor->id,
            'region' => $this->resolveRegion($monitor),
            'warn_bound' => $bounds['warn'],
            'critical_bound' => $bounds['critical'],
            'window_from' => $window->first()->checked_at,
            'window_to' => $window->last()->checked_at,
        ];
    }

    /**
     * Resolve static response-time thresholds from the monitor's own millisecond
     * metric, when one is configured. With none, both bounds are null and the
     * detector's cold-start branch returns null rather than flagging.
     *
     * @return array{warn: ?float, critical: ?float}
     */
    private function resolveBounds(Monitor $monitor): array
    {
        $metric = MonitorMetric::query()
            ->where('monitor_id', $monitor->id)
            ->where('type', MetricType::Numeric->value)
            ->where('unit', MetricUnit::Millisecond->value)
            ->first();

        return [
            'warn' => $metric?->warn_bound,
            'critical' => $metric?->critical_bound,
        ];
    }

    /**
     * Pick the monitor's first configured region, defaulting to `global` when it
     * probes from none in particular.
     */
    private function resolveRegion(Monitor $monitor): string
    {
        $regions = is_array($monitor->regions) ? $monitor->regions : [];

        return $regions === [] ? 'global' : (string) reset($regions);
    }
}
