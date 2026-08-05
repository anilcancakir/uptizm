<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BodyShape;
use App\Enums\HttpMethod;
use App\Enums\LocationBasis;
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
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Ai\ResponseTimeAnomalyDetector;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\CheckAggregateService;
use App\Services\Monitoring\RelayClient;
use App\Services\Monitoring\ResponseDigest;
use App\Services\Monitoring\ResponseDigestResult;
use App\Services\Monitoring\TargetLocation;
use App\Services\Monitoring\TargetLocationResult;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\HostGuard;
use App\Support\Monitoring\ProbeHeaderAllowList;
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
     * The uptime target the deterministic path prefills for each service class.
     *
     * A table rather than a judgement, because this path reads no semantics: the
     * body's SHAPE is all it has. Its keys are exactly
     * {@see LaravelAiAnalysisGateway::SERVICE_CLASSES} and its values exactly
     * members of {@see LaravelAiAnalysisGateway::SLO_TARGETS}, both pinned by a
     * test, because a value outside either set is not a prefill the operator's
     * form can hold.
     *
     * `99.99` appears nowhere in it: fifty-two minutes of allowed downtime a
     * year is a commitment nothing in a single probe can justify. `unknown` gets
     * `none` from the other end of the same rule: we could not tell what the
     * service is, so we name no target for it. Between those two, a
     * `health_endpoint` is held tighter than a `web_page` or a `json_api`
     * because it renders nothing, calls no third party and exists to answer one
     * liveness question, which is the surface an operator would actually write a
     * stricter number down for.
     *
     * @var array<string, string>
     */
    public const array SLO_TARGET_BY_SERVICE_CLASS = [
        'json_api' => '99.9',
        'health_endpoint' => '99.95',
        'web_page' => '99.9',
        'tcp_service' => '99.9',
        'unknown' => 'none',
    ];

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
     *
     * Every piece of evidence past the probe's own metadata is DERIVED from that
     * one {@see CheckResult}, never fetched again: the headers are filtered from
     * the set it already carries, the digest is rendered from the body already in
     * memory, and the only additional call is one DNS lookup
     * ({@see self::targetIps()}) plus, when the target is not behind a CDN, an
     * optional geo lookup that is dormant unless a token is configured.
     */
    public function analyze(
        AnalyzeMonitorRequest $request,
        RelayClient $relay,
        ResponseTimeAnomalyDetector $detector,
        AnalysisGateway $gateway,
        AiBudget $budget,
        MetricDiscoveryService $discovery,
        ResponseDigest $digester,
        TargetLocation $targetLocation,
        HostGuard $hostGuard,
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

        // 3. Assemble the evidence from the ONE probe already in memory. The
        //    header allowlist runs FIRST because everything below reads its
        //    output and nothing may read the raw set: the worker returns every
        //    response header verbatim, and once the next plan sends the
        //    operator's own credential, `Set-Cookie` on that set is an
        //    authenticated session token. It stops here, at this line.
        //
        //    A digest only where there is a body to describe: null content is a
        //    TCP probe, a content type the edge filtered out, or an older worker,
        //    and a null digest renders as an explicit `n/a` in the prompt rather
        //    than as an empty body we never observed.
        $headers = ProbeHeaderAllowList::filter($probe->responseHeaders);
        $digest = $probe->content !== null ? $digester->digest($probe->content) : null;
        $location = $targetLocation->resolve($url, $headers, $this->targetIps($hostGuard, $url));

        // 4. Spend one unit of the team's daily AI budget atomically. Over
        //    budget is not a failure: it degrades to a deterministic
        //    suggestion (statistics as the source of truth), it never drops
        //    the analyze. Within budget, the LLM labels the probe.
        $teamId = (string) $request->user()->current_team_id;
        $withinBudget = $budget->tryConsume($teamId);

        $modelled = $withinBudget
            ? $this->suggestViaGateway(
                $gateway,
                $this->analysisPayload($url, $region, $teamId, $probe, $candidate, $headers, $digest, $location),
            )
            : null;

        // 5. Either degrade path answers with the same deterministic suggestion,
        //    naming its own cause: within budget a null means the model or the
        //    provider failed, outside it the budget did. It carries the same
        //    fields a modelled answer does, read off the same evidence, so the
        //    client decodes one shape on every path.
        $result = $modelled ?? $this->deterministicSuggestion(
            $probe,
            $region,
            $withinBudget ? self::DEGRADE_AI_UNAVAILABLE : self::DEGRADE_BUDGET_EXHAUSTED,
            $digest,
            $location,
        );

        // 6. Mine the SAME probe body for metrics worth proposing. The body is
        //    already in memory here, so this costs no second probe; discovery
        //    spends its own budget unit and degrades to an empty array on its
        //    own, so a create flow never fails because of a suggestion.
        $suggestedMetrics = $discovery->discover($transient, $probe->content, $teamId);

        // 7. A metered try buys AI ANALYSIS, so it is spent only when a model
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
    protected function suggestViaGateway(AnalysisGateway $gateway, AnalysisPayload $payload): ?AnalysisResult
    {
        try {
            return $gateway->analyze($payload);
        } catch (RuntimeException) {
            Log::warning('Monitor analysis degraded: the model output could not be trusted.', [
                'url' => $payload->url,
                'region' => $payload->region,
            ]);
        } catch (ConnectionException|RequestException) {
            Log::warning('Monitor analysis degraded: the AI service was unreachable.', [
                'url' => $payload->url,
                'region' => $payload->region,
            ]);
        } catch (AiException) {
            Log::warning('Monitor analysis degraded: the AI provider could not complete the request.', [
                'url' => $payload->url,
                'region' => $payload->region,
            ]);
        }

        return null;
    }

    /**
     * Hydrate the analysis payload from the probe, the evidence derived from it,
     * and its optional detector read.
     *
     * The attacker-influenceable probe fields (error message, body preview, the
     * surviving header VALUES, the digest) are handed through untouched:
     * {@see AnalysisPayload} fences and hard truncates them at the LLM boundary.
     * Response headers used to be withheld entirely; they now reach the model
     * because {@see ProbeHeaderAllowList} decided by NAME which of them the
     * prompt has a consumer for, and nothing credential-bearing is on that list.
     * The caller has already applied it, and this method must be handed its
     * output rather than the raw set.
     *
     * @param  array<string, string>  $headers  Headers already through the allowlist.
     */
    protected function analysisPayload(
        string $url,
        string $region,
        string $teamId,
        CheckResult $probe,
        ?object $candidate,
        array $headers,
        ?ResponseDigestResult $digest,
        TargetLocationResult $location,
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
            responseHeaders: $headers,
            teamId: $teamId,
            digest: $digest,
            targetLocation: $location,
        );
    }

    /**
     * The public addresses the target resolves to, or an empty list.
     *
     * Resolved AFTER the probe, and this is the only DNS lookup the analyze path
     * adds. Two reasons for the ordering: nothing before the probe needs an
     * address, because {@see TargetLocation} reads the RESPONSE headers to decide
     * whether asking a geo provider is even honest, and moving the lookup earlier
     * would only change where the same milliseconds are spent. Measured against
     * `example.com` from this machine: 2-3 ms warm, 88 ms on a resolver cache
     * miss, inside a request that already spends a relay probe plus up to two
     * provider calls, so it does not move the latency budget the operator is
     * waiting on.
     *
     * {@see HostGuard} is the only DNS code in this backend and
     * {@see HostGuard::resolvePublicHostIps()} is its fail-closed entry point:
     * one denied address discards the whole list, so an empty return covers an
     * unresolvable host and a rebinding-shaped one alike, which is exactly how
     * `TargetLocation` treats both.
     *
     * @return list<string>
     */
    protected function targetIps(HostGuard $hostGuard, string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? $hostGuard->resolvePublicHostIps($host)
            : [];
    }

    /**
     * Build a deterministic suggestion from the probe and the evidence derived
     * from it, used on every path where no model narration is available.
     *
     * Bounds are anchored to the observed response time (warn at 3x, critical
     * at 6x, with sane floors) so the prefill stays useful even without a
     * model narration.
     *
     * [$reason] is carried into the rationale rather than hardcoded because two
     * different causes reach here and the operator acts differently on each; see
     * {@see self::DEGRADE_BUDGET_EXHAUSTED}.
     *
     * The three classification fields are answered from the SAME evidence a
     * modelled suggestion reads, so a degraded response carries the same shape
     * rather than a hole where a classification would be. Each is derived, never
     * guessed: the service class from the shape our own digest sniffed, the SLO
     * target from a fixed table over that class, and the region basis from what
     * our own lookup actually achieved.
     */
    protected function deterministicSuggestion(
        CheckResult $probe,
        string $region,
        string $reason,
        ?ResponseDigestResult $digest,
        TargetLocationResult $location,
    ): AnalysisResult {
        $observed = $probe->responseMs ?? 500;
        $serviceClass = $this->serviceClassFor($digest?->shape);

        return new AnalysisResult(
            recommendedIntervalSeconds: 60,
            recommendedWarnThresholdMs: max(500, $observed * 3),
            recommendedCriticalThresholdMs: max(1000, $observed * 6),
            recommendedRegions: [$region],
            rationale: "Deterministic baseline from the exploratory probe ({$reason}).",
            strippedCitations: [],
            serviceClass: $serviceClass,
            regionBasis: $this->regionBasisFor($location->locationBasis),
            recommendedSloTarget: self::SLO_TARGET_BY_SERVICE_CLASS[$serviceClass],
        );
    }

    /**
     * The service class a sniffed body shape proves on its own, with no model
     * reading a single key.
     *
     * Three of {@see LaravelAiAnalysisGateway::SERVICE_CLASSES} are unreachable
     * from here and each absence is deliberate. `health_endpoint` needs the
     * body's SEMANTICS (a `status` field, a `checks` map), which only the model
     * reads, so a JSON body is `json_api` and nothing more. `tcp_service` cannot
     * arise at all, because {@see self::transientMonitor()} always probes over
     * HTTP. And an XML body answers `unknown` rather than being forced into the
     * nearest member: a sitemap or a feed is neither an API nor a page, the
     * closed set has no case for it, and `unknown` is then the true answer rather
     * than a rounding of one.
     */
    protected function serviceClassFor(?BodyShape $shape): string
    {
        return match ($shape) {
            BodyShape::Json => 'json_api',
            BodyShape::Html => 'web_page',
            default => 'unknown',
        };
    }

    /**
     * Map a lookup OUTCOME onto the model-facing reason vocabulary.
     *
     * The only place {@see LocationBasis} and
     * {@see LaravelAiAnalysisGateway::REGION_BASES} meet, which is why they are
     * allowed to be different sets: one records what a lookup achieved, the other
     * why a region was chosen. `geoip` and `cdn_edge` carry across unchanged.
     * `unresolved` becomes `default`, because as a REASON "the lookup answered
     * nothing" is "nothing located this target". `content_language` has no source
     * on this path at all: the deterministic suggestion reads no page language,
     * so it could never honestly claim that basis.
     */
    protected function regionBasisFor(LocationBasis $basis): string
    {
        return match ($basis) {
            LocationBasis::Geoip => 'geoip',
            LocationBasis::CdnEdge => 'cdn_edge',
            LocationBasis::Unresolved => 'default',
        };
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
