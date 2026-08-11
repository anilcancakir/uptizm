<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiDegradeReason;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Incident;
use App\Models\MonitorCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

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

        // 1. Over budget never calls the LLM: degrade to the deterministic
        //    baseline so the endpoint still answers.
        if (! $this->budget->tryConsume($teamId)) {
            return $this->deterministicSummary($incident, AiDegradeReason::BudgetExhausted);
        }

        // 2. Three failures, one baseline: non-conforming output past the
        //    gateway's single retry, an unreachable AI service (outage, timeout,
        //    or a missing/invalid key), and a provider that answered with an
        //    error body all degrade to the SAME deterministic baseline, so the
        //    endpoint returns the identical empty-array wire shape rather than a
        //    500. They differ only in the reason code the client reads. The two
        //    provider-side failures are logged so the ops problem stays visible.
        try {
            return $this->gateway->analyze($payload);
        } catch (NonConformingAnalysisException) {
            return $this->deterministicSummary($incident, AiDegradeReason::OutputUntrusted);
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Incident AI analysis degraded: the AI service was unreachable.', [
                'incident_id' => (string) $incident->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->deterministicSummary($incident, AiDegradeReason::ServiceUnreachable);
        } catch (AiException) {
            // The third class, and not redundant with the two above:
            // `AiException extends Exception`, so it descends from neither this
            // app's own exception nor the Guzzle pair, and OpenRouter's
            // `ParsesTextResponses::validateTextResponse()` raises a PLAIN one
            // for an error body delivered in-band with HTTP 200. Without this
            // branch the most ordinary provider bad day there is answered 500,
            // against this method's own promise to degrade. It maps to the same
            // reason as an unreachable service because to a caller a provider
            // that answered with an error body is a provider that did not
            // answer; the finer distinction (429 vs 402 vs 503, all
            // `AiException` subclasses) is an ops concern, not an operator one.
            //
            // No `exception` key here, unlike the branch above: a Guzzle message
            // is our own client describing what it could not reach, while this
            // one is text the PROVIDER chose, and a message a third party
            // authored is not something to copy into our logs.
            Log::warning('Incident AI analysis degraded: the AI provider could not complete the request.', [
                'incident_id' => (string) $incident->id,
            ]);

            return $this->deterministicSummary($incident, AiDegradeReason::ServiceUnreachable);
        }
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
     * whenever the LLM is not consulted (over budget) or its output could not
     * be trusted (non-conforming past the retry).
     *
     * The enriched evidence and action arrays are left empty so this fallback
     * returns the IDENTICAL wire shape as the LLM path: the client renders no
     * hole and never sees a fabricated source.
     *
     * `summary` here is a MACHINE-READABLE BASELINE, not display copy. It
     * carries only the incident's own severity and lifecycle, in English, so a
     * direct API consumer sees a summary rather than an empty string; the client
     * ignores it and composes its own localized sentence from `degradeReason`
     * plus the labels it already translates. It used to open with a preamble
     * naming the baseline and parenthesising the reason as an English clause, and
     * a Turkish operator read that clause verbatim on the incident screen, which
     * is why the reason left the prose and became a field.
     *
     * @param  AiDegradeReason  $reason  Which failure sent us to the baseline.
     */
    protected function deterministicSummary(Incident $incident, AiDegradeReason $reason): IncidentAnalysisResult
    {
        return new IncidentAnalysisResult(
            summary: sprintf(
                '%s severity incident, currently %s.',
                $incident->severity?->value ?? 'unknown',
                $incident->lifecycle?->value ?? 'unknown',
            ),
            confidence: AiConfidence::Low,
            contributingFactors: [],
            strippedCitations: [],
            evidenceFor: [],
            evidenceAgainst: [],
            suggestedActions: [],
            degradeReason: $reason,
        );
    }
}
