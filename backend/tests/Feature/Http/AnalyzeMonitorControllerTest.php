<?php

namespace Tests\Feature\Http;

use App\Enums\AnalyzeRunStatus;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Jobs\AnalyzeMonitorJob;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Monitoring\RelayClient;
use App\Support\Monitoring\AnalyzeRunStore;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\CredentialRedactor;
use DateTimeImmutable;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers `POST /api/v1/monitors/analyze` and `GET /api/v1/monitors/analyze/{run}`:
 * the request half of the "Analyze with AI" flow, which probes a candidate URL
 * and then hands the model half to {@see AnalyzeMonitorJob}.
 *
 * WHAT THIS FILE IS ABOUT AFTER THE ASYNC SPLIT. The request no longer produces
 * an analysis, so nothing here asserts a recommended interval, a service class or
 * a confidence any more; those live in `Tests\Feature\Ai\AnalyzeMonitorJobTest`
 * beside the code that derives them. What is left here is the boundary itself,
 * and every control on the request side of it: the order they run in, the 202
 * contract, the per-team in-flight lock (including the aborts that must not leak
 * it), the daily budget spend and its handover, the credential audit line, and
 * that what crosses into the job carries no credential.
 *
 * THE QUEUE IS FAKED IN `setUp()` AND MUST STAY THAT WAY. {@see AnalyzeMonitorJob}
 * pins `onConnection('redis-analyze')`, so an unfaked dispatch does NOT fall back
 * to `phpunit.xml`'s `QUEUE_CONNECTION=sync`: it dials Redis from the suite.
 *
 * The Cloudflare relay worker is unreachable in CI, so every test that reaches
 * the probe binds a fake {@see RelayClient} (no network). No AI gateway double is
 * needed any longer: with the queue faked, no model code runs in a request at
 * all. The SSRF denylist is exercised without either, because request validation
 * rejects a blocked host before any probe is dispatched.
 */
class AnalyzeMonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_analyze_answers_202_with_the_run_to_poll_and_no_synchronous_analysis(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(202);

        // The run to poll, plus the probe block the client already renders: the
        // probe finished inside this request, so it is the one piece of the old
        // body a 202 can still answer truthfully.
        $this->assertIsString($response->json('data.run_id'));
        $this->assertNotSame('', $response->json('data.run_id'));
        $response->assertJsonPath('data.status', AnalyzeRunStatus::Queued->value);
        $response->assertJsonPath('data.step', 0);
        $response->assertJsonPath('data.steps', []);
        $response->assertJsonPath('data.result', null);
        $response->assertJsonPath('data.probe.region', MonitorRegion::default()->value);
        $response->assertJsonPath('data.probe.status_code', 200);
        $response->assertJsonPath('data.probe.response_ms', 180);

        // THE SYNCHRONOUS BODY IS GONE, and this is the half that would otherwise
        // rot quietly: a branch left in place would keep answering these keys for
        // whichever request happened to take it, and every other assertion here
        // would still pass.
        $response->assertJsonMissingPath('data.recommended_interval_seconds');
        $response->assertJsonMissingPath('data.rationale');
        $response->assertJsonMissingPath('data.confidence');
        $response->assertJsonMissingPath('data.suggested_metrics');
        $response->assertJsonMissingPath('meta');

        // And the work is queued where its own supervisor drains it, on the
        // connection whose `retry_after` is the only one clearing this job's
        // timeout (the properties themselves are pinned by the job's own test).
        Queue::assertPushedOn('analyze', AnalyzeMonitorJob::class);
        $this->assertSame('redis-analyze', $this->dispatchedJob()->connection);
    }

    public function test_the_job_is_handed_the_run_the_request_minted(): void
    {
        // The one linkage that makes the whole feature work: the id in the 202,
        // the entry in the store, and the id the worker reports against are one
        // value. Two of the three agreeing is a client polling a run nothing
        // advances.
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember();

        $runId = (string) $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202)->json('data.run_id');

        $this->assertSame($runId, $this->dispatchedJob()->runId);

        $stored = app(AnalyzeRunStore::class)->find($runId);

        $this->assertIsArray($stored);
        $this->assertSame((string) $team->id, $stored['team_id']);
        $this->assertSame(AnalyzeRunStatus::Queued->value, $stored['status']);
    }

    /**
     * A SECOND CONCURRENT ANALYZE IS REFUSED, and the window this closes is the
     * one the async split opened.
     *
     * Between the plan gate and the worker's trial spend there are now 30 to 150
     * seconds. Three concurrent analyses would all pass a three-use guard and all
     * consume, so the lock is what makes the meter's arithmetic true. Its red
     * phase is measured in `evidence/step-06-lock-and-audit-order-red.md`.
     */
    public function test_a_second_concurrent_analyze_for_the_same_team_is_refused_with_409(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $second = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/other',
        ]);

        $second->assertStatus(409);
        // A message the client renders verbatim, naming the state rather than the
        // mechanism.
        $this->assertStringContainsString(
            'already running',
            strtolower((string) $second->json('message')),
        );

        // And it queued nothing: a refusal that still dispatched would spend a
        // second trial for a run the operator never saw.
        $this->assertCount(1, Queue::pushed(AnalyzeMonitorJob::class));
    }

    public function test_another_teams_analyze_does_not_refuse_this_one(): void
    {
        // The lock is per TEAM. A single global name would read as "one analyze
        // at a time on the whole platform", which no test asserting a 409 for one
        // team would notice.
        $this->fakeRelay(MonitorStatus::Up);

        $this->actingAsTeamMember();
        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $this->actingAsTeamMember();
        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $this->assertCount(2, Queue::pushed(AnalyzeMonitorJob::class));
    }

    /**
     * THE LOCK OUTLIVES THE RESPONSE, and the job holds the only key.
     *
     * A lock released when the response returns would be held for 200
     * milliseconds and close nothing, so this asserts both halves: it is still
     * held after the 202, and the owner string the job was handed is the one that
     * releases it. A drifting owner (a literal at either site, a re-acquire in
     * the worker) leaves a lock nothing can release for the whole TTL, which
     * reads like a rate limiter nobody configured.
     */
    public function test_the_lock_outlives_the_response_and_the_job_holds_its_owner(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $this->assertFalse(
            Cache::lock(AnalyzeMonitorJob::lockName((string) $team->id), 200)->get(),
            'The lock was gone the moment the response returned, so it closed a 200ms window.',
        );

        Cache::restoreLock(
            AnalyzeMonitorJob::lockName((string) $team->id),
            $this->dispatchedJob()->lockOwner,
        )->release();

        $this->assertTrue(
            Cache::lock(AnalyzeMonitorJob::lockName((string) $team->id), 200)->get(),
            'The owner the job was handed does not release the lock the request took.',
        );
    }

    /**
     * A PLAN-WALLED REQUEST LEAVES NO LOCK BEHIND, and this is the assertion the
     * lock's own 409 test cannot make.
     *
     * The release lives in the job, and the job only runs if a dispatch happened,
     * so without the abort path a Free team that hits its wall is locked out of
     * analyze for the full 200-second TTL while every other test here passes.
     * Red phase measured in `evidence/step-06-lock-and-audit-order-red.md`.
     */
    public function test_a_plan_walled_request_leaves_no_in_flight_lock_behind(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember('free');
        $team->forceFill([
            'ai_analysis_trials_used' => (int) config('plans.tiers.0.limits.ai_analysis_trials'),
        ])->save();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(403);

        Queue::assertNothingPushed();
        $this->assertTrue(
            Cache::lock(AnalyzeMonitorJob::lockName((string) $team->id), 200)->get(),
            'The plan wall left the lock held, so a Free team is refused analyze for three minutes after it.',
        );
    }

    public function test_a_probe_that_throws_leaves_no_in_flight_lock_behind(): void
    {
        // The second abort between the acquire and the dispatch. A relay that
        // cannot be reached is an ordinary bad day, and it must not cost the team
        // its analyze slot for three minutes.
        $this->throwingRelay();
        $team = $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(500);

        Queue::assertNothingPushed();
        $this->assertTrue(
            Cache::lock(AnalyzeMonitorJob::lockName((string) $team->id), 200)->get(),
            'A failed probe left the lock held, so the operator cannot retry for three minutes.',
        );
    }

    /**
     * THE AUDIT LINE IS RECORDED BEFORE ANYTHING IS DISPATCHED.
     *
     * Measured at the moment the line fires rather than inferred from the order
     * of two assertions: the spy below reads the faked queue's push count from
     * inside the log call, so the value is 0 only if nothing had been dispatched
     * yet. Moving the line below the dispatch turns that into 1. Red phase
     * measured in `evidence/step-06-lock-and-audit-order-red.md`.
     */
    public function test_the_audit_line_is_recorded_before_anything_is_dispatched(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $pushedWhenAudited = null;
        Log::spy()->shouldReceive('info')->andReturnUsing(
            function (string $message) use (&$pushedWhenAudited): void {
                if (str_contains($message, 'operator-supplied credential')) {
                    $pushedWhenAudited = Queue::pushed(AnalyzeMonitorJob::class)->count();
                }
            },
        );

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => ['type' => 'bearer', 'token' => 'SECRETTOKEN'],
        ])->assertStatus(202);

        $this->assertNotNull($pushedWhenAudited, 'The audit line never fired, so nothing was ordered.');
        $this->assertSame(0, $pushedWhenAudited);
        $this->assertCount(1, Queue::pushed(AnalyzeMonitorJob::class));
    }

    /**
     * AN ATTEMPT WHOSE PROBE THREW IS STILL AUDITED, which is the reason the
     * ordering above is load-bearing rather than aesthetic.
     *
     * This endpoint is a credential-validity oracle by construction and leaves no
     * row behind, so the log line is the only record that a tenant sent a
     * credential to a host. A control that fired after the dispatch would detect
     * only the attempts that got that far, and would miss exactly the ones an
     * attacker probing for a valid credential produces.
     */
    public function test_an_attempt_whose_probe_threw_is_still_audited(): void
    {
        Log::spy();
        $this->throwingRelay();
        $team = $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => ['type' => 'bearer', 'token' => 'SECRETTOKEN'],
        ])->assertStatus(500);

        Queue::assertNothingPushed();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($team): bool {
                return str_contains($message, 'operator-supplied credential')
                    && $context['team_id'] === (string) $team->id
                    && $context['host'] === 'example.com';
            })
            ->once();
    }

    public function test_the_request_no_longer_spends_the_plan_trial_meter(): void
    {
        // The call site deleted from this controller. The trial is charged by the
        // worker, only once a model actually answered, so with the queue faked
        // nothing may move: a request-side spend would charge a Free team for an
        // analysis that never ran.
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember('free');

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
    }

    /**
     * The daily AI budget IS spent here, and the job is handed the ANSWER.
     *
     * A cap of one makes both halves observable in one test: the first accept
     * spends the unit and hands down `true`, the second finds the counter over
     * cap and hands down `false`. Without the handover the worker would either
     * re-spend the budget or silently assume it was within it.
     */
    public function test_the_request_spends_the_daily_ai_budget_and_hands_the_answer_to_the_job(): void
    {
        config(['ai.budget.daily_per_team' => 1]);
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        // The job releases the lock, and the faked queue never runs it, so a
        // second analyze in one sitting is modelled by releasing it here.
        $this->releaseInFlightLock((string) $team->id);

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $jobs = Queue::pushed(AnalyzeMonitorJob::class);

        $this->assertCount(2, $jobs);
        $this->assertTrue($jobs[0]->withinBudget, 'The first analyze of the day was inside the cap.');
        $this->assertFalse($jobs[1]->withinBudget, 'The second exceeded a cap of one and must degrade.');
    }

    public function test_analyze_is_open_on_free_within_its_metered_allowance(): void
    {
        // Free grants a metered number of AI setups, and a team with one left is
        // accepted. The count-down itself is the worker's, which is why this
        // asserts the acceptance and not a remaining number the 202 cannot carry.
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember('free');
        $allowance = (int) config('plans.tiers.0.limits.ai_analysis_trials');

        $this->assertGreaterThan(0, $allowance, 'Free must grant AI setups.');

        $team->forceFill(['ai_analysis_trials_used' => $allowance - 1])->save();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        Queue::assertPushed(AnalyzeMonitorJob::class);
    }

    public function test_analyze_walls_a_free_team_that_spent_its_allowance(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember('free');
        $team->forceFill([
            'ai_analysis_trials_used' => (int) config('plans.tiers.0.limits.ai_analysis_trials'),
        ])->save();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('Pro plan', (string) $response->json('message'));
        // The tier also travels machine-readably, so the client can offer an
        // upgrade action for exactly this plan instead of parsing the sentence.
        $response->assertJsonPath('upgrade.required_plan', 'pro');
        $response->assertJsonPath('upgrade.feature', 'AI monitor analysis');
    }

    public function test_analyze_does_not_spend_an_allowance_on_a_rejected_url(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember('free');

        // A validation failure never reaches the probe, so it must not cost the
        // user one of their setups, and it must queue nothing.
        $this->postJson('/api/v1/monitors/analyze', ['url' => 'not-a-url'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(0, (int) $team->fresh()->ai_analysis_trials_used);
    }

    public function test_analyze_rejects_a_cloud_metadata_ssrf_host(): void
    {
        // The fake relay would answer "up" if a probe ever ran; asserting the
        // 422 proves the SSRF guard rejects before any dispatch happens.
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'http://169.254.169.254/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        Queue::assertNothingPushed();
    }

    public function test_analyze_refuses_a_url_carrying_a_credential_in_its_userinfo(): void
    {
        // Laravel's `url` rule accepts `https://user:pass@host/path` (measured),
        // and the job hands the URL to the prompt as a TRUSTED fact on the turn
        // that also holds the web-search tool. The premise that makes a free-text
        // search query safe is that nothing secret is in the model's context, so
        // this is the one inlet that premise cannot survive, and the redaction
        // seam never sees it.
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://ops:s3cr3t@example.com/health',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
        // Refused rather than stripped: an operator who pasted a credential is
        // told, instead of quietly having it removed and the target probed
        // without it.
        $this->assertStringNotContainsString('s3cr3t', $response->getContent());
    }

    public function test_analyze_validates_the_credential_shape(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        // The shared `ValidatesAuthConfig` rules apply here exactly as they do
        // on create: a basic credential without its password is refused before
        // any probe runs, rather than probing unauthenticated and reporting a
        // 401 as the target's own answer.
        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => ['type' => 'basic', 'username' => 'ops'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('auth_config.password');
    }

    public function test_analyze_probes_the_target_with_the_submitted_credential(): void
    {
        $relay = $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => [
                'type' => 'basic',
                'username' => 'ops',
                'password' => 'SECRETPASSWORD',
            ],
        ])->assertStatus(202);

        // Read the ACCESSOR and never `getAttributes()`: the `encrypted:array`
        // cast holds ciphertext even on this unsaved instance, and
        // `RelayClient::buildSpec()` reads the decrypted array. Asserting on the
        // raw attribute would chase a ghost.
        $probed = $relay->probed;
        $this->assertInstanceOf(Monitor::class, $probed);
        $this->assertSame([
            'type' => 'basic',
            'username' => 'ops',
            'password' => 'SECRETPASSWORD',
        ], $probed->auth_config);

        // Transient means transient: an analyze leaves no row behind, which is
        // also why its log line is the only record that it happened.
        $this->assertFalse($probed->exists);
        $this->assertSame(0, Monitor::query()->count());
    }

    /**
     * WHAT CROSSES THE BOUNDARY CARRIES NO CREDENTIAL.
     *
     * The discriminating case for the whole control, and it is why the seam runs
     * in the request rather than in the worker: the relay sends
     * `Basic base64("user:pass")`, so a debug page echoing its request headers
     * prints THAT and never the pair, which is why a redactor built from the
     * submitted username and password would pass every other test here and fail
     * this one.
     *
     * The marker assertion comes FIRST and is not decoration: a credential is
     * absent from a body that never carried one too, so without it every negative
     * below could pass vacuously.
     */
    public function test_the_dispatched_job_carries_the_redacted_probe_and_never_the_credential(): void
    {
        $secret = 'SECRETPASSWORD';
        $wireForm = base64_encode('ops:'.$secret);

        $this->fakeRelay(MonitorStatus::Up, $this->cloudflareHeaders(), $this->echoingBody($wireForm));
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => [
                'type' => 'basic',
                'username' => 'ops',
                'password' => $secret,
            ],
        ])->assertStatus(202);

        $job = $this->dispatchedJob();

        $this->assertStringContainsString(CredentialRedactor::MARKER, (string) $job->probe->content);

        // The payload as the queue would write it, plus the two fields the echo
        // lands in. `serialize()` and not the queue's own payload, because
        // `ShouldBeEncrypted` would hide the strings behind ciphertext at that
        // layer, which is protection at rest rather than the design property.
        foreach ([$job->probe->content, $job->probe->responseBodyPreview, serialize($job)] as $rendered) {
            $this->assertStringNotContainsString($wireForm, (string) $rendered);
            $this->assertStringNotContainsString($secret, (string) $rendered);
        }

        // And nothing names the column either: the transient monitor the relay
        // probed is rebuilt inside the job, credential-free, precisely so this
        // string cannot appear (see the job's own class docblock).
        $this->assertStringNotContainsString('auth_config', serialize($job));
    }

    /**
     * THE HEADER ALLOWLIST RUNS ABOVE THE CUT.
     *
     * By NAME, and that is the whole reason it cannot move into the worker: the
     * redactor masks the operator's submitted VALUE, so a `Set-Cookie` the target
     * minted in response to that credential is an authenticated session token it
     * has never seen. The order is pinned too, because the allowlist iterates
     * itself rather than the input, so a hostile target cannot influence what a
     * prompt reads first.
     */
    public function test_the_dispatched_job_receives_only_the_allowlisted_headers(): void
    {
        $this->fakeRelay(MonitorStatus::Up, [
            ...$this->cloudflareHeaders(),
            'Set-Cookie' => 'session=SECRETVALUE; Path=/; HttpOnly',
            'Authorization' => 'Bearer SECRETVALUE',
            'WWW-Authenticate' => 'Basic realm="SECRETVALUE"',
        ], $this->healthBody());
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $job = $this->dispatchedJob();

        $this->assertSame(
            ['content-type', 'server', 'cf-cache-status', 'cf-ray'],
            array_keys($job->headers),
        );

        // The NAMES are withheld too: "this target sent a Set-Cookie" is itself
        // evidence the setup prompt has no consumer for.
        $rendered = strtolower(serialize($job->headers));

        foreach (['set-cookie', 'authorization', 'www-authenticate', 'secretvalue'] as $name) {
            $this->assertStringNotContainsString($name, $rendered);
        }
    }

    public function test_a_credentialled_analyze_is_logged_with_the_host_and_the_type_and_never_a_value(): void
    {
        Log::spy();
        $this->fakeRelay(MonitorStatus::Up);
        $team = $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health?token=QUERYSECRET',
            'auth_config' => ['type' => 'bearer', 'token' => 'SECRETTOKEN'],
        ])->assertStatus(202);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($team): bool {
                $rendered = $message.' '.json_encode($context);

                return str_contains($message, 'operator-supplied credential')
                    && $context['team_id'] === (string) $team->id
                    // The HOST, not the URL: a monitor target is frequently
                    // `…/health?token=…` and a log line is a place a query
                    // string would sit forever.
                    && $context['host'] === 'example.com'
                    && $context['auth_type'] === 'bearer'
                    && ! str_contains($rendered, 'SECRETTOKEN')
                    && ! str_contains($rendered, 'QUERYSECRET');
            })
            ->once();
    }

    public function test_an_auth_config_of_type_none_behaves_as_an_unauthenticated_analyze(): void
    {
        Log::spy();
        $relay = $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
            'auth_config' => ['type' => 'none'],
        ])->assertStatus(202);

        // The worker sends no header for `none`, so nothing was exposed and the
        // audit line stays silent: the same boundary `CredentialRedactor::for()`
        // draws, and the reason the ordinary path gains no noise.
        $this->assertSame(['type' => 'none'], $relay->probed?->auth_config);
        Log::shouldNotHaveReceived('info');
    }

    public function test_the_job_is_handed_the_operators_stored_locale(): void
    {
        // The wiring, which is the half a unit test cannot reach: the language
        // has to travel from the `users.locale` COLUMN into the payload the
        // discovery prompt is built from, and every layer in that chain defaults
        // to English, so a break anywhere reads as "the model ignored the
        // instruction" rather than as a missing argument.
        //
        // The column and not `Accept-Language`, deliberately: the header is
        // client state that changes with a browser, and these labels are
        // PERSISTED the moment the operator accepts a suggestion.
        $this->fakeRelay(MonitorStatus::Up, [], '{"latency_ms": 12.5}');
        $this->actingAsTeamMember();
        auth()->user()->forceFill(['locale' => 'tr'])->save();

        $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202);

        $this->assertSame('tr', $this->dispatchedJob()->locale);
    }

    public function test_an_unpinned_analyze_probes_from_eu_central_and_says_so(): void
    {
        // The one place the wire value is SPELLED OUT. Every other assertion in
        // this file reads `MonitorRegion::default()`, which keeps them honest
        // about their own subject but could not catch the default changing, so
        // this test carries the literal on purpose.
        //
        // Two halves, because only together do they mean anything: the probe has
        // to actually LEAVE from eu-central (read off the transient monitor the
        // relay was handed), and the accepted run has to SAY eu-central, both in
        // its probe block and in what the worker is told to reason about.
        $relay = $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(202);
        $this->assertSame(['eu-central'], $relay->probed?->regions, 'the probe has to leave from the default region');
        $response->assertJsonPath('data.probe.region', 'eu-central');
        $this->assertSame('eu-central', $this->dispatchedJob()->region);
    }

    public function test_the_poll_answers_the_run_the_202_minted(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $runId = (string) $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ])->assertStatus(202)->json('data.run_id');

        // ONE SHAPE FOR BOTH endpoints, so the client decodes one payload: the
        // 202 is the run's first snapshot and the poll is every later one.
        $this->getJson('/api/v1/monitors/analyze/'.$runId)
            ->assertStatus(200)
            ->assertJsonPath('data.run_id', $runId)
            ->assertJsonPath('data.status', AnalyzeRunStatus::Queued->value)
            ->assertJsonPath('data.step', 0)
            ->assertJsonPath('data.probe.response_ms', 180)
            ->assertJsonPath('data.result', null);
    }

    public function test_the_poll_reports_progress_before_a_result_exists(): void
    {
        $team = $this->actingAsTeamMember();
        $runId = $this->seedRun((string) $team->id);

        app(AnalyzeRunStore::class)->advance($runId, 2, AnalyzeRunStore::STATE_SKIPPED);

        $this->getJson('/api/v1/monitors/analyze/'.$runId)
            ->assertStatus(200)
            ->assertJsonPath('data.status', AnalyzeRunStatus::Analyzing->value)
            ->assertJsonPath('data.step', 2)
            // `skipped` reaches the client verbatim: a step that genuinely did
            // not run is not a step still running, and collapsing the two is
            // what hangs the form on work nothing was going to do.
            ->assertJsonPath('data.steps.2', AnalyzeRunStore::STATE_SKIPPED)
            ->assertJsonPath('data.result', null);
    }

    public function test_the_poll_returns_the_completed_result_verbatim(): void
    {
        // The worker's payload, unchanged: `data` prefills the create form and
        // `meta` carries the number the 202 can no longer answer, since the trial
        // is spent by a worker long after the request returned.
        $team = $this->actingAsTeamMember();
        $runId = $this->seedRun((string) $team->id, [
            'data' => [
                'url' => 'https://example.com/health',
                'name' => 'example.com',
                'recommended_interval_seconds' => 60,
            ],
            'meta' => ['ai_analysis_trials_remaining' => 2],
        ]);

        $this->getJson('/api/v1/monitors/analyze/'.$runId)
            ->assertStatus(200)
            ->assertJsonPath('data.status', AnalyzeRunStatus::Completed->value)
            ->assertJsonPath('data.result.data.name', 'example.com')
            ->assertJsonPath('data.result.data.recommended_interval_seconds', 60)
            ->assertJsonPath('data.result.meta.ai_analysis_trials_remaining', 2);
    }

    public function test_the_poll_masks_another_teams_run_as_404(): void
    {
        // AUTHORISED ON `current_team_id`, NEVER ON POSSESSION OF THE RUN ID. The
        // id travels through a 202 body, a Redis key and every teammate's socket,
        // so treating it as a bearer token would make one leaked log line a read
        // of another team's analysis. 404 and not 403, per this file's
        // cross-team convention: a 403 confirms the run exists.
        $owner = $this->actingAsTeamMember();
        $runId = $this->seedRun((string) $owner->id, [
            'data' => ['name' => 'example.com'],
            'meta' => ['ai_analysis_trials_remaining' => null],
        ]);

        $this->actingAsTeamMember();

        $response = $this->getJson('/api/v1/monitors/analyze/'.$runId);

        $response->assertStatus(404);
        $this->assertStringNotContainsString('example.com', $response->getContent());
    }

    public function test_the_poll_masks_an_unknown_or_evicted_run_as_404(): void
    {
        // A MISSING RUN IS A REAL STATE, not an impossible one: this store lives
        // in a Redis running `volatile-lru` under a 512 MB ceiling, and the entry
        // expires on its own too. The 404 is what tells the client to stop
        // polling and say "run it again"; answering 200 with `queued` for a run
        // nothing will ever advance is the eternal spinner.
        $this->actingAsTeamMember();

        $this->getJson('/api/v1/monitors/analyze/'.Str::uuid())->assertStatus(404);
    }

    public function test_the_poll_requires_authentication(): void
    {
        $this->getJson('/api/v1/monitors/analyze/'.Str::uuid())->assertStatus(401);
    }

    public function test_analyze_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(401);
    }

    public function test_analyze_requires_a_current_team(): void
    {
        $this->fakeRelay(MonitorStatus::Up);

        $user = User::factory()->create(['current_team_id' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'https://example.com/health',
        ]);

        $response->assertStatus(403);
    }

    public function test_analyze_validates_the_url(): void
    {
        $this->fakeRelay(MonitorStatus::Up);
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/monitors/analyze', [
            'url' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('url');
    }

    public function test_the_deterministic_slo_table_stays_inside_the_catalogs_the_gateway_owns(): void
    {
        // The deterministic path names a service class and an SLO target without
        // a model, so its table is the one place either catalog can drift
        // unnoticed: a value outside the schema's enum is not a prefill the
        // operator's form can hold. The table stays on the controller and the job
        // READS it; a copy would be a twin site.
        $table = MonitorController::SLO_TARGET_BY_SERVICE_CLASS;

        $this->assertSame(
            LaravelAiAnalysisGateway::SERVICE_CLASSES,
            array_keys($table),
            'Every service class needs a target, and none may be invented.',
        );

        foreach ($table as $serviceClass => $target) {
            $this->assertContains($target, LaravelAiAnalysisGateway::SLO_TARGETS, $serviceClass);
        }

        // The two ends of the table carry its reasoning: nothing in one probe
        // justifies the strictest target, and an unread service gets none.
        $this->assertNotContains('99.99', $table);
        $this->assertSame('none', $table['unknown']);
        $this->assertNotSame($table['health_endpoint'], $table['web_page']);
    }

    public function test_analyze_is_throttled_per_actor(): void
    {
        // One accepted request still costs a live relay probe, and `api/v1` never
        // calls throttleApi(), so the named limiter is the only thing bounding
        // how fast one member can spend it. The in-flight lock is a DIFFERENT
        // control (concurrency, not rate), which is why it is released between
        // attempts below: without that release this loop would measure the lock's
        // 409 and never reach the limiter at all.
        $this->fakeRelay(MonitorStatus::Up);
        // Pro entitles AI analysis outright, so a 403 plan wall can never be
        // mistaken here for the 429 this test is looking for.
        $team = $this->actingAsTeamMember('pro');

        $statuses = [];

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $statuses[] = $this->postJson('/api/v1/monitors/analyze', [
                'url' => 'https://example.com/health',
            ])->getStatusCode();

            if (end($statuses) === 429) {
                break;
            }

            $this->releaseInFlightLock((string) $team->id);
        }

        $this->assertContains(429, $statuses, 'The analyze route must carry a limiter.');
        // The floor is what keeps the limiter off a legitimate operator: a human
        // comparing two candidate URLs presses this button several times in one
        // sitting.
        $this->assertGreaterThanOrEqual(
            5,
            count(array_filter($statuses, fn (int $status): bool => $status === 202)),
            'A human clicking Analyze must get at least five a minute.',
        );
    }

    public function test_both_models_one_analyze_calls_inherit_the_surface_the_deployment_configured(): void
    {
        // Re-evaluating the config FILE rather than reading the resolved value,
        // because the fallback chain only shows itself while `env()` is being
        // read. A deployment that has moved the AI surface to another provider
        // sets `AI_DEFAULT` and `AI_TRIAGE_MODEL` and nothing else, and a literal
        // default here would ask THAT provider for an Anthropic-native id it does
        // not serve. The gateway's own degrade would then answer deterministically
        // on every run with only a log line, so the feature would ship dark
        // and look healthy: the worst failure shape this endpoint has.
        //
        // BOTH keys, because one analyze spends both: the suggestion turn reads
        // `analysis.model` and `MetricDiscoveryService` reads
        // `metric_discovery.model` on the same run. Asserting only the first
        // would let the second regress to a literal id and answer every run
        // with an empty `suggested_metrics`, green suite and all.
        //
        // The override has to move all THREE channels, and the earlier version of
        // this test measured nothing because it moved one. `Env::getRepository()`
        // reads `$_SERVER` and `$_ENV` BEFORE `getenv()`, so a bare `putenv()` is
        // inert for any key the loaded `.env` already holds, which is every key
        // here. It passed on a machine whose `.env` happened to carry the expected
        // value and failed in CI, where `.env.example` carries another.
        //
        // The sentinel is deliberately a model id no `.env` in this repo contains,
        // so the assertion cannot come out true by inheriting the environment.
        $sentinel = 'test-provider/sentinel-model';
        $keys = ['AI_TRIAGE_MODEL' => $sentinel, 'AI_ANALYSIS_MODEL' => null, 'AI_METRIC_DISCOVERY_MODEL' => null];
        $previous = [];

        foreach ($keys as $key => $value) {
            // `=== false` rather than a falsy test: an empty string is a value
            // the restore has to put back, and `getenv()` answers a miss with
            // `false`. Collapsing the two would leak an unset key into whatever
            // test the parallel runner schedules next in this process.
            $current = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            $previous[$key] = $current === false ? null : $current;

            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);

                continue;
            }

            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }

        try {
            $config = require config_path('ai.php');

            $this->assertSame($sentinel, $config['analysis']['model']);
            $this->assertSame($sentinel, $config['metric_discovery']['model']);
            $this->assertSame($config['triage']['model'], $config['analysis']['model']);
            $this->assertSame($config['triage']['model'], $config['metric_discovery']['model']);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                    putenv($key);

                    continue;
                }

                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv($key.'='.$value);
            }
        }
    }

    /**
     * Authenticate as a fresh user owning a personal team.
     */
    protected function actingAsTeamMember(string $plan = 'pro'): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
        // AI monitor analysis is an analysis-tier (Pro+) feature; the base
        // MagicStarter Team does not fill `plan`, so set it directly.
        $team->forceFill(['plan' => $plan])->save();

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    /**
     * The single {@see AnalyzeMonitorJob} the request under test dispatched.
     *
     * Read off the faked queue rather than reconstructed, so every assertion
     * about the boundary is made against the object that actually crossed it.
     */
    protected function dispatchedJob(): AnalyzeMonitorJob
    {
        $job = Queue::pushed(AnalyzeMonitorJob::class)->first();

        $this->assertInstanceOf(
            AnalyzeMonitorJob::class,
            $job,
            'No analyze job was dispatched, so there is no boundary to assert against.',
        );

        return $job;
    }

    /**
     * Free the per-team in-flight lock, standing in for the completed run that
     * would have freed it.
     *
     * The release lives in the job ({@see AnalyzeMonitorJob::releaseLock()}), and
     * a faked queue never runs one, so any test that posts twice for one team has
     * to model the first run finishing. `forceRelease()` because the owner string
     * belongs to the request that took it.
     */
    protected function releaseInFlightLock(string $teamId): void
    {
        Cache::lock(AnalyzeMonitorJob::lockName($teamId))->forceRelease();
    }

    /**
     * Seed a run the way the accepting request would have, and return its id.
     *
     * [$result] completes it, carrying the `{data, meta}` payload the worker
     * writes and the poll returns verbatim.
     *
     * @param  array<string, mixed>|null  $result
     */
    protected function seedRun(string $teamId, ?array $result = null): string
    {
        $runId = (string) Str::uuid();
        $runs = app(AnalyzeRunStore::class);

        $runs->start($runId, $teamId, [
            'region' => MonitorRegion::default()->value,
            'status_code' => 200,
            'response_ms' => 180,
        ]);

        if ($result !== null) {
            $runs->advance($runId, AnalyzeMonitorJob::STEP_DISCOVERY, AnalyzeRunStore::STATE_DONE);
            $runs->complete($runId, $result);
        }

        return $runId;
    }

    /**
     * Bind a fake {@see RelayClient} so the analyze probe never hits the
     * network: the transient monitor it is handed resolves to a fixed result.
     *
     * [$headers] and [$content] are what the evidence pipeline reads, so they
     * default to what a bare probe carries (none, and no captured body) and a
     * test that cares supplies a realistic set.
     *
     * The double is RETURNED and records the transient monitor it was handed,
     * because that instance is the whole probe spec: the only honest way to ask
     * "did the credential actually reach the target" is to read what the relay
     * was asked to send.
     *
     * @param  array<string, string>  $headers  RAW response headers, in the target's own
     *                                          casing, exactly as the worker returns them.
     */
    protected function fakeRelay(MonitorStatus $status, array $headers = [], ?string $content = null): object
    {
        $double = new class($status, $headers, $content) extends RelayClient
        {
            public ?Monitor $probed = null;

            /**
             * @param  array<string, string>  $headers
             */
            public function __construct(
                private readonly MonitorStatus $status,
                private readonly array $headers,
                private readonly ?string $content,
            ) {}

            public function dispatch(Monitor $monitor, string $region): CheckResult
            {
                $this->probed = $monitor;

                return new CheckResult(
                    monitorId: (string) ($monitor->id ?? ''),
                    region: $region,
                    checkedAt: new DateTimeImmutable,
                    status: $this->status,
                    statusCode: $this->status === MonitorStatus::Up ? 200 : 503,
                    responseMs: 180,
                    errorMessage: null,
                    timingDnsMs: 10,
                    timingConnectMs: 20,
                    timingTlsMs: 30,
                    timingTtfbMs: 100,
                    timingDownloadMs: 20,
                    responseHeaders: $this->headers,
                    // The worker sends a 10 KiB preview beside the full body,
                    // so a fake that carries one without the other would let
                    // the prompt look tidier than it is.
                    responseBodyPreview: $this->content !== null
                        ? mb_substr($this->content, 0, 10240)
                        : null,
                    probeRunId: (string) Str::uuid(),
                    content: $this->content,
                );
            }
        };

        $this->app->instance(RelayClient::class, $double);

        return $double;
    }

    /**
     * Bind a {@see RelayClient} that cannot reach the worker at all.
     *
     * The abort between the lock's acquire and the dispatch that is NOT a plan
     * wall: a client exception out of the relay. It is deliberately unhandled
     * here, exactly as it was before the split, so what this exercises is the
     * lock's release on a throw rather than a new degrade.
     */
    protected function throwingRelay(): void
    {
        $this->app->instance(RelayClient::class, new class extends RelayClient
        {
            public function __construct() {}

            public function dispatch(Monitor $monitor, string $region): CheckResult
            {
                throw new ConnectionException('The relay could not be reached.');
            }
        });
    }

    /**
     * A realistic Cloudflare-fronted response header set, in the target's own
     * casing, including two names the allowlist drops.
     *
     * @return array<string, string>
     */
    protected function cloudflareHeaders(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Server' => 'cloudflare',
            'CF-RAY' => '8f2b1c9a4e7d0123-FRA',
            'CF-Cache-Status' => 'DYNAMIC',
            'Strict-Transport-Security' => 'max-age=31536000',
            'X-Secret-Token' => 'nothing-unenumerated-survives',
        ];
    }

    /**
     * A health body that echoes the request's own `Authorization` header, the
     * exact shape the redactor exists for.
     *
     * Written inline rather than added to `tests/fixtures/content/` because the
     * echoed value has to be built from the credential the test submits; a
     * fixture file would have to hardcode one and would then keep passing after
     * the test changed its secret.
     *
     * The echo sits FIRST so it lands inside the 10 KiB body preview as well as
     * in the full body: a redactor that missed would then show up in both rather
     * than in whichever one happened to be read.
     */
    protected function echoingBody(string $wireForm): string
    {
        return (string) json_encode([
            'request' => [
                'headers' => [
                    'authorization' => 'Basic '.$wireForm,
                ],
            ],
            'status' => 'ok',
            'latency_ms' => 42,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * The IETF-shaped health payload fixture, shared with the digest's own tests.
     */
    protected function healthBody(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/content/health-endpoint.json'));
    }
}
