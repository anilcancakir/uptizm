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
     */
    public function handle(
        ResponseTimeAnomalyDetector $detector,
        AnomalyTriageGateway $gateway,
        AiBudget $budget,
        AiIncidentOpener $opener,
    ): void {
        // Chunk the fleet so a large workspace never loads every monitor into
        // memory at once. Off monitors are excluded by the scope.
        Monitor::query()
            ->whereIn('ai_mode', [
                AiMode::Suggest,
                AiMode::Auto,
            ])
            ->each(function (Monitor $monitor) use ($detector, $gateway, $budget, $opener): void {
                $this->sweepMonitor($monitor, $detector, $gateway, $budget, $opener);
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
    ): void {
        // 1. Pull the bounded, oldest-to-newest response_ms window.
        $window = $this->loadWindow($monitor);
        if ($window->isEmpty()) {
            return;
        }

        // 2. Score it. A null result means no actionable anomaly (or cold-start
        //    with no configured bounds), so there is nothing to act on.
        $candidate = $detector->detect(
            $window->pluck('response_ms')->map(static fn (mixed $ms): int => (int) $ms)->all(),
            $this->buildConfig($monitor, $window),
        );
        if ($candidate === null) {
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
            $this->sweepAuto($monitor, $candidate, $gateway, $budget, $opener);

            return;
        }

        // 5. Suggest: hand the non-secret candidate DTO to the triage job on the
        //    ai queue, exactly as before.
        TriageAnomalyCandidate::dispatch((string) $monitor->id, $candidate->toArray())->onQueue('ai');
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
        DB::transaction(function () use ($monitor, $candidate, $result, $opener): void {
            $suggestion = $this->persistSuggestion($monitor, $candidate, [
                'severity' => $result->severity,
                'confidence' => $result->confidence,
                'source' => 'llm',
                'recommendation' => $result->recommendation,
            ], AiSuggestionStatus::Accepted, null);
            $suggestion->setRelation('monitor', $monitor);

            $incident = $opener->open($suggestion);
            $suggestion->forceFill(['accepted_incident_id' => $incident->id])->save();

            $this->stampAutonomousOpening($incident);
        });
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
