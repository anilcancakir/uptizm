<?php

namespace App\Jobs;

use App\Enums\AnalyzeRunStatus;
use App\Enums\BodyShape;
use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\RegionBasis;
use App\Events\AnalyzeProgressBroadcast;
use App\Exceptions\AiBudgetExhaustedException;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Ai\AiDeadline;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\AnalysisPayload;
use App\Services\Ai\AnalysisResult;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Ai\ResponseTimeAnomalyDetector;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\ResponseDigest;
use App\Services\Monitoring\ResponseDigestResult;
use App\Services\Monitoring\TargetLocation;
use App\Services\Monitoring\TargetLocationResult;
use App\Support\Monitoring\AnalyzeRunStore;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\CredentialRedactor;
use App\Support\Monitoring\HostGuard;
use App\Support\Monitoring\ProbeHeaderAllowList;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;
use Throwable;

/**
 * Runs the model half of one `POST /api/v1/monitors/analyze` outside the
 * request, reporting each step to {@see AnalyzeRunStore} and to the team's
 * private channel while it goes.
 *
 * The request keeps everything that must not cross a process: the plan gate,
 * the credential audit line, the relay probe, the ONE redaction seam
 * ({@see CheckResult::withRedacted()}), the header allowlist
 * ({@see ProbeHeaderAllowList}), the daily AI budget spend, and the acquire of
 * the per-team in-flight lock this job releases. What arrives here is evidence
 * already scrubbed and already decided.
 *
 * THE CONSTRUCTOR SIGNATURE IS THE SECURITY BOUNDARY, and the wrong version of
 * it inspects as safe. Three properties hold it up, each of which fails
 * silently when someone "tidies" it:
 *
 * 1. NO `Monitor` IS SERIALISED. The transient monitor the relay probed carries
 *    the operator's `auth_config`, whose `encrypted:array` cast means the RAW
 *    attribute holds ciphertext. Put that instance in the payload and a reviewer
 *    reads opaque bytes as proof the credential is protected, while it stays
 *    decryptable with the `APP_KEY` sitting in `.env` on the same box. The
 *    transient is rebuilt here instead ({@see self::transientMonitor()}) with
 *    `auth_config` explicitly null, so credential-absence is readable in a
 *    signature rather than asserted in a comment.
 * 2. `SerializesModels` IS DELIBERATELY ABSENT, which is why this class composes
 *    `Dispatchable` + `Bus\Queueable` + `InteractsWithQueue` by hand instead of
 *    `Foundation\Queue\Queueable`, which bundles it. That trait maps any
 *    Eloquent property to a `ModelIdentifier` BEFORE the payload is written, so
 *    a mutation that passes the transient monitor produces a payload with no
 *    model in it and the credential-absence test above stays green while the
 *    property it guards is broken. Measured, not assumed:
 *    `evidence/step-05-no-credential-in-payload.md`. Note also that the token
 *    LITERAL never appears either way, for the ciphertext reason in (1), so the
 *    assertion that discriminates is the one on the string `auth_config`.
 * 3. `ShouldBeEncrypted` is NOT about the credential, which never reaches here.
 *    It is about {@see $probe}'s `content`: the response body has to travel for
 *    metric discovery, and with `$tries = 1` every failure writes the whole
 *    payload into `failed_jobs.payload` in PostgreSQL and into Horizon's
 *    retained-failure view, which is rendered in a browser.
 *
 * TERMINAL-ONLY PROGRESS TICKS, AND THIS IS A CONTRACT THE CLIENT READS.
 * A step is reported exactly once, with a terminal state
 * (`done` / `skipped` / `failed`); `running` is never written by this job at
 * all, even though both the store and the event can represent it. That makes an
 * eternal spinner structurally impossible instead of defended against: the
 * client renders the row AFTER the last terminal tick as the one in flight, so
 * there is no state a killed worker can leave behind that reads as "still
 * working" for a step that will never report. The five ordinals in
 * {@see self::STEP_PROBE} to {@see self::STEP_DISCOVERY} are the same contract
 * in the other direction: they must agree with `kAnalyzeSteps` in the Flutter
 * client (`lib/resources/views/monitors/monitor_form_support.dart`), which
 * renders one row per ordinal and has no way to discover the count.
 *
 * BOTH METERS ARE AT-MOST-ONCE, by different mechanisms, and only one of them
 * lives here. The daily AI budget is spent in the request, which mints the run
 * id and runs once, so it is single by construction and this job is handed the
 * ANSWER as {@see $withinBudget} rather than the ability to re-spend it. The
 * plan trial meter IS spent here, guarded by a SETNX
 * ({@see self::TRIAL_GUARD_KEY_TEMPLATE}), because a job can be entered more
 * than once in the one scenario `$tries = 1` does not cover: a worker dying
 * between the meter write and the run-state write.
 */
class AnalyzeMonitorJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * The dedicated queue connection, whose `retry_after` (200) is the only one
     * in this app that clears this job's own timeout.
     *
     * BOTH `onConnection()` AND `onQueue()` are required and neither is
     * redundant. `retry_after` belongs to the connection a CONSUMER names, not
     * to the Redis list, so a job that landed here without this connection
     * would be released to a SECOND worker at the shared 90 seconds: two meter
     * spends, two broadcast streams and two writers on one run, with nothing
     * thrown and a result on screen. The whole chain is pinned by
     * Tests\Unit\AnalyzeQueueConfigTest (retry_after 200 > worker timeout 170 >
     * {@see $timeout} 160 > `ai.request_budget_seconds` 150), and a constructor
     * `onConnection()` leaves no reflectable default property, so only this
     * class's own test can prove the job actually rides it.
     */
    protected const string CONNECTION = 'redis-analyze';

    /**
     * The queue its own Horizon supervisor drains, at `maxProcesses` 2 and a
     * 512 MB ceiling, isolated from the customer uptime checks.
     */
    protected const string QUEUE = 'analyze';

    /**
     * The probe. Already done: it ran inside the accepting request, on the
     * relay's own 30-second timeout, and its result arrived here as
     * {@see $probe}. Ticked `done` on entry so the client's first row resolves
     * immediately rather than spinning on work that is already finished.
     */
    public const int STEP_PROBE = 1;

    /**
     * The body digest, which is what "detecting the monitor type" actually is:
     * {@see ResponseDigest} sniffs the SHAPE, and {@see self::serviceClassFor()}
     * reads a service class off it with no model involved.
     */
    public const int STEP_DIGEST = 2;

    /**
     * The response-time detector over the single-probe window. One sample never
     * clears the cold-start gate, so the candidate is null; the step exists
     * because the payload shape has to match the sweep pipeline's.
     */
    public const int STEP_DETECTOR = 3;

    /**
     * The target location lookup and the model call that reads it. The only
     * step that spends provider wall time, hence the only one gated on
     * {@see $withinBudget}.
     */
    public const int STEP_SUGGESTION = 4;

    /**
     * Metric discovery over the same body and the same filtered headers.
     */
    public const int STEP_DISCOVERY = 5;

    /**
     * Every ordinal this job reports, in order, for a client or a test that
     * wants to check the contract rather than trust five literals.
     *
     * @var list<int>
     */
    public const array STEPS = [
        self::STEP_PROBE,
        self::STEP_DIGEST,
        self::STEP_DETECTOR,
        self::STEP_SUGGESTION,
        self::STEP_DISCOVERY,
    ];

    /**
     * The sequence a failure tick carries.
     *
     * Deliberately far above any tick a run can reach, because {@see failed()}
     * runs on an instance Laravel REBUILT from the payload, whose
     * {@see $sequence} counter is therefore back at zero. A failure numbered 1
     * arrives behind ticks the client has already rendered and a client
     * ordering by sequence (which it must: production Horizon runs ten
     * processes on the broadcast queue and Laravel guarantees ordering only for
     * SQS FIFO) would drop the only tick that ends the run.
     */
    protected const int FAILURE_SEQUENCE = 999;

    /**
     * The SETNX key that makes the trial spend at-most-once per run.
     *
     * A separate key from the run's own state entry, and the prefix difference
     * is intentional (see {@see AnalyzeRunStore}'s `KEY_PREFIX` docblock): the
     * two are written by different callers for different reasons.
     */
    protected const string TRIAL_GUARD_KEY_TEMPLATE = 'analyze:%s:trial';

    /**
     * Seconds the trial guard survives, matching the run store's own TTL: the
     * guard is worthless once the run it protects can no longer be read, and
     * outliving it would refuse a legitimate re-run of a NEW run id, which it
     * cannot see anyway.
     */
    protected const int TRIAL_GUARD_TTL_SECONDS = 900;

    /**
     * Prefix of the per-team single-in-flight lock, whose full name
     * {@see self::lockName()} derives.
     */
    protected const string LOCK_PREFIX = 'analyze-in-flight:';

    /**
     * Why a run ended without a result, in a CLOSED vocabulary of two.
     *
     * Never an exception message: the failing call sites here read text the
     * monitored target authored (an error body, a gateway message quoting the
     * model quoting the page), and this string is written into a cache entry a
     * client renders. `errored` is something raised; `stopped` is a run failed
     * with no exception at all, which is what a manual `$job->fail()` looks
     * like.
     */
    protected const string REASON_ERRORED = 'errored';

    protected const string REASON_STOPPED = 'stopped';

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
     * One attempt, so {@see failed()} fires on the FIRST failure.
     *
     * Laravel only calls that hook on the attempt that exhausts the tries, so a
     * retry would leave the first hard kill (the worker's timeout, SIGALRM mid
     * model call) with nothing to write the terminal state, and the operator
     * watching a form that never resolves. The rest of the trade is the same
     * one {@see RenderStatusPagePreview} made and then some: an operator is
     * watching, a silent retry would double both the wait and the trial meter,
     * and the realistic failure set already degrades in band
     * ({@see self::suggestViaGateway()} catches five exception families and
     * discovery degrades to `[]`), so what is left for a retry to absorb is the
     * failure an operator is better placed to answer by pressing the button
     * again.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Whole-job budget in seconds.
     *
     * Third link of the four-number chain pinned by
     * Tests\Unit\AnalyzeQueueConfigTest: it sits above
     * `ai.request_budget_seconds` (150), so the model calls run out of funded
     * time before the job runs out of its own, and under the analyze
     * supervisor's 170, so a slow run surfaces as this job failing (and writing
     * its terminal state) rather than as a worker kill. Changing it here
     * without re-deriving the chain fails that test.
     *
     * @var int
     */
    public $timeout = 160;

    /**
     * The next tick's sequence number, monotonic within one run.
     *
     * Not readonly, and not derivable from the step ordinal: a run emits one
     * tick per step today, but the sequence's job is to be strictly increasing
     * and to keep consecutive payloads distinct, which the ordinal stops doing
     * the moment two ticks ever report on one step. See
     * {@see AnalyzeProgressBroadcast::broadcastWith()} for both measurements
     * behind it.
     */
    protected int $sequence = 0;

    /**
     * @param  string  $runId  The run this job reports on, minted by the accepting request.
     * @param  string  $teamId  The owning team. Addresses the broadcast channel, resolves the trial meter, and derives the in-flight lock name.
     * @param  string|null  $locale  The operator's OWN stored locale, which decides the language of the metric labels discovery proposes. Not the request's `Accept-Language`: the header is client state and this is a preference they set, and these labels are persisted the moment the suggestion is accepted.
     * @param  CheckResult  $probe  The probe result, ALREADY through the one redaction seam in the request. Its `content` is why this job is `ShouldBeEncrypted`.
     * @param  array<string, string>  $headers  Response headers ALREADY through {@see ProbeHeaderAllowList}. Filtering by NAME has to happen in the request, above this boundary: {@see CredentialRedactor} masks the operator's submitted value, not a `Set-Cookie` the target minted in response to it.
     * @param  string  $url  The validated target. Past the SSRF host denylist in request validation.
     * @param  string  $region  The relay region the probe ran from.
     * @param  MonitorType  $type  Carried rather than assumed so the rebuilt transient describes the same spec that was probed.
     * @param  HttpMethod  $method  Same reason as [$type].
     * @param  bool  $withinBudget  Whether the request's `AiBudget::tryConsume()` succeeded. The ANSWER, not the ability to ask again: the spend stays in the request, which mints the run id and runs once, so it is single by construction there and would be re-spendable here.
     * @param  string  $lockOwner  Owner string of the per-team in-flight lock the request acquired. Not a credential: an opaque token whose only power is releasing a lock the operator's own request took, which is exactly what this job must do on both of its exits.
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $teamId,
        public readonly ?string $locale,
        public readonly CheckResult $probe,
        public readonly array $headers,
        public readonly string $url,
        public readonly string $region,
        public readonly MonitorType $type,
        public readonly HttpMethod $method,
        public readonly bool $withinBudget,
        public readonly string $lockOwner,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    /**
     * The per-team single-in-flight lock's name.
     *
     * DERIVED here rather than passed in, and called by the request too
     * (`MonitorController::analyze()` acquires; this job releases), so the two
     * sides cannot disagree about which lock they hold. A request that wrote
     * the string itself and a job that derived it would drift into a lock
     * nothing ever releases, which locks the operator out for the whole 200
     * second TTL and looks like a rate limiter.
     */
    public static function lockName(string $teamId): string
    {
        return self::LOCK_PREFIX.$teamId;
    }

    /**
     * Run the model half of the analyze, reporting every step.
     *
     * Dependencies arrive by method injection, the way the controller took
     * them: they are all stateless services and several are doubled in tests.
     *
     * @param  AnalyzeRunStore  $runs  The run's state, read by the client's poll.
     * @param  AiDeadline  $deadline  The shared provider wall-time budget.
     * @param  ResponseTimeAnomalyDetector  $detector  Statistical read over the probe window.
     * @param  ResponseDigest  $digester  Body shape and structure sniffer.
     * @param  TargetLocation  $targetLocation  Where the target actually is, or an honest null.
     * @param  HostGuard  $hostGuard  The only DNS in this backend, fail-closed.
     * @param  AnalysisGateway  $gateway  The model boundary; faked in CI.
     * @param  MetricDiscoveryService  $discovery  The second prompt, with its own budget and its own degrade.
     * @param  PlanGate  $gate  The plan trial meter.
     *
     * @throws Throwable Whatever the pipeline raised, after the terminal state
     *                   has been written, so the queue still records a failed
     *                   job.
     */
    public function handle(
        AnalyzeRunStore $runs,
        AiDeadline $deadline,
        ResponseTimeAnomalyDetector $detector,
        ResponseDigest $digester,
        TargetLocation $targetLocation,
        HostGuard $hostGuard,
        AnalysisGateway $gateway,
        MetricDiscoveryService $discovery,
        PlanGate $gate,
    ): void {
        // Anchor the provider budget at the top of the unit of work. BELT AND
        // BRACES, and the tempting justification for it is false: the queue
        // worker already calls `$app->forgetScopedInstances()` between jobs
        // (Illuminate\Queue\QueueServiceProvider:263), so a scoped AiDeadline
        // is fresh per job and nothing leaks one analyze's spent time into the
        // next. It stays because it costs nothing, states the anchor
        // explicitly, and survives a future change to that reset. See
        // {@see AiDeadline::restart()}, whose docblock carries the vendor line.
        $deadline->restart();

        try {
            // 1. The probe already ran, in the request. Reporting it done on
            //    entry is not decoration: the client cannot distinguish "queued
            //    behind two other teams" from "probing" on its own, and this
            //    tick is what turns the first row over the moment a worker
            //    picks the run up.
            $this->tick($runs, self::STEP_PROBE, ran: true);

            // 2. A digest only where there is a body to describe. Null content
            //    is a TCP probe, a content type the edge filtered out, or an
            //    older worker; either way the step genuinely did not run, and
            //    `skipped` is what stops the row spinning on work nothing was
            //    going to do.
            $digest = $this->probe->content !== null ? $digester->digest($this->probe->content) : null;
            $this->tick($runs, self::STEP_DIGEST, ran: $digest !== null);

            // 3. The detector over the single-probe window. One sample never
            //    clears the cold-start gate, so the candidate is null here;
            //    wiring it keeps the analysis payload consistent with the sweep
            //    pipeline once prior history exists. A probe that recorded no
            //    response time gives it nothing to read at all.
            $candidate = $detector->detect(
                $this->probe->responseMs !== null ? [$this->probe->responseMs] : [],
                [
                    'region' => $this->region,
                    'monitor_id' => '',
                ],
            );
            $this->tick($runs, self::STEP_DETECTOR, ran: $this->probe->responseMs !== null);

            // 4. Locate the target and ask a model. Both halves are gated on
            //    the budget answer the request handed down, because the
            //    location facts exist only to be READ by the model: the
            //    deterministic path below always reports `default` as its
            //    region basis whatever a lookup achieved, so resolving one on
            //    that path would spend a DNS lookup and possibly a geo call for
            //    evidence nothing consults, and would report a step as having
            //    run when the only thing it exists to feed did not.
            $modelled = null;
            if ($this->withinBudget) {
                $location = $targetLocation->resolve($this->url, $this->headers, $this->targetIps($hostGuard));

                // Named arguments below, and not for decoration: the first
                // three parameters are same-typed strings, so a transposition
                // type-checks silently and produces a prompt that is merely
                // wrong.
                $modelled = $this->suggestViaGateway(
                    gateway: $gateway,
                    payload: $this->analysisPayload(
                        probe: $this->probe,
                        candidate: $candidate,
                        digest: $digest,
                        location: $location,
                    ),
                    deadline: $deadline,
                );
            }
            $this->tick($runs, self::STEP_SUGGESTION, ran: $this->withinBudget);

            // 5. Either degrade path answers with the same deterministic
            //    suggestion, naming its own cause: within budget a null means
            //    the model or the provider failed, outside it the budget did.
            $result = $modelled ?? $this->deterministicSuggestion(
                reason: $this->withinBudget ? self::DEGRADE_AI_UNAVAILABLE : self::DEGRADE_BUDGET_EXHAUSTED,
                digest: $digest,
            );

            // 5b. Attach the confidence the evidence actually supports,
            //     overwriting whatever either construction path left in place.
            //     Deriving it here rather than trusting `$result->confidence` is
            //     the whole point: neither the gateway's schema nor a fake model
            //     answer gets a vote, only what this job can observe.
            $result = $result->withConfidence(
                $this->confidenceFor($modelled !== null, $result->regionBasis, $digest),
            );

            // 6. Mine the SAME body and the SAME filtered headers for metrics
            //    worth proposing. `$this->headers` and never
            //    `$probe->responseHeaders`: the allowlist that ran in the
            //    request is the only thing standing between a credentialled
            //    probe's `Set-Cookie` and a metric that would persist it on
            //    every check. Discovery spends its own budget unit and degrades
            //    to an empty array on its own.
            $suggestedMetrics = $discovery->discover(
                $this->transientMonitor(),
                $this->probe->content,
                $this->teamId,
                $this->headers,
                $this->locale,
                $this->runId,
            );
            $this->tick($runs, self::STEP_DISCOVERY, ran: $this->probe->content !== null);

            // 7. Spend the trial, then read what is left, in that order: the
            //    number the client counts down with has to reflect the run it
            //    is reading about.
            $trialsRemaining = $this->spendTrial($gate, $modelled !== null);

            $runs->complete($this->runId, $this->resultPayload($result, $suggestedMetrics, $trialsRemaining));
        } catch (Throwable $e) {
            // Record the terminal state, then let the failure out: the queue
            // has to see it to record a failed job, and swallowing it here
            // would leave a run looking merely slow rather than dead.
            // {@see failed()} writes the same thing for the failures no catch
            // block ever sees, and the two overlapping is harmless (see that
            // method).
            $this->recordFailure($runs, self::REASON_ERRORED);

            throw $e;
        } finally {
            // Both exits, success and throw, and this is the release that
            // matters: a run that ends without it locks the operator out of
            // analyze until the lock's own 200-second TTL expires, which reads
            // like a rate limiter nobody configured.
            $this->releaseLock();
        }

        // 8. One last tick, carrying the COMPLETED status rather than a new step.
        //    Without it the socket delivers five step ticks and then goes quiet,
        //    and the client learns the result exists only on its next poll, which
        //    is the one signal a broadcast-only verification pass would have no
        //    way to observe.
        //
        //    OUTSIDE the try, and that placement is the fix for a real defect
        //    rather than tidiness. Inside it, a throw from this `event()` routed
        //    into the catch above and flipped a run whose result was already
        //    written and complete into `failed`, handing the operator nothing.
        //    `ShouldRescue` does not cover it: that wraps the Redis enqueue only,
        //    as the event's own docblock says. Out here a broadcast failure costs
        //    the operator one poll interval instead of the whole analysis, which
        //    is the right trade for a progress report.
        $this->broadcastTick(
            self::STEP_DISCOVERY,
            $this->probe->content !== null
                ? AnalyzeProgressBroadcast::STATE_DONE
                : AnalyzeProgressBroadcast::STATE_SKIPPED,
            AnalyzeRunStatus::Completed,
        );
    }

    /**
     * Last-resort terminal write, for the failures no catch block ever sees.
     *
     * The one this exists for is the worker timeout: SIGALRM kills the process
     * mid model call, no `catch` in {@see handle()} ever runs, and the only
     * thing left is Laravel rebuilding this job from its payload to call this.
     * Without it the run keeps saying `analyzing` until its cache entry expires
     * and the form spins for fifteen minutes.
     *
     * Overlapping with {@see handle()}'s own catch is deliberate and cheap. The
     * store write is idempotent, and the broadcast is byte-identical to the one
     * the catch already sent (same step read back from the store, same closed
     * reason, same {@see self::FAILURE_SEQUENCE}), so the client's 100-entry
     * dedup ring drops the duplicate rather than rendering the failure twice.
     *
     * The store arrives through the container rather than by injection: Laravel
     * calls this hook with the exception only.
     *
     * @param  Throwable|null  $exception  Null when the job was failed manually.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordFailure(
            app(AnalyzeRunStore::class),
            $exception !== null ? self::REASON_ERRORED : self::REASON_STOPPED,
        );

        $this->releaseLock();
    }

    /**
     * Report one step's TERMINAL state to the store and to the client.
     *
     * [$ran] rather than a state string, because this job only ever writes two
     * of the four states and the mapping between the store's vocabulary and the
     * event's is the thing worth keeping in one place: they carry identical
     * values today and are deliberately separate constants (the event is on a
     * wire, the store is not), so a divergence would otherwise have to be
     * caught at five call sites.
     */
    protected function tick(AnalyzeRunStore $runs, int $step, bool $ran): void
    {
        $runs->advance(
            $this->runId,
            $step,
            $ran ? AnalyzeRunStore::STATE_DONE : AnalyzeRunStore::STATE_SKIPPED,
        );

        $this->broadcastTick(
            $step,
            $ran ? AnalyzeProgressBroadcast::STATE_DONE : AnalyzeProgressBroadcast::STATE_SKIPPED,
            AnalyzeRunStatus::Analyzing,
        );
    }

    /**
     * Push one progress tick onto the team's private channel.
     *
     * `event()` and not `broadcast()`: the helper goes through the event
     * dispatcher, which is what honours {@see AnalyzeProgressBroadcast}'s
     * `ShouldDispatchAfterCommit`, and it matches how the two existing
     * broadcast events in this app are dispatched. The event's own
     * `ShouldRescue` keeps a Redis push failure from killing this job over a
     * tick nothing depends on.
     */
    protected function broadcastTick(int $step, string $state, AnalyzeRunStatus $status): void
    {
        $this->sequence++;

        event(new AnalyzeProgressBroadcast(
            teamId: $this->teamId,
            runId: $this->runId,
            sequence: $this->sequence,
            step: $step,
            state: $state,
            status: $status->value,
        ));
    }

    /**
     * Write the terminal failure state and broadcast it.
     *
     * THE STEP NAMED IS THE ONE IN FLIGHT, NOT THE LAST ONE RECORDED, and the
     * difference is the whole reason this method reads the store at all.
     * Terminal-only ticks mean the client renders the row AFTER the last
     * terminal tick as the one working, so a run that died inside step 4 has
     * step 3 as its last RECORDED ordinal, and naming that one would overwrite a
     * row the operator already watched succeed while leaving the row that
     * actually died spinning. Which is the eternal spinner arriving through the
     * back door.
     *
     * Read back rather than remembered, because the instance this runs on may
     * have been rebuilt from the payload and know nothing about how far the
     * killed attempt got. A run whose entry is gone (evicted under
     * `volatile-lru`, or expired) reports step 1: nothing is recorded, so
     * nothing had finished, so the first step is the one that was in flight.
     *
     * The cap matters for one narrow window only: between the last step's tick
     * and {@see AnalyzeRunStore::complete()} there is no step in flight at all,
     * and a failure there marks the last row failed after it reported done. That
     * is preferred to naming an ordinal the client has no row for, and the run's
     * own `status` is `failed` on every path either way, which is what the client
     * acts on.
     */
    protected function recordFailure(AnalyzeRunStore $runs, string $reason): void
    {
        $run = $runs->find($this->runId);
        $recorded = is_int($run['step'] ?? null) ? $run['step'] : 0;
        $step = min($recorded + 1, self::STEP_DISCOVERY);

        $runs->fail($this->runId, $reason);

        // Wound back one, because {@see broadcastTick()} owns the increment and
        // this tick has to LAND on FAILURE_SEQUENCE rather than merely above the
        // ticks a run reached. Landing on a fixed number is what makes the two
        // failure paths (this method from {@see handle()}'s catch, then again
        // from {@see failed()}) produce byte-identical payloads, which is what
        // the client's dedup ring collapses into one rendered failure.
        $this->sequence = self::FAILURE_SEQUENCE - 1;

        $this->broadcastTick($step, AnalyzeProgressBroadcast::STATE_FAILED, AnalyzeRunStatus::Failed);
    }

    /**
     * Release the per-team in-flight lock the accepting request acquired.
     *
     * `restoreLock()` because the lock was taken in another process: the owner
     * string travelled here in the payload precisely so this job can release a
     * lock it never acquired. Called on every exit including twice on one
     * failure ({@see handle()}'s `finally` and then {@see failed()}), which is
     * safe rather than tolerated: `release()` compares the owner, and the
     * second call finds no lock to own.
     */
    protected function releaseLock(): void
    {
        Cache::restoreLock(self::lockName($this->teamId), $this->lockOwner)->release();
    }

    /**
     * Spend one metered AI monitor setup and report what is left, or report
     * what is left without spending.
     *
     * TWO independent conditions, and neither is the other's restatement.
     * [$modelled] is the product rule: a metered try buys AI ANALYSIS, so
     * neither degrade path charges for one, and a run where the provider was
     * down costs the operator nothing. The `Cache::add()` SETNX is the
     * at-most-once rule, and it is NOT the vacuous guard a request-side one
     * would be: `$tries = 1` covers the retry, but nothing covers a worker
     * dying between this write and the run-state write below it, and the
     * re-dispatch that follows enters `handle()` a second time on the same run
     * id. Measured by invoking `handle()` twice and reading
     * `teams.ai_analysis_trials_used` once.
     *
     * @return int|null Metered setups left, or null on a tier that entitles AI
     *                  analysis outright and has nothing to count down.
     */
    protected function spendTrial(PlanGate $gate, bool $modelled): ?int
    {
        $team = Team::find($this->teamId);

        if ($team === null) {
            return null;
        }

        $guardKey = sprintf(self::TRIAL_GUARD_KEY_TEMPLATE, $this->runId);

        if ($modelled && Cache::add($guardKey, true, self::TRIAL_GUARD_TTL_SECONDS)) {
            $gate->consumeAiAnalysisTrial($team);
        }

        // [$team] and not a re-read: `consumeAiAnalysisTrial()` force-fills and
        // saves the same instance, so the counter this reads is already the one
        // that was written, and a `fresh()` here would spend a query to learn
        // what is in memory.
        return $gate->aiAnalysisTrialsRemaining($team);
    }

    /**
     * The completed run's payload, shaped exactly as the synchronous response
     * body was.
     *
     * `data` and `meta` are kept as two halves rather than flattened because
     * `GET /api/v1/monitors/analyze/{run}` returns this verbatim and the client
     * already decodes that shape: `data` prefills the create form, and `meta`
     * carries the one number the 202 can no longer answer, since the trial is
     * now spent by a worker long after the request returned.
     *
     * @param  list<array<string, mixed>>  $suggestedMetrics
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    protected function resultPayload(AnalysisResult $result, array $suggestedMetrics, ?int $trialsRemaining): array
    {
        return [
            'data' => [
                'url' => $this->url,
                'name' => $this->suggestedName($this->url),
                ...$result->toArray(),
                'suggested_metrics' => $suggestedMetrics,
                'probe' => [
                    'region' => $this->probe->region,
                    'status_code' => $this->probe->statusCode,
                    'response_ms' => $this->probe->responseMs,
                ],
            ],
            'meta' => [
                'ai_analysis_trials_remaining' => $trialsRemaining,
            ],
        ];
    }

    /**
     * Rebuild the transient, unsaved monitor the evidence pipeline reads.
     *
     * `auth_config` is EXPLICITLY null, and that line is the security property
     * this whole class is shaped around: the instance the relay probed carried
     * the operator's credential, and the `encrypted:array` cast means passing
     * that instance into the payload would have put decryptable ciphertext in
     * `failed_jobs`. Rebuilding it here is what makes the absence readable in a
     * constructor signature instead of asserted in a comment. See the class
     * docblock.
     *
     * The field set mirrors `MonitorController::transientMonitor()` even though
     * {@see MetricDiscoveryService} reads only `url` and `type` off it: the two
     * objects describe one probe spec, and a rebuilt one that quietly disagreed
     * with the probed one about its method or its timeout would be a worse
     * problem than two inert fields.
     */
    protected function transientMonitor(): Monitor
    {
        return new Monitor([
            'type' => $this->type,
            'method' => $this->method,
            'url' => $this->url,
            'timeout_sec' => 30,
            'expected_status_code' => 200,
            'regions' => [$this->region],
            'auth_config' => null,
        ]);
    }

    /**
     * The public addresses the target resolves to, or an empty list.
     *
     * {@see HostGuard} is the only DNS code in this backend and
     * {@see HostGuard::resolvePublicHostIps()} is its fail-closed entry point:
     * one denied address discards the whole list, so an empty return covers an
     * unresolvable host and a rebinding-shaped one alike, which is exactly how
     * {@see TargetLocation} treats both.
     *
     * @return list<string>
     */
    protected function targetIps(HostGuard $hostGuard): array
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? $hostGuard->resolvePublicHostIps($host)
            : [];
    }

    /**
     * The model's suggestion for this probe, or null when it could not be
     * trusted or the provider could not be reached.
     *
     * Five exception families, all named, and the last is not redundant with the
     * client ones: `Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors` maps
     * a provider 429, 402 or 503 onto an {@see AiException} SUBCLASS before it
     * reaches a caller, and the OpenRouter gateway raises a plain one for an
     * error payload delivered in-band with HTTP 200. Neither descends from
     * `RuntimeException`, so without that branch the most ordinary provider bad
     * day there is would fail the run instead of degrading it.
     *
     * Only those five. A `TypeError` or an `Error` from our own code still
     * escapes, reaches {@see handle()}'s catch, and fails the run loudly rather
     * than hiding behind a plausible suggestion.
     *
     * Two things are deliberately absent from the log lines: the exception
     * MESSAGE, because a gateway message can quote the model, which was reading
     * text the target authored, and every probe field, for the same reason. What
     * IS present is everything about the failure that WE authored, and see
     * {@see self::degradeContext()} for why that distinction had to be drawn
     * again.
     *
     * FIVE BRANCHES, BECAUSE A TIMEOUT IS NOT AN OUTAGE. `ConnectionException`
     * and `RequestException` used to share one, and sharing it hid a real
     * production defect for a day: on 2026-08-09 two analyzes degraded saying the
     * AI service was unreachable, and the service had answered discovery on both
     * of the same runs. Our own client had hung up at the suggestion turn's
     * per-call ceiling. One is a wall of ours and the other is the provider's
     * word, they are reached by different fixes, and a line that cannot tell them
     * apart sends the next reader to the wrong one.
     */
    protected function suggestViaGateway(
        AnalysisGateway $gateway,
        AnalysisPayload $payload,
        AiDeadline $deadline,
    ): ?AnalysisResult {
        try {
            return $gateway->analyze($payload);
        } catch (AiBudgetExhaustedException $e) {
            // FIRST, because it extends RuntimeException. Nothing was sent, so
            // this is a signal about the wall-time budget or a slow provider
            // rather than about the model's output.
            Log::warning(
                "Monitor analysis degraded: the request's AI budget was already spent.",
                $this->degradeContext($payload, $deadline, $e),
            );
        } catch (RuntimeException $e) {
            Log::warning(
                'Monitor analysis degraded: the model output could not be trusted.',
                $this->degradeContext($payload, $deadline, $e),
            );
        } catch (ConnectionException $e) {
            // OURS, not theirs: the provider never got to answer because the
            // call reached the timeout this request handed it. Nothing here is
            // waiting-out-able, so the number that matters is how much of the
            // budget was still unspent, which says whether a per-call ceiling or
            // the shared budget did the cutting.
            Log::warning(
                'Monitor analysis degraded: the AI service did not answer in time.',
                $this->degradeContext($payload, $deadline, $e),
            );
        } catch (RequestException $e) {
            // THEIRS: the provider answered, with a refusal. The status is the
            // one fact worth having and it is the provider's own.
            Log::warning(
                'Monitor analysis degraded: the AI provider answered with an error status.',
                $this->degradeContext($payload, $deadline, $e),
            );
        } catch (AiException $e) {
            Log::warning(
                'Monitor analysis degraded: the AI provider could not complete the request.',
                $this->degradeContext($payload, $deadline, $e),
            );
        }

        return null;
    }

    /**
     * The facts a degrade line may carry, which are exactly the ones nobody
     * outside this system authored.
     *
     * The exception MESSAGE stays out, for the reason
     * {@see self::suggestViaGateway()} gives: a gateway message can quote the
     * model quoting the target. Its CLASS does not, and neither does an HTTP
     * status the provider itself set, so both go in. That pair is what turns the
     * next occurrence of this degrade into a log read.
     *
     * The two budget numbers are here because their RATIO is the answer to the
     * question a degraded run actually raises. A run that gave up 42 seconds into
     * a 150 second budget was cut by a per-call ceiling; one that gave up at 149
     * was cut by the budget. Reading that off Horizon's retained job records
     * instead, which is how it was read the first time, works for about an hour
     * and then the records are trimmed.
     *
     * @return array<string, mixed>
     */
    protected function degradeContext(AnalysisPayload $payload, AiDeadline $deadline, Throwable $failure): array
    {
        return array_filter(
            [
                'url' => $payload->displayUrl(),
                'region' => $payload->region,
                'failure' => class_basename($failure),
                'status' => $failure instanceof RequestException ? $failure->response->status() : null,
                'budget_elapsed_seconds' => (int) round($deadline->elapsed()),
                'budget_seconds' => $deadline->budget(),
            ],
            // Only the absent status is dropped. A comparison against null and
            // not a truthiness test, because an elapsed of 0 is a real reading
            // and the most interesting one there is: it means the call was
            // refused before it was made.
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * Hydrate the analysis payload from the probe, the evidence derived from
     * it, and its optional detector read.
     *
     * The attacker-influenceable fields (error message, body preview, the
     * surviving header VALUES, the digest) are handed through untouched:
     * {@see AnalysisPayload} fences and hard truncates them at the LLM
     * boundary, and the credential is already out of all of them.
     */
    protected function analysisPayload(
        CheckResult $probe,
        ?object $candidate,
        ?ResponseDigestResult $digest,
        TargetLocationResult $location,
    ): AnalysisPayload {
        return new AnalysisPayload(
            url: $this->url,
            region: $this->region,
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
            responseHeaders: $this->headers,
            teamId: $this->teamId,
            digest: $digest,
            targetLocation: $location,
        );
    }

    /**
     * Build a deterministic suggestion from the probe and the evidence derived
     * from it, used on every path where no model narration is available.
     *
     * Bounds are anchored to the observed response time (warn at 3x, critical
     * at 6x, with sane floors) so the prefill stays useful even without a model
     * narration. [$reason] is carried into the rationale rather than hardcoded
     * because two different causes reach here and the operator acts differently
     * on each.
     *
     * `region_basis` is ALWAYS `default` on this path, whatever any lookup
     * achieved, because this path does not use a lookup to choose a region:
     * `recommendedRegions` is the region the request asked to probe from.
     * Reporting `geoip` here would justify the suggestion by evidence that
     * played no part in making it. Only the MODEL, which reads the location
     * facts and can weigh them, may claim another basis.
     *
     * The SLO table is REFERENCED on {@see MonitorController} rather than
     * copied: `AnalyzeMonitorControllerTest` pins its keys and values against
     * the gateway's two closed catalogs, and a second copy here would be a twin
     * site where a fix lands on one of two identical places.
     */
    protected function deterministicSuggestion(string $reason, ?ResponseDigestResult $digest): AnalysisResult
    {
        $observed = $this->probe->responseMs ?? 500;
        $serviceClass = $this->serviceClassFor($digest?->shape);

        return new AnalysisResult(
            recommendedIntervalSeconds: 60,
            recommendedWarnThresholdMs: max(500, $observed * 3),
            recommendedCriticalThresholdMs: max(1000, $observed * 6),
            recommendedRegions: [$this->region],
            rationale: "Deterministic baseline from the exploratory probe ({$reason}).",
            strippedCitations: [],
            serviceClass: $serviceClass,
            regionBasis: 'default',
            recommendedSloTarget: MonitorController::SLO_TARGET_BY_SERVICE_CLASS[$serviceClass],
        );
    }

    /**
     * The service class a sniffed body shape proves on its own, with no model
     * reading a single key.
     *
     * `health_endpoint` is unreachable from here because it needs the body's
     * SEMANTICS (a `status` field, a `checks` map), which only the model reads,
     * so a JSON body is `json_api` and nothing more. An XML body answers
     * `unknown` rather than being forced into the nearest member: a sitemap or
     * a feed is neither an API nor a page, and `unknown` is the true answer
     * rather than a rounding of one.
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
     * How much evidence the suggestion actually rests on, matching the Dart
     * `AiConfidence` enum's case names exactly (`high`, `medium`, `low`) so
     * `aiConfidenceFromWire()` decodes it with no mapping table.
     *
     * Three branches over evidence already in scope on both construction paths,
     * never over anything a model reported about itself:
     *
     * - `low`: no model answered, whichever of the two named causes forced that.
     * - `medium`: a model answered, but [$regionBasis] is an INFERRED value, so
     *   nothing measured located the target and the model's regions are a guess
     *   dressed as a suggestion.
     * - `high`: a model answered, [$regionBasis] names a MEASURED basis, and
     *   the probe actually returned a body to describe. A measured basis with
     *   no body evidence stays `medium` rather than borrowing the higher grade
     *   from a fact the model never read.
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
}
