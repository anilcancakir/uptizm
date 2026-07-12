<?php

namespace App\Services\Ai;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Http\Controllers\Api\V1\AiSuggestionController;
use App\Jobs\PerformSslCheck;
use App\Jobs\SweepAiSuggestions;
use App\Models\AiSuggestion;
use App\Models\Incident;
use App\Models\Monitor;
use App\Services\Monitoring\ThresholdEvaluator;

/**
 * Opens a single AI-owned incident from an {@see AiSuggestion}.
 *
 * This is the shared incident-creation seam behind both the operator-accept
 * path ({@see AiSuggestionController::accept()})
 * and the autonomous sweep ({@see SweepAiSuggestions}). It performs
 * NO authorization and NO human gating: the caller owns that. The accept path
 * gates on team ownership plus a row-locked conditional claim; the autonomous
 * path gates on the daily budget plus a confidence threshold. This service only
 * writes the incident row (marked `ai_owned` with the `ai_anomaly` signal
 * source) and attaches the affected monitor, freezing its health at open time.
 *
 * Mirrors {@see PerformSslCheck::openSslIncident()}: `create` +
 * `monitors()->attach` on the public model path.
 */
class AiIncidentOpener
{
    /**
     * Open an AI-owned incident for the suggestion's monitor.
     *
     * Dedupes within the AI detection lane only: a monitor already carrying an
     * active AI-owned incident folds the suggestion into it rather than opening
     * a second, so a fresh anomaly episode on a still-firing monitor cannot
     * double-open. The dedupe is deliberately scoped to AI-owned incidents (an
     * active threshold or manual incident does NOT block an AI open), which is
     * the mirror of {@see ThresholdEvaluator::hasActiveIncidentForMonitor}
     * scoping its own dedupe to non-AI incidents: the two lanes are independent,
     * so neither source can mask the other.
     *
     * Callers dispatch the off-lock side effects only for a genuine open; the
     * returned model's `wasRecentlyCreated` flag distinguishes a fresh open from
     * a deduped fold, so a fold pages/broadcasts nothing a second time.
     *
     * @param  AiSuggestion  $suggestion  The suggestion whose monitor + severity seed the incident.
     * @return Incident The freshly opened incident, or the existing active AI incident on dedupe.
     */
    public function open(AiSuggestion $suggestion): Incident
    {
        $monitor = $suggestion->monitor;

        // 1. Dedupe within the AI lane: an active AI-owned incident already
        //    covers this monitor, so fold into it instead of opening a second.
        $existing = $this->activeAiIncidentForMonitor($monitor);
        if ($existing !== null) {
            return $existing;
        }

        $severity = IncidentSeverity::from($suggestion->severity);

        // 2. Persist the incident with the denormalized primary-monitor hint and
        //    the anomaly signal marker. Impact rolls up from the operator-facing
        //    severity the detector assigned to the suggestion.
        $incident = Incident::query()->create([
            'team_id' => $suggestion->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => "Anomaly detected on {$monitor->name}",
            'impact' => $severity->toImpact(),
            'severity' => $severity,
            'signal_source' => SignalSource::AiAnomaly,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => true,
            'trigger_metric_key' => $suggestion->signal,
            'started_at' => now(),
        ]);

        // 3. Attach the monitor to the affected-component pivot, freezing its
        //    current health at open time and mirroring it as the live status.
        $componentStatus = $monitor->last_status?->value ?? MonitorStatus::Down->value;
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => $componentStatus,
            'component_status_current' => $componentStatus,
        ]);

        return $incident;
    }

    /**
     * The monitor's active AI-owned incident, or null when none is open.
     *
     * Scoped to `ai_owned` incidents so only the AI lane dedupes against itself;
     * active-ness is the lifecycle enum's own predicate, filtered on the loaded
     * rows since there is no query scope for it.
     */
    protected function activeAiIncidentForMonitor(Monitor $monitor): ?Incident
    {
        return Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('ai_owned', true)
            ->get()
            ->first(fn (Incident $incident): bool => $incident->lifecycle->isActive());
    }
}
