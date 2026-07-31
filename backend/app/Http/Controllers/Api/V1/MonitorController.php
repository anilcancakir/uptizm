<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeMonitorRequest;
use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Http\Resources\MonitorResource;
use App\Jobs\PerformMonitorCheck;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\AnalysisPayload;
use App\Services\Ai\AnalysisResult;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Ai\ResponseTimeAnomalyDetector;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\CheckAggregateService;
use App\Services\Monitoring\RelayClient;
use App\Support\Monitoring\CheckResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped CRUD + lifecycle controls for {@see Monitor}.
 *
 * Serves the Flutter monitors list / show / edit screens. Cross-team
 * access is masked as 404 rather than 403 (see {@see self::authorizeTeam()})
 * so the existence of another team's monitors never leaks. Team scope is
 * enforced at the application layer via the acting user's
 * `current_team_id`; there is no row-level security.
 */
class MonitorController extends Controller
{
    /**
     * List the current team's monitors, newest first, paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $monitors = Monitor::query()
            ->where('team_id', $request->user()->current_team_id)
            ->orderByDesc('created_at')
            ->paginate();

        return MonitorResource::collection($monitors);
    }

    /**
     * Create a monitor for the current team and kick off a first check.
     */
    public function store(StoreMonitorRequest $request): JsonResponse
    {
        // 1. Persist the monitor scoped to the acting team, primed for the
        //    scheduler to pick up on its next tick.
        $monitor = Monitor::create([
            ...$request->validated(),
            'team_id' => $request->user()->current_team_id,
            'status' => 'active',
            'next_check_at' => now(),
        ]);

        // 2. Fan out an immediate first check per region so the detail page
        //    lands on real data instead of empty placeholders.
        $this->dispatchChecks($monitor);

        return MonitorResource::make($monitor)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a single monitor owned by the current team.
     *
     * Attaches the measured 24h / 7-day / 30-day uptime (from the raw check
     * stream) as transient attributes so the detail screen's KPI row and
     * reliability section render real figures. Each is null when its window
     * has no checks yet, so a brand-new monitor shows "no data" instead of a
     * fabricated 0% (which read as a total breach). Only computed here
     * (single-monitor show), never in the list/collection path, to avoid an
     * N+1 of aggregate queries.
     */
    public function show(Request $request, Monitor $monitor, CheckAggregateService $checks): MonitorResource
    {
        $this->authorizeTeam($request, $monitor);

        $monitor->setAttribute('uptime_24h', $this->measuredUptimePercent($checks, $monitor, '24h'));
        $monitor->setAttribute('slo_uptime_7d', $this->measuredUptimePercent($checks, $monitor, '7d'));
        $monitor->setAttribute('slo_uptime_30d', $this->measuredUptimePercent($checks, $monitor, '30d'));

        return MonitorResource::make($monitor);
    }

    /**
     * Measured uptime percentage for [$monitor] over [$range], or null when
     * the window holds no checks (so the client distinguishes "no data" from
     * a real 0%).
     */
    protected function measuredUptimePercent(CheckAggregateService $checks, Monitor $monitor, string $range): ?float
    {
        $summary = $checks->uptimeSummary($monitor, $range);

        return $summary->total > 0 ? round($summary->uptime_ratio * 100, 2) : null;
    }

    /**
     * Update a monitor owned by the current team.
     */
    public function update(UpdateMonitorRequest $request, Monitor $monitor): MonitorResource
    {
        $this->authorizeTeam($request, $monitor);

        $monitor->update($request->validated());

        return MonitorResource::make($monitor->refresh());
    }

    /**
     * Delete a monitor owned by the current team.
     */
    public function destroy(Request $request, Monitor $monitor): Response
    {
        $this->authorizeTeam($request, $monitor);

        $monitor->delete();

        return response()->noContent();
    }

    /**
     * Pause a monitor: stop scheduling checks by clearing `next_check_at`.
     */
    public function pause(Request $request, Monitor $monitor): MonitorResource
    {
        $this->authorizeTeam($request, $monitor);

        $monitor->forceFill([
            'status' => 'paused',
            'next_check_at' => null,
        ])->save();

        return MonitorResource::make($monitor);
    }

    /**
     * Resume a paused monitor: re-arm `next_check_at` for the next tick.
     */
    public function resume(Request $request, Monitor $monitor): MonitorResource
    {
        $this->authorizeTeam($request, $monitor);

        $monitor->forceFill([
            'status' => 'active',
            'next_check_at' => now(),
        ])->save();

        return MonitorResource::make($monitor);
    }

    /**
     * Fire an immediate one-off probe across the monitor's regions.
     */
    public function test(Request $request, Monitor $monitor): Response
    {
        $this->authorizeTeam($request, $monitor);

        $this->dispatchChecks($monitor);

        return response()->noContent(HttpResponse::HTTP_ACCEPTED);
    }

    /**
     * Probe a candidate URL and suggest a starting monitor configuration.
     *
     * Backs the "Analyze with AI" flow on the create-monitor screen. The URL
     * is not yet a monitor, so it is wrapped in a transient (unsaved)
     * {@see Monitor} the {@see RelayClient} can probe. The probe timing is
     * run through {@see ResponseTimeAnomalyDetector} (a single sample stays in
     * cold-start, so no anomaly is manufactured) and the probe plus any
     * detector read are handed to the {@see AnalysisGateway} for a suggested
     * configuration.
     *
     * The per-team AI budget is spent AT THIS call site: over budget degrades
     * to a deterministic suggestion derived from the probe alone, never
     * calling the LLM, so the endpoint still prefills a config. The gateway
     * only SUGGESTS: the operator still submits the create form and can
     * override every field.
     *
     * The same probe body also feeds {@see MetricDiscoveryService}, so the
     * response carries `suggested_metrics` beside the configuration. That rides
     * this call's EXISTING metered try: the operator asked for one analysis and
     * spends one, whatever the probe body turned out to contain.
     */
    public function analyze(
        AnalyzeMonitorRequest $request,
        RelayClient $relay,
        ResponseTimeAnomalyDetector $detector,
        AnalysisGateway $gateway,
        AiBudget $budget,
        MetricDiscoveryService $discovery,
    ): JsonResponse {
        $gate = new PlanGate;
        $team = Team::find($request->user()->current_team_id);
        if ($team !== null) {
            // Open on Free for a metered number of setups, entitled outright on
            // the AI tiers. The meter is spent below, only once a setup actually
            // produced a result, so a failed probe never costs the user a try.
            $gate->assertAiAnalysisAllowed($team);
        }

        $url = (string) $request->validated('url');
        $region = $request->probeRegion();

        // 1. Probe the target through a transient monitor: the URL has no row
        //    yet, so a throwaway instance carries the probe spec the relay
        //    (and its SSRF-checked worker) executes. The SSRF host denylist
        //    already rejected an internal target in request validation.
        $transient = $this->transientMonitor($url, $region);
        $probe = $relay->dispatch($transient, $region);

        // 2. Run the detector over the single-probe window. One sample never
        //    clears the cold-start gate, so the candidate is null here; wiring
        //    it keeps the analysis payload consistent with the sweep pipeline
        //    once prior history exists.
        $candidate = $detector->detect(
            $probe->responseMs !== null ? [$probe->responseMs] : [],
            [
                'region' => $region,
                'monitor_id' => '',
            ],
        );

        // 3. Spend one unit of the team's daily AI budget atomically. Over
        //    budget is not a failure: it degrades to a deterministic
        //    suggestion (statistics as the source of truth), it never drops
        //    the analyze. Within budget, the LLM labels the probe.
        $teamId = (string) $request->user()->current_team_id;

        $result = $budget->tryConsume($teamId)
            ? $gateway->analyze($this->analysisPayload($url, $region, $probe, $candidate))
            : $this->deterministicSuggestion($probe, $region);

        // 4. Mine the SAME probe body for metrics worth proposing. The body is
        //    already in memory here, so this costs no second probe; discovery
        //    spends its own budget unit and degrades to an empty array on its
        //    own, so a create flow never fails because of a suggestion.
        $suggestedMetrics = $discovery->discover($transient, $probe->content, $teamId);

        // 5. The setup produced a result, so spend one metered try (a no-op on a
        //    tier that entitles AI analysis) and report what is left, so the
        //    client can count the allowance down without a second request.
        if ($team !== null) {
            $gate->consumeAiAnalysisTrial($team);
        }

        return response()->json([
            'data' => [
                'url' => $url,
                'name' => $this->suggestedName($url),
                ...$result->toArray(),
                'suggested_metrics' => $suggestedMetrics,
                'probe' => [
                    'region' => $probe->region,
                    'status_code' => $probe->statusCode,
                    'response_ms' => $probe->responseMs,
                ],
            ],
            'meta' => [
                'ai_analysis_trials_remaining' => $team !== null
                    ? $gate->aiAnalysisTrialsRemaining($team)
                    : null,
            ],
        ]);
    }

    /**
     * Wrap a candidate URL in a transient, unsaved monitor the relay can probe.
     *
     * The instance is never persisted: it only carries the fields
     * {@see RelayClient} reads to build the worker probe spec, defaulted to a
     * plain HTTP GET expecting a 200.
     */
    protected function transientMonitor(string $url, string $region): Monitor
    {
        return new Monitor([
            'type' => MonitorType::Http,
            'method' => HttpMethod::Get,
            'url' => $url,
            'timeout_sec' => 30,
            'expected_status_code' => 200,
            'regions' => [$region],
        ]);
    }

    /**
     * Hydrate the analysis payload from the probe and its optional detector
     * read.
     *
     * The attacker-influenceable probe fields (error message, body preview)
     * are handed through untouched: {@see AnalysisPayload} fences and hard
     * truncates them at the LLM boundary. Response headers are withheld
     * entirely so a probe-controlled secret header can never reach the model.
     */
    protected function analysisPayload(
        string $url,
        string $region,
        CheckResult $probe,
        ?object $candidate,
    ): AnalysisPayload {
        return new AnalysisPayload(
            url: $url,
            region: $region,
            statusCode: $probe->statusCode,
            responseMs: $probe->responseMs,
            timingDnsMs: $probe->timingDnsMs,
            timingConnectMs: $probe->timingConnectMs,
            timingTlsMs: $probe->timingTlsMs,
            timingTtfbMs: $probe->timingTtfbMs,
            timingDownloadMs: $probe->timingDownloadMs,
            knownRegions: MonitorRegion::values(),
            detectorSignal: $candidate->signal ?? null,
            detectorMethod: $candidate->method ?? null,
            detectorScore: $candidate->score ?? null,
            detectorSeverity: $candidate->severity ?? null,
            detectorEvidence: $candidate->evidence ?? [],
            errorMessage: $probe->errorMessage,
            responseBodyPreview: $probe->responseBodyPreview,
            responseHeaders: [],
        );
    }

    /**
     * Build a deterministic suggestion from the probe alone, used when the
     * team is over its daily AI budget so the LLM is never called.
     *
     * Bounds are anchored to the observed response time (warn at 3x, critical
     * at 6x, with sane floors) so the prefill stays useful even without a
     * model narration.
     */
    protected function deterministicSuggestion(CheckResult $probe, string $region): AnalysisResult
    {
        $observed = $probe->responseMs ?? 500;

        return new AnalysisResult(
            recommendedIntervalSeconds: 60,
            recommendedWarnThresholdMs: max(500, $observed * 3),
            recommendedCriticalThresholdMs: max(1000, $observed * 6),
            recommendedRegions: [$region],
            rationale: 'Deterministic baseline from the exploratory probe (AI analysis budget exhausted for today).',
            strippedCitations: [],
        );
    }

    /**
     * Derive a human-friendly default monitor name from the target host.
     */
    protected function suggestedName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return 'New monitor';
        }

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * Guard team ownership, masking a foreign monitor as 404.
     *
     * A 403 would confirm the monitor exists; the 404 mask keeps the
     * existence of another team's monitors hidden.
     */
    protected function authorizeTeam(Request $request, Monitor $monitor): void
    {
        abort_if(
            $monitor->team_id !== $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * Dispatch one check job per configured region for the monitor.
     */
    protected function dispatchChecks(Monitor $monitor): void
    {
        foreach ($monitor->regions ?? [] as $region) {
            PerformMonitorCheck::dispatch($monitor, $region);
        }
    }
}
