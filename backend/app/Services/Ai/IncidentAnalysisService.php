<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Incident;
use App\Models\MonitorCheck;
use Illuminate\Support\Collection;

/**
 * Composes an incident's timeline plus the checks recorded against its
 * affected monitors into a fenced {@see IncidentAnalysisPayload} and asks the
 * {@see IncidentAnalysisGateway} for a post-incident root-cause summary.
 *
 * Mirrors {@see MonitorController::analyze()}'s
 * budget-guard shape: the per-team AI budget is spent AT THIS call site. Over
 * budget degrades to a deterministic summary derived from the incident's own
 * fields, never calling the LLM, so the endpoint still answers.
 */
class IncidentAnalysisService
{
    /**
     * Maximum number of recent checks folded into the RCA evidence.
     */
    private const MAX_CHECKS = 20;

    public function __construct(
        protected IncidentAnalysisGateway $gateway,
        protected AiBudget $budget,
    ) {}

    /**
     * Summarize the likely root cause of an incident from its timeline and
     * the checks recorded against its affected monitors during the incident
     * window.
     */
    public function analyzeFor(Incident $incident): IncidentAnalysisResult
    {
        $incident->loadMissing([
            'updates' => fn ($query) => $query->orderBy('display_at'),
            'monitors',
        ]);

        $monitorIds = $this->affectedMonitorIds($incident);

        $checks = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $incident->started_at)
            ->orderByDesc('checked_at')
            ->limit(self::MAX_CHECKS)
            ->get();

        $payload = $this->buildPayload($incident, $checks, $monitorIds);

        $teamId = (string) $incident->team_id;

        return $this->budget->tryConsume($teamId)
            ? $this->gateway->analyze($payload)
            : $this->deterministicSummary($incident);
    }

    /**
     * The affected monitor ids: the primary hint plus the full pivot set.
     *
     * @return list<string>
     */
    protected function affectedMonitorIds(Incident $incident): array
    {
        return $incident->monitors
            ->pluck('id')
            ->push($incident->primary_monitor_id)
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Hydrate the fenced payload from the incident's timeline and its
     * recorded checks.
     *
     * @param  Collection<int, MonitorCheck>  $checks
     * @param  list<string>  $monitorIds
     */
    protected function buildPayload(Incident $incident, Collection $checks, array $monitorIds): IncidentAnalysisPayload
    {
        $timeline = $incident->updates->map(fn ($update) => [
            'author' => $update->author,
            'status' => $update->status?->value,
            'is_public' => (bool) $update->is_public,
            'autonomous' => (bool) $update->autonomous,
            'display_at' => $update->display_at?->toIso8601String(),
            'message' => $update->message,
        ])->all();

        $trustedChecks = $checks->map(fn (MonitorCheck $check) => [
            'check_id' => (string) $check->id,
            'monitor_id' => (string) $check->monitor_id,
            'region' => $check->region,
            'status' => $check->status?->value,
            'status_code' => $check->status_code,
            'response_ms' => $check->response_ms,
            'checked_at' => $check->checked_at?->toIso8601String(),
        ])->all();

        $untrustedChecks = $checks->map(fn (MonitorCheck $check) => [
            'check_id' => (string) $check->id,
            'error_message' => $check->error_message,
            'response_body_preview' => $check->response_body_preview,
            'response_headers' => $check->response_headers ?? [],
        ])->all();

        return new IncidentAnalysisPayload(
            incidentId: (string) $incident->id,
            severity: $incident->severity?->value ?? 'unknown',
            impact: $incident->impact?->value ?? 'unknown',
            lifecycle: $incident->lifecycle?->value ?? 'unknown',
            signalSource: $incident->signal_source?->value ?? 'unknown',
            aiOwned: (bool) $incident->ai_owned,
            startedAt: $incident->started_at?->toIso8601String() ?? '',
            resolvedAt: $incident->resolved_at?->toIso8601String(),
            timeline: $timeline,
            checks: $trustedChecks,
            untrustedChecks: $untrustedChecks,
            knownCheckIds: array_column($trustedChecks, 'check_id'),
            knownMonitorIds: $monitorIds,
        );
    }

    /**
     * Build a deterministic summary from the incident's own fields, used
     * when the team is over its daily AI budget so the LLM is never called.
     */
    protected function deterministicSummary(Incident $incident): IncidentAnalysisResult
    {
        return new IncidentAnalysisResult(
            summary: sprintf(
                'Deterministic baseline from the incident record (AI analysis budget exhausted for today): %s severity incident, currently %s.',
                $incident->severity?->value ?? 'unknown',
                $incident->lifecycle?->value ?? 'unknown',
            ),
            confidence: AiConfidence::Low,
            contributingFactors: [],
            strippedCitations: [],
        );
    }
}
