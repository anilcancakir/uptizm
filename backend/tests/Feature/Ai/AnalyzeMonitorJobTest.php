<?php

namespace Tests\Feature\Ai;

use App\Enums\AnalyzeRunStatus;
use App\Enums\HttpMethod;
use App\Enums\LocationBasis;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Events\AnalyzeProgressBroadcast;
use App\Jobs\AnalyzeMonitorJob;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\FakeAnalysisGateway;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Monitoring\TargetLocation;
use App\Services\Monitoring\TargetLocationResult;
use App\Support\Monitoring\AnalyzeRunStore;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\HostGuard;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers {@see AnalyzeMonitorJob}: the model half of `POST /monitors/analyze`
 * once it runs outside the request.
 *
 * Every provider boundary is doubled, and one of the doubles is less obvious
 * than the others: {@see HostGuard} is real DNS, so a job left with the real one
 * resolves the fixture host from whatever machine runs the suite. It is bound to
 * a double here for the same reason the provider keys are pinned empty in
 * phpunit.xml, and a test that cares about the target location cans that too.
 *
 * THREE OF THESE TESTS GUARD PROPERTIES THAT FAIL SILENTLY, and their red phases
 * were measured rather than assumed (`evidence/step-05-no-credential-in-payload.md`):
 * the credential-absence assertion, which stays green under a mutation as soon
 * as `SerializesModels` is present; the at-most-once trial meter; and the
 * terminal-only tick contract, which is what stops a killed worker from leaving
 * a row spinning.
 */
class AnalyzeMonitorJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A credential value the request would have probed with.
     *
     * Load-bearing in exactly one test, and see that test for why the assertion
     * against this literal is the WEAKER half of the pair it appears in.
     */
    private const SECRET_TOKEN = 'sk-live-analyze-must-never-carry-this';

    /**
     * A realistic JSON health body: enough for the digest to sniff a shape and
     * for discovery to have something to be asked about.
     */
    private const PROBE_BODY = '{"status":"ok","checks":{"db":"ok"},"used_percent":80.28}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(AnalysisGateway::class, FakeAnalysisGateway::class);
        $this->stubMetricDiscovery();
        $this->stubHostGuard();
        $this->cannedTargetLocation();
    }

    /**
     * THE PAYLOAD CARRIES NO CREDENTIAL, and the two assertions below are not
     * equally strong.
     *
     * The `auth_config` one is the one that discriminates. A mutation that
     * serialises the transient monitor the relay probed does NOT put the token
     * literal in the payload, because `Monitor`'s `encrypted:array` cast
     * encrypts inside `setAttribute`, so the raw attribute holds ciphertext even
     * on an unsaved instance: a reviewer reading that payload sees opaque bytes
     * and concludes the credential is protected, while it stays decryptable with
     * the `APP_KEY` in `.env` on the same box. What the mutation DOES put there
     * is the attribute NAME.
     *
     * The literal assertion stays anyway, because it is the one that would catch
     * a future constructor argument carrying a credential in the clear, which is
     * the failure the plan's Must-NOT names.
     *
     * `serialize()` and not the queue's own payload: `ShouldBeEncrypted` would
     * hide both strings behind ciphertext at that layer, which is protection at
     * rest and not the design property under test here.
     */
    public function test_the_serialized_payload_carries_neither_the_credential_nor_its_attribute_name(): void
    {
        $team = $this->makeTeam();

        // The instance the REQUEST probes with, built exactly as the controller
        // builds it, so this test is anchored to a real credentialled analyze
        // rather than to an empty one.
        $probed = new Monitor([
            'type' => MonitorType::Http,
            'method' => HttpMethod::Get,
            'url' => 'https://example.com/health',
            'auth_config' => ['type' => 'bearer', 'token' => self::SECRET_TOKEN],
        ]);

        $this->assertSame(
            self::SECRET_TOKEN,
            $probed->auth_config['token'],
            'The fixture must actually carry the credential, or every assertion below is vacuous.',
        );

        // THE POSITIVE HALF, so the two negatives below cannot pass by accident.
        // Serialising that instance is what the forbidden design does, and this
        // is what it produces: the attribute NAME in the clear, its value as
        // ciphertext rather than as the literal. It is measured here rather than
        // reasoned about, because reasoning about it wrongly is what would make
        // the token assertion look like the strong one.
        $probedPayload = serialize($probed);

        $this->assertStringContainsString('auth_config', $probedPayload);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, $probedPayload);

        $payload = serialize($this->makeJob(teamId: $team->id));

        $this->assertStringNotContainsString(self::SECRET_TOKEN, $payload);
        $this->assertStringNotContainsString('auth_config', $payload);
    }

    /**
     * `SerializesModels` MUST stay off this class.
     *
     * Not style: that trait maps an Eloquent property to a `ModelIdentifier`
     * before the payload is written, so with it on, the mutation the test above
     * exists to catch produces a payload with no model in it and passes. The
     * trait is what would make the whole credential-absence assertion vacuous,
     * which is why its absence is asserted rather than commented.
     */
    public function test_the_job_omits_the_trait_that_would_hide_a_serialized_model(): void
    {
        $this->assertNotContains(
            SerializesModels::class,
            class_uses_recursive($this->makeJob()),
            'With SerializesModels on, a serialized Monitor never reaches the payload as a model, and the '
            .'credential-absence test passes while the property it guards is broken.',
        );

        $this->assertInstanceOf(
            ShouldBeEncrypted::class,
            $this->makeJob(),
            'The probe body travels in this payload and lands in failed_jobs on every failure, which '
            .'Horizon renders in a browser.',
        );
    }

    /**
     * The job rides its own connection AND its own queue.
     *
     * Both, because they fail differently. Without the connection, the shared
     * `redis` releases a still-running analyze to a second worker at 90 seconds
     * (two meter spends, two broadcast streams, two writers on one run) and
     * nothing throws. Without the queue, the run lands in `queues:default`,
     * which supervisor-1 drains at a 60-second timeout, and a 150-second job is
     * killed at 60.
     *
     * These two properties ARE the mechanism: `Bus\Dispatcher::dispatchToQueue()`
     * reads `$command->connection` to pick the connection and `$command->queue`
     * to pick the list. A constructor `onConnection()` leaves no reflectable
     * default, so Tests\Unit\AnalyzeQueueConfigTest cannot see this and says so.
     */
    public function test_the_job_rides_the_dedicated_analyze_connection_and_queue(): void
    {
        $job = $this->makeJob();

        $this->assertSame('redis-analyze', $job->connection);
        $this->assertSame('analyze', $job->queue);
    }

    /**
     * One attempt, and the pinned timeout.
     *
     * `$tries = 1` is what makes {@see AnalyzeMonitorJob::failed()} fire on the
     * FIRST failure: Laravel calls that hook only on the attempt that exhausts
     * the tries, so a retry would leave the first hard kill with nothing to
     * write the terminal state.
     */
    public function test_the_job_declares_one_attempt_and_the_pinned_timeout(): void
    {
        $job = $this->makeJob();

        $this->assertSame(1, $job->tries);
        $this->assertSame(160, $job->timeout);
    }

    /**
     * The step ordinals agree with the client's row list.
     *
     * A CROSS-LANGUAGE CONTRACT with no compiler behind it: the Flutter form
     * renders one row per label in `kAnalyzeSteps` and has no way to discover
     * how many steps the backend reports. Six ordinals against five labels
     * silently drops the last tick; five against six leaves a row that never
     * resolves.
     */
    public function test_the_step_ordinals_agree_with_the_clients_step_list(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], AnalyzeMonitorJob::STEPS);

        $support = base_path('../lib/resources/views/monitors/monitor_form_support.dart');

        $this->assertFileExists($support, 'The client step list moved; re-anchor this contract.');

        $labels = preg_match_all(
            "/create_ai_step_\d+/",
            (string) file_get_contents($support),
        );

        // Guarded rather than trusted: a pattern that matched nothing would
        // certify the contract by comparing five against zero.
        $this->assertGreaterThan(0, $labels, 'Matched no client step labels, so nothing was compared.');

        $this->assertCount(
            $labels,
            AnalyzeMonitorJob::STEPS,
            'The backend reports a different number of steps than the client renders rows for.',
        );
    }

    /**
     * The lock name is derived, and both sides derive it the same way.
     *
     * The request acquires and the JOB releases, across a process, so the name
     * cannot be a literal at either site: a drift leaves a lock nothing releases
     * and the operator locked out of analyze for the whole 200-second TTL, which
     * reads like a rate limiter.
     */
    public function test_the_lock_name_is_derived_from_the_team(): void
    {
        $this->assertSame(
            'analyze-in-flight:team-7',
            AnalyzeMonitorJob::lockName('team-7'),
        );

        $this->assertNotSame(
            AnalyzeMonitorJob::lockName('team-7'),
            AnalyzeMonitorJob::lockName('team-8'),
            'The lock must be per team, or one team analysing locks out every other.',
        );
    }

    public function test_it_completes_a_run_end_to_end_against_fake_gateways(): void
    {
        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id));

        $run = app(AnalyzeRunStore::class)->find($runId);

        $this->assertSame(AnalyzeRunStatus::Completed->value, $run['status']);

        // The synchronous response body, unchanged: this is what the GET returns
        // verbatim and what the create form already decodes.
        $this->assertSame('https://example.com/health', $run['result']['data']['url']);
        $this->assertSame('example.com', $run['result']['data']['name']);
        $this->assertSame(60, $run['result']['data']['recommended_interval_seconds']);
        $this->assertSame(800, $run['result']['data']['recommended_warn_threshold_ms']);
        $this->assertSame([], $run['result']['data']['suggested_metrics']);
        $this->assertSame([
            'region' => 'eu-west',
            'status_code' => 200,
            'response_ms' => 180,
        ], $run['result']['data']['probe']);

        // The `meta` half exists because the 202 can no longer answer it: the
        // trial is spent by this worker, long after the request returned.
        $this->assertArrayHasKey('ai_analysis_trials_remaining', $run['result']['meta']);

        // Every step reached a terminal state, and every ordinal reported.
        $this->assertSame(
            AnalyzeMonitorJob::STEPS,
            array_map('intval', array_keys($run['steps'])),
        );
    }

    /**
     * TERMINAL-ONLY TICKS, which is what makes an eternal spinner structurally
     * impossible rather than defended against.
     *
     * The client renders the row after the last terminal tick as the one in
     * flight, so there is no `running` state a killed worker can strand. The
     * sequence assertion is the other half: production Horizon runs ten
     * processes on the broadcast queue, Laravel guarantees ordering only for SQS
     * FIFO, and the Dart driver drops a byte-identical frame through a 100-entry
     * dedup ring.
     */
    public function test_every_tick_is_terminal_and_the_sequence_strictly_increases(): void
    {
        Event::fake([AnalyzeProgressBroadcast::class]);

        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id));

        $ticks = $this->recordedTicks();

        // Five steps plus the completion tick.
        $this->assertCount(6, $ticks);

        $sequences = array_column($ticks, 'sequence');
        $sorted = $sequences;
        sort($sorted);

        $this->assertSame($sorted, $sequences, 'The ticks were not emitted in sequence order.');
        $this->assertSame($sequences, array_unique($sequences), 'Two ticks share one sequence number.');

        foreach ($ticks as $tick) {
            $this->assertNotSame(
                AnalyzeProgressBroadcast::STATE_RUNNING,
                $tick['state'],
                'This job must never write `running`: a step reported as running that then dies leaves the '
                .'client spinning on it forever.',
            );
        }

        $this->assertSame(AnalyzeRunStatus::Completed->value, end($ticks)['status']);
    }

    /**
     * A step that genuinely did not run reports `skipped`, not `done`.
     *
     * Three of the five can, and each for its own reason: no body means no
     * digest and nothing for discovery to mine, and no budget means no model
     * call. Collapsing any of them into `done` tells the operator work happened
     * that did not; leaving them running hangs the form.
     */
    public function test_the_steps_that_did_not_run_report_skipped(): void
    {
        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->runJob($this->makeJob(
            runId: $runId,
            teamId: $team->id,
            content: null,
            withinBudget: false,
        ));

        $steps = app(AnalyzeRunStore::class)->find($runId)['steps'];

        $this->assertSame(AnalyzeRunStore::STATE_DONE, $steps[AnalyzeMonitorJob::STEP_PROBE]);
        $this->assertSame(AnalyzeRunStore::STATE_SKIPPED, $steps[AnalyzeMonitorJob::STEP_DIGEST]);
        $this->assertSame(AnalyzeRunStore::STATE_DONE, $steps[AnalyzeMonitorJob::STEP_DETECTOR]);
        $this->assertSame(AnalyzeRunStore::STATE_SKIPPED, $steps[AnalyzeMonitorJob::STEP_SUGGESTION]);
        $this->assertSame(AnalyzeRunStore::STATE_SKIPPED, $steps[AnalyzeMonitorJob::STEP_DISCOVERY]);

        // Skipped work still completes the run: the deterministic suggestion is
        // the answer on this path, not an error.
        $run = app(AnalyzeRunStore::class)->find($runId);
        $this->assertSame(AnalyzeRunStatus::Completed->value, $run['status']);
        $this->assertSame('low', $run['result']['data']['confidence']);
    }

    /**
     * A probe with no response time leaves the detector nothing to read.
     */
    public function test_a_probe_without_a_response_time_skips_the_detector(): void
    {
        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id, responseMs: null));

        $steps = app(AnalyzeRunStore::class)->find($runId)['steps'];

        $this->assertSame(AnalyzeRunStore::STATE_SKIPPED, $steps[AnalyzeMonitorJob::STEP_DETECTOR]);
    }

    /**
     * A CALL THAT RAN OUT OF ITS OWN TIMEOUT IS NAMED A TIMEOUT, and the line
     * says which wall it was not.
     *
     * The production run this exists for, 2026-08-09 22:43 and 22:46 UTC: two
     * analyzes degraded with `the AI service was unreachable`, and the service
     * was reachable on both of them. Metric discovery answered on the same runs.
     * What actually happened is that the suggestion turn spent its whole
     * per-call ceiling and our own HTTP client hung up, which the old wording
     * described as the provider being down.
     *
     * That mislabel cost a forensics pass over Horizon's retained job records to
     * undo, because the line carried nothing that could tell a client-side
     * timeout from an HTTP error status, and nothing that could tell either from
     * the shared budget running out. So the three facts asserted here are the
     * three that were missing: the exception FAMILY, the budget CEILING, and how
     * much of that budget had actually been spent when the call gave up.
     *
     * `budget_elapsed_seconds` is the one that answers the question this whole
     * investigation opened with: a number far below `budget_seconds` says a
     * per-call ceiling cut the call, not the shared budget, and no other artifact
     * in the system says so.
     */
    public function test_a_call_that_ran_out_of_time_is_named_a_timeout_rather_than_an_unreachable_service(): void
    {
        Log::spy();

        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(
                new ConnectionException('cURL error 28: Operation timed out after 40001 milliseconds'),
            ),
        );

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'did not answer in time')
                && $context['failure'] === 'ConnectionException'
                && $context['budget_seconds'] === (int) config('ai.request_budget_seconds')
                && is_int($context['budget_elapsed_seconds'])
                && ! array_key_exists('status', $context))
            ->once();

        // The operator-facing half is deliberately UNCHANGED: a timeout and a
        // provider error are answered the same way, by trying again shortly, and
        // the degrade vocabulary is split by what the operator should do rather
        // than by what the log needs to say.
        $result = app(AnalyzeRunStore::class)->find($runId)['result']['data'];

        $this->assertStringContainsString('AI analysis temporarily unavailable', $result['rationale']);
        $this->assertSame('low', $result['confidence']);
    }

    /**
     * A provider that ANSWERED, with a refusal, is named with its status.
     *
     * The other half of the branch the run above shared. An HTTP status is the
     * single most useful fact about a provider refusal and it is OpenRouter's own
     * word, not the monitored target's, so it is safe to record where the
     * exception message is not: a 404 is `require_parameters` finding no endpoint
     * that can serve the request as sent, a 400 is a schema it rejected, and
     * neither is reached by waiting.
     */
    public function test_a_provider_error_response_is_named_with_the_status_it_answered_with(): void
    {
        Log::spy();

        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(new RequestException(
                new Response(new Psr7Response(404, [], '{"error":{"message":"No endpoints found"}}')),
            )),
        );

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'answered with an error status')
                && $context['failure'] === 'RequestException'
                && $context['status'] === 404)
            ->once();
    }

    /**
     * A failure the degrade path does not cover leaves a terminal state and a
     * terminal tick, and still fails the job.
     *
     * `LogicException` deliberately: {@see AnalyzeMonitorJob::suggestViaGateway()}
     * catches five families on purpose, so a test that threw one of those would
     * be exercising the degrade rather than the failure path. What must not
     * happen is a run left saying `analyzing` while the queue records a failed
     * job.
     */
    public function test_a_failure_the_degrade_does_not_cover_leaves_a_terminal_state_and_tick(): void
    {
        Event::fake([AnalyzeProgressBroadcast::class]);

        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(new LogicException('our own bug, not the provider')),
        );

        try {
            $this->runJob($this->makeJob(runId: $runId, teamId: $team->id));
            $this->fail('A failure outside the degrade set must not return normally.');
        } catch (LogicException $e) {
            $this->assertSame('our own bug, not the provider', $e->getMessage());
        }

        $run = app(AnalyzeRunStore::class)->find($runId);

        $this->assertSame(AnalyzeRunStatus::Failed->value, $run['status']);
        $this->assertSame('errored', $run['reason']);

        $ticks = $this->recordedTicks();
        $last = end($ticks);

        $this->assertSame(AnalyzeProgressBroadcast::STATE_FAILED, $last['state']);
        $this->assertSame(AnalyzeRunStatus::Failed->value, $last['status']);

        // THE STEP IN FLIGHT, not the last one recorded. The gateway threw
        // inside step 4, whose tick therefore never happened, so the store's
        // last recorded ordinal is 3. Naming 3 here would mark a row the operator
        // already watched succeed as failed and leave row 4, the one that
        // actually died, spinning: the eternal spinner through the back door.
        // This assertion exists because the first version of this job got it
        // wrong and a mutation's diff is what showed it.
        $this->assertSame(AnalyzeMonitorJob::STEP_SUGGESTION, $last['step']);
        $this->assertSame(
            AnalyzeRunStore::STATE_DONE,
            app(AnalyzeRunStore::class)->find($runId)['steps'][AnalyzeMonitorJob::STEP_DETECTOR],
            'The step before the failure must still read as done, or this test is asserting the wrong pair.',
        );
    }

    /**
     * LOAD-BEARING NEGATIVE TEST: `failed()` alone ends the run.
     *
     * This is the path where no catch block runs at all: the worker's timeout
     * kills the process with SIGALRM mid model call, and the only thing left is
     * Laravel invoking this hook on a job rebuilt from its payload. Without it
     * the run keeps saying `analyzing` until its cache entry expires and the
     * form spins for fifteen minutes.
     *
     * Rebuilt the way the queue rebuilds it, so the write is proven to work on a
     * restored instance rather than on the one that happened to be running.
     */
    public function test_the_failed_hook_alone_writes_the_terminal_state_and_broadcasts(): void
    {
        Event::fake([AnalyzeProgressBroadcast::class]);

        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $job = unserialize(serialize($this->makeJob(runId: $runId, teamId: $team->id)));
        $job->failed(new RuntimeException('SIGALRM: the worker killed the analyze'));

        $run = app(AnalyzeRunStore::class)->find($runId);

        $this->assertSame(AnalyzeRunStatus::Failed->value, $run['status']);
        $this->assertSame('errored', $run['reason']);

        $ticks = $this->recordedTicks();

        $this->assertCount(1, $ticks);
        $this->assertSame(AnalyzeProgressBroadcast::STATE_FAILED, $ticks[0]['state']);

        // High on purpose. The rebuilt instance's own counter is back at zero,
        // and a failure numbered 1 arrives behind ticks the client has already
        // rendered, so a client ordering by sequence would drop the only tick
        // that ends the run.
        $this->assertSame(999, $ticks[0]['sequence']);
    }

    /**
     * The two failure paths produce ONE rendered failure, not two.
     *
     * Both run over a single failed run: `handle()`'s own catch writes the
     * terminal state, and then the queue calls `failed()` on the same instance
     * (`Illuminate\Queue\Jobs\SyncJob` and the redis worker both do). The
     * overlap is deliberate, because only `failed()` covers the worker kill
     * where no catch runs at all, so what has to be true is that the second tick
     * is BYTE-IDENTICAL to the first: that is what the Dart driver's 100-entry
     * dedup ring collapses, and it is the reason the failure tick lands on a
     * fixed sequence rather than merely above the ticks the run reached.
     *
     * Driven by calling the two in the worker's own order rather than through a
     * real dispatch: this job pins `onConnection('redis-analyze')`, so an
     * unfaked dispatch in the suite would dial Redis, and a faked one would
     * never run `handle()`.
     */
    public function test_the_two_failure_paths_produce_one_identical_terminal_tick(): void
    {
        Event::fake([AnalyzeProgressBroadcast::class]);

        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->app->instance(
            AnalysisGateway::class,
            FakeAnalysisGateway::throwing(new LogicException('our own bug, not the provider')),
        );

        $job = $this->makeJob(runId: $runId, teamId: $team->id);

        try {
            $this->runJob($job);
        } catch (LogicException) {
            // Asserted elsewhere; here only the tick pair matters.
        }

        $job->failed(new LogicException('our own bug, not the provider'));

        $ticks = $this->recordedTicks();

        $this->assertGreaterThanOrEqual(2, count($ticks));
        $this->assertSame(
            $ticks[count($ticks) - 2],
            $ticks[count($ticks) - 1],
            'The two failure ticks differ, so the client renders one failure twice.',
        );
    }

    /**
     * A manual `$job->fail()` passes no exception at all, so the hook has to
     * accept null rather than raising a TypeError on top of the failure.
     */
    public function test_the_failed_hook_accepts_a_missing_exception(): void
    {
        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);

        $this->makeJob(runId: $runId, teamId: $team->id)->failed(null);

        $run = app(AnalyzeRunStore::class)->find($runId);

        $this->assertSame(AnalyzeRunStatus::Failed->value, $run['status']);

        // A closed vocabulary of two, never an exception message: the failing
        // call sites read text the monitored target authored.
        $this->assertSame('stopped', $run['reason']);
    }

    /**
     * THE TRIAL METER IS AT-MOST-ONCE PER RUN, and this guard is not the vacuous
     * kind.
     *
     * `$tries = 1` covers the retry. What it does not cover is a worker dying
     * between the meter write and the run-state write, after which the run is
     * re-entered on the same run id, which is exactly what invoking `handle()`
     * twice models. The SETNX is the only thing standing between that and a
     * second charge for one analyze the operator asked for once.
     */
    public function test_the_trial_meter_is_spent_at_most_once_for_one_run(): void
    {
        $team = $this->makeTeam('free');
        $runId = $this->startRun($team->id);

        $job = $this->makeJob(runId: $runId, teamId: $team->id);

        $this->runJob($job);
        $this->runJob($job);

        $this->assertSame(1, (int) $team->fresh()->ai_analysis_trials_used);
    }

    /**
     * A metered try buys AI ANALYSIS, so a run no model answered is free.
     *
     * Both degrade causes reach this: over budget (no call attempted) and a
     * provider that could not be reached. Neither delivered an analysis, so
     * neither charges for one.
     */
    public function test_a_run_no_model_answered_spends_no_trial(): void
    {
        $team = $this->makeTeam('free');
        $runId = $this->startRun($team->id);

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id, withinBudget: false));

        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
        $this->assertSame(
            (int) config('plans.tiers.0.limits.ai_analysis_trials'),
            app(AnalyzeRunStore::class)->find($runId)['result']['meta']['ai_analysis_trials_remaining'],
        );
    }

    /**
     * The remaining count is read AFTER the spend.
     *
     * The client counts an allowance down from this number, so reading it before
     * the spend would report the run the operator is looking at as still
     * available.
     */
    public function test_the_reported_allowance_reflects_the_spend_it_is_returned_with(): void
    {
        $team = $this->makeTeam('free');
        $runId = $this->startRun($team->id);
        $allowance = (int) config('plans.tiers.0.limits.ai_analysis_trials');

        $this->assertGreaterThan(0, $allowance, 'Free must grant AI setups, or this test compares nothing.');

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id));

        $this->assertSame(
            $allowance - 1,
            app(AnalyzeRunStore::class)->find($runId)['result']['meta']['ai_analysis_trials_remaining'],
        );
    }

    /**
     * The in-flight lock the REQUEST took is released by this job, on the
     * success path.
     *
     * Asserted behaviourally, by taking the lock the way the controller will and
     * then proving a second acquire succeeds afterwards. A run that ends without
     * this locks the operator out of analyze until the 200-second TTL expires.
     */
    public function test_the_in_flight_lock_is_released_on_the_success_path(): void
    {
        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);
        $owner = $this->holdInFlightLock($team->id);

        $this->runJob($this->makeJob(runId: $runId, teamId: $team->id, lockOwner: $owner));

        $this->assertTrue(
            Cache::lock(AnalyzeMonitorJob::lockName($team->id), 200)->get(),
            'The lock survived a completed run, so the next analyze is refused for three minutes.',
        );
    }

    /**
     * And on the failure path, which is the one that matters more: the failure
     * the release exists for is the worker kill, where no catch block runs.
     */
    public function test_the_in_flight_lock_is_released_on_the_failure_path(): void
    {
        $team = $this->makeTeam();
        $runId = $this->startRun($team->id);
        $owner = $this->holdInFlightLock($team->id);

        $job = unserialize(serialize($this->makeJob(runId: $runId, teamId: $team->id, lockOwner: $owner)));
        $job->failed(new RuntimeException('SIGALRM: the worker killed the analyze'));

        $this->assertTrue(
            Cache::lock(AnalyzeMonitorJob::lockName($team->id), 200)->get(),
            'The lock survived a dead run, which is precisely the case its TTL is only a backstop for.',
        );
    }

    /**
     * A run whose cache entry is gone is not resurrected by this job.
     *
     * Redis runs `volatile-lru` with a 512 MB ceiling here, so eviction is a
     * real state. The store refuses to recreate a run it cannot authorise
     * anyone against; what this asserts is that the job survives it rather than
     * throwing on top of it, because the lock release still has to happen.
     */
    public function test_a_run_whose_entry_vanished_still_releases_the_lock(): void
    {
        $team = $this->makeTeam();
        $owner = $this->holdInFlightLock($team->id);

        // No startRun(): the entry never existed, which is indistinguishable
        // from having been evicted.
        $this->runJob($this->makeJob(runId: (string) Str::uuid(), teamId: $team->id, lockOwner: $owner));

        $this->assertTrue(Cache::lock(AnalyzeMonitorJob::lockName($team->id), 200)->get());
    }

    /**
     * Build the job with the shape the accepting request will hand it.
     */
    protected function makeJob(
        ?string $runId = null,
        ?string $teamId = null,
        ?string $content = self::PROBE_BODY,
        ?int $responseMs = 180,
        bool $withinBudget = true,
        string $lockOwner = 'request-owner',
    ): AnalyzeMonitorJob {
        return new AnalyzeMonitorJob(
            runId: $runId ?? (string) Str::uuid(),
            teamId: $teamId ?? (string) Str::uuid(),
            locale: 'en',
            probe: $this->probeResult($content, $responseMs),
            headers: ['content-type' => 'application/json'],
            url: 'https://example.com/health',
            region: 'eu-west',
            type: MonitorType::Http,
            method: HttpMethod::Get,
            withinBudget: $withinBudget,
            lockOwner: $lockOwner,
        );
    }

    /**
     * A probe result as it reaches the job: already through the request's one
     * redaction seam, so it carries no credential to begin with.
     */
    protected function probeResult(?string $content, ?int $responseMs): CheckResult
    {
        return new CheckResult(
            monitorId: '',
            region: 'eu-west',
            checkedAt: new DateTimeImmutable,
            status: MonitorStatus::Up,
            statusCode: 200,
            responseMs: $responseMs,
            errorMessage: null,
            timingDnsMs: 10,
            timingConnectMs: 20,
            timingTlsMs: 30,
            timingTtfbMs: 100,
            timingDownloadMs: 20,
            responseHeaders: ['content-type' => 'application/json'],
            responseBodyPreview: $content !== null ? mb_substr($content, 0, 10240) : null,
            probeRunId: (string) Str::uuid(),
            content: $content,
        );
    }

    /**
     * Run the job through the container, so its `handle()` dependencies resolve
     * the way the worker resolves them.
     */
    protected function runJob(AnalyzeMonitorJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /**
     * Seed the run the accepting request would have created, and return its id.
     */
    protected function startRun(string $teamId): string
    {
        $runId = (string) Str::uuid();

        app(AnalyzeRunStore::class)->start($runId, $teamId, [
            'region' => 'eu-west',
            'status_code' => 200,
            'response_ms' => 180,
        ]);

        return $runId;
    }

    /**
     * Take the per-team in-flight lock exactly as the controller will, and
     * return the owner string it would pass into the job.
     */
    protected function holdInFlightLock(string $teamId): string
    {
        $lock = Cache::lock(AnalyzeMonitorJob::lockName($teamId), 200);

        $this->assertTrue($lock->get(), 'The fixture could not take the lock it means to release.');

        return $lock->owner();
    }

    /**
     * The broadcast payloads recorded by `Event::fake()`, in dispatch order.
     *
     * @return list<array<string, mixed>>
     */
    protected function recordedTicks(): array
    {
        return Event::dispatched(AnalyzeProgressBroadcast::class)
            ->map(static fn (array $event): array => $event[0]->broadcastWith())
            ->values()
            ->all();
    }

    /**
     * A team on [$plan], with the plan set directly: the base MagicStarter team
     * does not fill it.
     */
    protected function makeTeam(string $plan = 'pro'): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $team->forceFill(['plan' => $plan])->save();

        $user->forceFill(['current_team_id' => $team->id])->save();

        return $team;
    }

    /**
     * Stub metric discovery out.
     *
     * Required by every test that gives the probe a BODY: with one, the real
     * service finds candidates and asks a live provider to select among them.
     * Discovery has its own test file.
     */
    protected function stubMetricDiscovery(): void
    {
        $this->app->instance(MetricDiscoveryService::class, new class extends MetricDiscoveryService
        {
            public function __construct() {}

            /**
             * @param  array<string, mixed>  $headers
             * @return list<array<string, mixed>>
             */
            public function discover(
                Monitor $monitor,
                ?string $body,
                string $teamId,
                array $headers = [],
                ?string $locale = null,
            ): array {
                return [];
            }
        });
    }

    /**
     * Bind a {@see HostGuard} that resolves nothing.
     *
     * The real one is the only DNS code in this backend, and the job calls it to
     * assemble the location evidence. Left real, the suite resolves the fixture
     * host from whatever machine runs it, which is a live outbound request in a
     * unit run and a different answer per machine.
     */
    protected function stubHostGuard(): void
    {
        $this->app->instance(HostGuard::class, new class extends HostGuard
        {
            /**
             * @return list<string>
             */
            public function resolvePublicHostIps(string $host): array
            {
                return [];
            }
        });
    }

    /**
     * Bind a {@see TargetLocation} answering a fixed, uninformative location, so
     * no geo provider is ever consulted.
     */
    protected function cannedTargetLocation(): void
    {
        $this->app->instance(TargetLocation::class, new class extends TargetLocation
        {
            /**
             * @param  array<string, string>  $headers
             * @param  list<string>  $ips
             */
            public function resolve(string $url, array $headers, array $ips = []): TargetLocationResult
            {
                return new TargetLocationResult(
                    ips: $ips,
                    cdn: null,
                    country: null,
                    region: null,
                    locationBasis: LocationBasis::Unresolved,
                );
            }
        });
    }
}
