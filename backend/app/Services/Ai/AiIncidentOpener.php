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
     * @param  AiSuggestion  $suggestion  The suggestion whose monitor + severity seed the incident.
     * @return Incident The freshly opened incident.
     */
    public function open(AiSuggestion $suggestion): Incident
    {
        $monitor = $suggestion->monitor;
        $severity = IncidentSeverity::from($suggestion->severity);

        // 1. Persist the incident with the denormalized primary-monitor hint and
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

        // 2. Attach the monitor to the affected-component pivot, freezing its
        //    current health at open time and mirroring it as the live status.
        $componentStatus = $monitor->last_status?->value ?? MonitorStatus::Down->value;
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => $componentStatus,
            'component_status_current' => $componentStatus,
        ]);

        return $incident;
    }
}
