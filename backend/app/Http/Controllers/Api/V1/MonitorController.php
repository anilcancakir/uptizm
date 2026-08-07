<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BodyShape;
use App\Enums\HttpAuthType;
use App\Enums\HttpMethod;
use App\Enums\MetricBand;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\RegionBasis;
use App\Exceptions\AiBudgetExhaustedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeMonitorRequest;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Http\Resources\MonitorResource;
use App\Jobs\PerformMonitorCheck;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Models\Team;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiDeadline;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\AnalysisPayload;
use App\Services\Ai\AnalysisResult;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\LaravelAiMetricDiscoveryGateway;
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
use App\Support\Monitoring\CredentialRedactor;
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
use Illuminate\Support\Facades\DB;
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
     * Create a monitor for the current team, with the metrics submitted
     * alongside it, and kick off a first check.
     *
     * The monitor and its metrics are one write or none. The AI create flow
     * submits a monitor and the metric rows the operator accepted in the same
     * request, and a monitor that exists while the metrics it was created for do
     * not is a monitor silently measuring nothing: the operator saw the pills,
     * the create answered 201, and the detail screen shows no metrics.
     *
     * The dispatch is OUTSIDE that transaction, and the ordering is the whole
     * reason the transaction needs a comment. `config/queue.php` sets
     * `after_commit => false` on every connection, so a dispatch from inside
     * pushes the payload to Redis before the row is committed; the worker
     * re-resolves the monitor by key, misses it, and deletes the job. The first
     * check then never runs, in production only, with nothing failing. It is
     * fixed here rather than with `->afterCommit()` on the job because
     * {@see self::test()} and {@see self::resume()} dispatch the same job with
     * no transaction around it, and a modifier one of three callers needs is a
     * worse rule than an ordering all three already satisfy.
     */
    public function store(StoreMonitorRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $metrics = $this->pullMetricRows($attributes);

        // 1. Persist the monitor scoped to the acting team, primed for the
        //    scheduler to pick up on its next tick, and its metrics with it.
        $monitor = DB::transaction(function () use ($attributes, $metrics, $request): Monitor {
            $monitor = Monitor::create([
                ...$attributes,
                'team_id' => $request->user()->current_team_id,
                'status' => 'active',
                'next_check_at' => now(),
            ]);

            $this->createMetrics($monitor, $metrics);

            return $monitor;
        });

        // 2. Fan out an immediate first check per region so the detail page
        //    lands on real data instead of empty placeholders. After the commit,
        //    never inside it.
        $this->dispatchChecks($monitor);

        return MonitorResource::make($monitor)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Take the bulk `metrics[]` rows out of the validated attributes.
     *
     * By reference and removed rather than read and left in place: `metrics` is
     * not a monitor column, and {@see Monitor} guards nothing, so leaving it in
     * the create array sets an attribute the INSERT then names as a column that
     * does not exist.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    protected function pullMetricRows(array &$attributes): array
    {
        $rows = $attributes['metrics'] ?? null;
        unset($attributes['metrics']);

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Write the submitted metric rows for a freshly created monitor.
     *
     * Two columns are stamped here rather than accepted from the client:
     *
     * - `team_id`, from the monitor, because the column is a denormalized tenant
     *   link that team-scoped metric queries read directly.
     * - `display_order`, from the ARRAY INDEX. The submitted order is the order
     *   the operator saw the suggestions in, and it is already expressed by the
     *   array itself; honouring a per-row `display_order` as well would let two
     *   rows claim one position, which {@see MonitorMetricController::reorder()}
     *   then has no way to resolve.
     *
     * `unmatched_band` is pinned to `ok` for a row that arrived with at least
     * one non-empty band list and did not choose a band itself. The discovery
     * schema deliberately offers the model no field to say otherwise
     * ({@see LaravelAiMetricDiscoveryGateway::schema()}), because
     * {@see MetricBand} has no neutral case and a model pinning `critical` would
     * page on every unrecognized reading. The pin is CONDITIONAL for a reason
     * that is easy to miss: `validateUnmatchedBandHasAList` refuses a band with
     * all three lists empty, which is every AI-proposed numeric metric, so an
     * unconditional pin would 422 the common case.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function createMetrics(Monitor $monitor, array $rows): void
    {
        foreach ($rows as $index => $row) {
            MonitorMetric::query()->create([
                ...$row,
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'display_order' => $index,
                'unmatched_band' => $this->unmatchedBandFor($row),
            ]);
        }
    }

    /**
     * The `unmatched_band` a submitted metric row is written with.
     *
     * @param  array<string, mixed>  $row
     */
    protected function unmatchedBandFor(array $row): ?string
    {
        if (array_key_exists('unmatched_band', $row)) {
            $chosen = $row['unmatched_band'];

            return is_string($chosen) ? $chosen : null;
        }

        foreach (StoreMonitorMetricRequest::VALUE_LIST_FIELDS as $field) {
            $list = $row[$field] ?? null;

            if (is_array($list) && $list !== []) {
                return MetricBand::Ok->value;
            }
        }

        return null;
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
     * the set it already carries, and the digest is rendered from the body already
     * in memory. No second probe, ever.
     *
     * That single-source property is also what makes an authenticated analyze
     * safe to serve. The request may carry an `auth_config`, so the probe sends
     * the operator's own credential and a target that echoes its request headers
     * sends it straight back. One {@see CredentialRedactor} pass over the one
     * `CheckResult`, immediately after the dispatch, therefore covers both
     * prompts, the digest, the metric candidates and the JSON response at once,
     * and every consumer below stays credential-unaware.
     *
     * What the request DOES spend beyond the probe, stated in full because a
     * short version of this list was wrong: DNS is resolved TWICE, once in
     * validation ({@see AnalyzeMonitorRequest::noInternalHost()} ->
     * {@see HostGuard::isBlockedHost()}) and once here
     * ({@see self::targetIps()} -> {@see HostGuard::resolvePublicHostIps()}),
     * each of which reads A and AAAA. Nothing memoizes between them: the two
     * answer different questions (a bool for the guard, a fail-closed address
     * list for the evidence) and the request holds a different guard instance
     * than this method does. On top of that, when the target is not behind a
     * CDN, one geo lookup that stays dormant unless a token is configured.
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
        AiDeadline $deadline,
    ): JsonResponse {
        // The budget is anchored HERE rather than at the first model call, and
        // that is what makes it safe to raise. Everything under this line shares
        // one Octane wall, and the PROBE sits under it too with a 30 second
        // timeout of its own (see `transientMonitor()`), so a budget that starts
        // counting only once the first prompt goes out bounds the AI work
        // without bounding the request: 30 + 75 is past the 90 second wall that
        // produced the original 500. Started from the action instead, a slow
        // probe simply leaves the model less, which is the correct trade and
        // needs no second number to keep in sync.
        $deadline->restart();

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
        $authConfig = $request->authConfig();

        // 1. Probe the target through a transient monitor: the URL has no row
        //    yet, so a throwaway instance carries the probe spec the relay
        //    (and its SSRF-checked worker) executes. The SSRF host denylist
        //    already rejected an internal target in request validation.
        //
        //    The audit line goes out BEFORE the dispatch, so an attempt is
        //    recorded even when the relay throws: this endpoint is a credential
        //    validity oracle by construction (200-versus-401 is readable in the
        //    response and no row is left behind), and a detection control that
        //    only fires on success detects the wrong half.
        $this->logCredentialledProbe($url, $authConfig, (string) $request->user()->current_team_id);

        $transient = $this->transientMonitor($url, $region, $authConfig);
        $probe = $relay->dispatch($transient, $region);

        // 1b. THE redaction seam, and there is exactly one. The probe now
        //     carries the operator's own credential, so a target that echoes
        //     its request headers (a debug page, a request-echo endpoint, a
        //     verbose error) has just put that credential in the body, the
        //     preview, the headers and the error message. Everything below
        //     derives from this one object: step 3's digest, both prompts, the
        //     metric candidates, the JSON `probe` block and the deterministic
        //     path. Reassigning here is what makes every one of those consumers
        //     credential-unaware; a second variable handed to the two prompt
        //     builders would leave the other consumers reading the raw object.
        $probe = $probe->withRedacted(CredentialRedactor::for($authConfig));

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

        // Named arguments below, and not for decoration: the first three
        // parameters of both calls are same-typed strings, so a transposition
        // type-checks silently and produces a prompt or a rationale that is
        // merely wrong.
        $modelled = $withinBudget
            ? $this->suggestViaGateway(
                gateway: $gateway,
                payload: $this->analysisPayload(
                    url: $url,
                    region: $region,
                    teamId: $teamId,
                    probe: $probe,
                    candidate: $candidate,
                    headers: $headers,
                    digest: $digest,
                    location: $location,
                ),
            )
            : null;

        // 5. Either degrade path answers with the same deterministic suggestion,
        //    naming its own cause: within budget a null means the model or the
        //    provider failed, outside it the budget did. It carries the same
        //    fields a modelled answer does, read off the same evidence, so the
        //    client decodes one shape on every path.
        $result = $modelled ?? $this->deterministicSuggestion(
            probe: $probe,
            region: $region,
            reason: $withinBudget ? self::DEGRADE_AI_UNAVAILABLE : self::DEGRADE_BUDGET_EXHAUSTED,
            digest: $digest,
        );

        // 5b. Attach the confidence the evidence actually supports, overwriting
        //     whatever either construction path above left in place. Deriving
        //     it here rather than trusting `$result->confidence` is the whole
        //     point: neither the gateway's schema nor a fake model answer gets
        //     a vote, only what this controller can observe about the run.
        $result = $result->withConfidence($this->confidenceFor($modelled !== null, $result->regionBasis, $digest));

        // 6. Mine the SAME probe body and the SAME filtered headers for metrics
        //    worth proposing. Both are already in memory here, so this costs no
        //    second probe, and passing them together is what keeps a proposed
        //    header metric an observation rather than a guess. `$headers` and
        //    never `$probe->responseHeaders`: the allowlist at step 3 is the
        //    only thing standing between a credentialled probe's `Set-Cookie`
        //    and a metric that would persist it on every check.
        //
        //    Discovery spends its own budget unit and degrades to an empty
        //    array on its own, so a create flow never fails because of a
        //    suggestion.
        $suggestedMetrics = $discovery->discover($transient, $probe->content, $teamId, $headers);

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
     * How much evidence the suggestion actually rests on, matching the Dart
     * `AiConfidence` enum's case names exactly (`high`, `medium`, `low`) so
     * `aiConfidenceFromWire()` decodes it with no mapping table.
     *
     * Three branches over evidence already in scope on both construction
     * paths, never over anything a model reported about itself:
     *
     * - `low`: [$modelled] is false, meaning {@see self::deterministicSuggestion()}
     *   answered instead of a model, whichever of the two named causes forced
     *   that (budget exhausted, or the provider/output degrade).
     * - `medium`: a model answered, but [$regionBasis] is an INFERRED value
     *   ({@see RegionBasis::ContentLanguage} or {@see RegionBasis::Default}):
     *   nothing measured located the target, so the model's regions are a
     *   guess dressed as a suggestion.
     * - `high`: a model answered, [$regionBasis] is a MEASURED value
     *   ({@see RegionBasis::Geoip} or {@see RegionBasis::CdnEdge}), and
     *   [$digest] is not null, i.e. the probe actually returned a body to
     *   describe. A measured basis with no body evidence stays `medium`
     *   rather than borrowing the higher grade from a fact the model never
     *   read.
     */
    protected function confidenceFor(bool $modelled, string $regionBasis, ?ResponseDigestResult $digest): string
    {
        if (! $modelled) {
            return 'low';
        }

        $measuredBasis = in_array($regionBasis, [RegionBasis::Geoip->value, RegionBasis::CdnEdge->value], true);

        return $measuredBasis && $digest !== null ? 'high' : 'medium';
    }

    /**
     * Wrap a candidate URL in a transient, unsaved monitor the relay can probe.
     *
     * The instance is never persisted: it only carries the fields
     * {@see RelayClient} reads to build the worker probe spec, defaulted to a
     * plain HTTP GET expecting a 200.
     *
     * [$authConfig] needs nothing else to reach the target:
     * {@see RelayClient::buildSpec()} already puts `$monitor->auth_config` on
     * the signed spec and the worker already applies all four auth types.
     * `Monitor` is `$guarded = []`, so mass assignment lands it here.
     *
     * One mechanism worth naming, because it looks like a bug from the outside:
     * the `encrypted:array` cast encrypts inside `setAttribute`, so the RAW
     * attribute holds ciphertext on this unsaved instance too, while
     * `$monitor->auth_config` decrypts it back on read. The cast round-trips in
     * memory and the spec receives the plain array either way; an assertion
     * against `getAttributes()` is reading the ciphertext and proves nothing.
     *
     * @param  array<string, mixed>|null  $authConfig  Validated credential map, or null.
     */
    protected function transientMonitor(string $url, string $region, ?array $authConfig): Monitor
    {
        return new Monitor([
            'type' => MonitorType::Http,
            'method' => HttpMethod::Get,
            'url' => $url,
            'timeout_sec' => 30,
            'expected_status_code' => 200,
            'regions' => [$region],
            'auth_config' => $authConfig,
        ]);
    }

    /**
     * Record that an analyze sent an operator-supplied credential to a target.
     *
     * The audit trail for the validity-oracle risk this endpoint accepts: a
     * tenant can make the relay send an arbitrary `Authorization` header to any
     * public host and read the answer, and unlike `POST /monitors` the request
     * leaves no row behind. This line is the only record that it happened. It
     * is a DETECTION control, not a prevention one; the named limiter bounds
     * throughput, not capability.
     *
     * Three fields and no fourth. The team is who to ask, the HOST is where it
     * went, and the TYPE is what shape of secret left the building. Never a
     * value, and never the raw URL: a monitor target is frequently
     * `…/health?token=…`, so the query string is dropped for the same reason
     * `AnalysisPayload::displayUrl()` drops it before showing the URL to a
     * model. The host alone is narrower than that rendering rather than a
     * second copy of it, which is why this is not the third caller that would
     * trigger extracting it.
     *
     * Silent for an absent credential and for `type: none`, which is the same
     * boundary {@see CredentialRedactor::for()} draws: nothing was sent, so
     * there is nothing to audit and no noise on the ordinary path.
     *
     * @param  array<string, mixed>|null  $authConfig
     */
    protected function logCredentialledProbe(string $url, ?array $authConfig, string $teamId): void
    {
        $submittedType = $authConfig['type'] ?? null;
        $type = is_string($submittedType) ? HttpAuthType::tryFrom($submittedType) : null;

        if ($type === null || $type === HttpAuthType::None) {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST);

        Log::info('Monitor analysis probed a target with an operator-supplied credential.', [
            'team_id' => $teamId,
            'host' => is_string($host) && $host !== '' ? $host : 'n/a',
            'auth_type' => $type->value,
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
        } catch (AiBudgetExhaustedException) {
            // FIRST, because it extends RuntimeException. See the same branch in
            // {@see MetricDiscoveryService::select()}: nothing was sent, so this
            // is a signal about the request budget or a slow provider rather
            // than about the model's output.
            Log::warning("Monitor analysis degraded: the request's AI budget was already spent.", [
                'url' => $payload->displayUrl(),
                'region' => $payload->region,
            ]);
        } catch (RuntimeException) {
            Log::warning('Monitor analysis degraded: the model output could not be trusted.', [
                'url' => $payload->displayUrl(),
                'region' => $payload->region,
            ]);
        } catch (ConnectionException|RequestException) {
            Log::warning('Monitor analysis degraded: the AI service was unreachable.', [
                'url' => $payload->displayUrl(),
                'region' => $payload->region,
            ]);
        } catch (AiException) {
            Log::warning('Monitor analysis degraded: the AI provider could not complete the request.', [
                'url' => $payload->displayUrl(),
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
     * rather than a hole where a classification would be. Two are derived: the
     * service class from the shape our own digest sniffed, and the SLO target
     * from a fixed table over that class.
     *
     * The third is not derived, and that is the point. `region_basis` answers
     * why THIS region was suggested, and on this path the answer is always that
     * the request asked to probe from it, so it is always `default`. What the
     * location lookup achieved is stated separately as a fact and is not a
     * reason; borrowing it here would justify a suggestion with evidence that
     * played no part in making it.
     */
    protected function deterministicSuggestion(
        CheckResult $probe,
        string $region,
        string $reason,
        ?ResponseDigestResult $digest,
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
            // ALWAYS `default`, whatever the lookup achieved, because this path
            // does not use the lookup to choose a region: `recommendedRegions`
            // above is the region the request asked to probe from. Reporting
            // `geoip` here because a geo provider happened to answer would
            // justify the suggestion by evidence that played no part in it,
            // which is the same fabrication this plan removed from the
            // dashboard's KPIs. Only the MODEL, which reads the location facts
            // and can weigh them, may claim a basis other than this one.
            regionBasis: 'default',
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
