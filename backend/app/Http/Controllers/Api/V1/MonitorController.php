<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalyzeRunStatus;
use App\Enums\HttpAuthType;
use App\Enums\HttpMethod;
use App\Enums\MetricBand;
use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeMonitorRequest;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Http\Requests\StoreMonitorRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Http\Resources\MonitorResource;
use App\Jobs\AnalyzeMonitorJob;
use App\Jobs\PerformMonitorCheck;
use App\Models\CredentialProbeAudit;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Models\Team;
use App\Services\Ai\AiBudget;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\LaravelAiMetricDiscoveryGateway;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\CheckAggregateService;
use App\Services\Monitoring\RelayClient;
use App\Support\Logging\EvidenceLog;
use App\Support\Monitoring\AnalyzeRunStore;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\CredentialRedactor;
use App\Support\Monitoring\ProbeHeaderAllowList;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

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
     * calls `throttleApi()`, and one accepted request still runs a live relay
     * probe against an operator-supplied URL and then queues a job that spends
     * up to two provider calls. The per-team daily AI budget caps the model
     * spend over a DAY and degrades instead of refusing, so it bounds cost
     * rather than rate, and it does not bound the probe at all.
     *
     * What the accept now COSTS changed with the async split and the rate has to
     * be read against the new number rather than the old one: the request no
     * longer holds a worker for the model calls, so a fast target answers in well
     * under a second instead of occupying a worker for a minute.
     *
     * "Well under a second" is the TYPICAL accept, not the ceiling, and the
     * distinction was wrong here before review caught it: the accept still runs
     * the relay probe synchronously, and the transient it probes with carries
     * `timeout_sec => 30` ({@see self::transientMonitor()}). So a deliberately
     * slow target still parks this request for up to thirty seconds. The
     * tightening below is right either way, and for the same reason, but the
     * premise is "a fast accept is now possible" rather than "every accept is
     * 200ms".
     *
     * Re-checked against that: the ~60s wall was an ACCIDENTAL rate limiter (a
     * client waiting on its own response could only fire near one request a
     * minute), and a sub-second accept removes it, so the buckets in
     * `bootstrap/app.php` were tightened from
     * 10/20 to 6 (actor) and 12 (team) per minute. See the comment on that
     * `RateLimiter::for()` call for the full reasoning; this limiter bounds
     * SERIAL abuse (repeated live relay probes and AI-budget spends), which is a
     * different control from {@see self::IN_FLIGHT_LOCK_SECONDS}, which bounds
     * CONCURRENCY.
     *
     * Unlike {@see self::test()} this cannot be a per-resource cooldown: the
     * target of an analyze is not a monitor yet, so there is no row to claim.
     */
    public const string ANALYZE_LIMITER = 'monitor-analyze';

    /**
     * Seconds the per-team single-in-flight analyze lock survives unreleased.
     *
     * A BACKSTOP, not the mechanism: {@see AnalyzeMonitorJob} releases the lock
     * on both of its exits, and this TTL only covers the worker that dies
     * without reaching either. It sits ABOVE that job's own 160-second timeout
     * (and equals the `redis-analyze` connection's `retry_after`, pinned by
     * Tests\Unit\AnalyzeQueueConfigTest) so a run that is still legitimately
     * working can never lose its lock to expiry and let a second analyze in
     * beside it.
     *
     * The TTL lives here rather than beside {@see AnalyzeMonitorJob::lockName()}
     * because only the ACQUIRE names one: the job releases by owner and never
     * re-takes it.
     *
     * KNOWN WEAKNESS, and the docblock above used to overstate this: 200 clears
     * the job's own 160-second work, but NOT queue wait plus work. The analyze
     * supervisor runs `maxProcesses => 2`, so with two other teams' runs ahead a
     * third can wait ~160 seconds and then run 160, and the lock expires
     * mid-flight. A second accept for that team is then admitted. Nothing in the
     * Must Have breaks, because both meters are keyed per RUN and stay
     * at-most-once, but the concurrency guarantee is weaker than "a run that is
     * still legitimately working can never lose its lock", which is what this
     * comment claimed before review measured it.
     *
     * The proper fix is for the JOB to re-take the lock on entry, so the clock
     * starts when the work does rather than when the request did; it is not done
     * here because it moves the lock's ownership across the boundary and wants its
     * own test. Until then, the exposure is bounded to a backlogged queue.
     */
    public const int IN_FLIGHT_LOCK_SECONDS = 200;

    /**
     * The uptime target the deterministic path prefills for each service class.
     *
     * READ FROM {@see AnalyzeMonitorJob::deterministicSuggestion()}, which owns
     * that path now, and deliberately not copied there: this table's keys and
     * values are pinned against the gateway's two closed catalogs by
     * `AnalyzeMonitorControllerTest`, and a second copy would be a twin site
     * where a fix lands on one of two identical places.
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
     * Probe a candidate URL, then hand the model half to a worker and answer
     * 202 with the run to poll.
     *
     * Backs the "Analyze with AI" flow on the create-monitor screen. The URL is
     * not yet a monitor, so it is wrapped in a transient (unsaved)
     * {@see Monitor} the {@see RelayClient} can probe.
     *
     * SPLIT AT THE REDACTION SEAM, and the split is a security boundary rather
     * than a performance one. What stays in the REQUEST is everything that
     * cannot cross a process without losing a property, in this order:
     *
     * 1. The per-team in-flight lock, taken BEFORE the gate (see below).
     * 2. The plan gate, {@see PlanGate::assertAiAnalysisAllowed()}.
     * 3. The credential audit, {@see self::auditCredentialledProbe()}, before
     *    any dispatch of any kind: this endpoint is a credential-validity oracle
     *    by construction, so a detection control that recorded only what a
     *    worker picked up would miss every attempt that never ran (a refused
     *    relay, a probe that threw, a run whose job was never drained). It is a
     *    persisted row plus a line derived from it, not a log line alone.
     * 4. The relay probe itself, on the relay's own 30-second timeout.
     * 5. THE redaction seam, {@see CheckResult::withRedacted()}, and there is
     *    exactly one. The probe carries the operator's own credential, so a
     *    target that echoes its request headers has just put it in the body, the
     *    preview, the headers and the error message.
     * 6. The header allowlist, {@see ProbeHeaderAllowList::filter()}, which has
     *    to run ABOVE the boundary and not below it. The redactor masks the
     *    operator's SUBMITTED value; it cannot mask a `Set-Cookie` the target
     *    minted in response to it, so that one is stopped by NAME, here, before
     *    anything crosses.
     * 7. The daily AI budget spend, {@see AiBudget::tryConsume()}.
     *
     * Everything past that is {@see AnalyzeMonitorJob}: the digest, the
     * detector, the location lookup, both model calls, the deterministic
     * degrade, the confidence derivation and the plan trial meter. The job is
     * handed evidence already scrubbed and already decided, which is why its
     * constructor signature can be read as proof no credential travels.
     *
     * THE IN-FLIGHT LOCK OUTLIVES THIS REQUEST ON PURPOSE. The window it closes
     * is the 30-to-150 seconds between the plan gate above and the job's trial
     * spend, during which three concurrent analyses would all pass a three-use
     * guard and all consume one. That window is a REGRESSION the async split
     * introduces, not a retry artefact, so a lock released when the response
     * returns would be held for 200 milliseconds and close nothing. It is
     * therefore acquired here with {@see self::IN_FLIGHT_LOCK_SECONDS} and
     * released by the JOB, by owner, on both of its exits. The name comes from
     * {@see AnalyzeMonitorJob::lockName()} rather than a literal written twice,
     * so the request and the worker cannot disagree about which lock they hold.
     *
     * THE BUDGET SPEND CARRIES NO IDEMPOTENCY GUARD, and that is deliberate: a
     * `Cache::add("analyze:{$runId}:budget", ...)` SETNX here would be vacuous.
     * This request MINTS the run id and runs once, so `add` cannot lose a race
     * against a key nobody else can mint, and the scenario such a guard appears
     * to cover (a worker dying mid-run and being re-entered) cannot re-enter a
     * request-side call at all. The job is handed the ANSWER as `withinBudget`
     * rather than the ability to ask again. The plan TRIAL meter is the one with
     * a real guard, and it lives in the job for the opposite reason.
     */
    public function analyze(
        AnalyzeMonitorRequest $request,
        RelayClient $relay,
        AiBudget $budget,
        AnalyzeRunStore $runs,
    ): JsonResponse {
        $teamId = (string) $request->user()->current_team_id;

        // 1. Claim the team's single analyze slot, before the gate and before
        //    anything is spent. A team already analysing is refused rather than
        //    queued: the operator is watching a form, and a second run they did
        //    not ask for would spend a second trial against the same allowance
        //    the gate just measured.
        $lock = Cache::lock(AnalyzeMonitorJob::lockName($teamId), self::IN_FLIGHT_LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->analyzeInFlightResponse();
        }

        try {
            // 2. Open on Free for a metered number of setups, entitled outright
            //    on the AI tiers. The meter itself is spent by the worker, only
            //    once a MODEL actually delivered an analysis, so neither a
            //    failed probe nor a degrade costs the user a try.
            $team = Team::find($teamId);

            if ($team !== null) {
                (new PlanGate)->assertAiAnalysisAllowed($team);
            }

            $url = (string) $request->validated('url');
            $region = $request->probeRegion();
            $authConfig = $request->authConfig();

            // 3. The audit, ahead of the probe and therefore ahead of every
            //    later step, for the reason in this method's docblock. Named
            //    arguments because two of the four are same-typed identifier
            //    strings and a transposition would attribute the row to the
            //    wrong party while type-checking silently.
            $this->auditCredentialledProbe(
                url: $url,
                authConfig: $authConfig,
                teamId: $teamId,
                userId: (string) $request->user()->getKey(),
            );

            // 4. Probe the target through a transient monitor: the URL has no
            //    row yet, so a throwaway instance carries the probe spec the
            //    relay (and its SSRF-checked worker) executes. The SSRF host
            //    denylist already rejected an internal target in request
            //    validation.
            $probe = $relay->dispatch($this->transientMonitor($url, $region, $authConfig), $region);

            // 5. THE redaction seam, and it is now also the PROCESS boundary.
            //    Reassigning is what makes every consumer below credential
            //    unaware, in this request and in the worker alike; a second
            //    variable handed to one consumer would leave the others reading
            //    the raw object.
            $probe = $probe->withRedacted(CredentialRedactor::for($authConfig));

            // 6. Filter the headers by NAME, above the cut. The worker returns
            //    every response header verbatim, and on a credentialled probe
            //    `Set-Cookie` is an authenticated session token the redactor
            //    above never saw the value of. It stops here, at this line, and
            //    the job is handed the filtered set only.
            $headers = ProbeHeaderAllowList::filter($probe->responseHeaders);

            // 7. Spend one unit of the team's daily AI budget atomically, and
            //    hand the ANSWER down. Over budget is not a failure: the worker
            //    degrades to a deterministic suggestion (statistics as the
            //    source of truth) and still completes the run.
            $withinBudget = $budget->tryConsume($teamId);

            // 8. Mint the run and seed it with the probe block the client
            //    already renders, so a poll that arrives before the worker picks
            //    the job up still has something true to show.
            $runId = (string) Str::uuid();

            $runs->start($runId, $teamId, [
                'region' => $probe->region,
                'status_code' => $probe->statusCode,
                'response_ms' => $probe->responseMs,
            ]);

            // 9. Hand over. Named arguments because the signature is the
            //    security boundary: eleven arguments, several same-typed
            //    strings, and a transposition would type-check silently.
            AnalyzeMonitorJob::dispatch(
                runId: $runId,
                teamId: $teamId,
                locale: $request->user()->locale,
                probe: $probe,
                headers: $headers,
                url: $url,
                region: $region,
                type: MonitorType::Http,
                method: HttpMethod::Get,
                withinBudget: $withinBudget,
                lockOwner: $lock->owner(),
            );
        } catch (Throwable $e) {
            // RELEASE ON EVERY REQUEST-SIDE ABORT. The lock's own release lives
            // in the job, and the job only runs if a dispatch happened, so
            // without this a Free team hitting the plan wall at step 2 (or a
            // relay that threw at step 4) would be locked out of analyze for the
            // whole 200-second TTL while the 409 test above still passed. Both
            // aborts are real: `assertAiAnalysisAllowed()` raises
            // `PlanUpgradeRequiredException` and the relay raises a client
            // exception.
            $lock->release();

            throw $e;
        }

        // 202, and the SAME shape the poll answers with (see
        // {@see self::runPayload()}), so the client decodes one payload rather
        // than two. Read back from the store rather than re-assembled, which is
        // also what keeps the two endpoints from drifting.
        return response()->json([
            'data' => $this->runPayload($runId, $runs->find($runId) ?? []),
        ], HttpResponse::HTTP_ACCEPTED);
    }

    /**
     * One analyze run's state, for the client's poll.
     *
     * AUTHORISED ON `current_team_id`, NEVER ON POSSESSION OF THE RUN ID. The id
     * is a uuid that travels through a 202 body, a Redis key and (via the
     * broadcast) every teammate's socket, so treating it as a bearer token would
     * make one leaked log line a read of another team's analysis. A run owned by
     * another team and a run that does not exist are both masked as 404, per the
     * same convention {@see self::authorizeTeam()} applies to monitors.
     *
     * A MISSING RUN IS A REAL STATE, not a bug: {@see AnalyzeRunStore} lives in
     * a Redis instance running `volatile-lru` under a 512 MB ceiling, and the
     * entry also simply expires. The 404 is what tells the client to stop
     * polling and say "run it again"; a 200 saying `queued` for a run nothing
     * will ever advance is the eternal spinner.
     */
    public function analyzeRun(Request $request, AnalyzeRunStore $runs, string $run): JsonResponse
    {
        $stored = $runs->find($run);

        abort_if(
            $stored === null || ($stored['team_id'] ?? null) !== (string) $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );

        return response()->json(['data' => $this->runPayload($run, $stored)]);
    }

    /**
     * One run's wire shape, shared by the 202 and the poll.
     *
     * ONE SHAPE FOR BOTH, because the alternative is two decoders on the client
     * for one subject and a drift nobody notices until a live run: the 202 is
     * simply the run's first snapshot (`queued`, no steps, no result).
     *
     * `result` is the completed run's payload VERBATIM, `{data, meta}`, exactly
     * as the synchronous response body used to be: `data` prefills the create
     * form and `meta` carries `ai_analysis_trials_remaining`, the one number a
     * 202 can no longer answer because the trial is now spent by a worker long
     * after the request returned. Nested rather than flattened, so the run's own
     * status and step map can sit beside it without colliding with either half.
     *
     * @param  array<string, mixed>  $run  What the store holds, or `[]` for a run
     *                                     just created (the 202 path).
     * @return array<string, mixed>
     */
    protected function runPayload(string $runId, array $run): array
    {
        return [
            'run_id' => $runId,
            'status' => $run['status'] ?? AnalyzeRunStatus::Queued->value,
            'step' => $run['step'] ?? 0,
            'steps' => $run['steps'] ?? [],
            'probe' => $run['probe'] ?? null,
            'reason' => $run['reason'] ?? null,
            'result' => $run['result'] ?? null,
        ];
    }

    /**
     * Refuse a second concurrent analyze for one team.
     *
     * 409 and not 429: nothing about this is a rate, and the client renders the
     * two differently (a limiter says wait, this says your other analysis is
     * still running). The `message` is what the create form surfaces verbatim,
     * so it names the state rather than the mechanism.
     */
    protected function analyzeInFlightResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'An analysis is already running for this team. Wait for it to finish before starting another.',
        ], HttpResponse::HTTP_CONFLICT);
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
     * leaves nothing else behind. It is a DETECTION control, not a prevention
     * one; the named limiter bounds throughput, not capability.
     *
     * A ROW AND THEN A LINE, in that order, and the order is the whole design.
     * {@see CredentialProbeAudit} is the system of record: it can be queried,
     * it survives a server move, and nothing rotates it away. The line on
     * {@see EvidenceLog::CHANNEL} is DERIVED from the row that was just
     * persisted ({@see CredentialProbeAudit::evidenceContext()}), never rebuilt
     * from the local variables above, so the file and the table cannot come to
     * describe different attempts. A failed insert is deliberately left to
     * propagate: a swallowed write here is the control silently switching
     * itself off, which is the failure this whole method exists to remove.
     *
     * Five facts and no sixth. The team is who to ask, the USER is who to ask
     * first, the HOST is where it went, and the TYPE is what shape of secret
     * left the building. Never a value, and never the raw URL: a monitor target
     * is frequently `…/health?token=…`, so the query string is dropped for the
     * same reason `AnalysisPayload::displayUrl()` drops it before showing the
     * URL to a model. The host alone is narrower than that rendering rather
     * than a second copy of it, which is why this is not the third caller that
     * would trigger extracting it.
     *
     * Silent for an absent credential and for `type: none`, which is the same
     * boundary {@see CredentialRedactor::for()} draws: nothing was sent, so
     * there is nothing to audit and no noise on the ordinary path.
     *
     * @param  array<string, mixed>|null  $authConfig  Validated credential map, or null.
     * @param  string  $teamId  The acting team, and the row's owner.
     * @param  string  $userId  The acting operator, kept for a follow-up question.
     */
    protected function auditCredentialledProbe(
        string $url,
        ?array $authConfig,
        string $teamId,
        string $userId,
    ): void {
        $submittedType = $authConfig['type'] ?? null;
        $type = is_string($submittedType) ? HttpAuthType::tryFrom($submittedType) : null;

        if ($type === null || $type === HttpAuthType::None) {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST);

        // 1. The record itself, first, because everything below it is a copy.
        $audit = CredentialProbeAudit::query()->create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'host' => is_string($host) && $host !== '' ? $host : null,
            'auth_type' => $type,
        ]);

        // 2. Then the human-readable copy, off the STORED row. `refresh()` is
        //    not ceremony: it reads back what the database actually holds, so
        //    the line cannot report a value a column truncated or a default
        //    overrode, and a row that vanished between the two statements
        //    raises here instead of being narrated as a success.
        EvidenceLog::record(
            'Monitor analysis probed a target with an operator-supplied credential.',
            $audit->refresh()->evidenceContext(),
        );
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
