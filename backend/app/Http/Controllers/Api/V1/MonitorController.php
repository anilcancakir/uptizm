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
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;
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
     * Name of the limiter on POST /monitors/analyze.
     *
     * Declared here and referenced by name from `routes/api.php` and
     * `bootstrap/app.php`, so a rename cannot leave the route silently
     * unbounded. The limiter is REQUIRED rather than defensive: `api/v1` never
     * calls `throttleApi()`, and one accepted request runs a live relay probe
     * against an operator-supplied URL plus up to two provider calls. The
     * per-team daily AI budget caps the model spend over a DAY and degrades
     * instead of refusing, so it bounds cost rather than rate, and it does not
     * bound the probe at all.
     *
     * Unlike {@see self::test()} this cannot be a per-resource cooldown: the
     * target of an analyze is not a monitor yet, so there is no row to claim.
     */
    public const string ANALYZE_LIMITER = 'monitor-analyze';

    /**
     * Why {@see self::deterministicSuggestion()} answered instead of a model, in
     * the operator's own terms.
     *
     * Two causes, two wordings, deliberately not shared: an exhausted budget is
     * answered by waiting for tomorrow or upgrading, while a model that
     * misbehaved is answered by trying again shortly. Telling an operator their
     * budget is gone when it is intact sends them to the wrong place.
     */
    protected const string DEGRADE_BUDGET_EXHAUSTED = 'AI analysis budget exhausted for today';

    protected const string DEGRADE_AI_UNAVAILABLE = 'AI analysis temporarily unavailable';

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
     *
     * Gated by a per-monitor cooldown ({@see Monitor::MANUAL_CHECK_COOLDOWN_SECONDS})
     * claimed via {@see self::claimManualCheck()}. The route itself carries no
     * throttle: this is a per-resource cooldown, not a per-route rate limit,
     * and a route limiter cannot express "once per minute per monitor"
     * cleanly.
     */
    public function test(Request $request, Monitor $monitor): Response|JsonResponse
    {
        $this->authorizeTeam($request, $monitor);

        if (! $this->claimManualCheck($monitor)) {
            return $this->manualCheckCooldownResponse($monitor);
        }

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
     * calling the LLM, so the endpoint still prefills a config. A model whose
     * output the gateway refuses, and a provider we cannot reach, degrade the
     * same way ({@see self::suggestViaGateway()}): this is a prefill on a form
     * the operator still submits, so it must never become a 500 in the middle
     * of creating a monitor. The gateway only SUGGESTS: the operator still
     * submits the create form and can override every field.
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
            // the AI tiers. The meter is spent below, only once a MODEL actually
            // delivered an analysis, so neither a failed probe nor a degrade
            // costs the user a try.
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
        $withinBudget = $budget->tryConsume($teamId);

        $modelled = $withinBudget
            ? $this->suggestViaGateway($gateway, $url, $region, $probe, $candidate)
            : null;

        // 4. Either degrade path answers with the same deterministic suggestion,
        //    naming its own cause: within budget a null means the model or the
        //    provider failed, outside it the budget did.
        $result = $modelled ?? $this->deterministicSuggestion(
            $probe,
            $region,
            $withinBudget ? self::DEGRADE_AI_UNAVAILABLE : self::DEGRADE_BUDGET_EXHAUSTED,
        );

        // 5. Mine the SAME probe body for metrics worth proposing. The body is
        //    already in memory here, so this costs no second probe; discovery
        //    spends its own budget unit and degrades to an empty array on its
        //    own, so a create flow never fails because of a suggestion.
        $suggestedMetrics = $discovery->discover($transient, $probe->content, $teamId);

        // 6. A metered try buys AI ANALYSIS, so it is spent only when a model
        //    actually delivered one: neither degrade path above ran a model, so
        //    neither charges for one. A no-op on a tier that entitles AI
        //    analysis. Reporting what is left lets the client count the
        //    allowance down without a second request.
        //
        //    Residual, and unfixable from here: this is the last call before the
        //    response, so the try is spent once the server has an answer to
        //    deliver, but a client that disconnects after the response was
        //    flushed has still spent it and the server cannot observe that. The
        //    alternative is an acknowledgement round trip for a three-use meter.
        if ($team !== null && $modelled !== null) {
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
     * The model's suggestion for this probe, or null when it could not be
     * trusted or the provider could not be reached.
     *
     * Mirrors {@see MetricDiscoveryService::select()}'s degrade: non-conforming
     * output past the gateway's own retry raises a {@see RuntimeException},
     * while an outage, a timeout or a missing key raises a client exception, and
     * all of them return the same null so the caller's wire shape never changes
     * on a bad day.
     *
     * {@see AiException} is the fourth, and it is not redundant with the client
     * exceptions: `Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors` maps a
     * provider 429, 402 or 503 onto an `AiException` SUBCLASS before it ever
     * reaches a caller, and the OpenRouter gateway raises a plain `AiException`
     * for an error payload the provider delivers in-band with HTTP 200. Neither
     * descends from `RuntimeException`, so without this branch the most ordinary
     * provider bad day there is would still 500 the create flow.
     *
     * Only those four, all named: a `TypeError` or an `Error` from our own code
     * still surfaces as a 500 rather than hiding behind a plausible suggestion.
     *
     * Two things are deliberately absent from the log line. The exception
     * MESSAGE, because a gateway message can quote the model, which was reading
     * text the target authored ({@see MetricDiscoveryService} logs it; that key
     * is not copied here). And every probe field, for the same reason. What is
     * left is the operator's own validated target and the region it ran from,
     * which is the only monitor context an analyze has: the URL is not a monitor
     * yet, so there is no id to name.
     */
    protected function suggestViaGateway(
        AnalysisGateway $gateway,
        string $url,
        string $region,
        CheckResult $probe,
        ?object $candidate,
    ): ?AnalysisResult {
        try {
            return $gateway->analyze($this->analysisPayload($url, $region, $probe, $candidate));
        } catch (RuntimeException) {
            Log::warning('Monitor analysis degraded: the model output could not be trusted.', [
                'url' => $url,
                'region' => $region,
            ]);
        } catch (ConnectionException|RequestException) {
            Log::warning('Monitor analysis degraded: the AI service was unreachable.', [
                'url' => $url,
                'region' => $region,
            ]);
        } catch (AiException) {
            Log::warning('Monitor analysis degraded: the AI provider could not complete the request.', [
                'url' => $url,
                'region' => $region,
            ]);
        }

        return null;
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
     * Build a deterministic suggestion from the probe alone, used on every path
     * where no model narration is available.
     *
     * Bounds are anchored to the observed response time (warn at 3x, critical
     * at 6x, with sane floors) so the prefill stays useful even without a
     * model narration.
     *
     * [$reason] is carried into the rationale rather than hardcoded because two
     * different causes reach here and the operator acts differently on each; see
     * {@see self::DEGRADE_BUDGET_EXHAUSTED}.
     */
    protected function deterministicSuggestion(CheckResult $probe, string $region, string $reason): AnalysisResult
    {
        $observed = $probe->responseMs ?? 500;

        return new AnalysisResult(
            recommendedIntervalSeconds: 60,
            recommendedWarnThresholdMs: max(500, $observed * 3),
            recommendedCriticalThresholdMs: max(1000, $observed * 6),
            recommendedRegions: [$region],
            rationale: "Deterministic baseline from the exploratory probe ({$reason}).",
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

    /**
     * Atomically claim the manual-check cooldown for [$monitor].
     *
     * A single conditional UPDATE, not a find-then-save: the WHERE clause
     * re-checks the cooldown at the database, not against the possibly stale
     * in-memory [$monitor]. Of two concurrent requests, at most one UPDATE
     * affects a row, so the caller can dispatch on the strength of the
     * affected-row count alone, with no separate read to race against.
     */
    protected function claimManualCheck(Monitor $monitor): bool
    {
        $affected = Monitor::query()
            ->where('id', $monitor->id)
            ->where(function (Builder $query): void {
                $query->whereNull('last_manual_check_at')
                    ->orWhere(
                        'last_manual_check_at',
                        '<=',
                        now()->subSeconds(Monitor::MANUAL_CHECK_COOLDOWN_SECONDS),
                    );
            })
            ->update(['last_manual_check_at' => now()]);

        return $affected > 0;
    }

    /**
     * Build the 429 refusal for a manual check still on cooldown.
     *
     * Re-reads `last_manual_check_at` rather than trusting [$monitor]'s
     * in-memory copy, so the remaining seconds reflect whichever concurrent
     * request actually won the claim.
     */
    protected function manualCheckCooldownResponse(Monitor $monitor): JsonResponse
    {
        $lastManualCheckAt = Monitor::query()
            ->where('id', $monitor->id)
            ->value('last_manual_check_at');

        $elapsedSeconds = $lastManualCheckAt !== null
            ? (int) floor(now()->diffInSeconds(Carbon::parse($lastManualCheckAt), true))
            : 0;

        $remainingSeconds = max(1, Monitor::MANUAL_CHECK_COOLDOWN_SECONDS - $elapsedSeconds);

        return response()->json([
            'message' => 'A manual check for this monitor was run recently.',
            'retry_after_seconds' => $remainingSeconds,
        ], HttpResponse::HTTP_TOO_MANY_REQUESTS);
    }
}
