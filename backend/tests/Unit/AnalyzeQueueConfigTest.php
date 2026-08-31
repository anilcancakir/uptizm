<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the queue wiring behind the asynchronous monitor analyze.
 *
 * Analyze is the first job in this repo that needs more wall time than the
 * shared redis connection's `retry_after` allows, and every way of getting that
 * wrong looks like success from the outside. Four independent misconfigurations
 * are each pinned by an assertion here rather than by a comment in the config:
 *
 * 1. The timing chain, and it is FOUR numbers deep here rather than three:
 *    `ai.request_budget_seconds` (the wall time the model calls may spend) sits
 *    under the job's own `$timeout`, which sits under the worker `timeout`, which
 *    sits under the connection's `retry_after` (see the invariant comment in
 *    config/queue.php). Break the top of that chain and Redis hands a
 *    still-running analyze to a second worker at 90 seconds: two AI spends, two
 *    broadcast streams, and two writers on one run's state. Nothing throws, and
 *    the operator sees a result, so only this test would notice.
 * 2. The connection. The chain above is only satisfiable on a connection whose
 *    `retry_after` clears the 170s worker timeout, which the shared `redis` one
 *    (90) does not. That is the whole reason `redis-analyze` exists, and the
 *    tempting repair (raising the shared number) would hold a stuck uptime check
 *    for 200 seconds before re-dispatch instead of 90.
 * 3. Supervisor coverage and its process count. Horizon sizes workers per
 *    environment, so an `analyze` supervisor missing from an environment block is
 *    sized by accident, and one provisioning zero processes is skipped outright
 *    by Horizon's ProvisioningPlan: the queue is consumed by nobody and every run
 *    stays `queued` until its cache key expires, while the operator watches five
 *    spinners.
 * 4. Local consumption. Queue registration in this repo is TWO-PLACE (the note at
 *    the top of config/horizon.php's defaults says so): Horizon drains the queue
 *    on the server and `queue:listen` in composer's `dev` script drains it
 *    locally. With only the server half, every local analyze hangs forever, and a
 *    live verification pass that hand-boots a worker hides it.
 *
 * What this test CANNOT see, so it is worth stating: `retry_after` belongs to the
 * connection a CONSUMER names, not to the Redis list, and both connections share
 * one list namespace. The dev listener asserted below runs on `queue.default`
 * (redis, 90), so a local analyze past 90 seconds is re-run once the listener
 * frees up. Drain it by hand with `--connection=redis-analyze` when that matters.
 */
class AnalyzeQueueConfigTest extends TestCase
{
    /** The dedicated Horizon supervisor name. */
    private const SUPERVISOR = 'analyze';

    /** The queue that supervisor serves. */
    private const QUEUE = 'analyze';

    /** The dedicated queue connection, sized for a job that outlives the shared one. */
    private const CONNECTION = 'redis-analyze';

    /** That connection's `retry_after`, the top of the chain. */
    private const RETRY_AFTER = 200;

    /** The supervisor's worker timeout, the second link. */
    private const WORKER_TIMEOUT = 170;

    /**
     * The `$timeout` of the analyze job the chain protects. The job arrives in a
     * later step; test_the_pinned_job_timeout_matches_the_analyze_job
     * cross-checks this value against the class as soon as it exists, so the
     * chain asserted here cannot drift away from the job it is sized for.
     */
    private const JOB_TIMEOUT = 160;

    /** The analyze job, referenced by name because it lands in a later step. */
    private const JOB_CLASS = 'App\Jobs\AnalyzeMonitorJob';

    /**
     * The memory floor, in MB. A probe response body (1 MB ceiling) is held, then
     * JSON-encoded into a prompt fence, so it exists two or three times over in
     * one worker before the model client's own buffers.
     */
    private const MEMORY_FLOOR = 512;

    /** The supervisor that must never take analyze work. */
    private const SHARED_SUPERVISOR = 'supervisor-1';

    /** That supervisor's worker timeout, which analyze must not have raised. */
    private const SHARED_SUPERVISOR_TIMEOUT = 60;

    /** That supervisor's memory ceiling, which analyze must not have raised. */
    private const SHARED_SUPERVISOR_MEMORY = 128;

    /** The connection every other job rides. */
    private const SHARED_CONNECTION = 'redis';

    /** Its `retry_after`, which analyze must not have raised either. */
    private const SHARED_CONNECTION_RETRY_AFTER = 90;

    /** The queue the dev listener must drain AFTER analyze. */
    private const LOWER_PRIORITY_QUEUE = 'content';

    /**
     * The whole four-number chain holds, in every environment Horizon provisions.
     *
     * Read it top down: the connection releases a reserved job later than the
     * worker kills it, the worker kills the job later than the job gives up on
     * itself, and the job gives up later than the model calls were ever funded
     * to run. Each link is a different failure, and the messages below name which.
     */
    public function test_the_analyze_timing_chain_holds_in_every_horizon_environment(): void
    {
        $budget = config('ai.request_budget_seconds');

        $this->assertIsInt($budget, 'ai.request_budget_seconds no longer resolves to an integer.');

        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $supervisor = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR] ?? [];

            $connection = $supervisor['connection'] ?? null;
            $timeout = $supervisor['timeout'] ?? null;

            $this->assertIsString(
                $connection,
                "The [{$environment}] environment provisions no [analyze] supervisor connection."
            );

            $this->assertIsInt(
                $timeout,
                "The [{$environment}] environment provisions no [analyze] supervisor timeout."
            );

            $retryAfter = config("queue.connections.{$connection}.retry_after");

            $this->assertIsInt(
                $retryAfter,
                "The [{$connection}] queue connection the [{$environment}] analyze supervisor names "
                .'declares no retry_after, so nothing bounds how long a reserved analyze stays reserved.'
            );

            $this->assertGreaterThan(
                $timeout,
                $retryAfter,
                "The [{$environment}] analyze worker timeout must stay under its connection's retry_after, "
                .'or the queue releases a still-running analyze to a second worker: two AI meter spends, '
                .'two broadcast streams, and two writers on one run. This is the first job in the repo '
                .'whose timeout crosses the shared 90, which is why it has a connection of its own.'
            );

            $this->assertGreaterThan(
                self::JOB_TIMEOUT,
                $timeout,
                "The [{$environment}] analyze worker timeout must exceed the job timeout, or the worker "
                .'kills the run before failed() can write the terminal state and broadcast it, and the '
                .'operator is left watching a spinner that never resolves.'
            );

            $this->assertGreaterThan(
                $budget,
                self::JOB_TIMEOUT,
                'The analyze job timeout must exceed ai.request_budget_seconds, or the worker kills the '
                .'job while the budget still believes there is time to start another model call.'
            );
        }
    }

    /**
     * The chain uses the numbers the supervisor was actually sized with.
     *
     * Separate from the ordering test above on purpose: the ordering can be
     * satisfied by numbers nobody derived, and this one catches a value drifting
     * away from the reasoning written beside it in the two config files.
     */
    public function test_the_analyze_chain_uses_the_numbers_it_was_sized_with(): void
    {
        $this->assertSame(
            self::RETRY_AFTER,
            config('queue.connections.'.self::CONNECTION.'.retry_after'),
            'The [redis-analyze] retry_after changed. Re-derive the chain in config/queue.php and '
            .'config/horizon.php rather than only editing one of them.'
        );

        $this->assertSame(
            self::WORKER_TIMEOUT,
            config('horizon.defaults.'.self::SUPERVISOR.'.timeout'),
            'The analyze worker timeout changed. Re-derive the chain, including the job timeout below it.'
        );

        $this->assertSame(
            self::CONNECTION,
            config('horizon.defaults.'.self::SUPERVISOR.'.connection'),
            'The analyze supervisor must run on its own connection: the shared one releases a reserved '
            .'job at 90 seconds, below this supervisor\'s own timeout.'
        );
    }

    /**
     * The shared connection and the shared supervisor were left exactly as they
     * were, which is half of what makes the dedicated ones worth having.
     *
     * Raising the shared `retry_after` to 200 would satisfy the chain above and
     * hold a stuck uptime check for 200 seconds instead of 90 before re-dispatch.
     * Raising supervisor-1's timeout or memory to host analyze would put a
     * 150-second model call in a slot a customer's outage detection needs, at ten
     * processes in production.
     */
    public function test_analyze_did_not_widen_the_shared_connection_or_supervisor(): void
    {
        $this->assertSame(
            self::SHARED_CONNECTION_RETRY_AFTER,
            config('queue.connections.'.self::SHARED_CONNECTION.'.retry_after'),
            'The shared redis retry_after moved. Analyze has its own connection precisely so this one '
            .'can keep releasing a stuck check after 90 seconds.'
        );

        $shared = config('horizon.defaults.'.self::SHARED_SUPERVISOR);

        $this->assertSame(
            self::SHARED_SUPERVISOR_TIMEOUT,
            $shared['timeout'] ?? null,
            'supervisor-1 timeout moved. It runs the customer uptime checks; a long AI job belongs on '
            .'the analyze supervisor, not on a raised ceiling here.'
        );

        $this->assertSame(
            self::SHARED_SUPERVISOR_MEMORY,
            $shared['memory'] ?? null,
            'supervisor-1 memory moved. At seven production processes this ceiling is multiplied by seven; '
            .'the analyze supervisor is where a full response body is allowed to sit.'
        );
    }

    /**
     * Every environment block names the `analyze` supervisor explicitly, so
     * adding an environment cannot silently leave analyze unsized and every run
     * stuck at `queued`.
     */
    public function test_the_analyze_supervisor_is_declared_in_every_horizon_environment(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $this->assertArrayHasKey(
                self::SUPERVISOR,
                $supervisors,
                "The [{$environment}] Horizon environment does not declare the [analyze] supervisor."
            );
        }
    }

    /**
     * Every environment provisions at least one analyze process.
     *
     * Deliberately a floor rather than the exact pin the `previews` and `content`
     * precedents use, and the difference is what each number actually protects.
     * There, one process IS the serialization. Here the run is single-in-flight
     * per team through a cache lock, so this count only decides how many
     * DIFFERENT teams may analyze at once, which is a cost question. Zero is
     * still a correctness failure: Horizon's ProvisioningPlan skips a supervisor
     * provisioning no processes, so the queue is consumed by nobody and every run
     * sits at `queued` with nothing logged anywhere.
     */
    public function test_every_horizon_environment_provisions_at_least_one_analyze_process(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $maxProcesses = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['maxProcesses'] ?? null;

            $this->assertIsInt(
                $maxProcesses,
                "The [{$environment}] environment provisions no [analyze] maxProcesses."
            );

            $this->assertGreaterThanOrEqual(
                1,
                $maxProcesses,
                "The [{$environment}] analyze supervisor provisions no process, so Horizon skips it "
                .'entirely and every analyze run stays queued until its cache key expires.'
            );
        }
    }

    /**
     * Every environment gives an analyze worker room for the body it carries.
     *
     * The job holds a probe response body (1 MB ceiling) and JSON-encodes it into
     * a prompt fence, so the bytes exist several times over in one process. A
     * worker restarted on its memory ceiling mid-run is a lost run, because the
     * job runs with `tries` 1 and an operator is waiting on it.
     */
    public function test_every_horizon_environment_gives_analyze_room_for_a_response_body(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $memory = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['memory'] ?? null;

            $this->assertIsInt(
                $memory,
                "The [{$environment}] environment provisions no [analyze] memory ceiling."
            );

            $this->assertGreaterThanOrEqual(
                self::MEMORY_FLOOR,
                $memory,
                "The [{$environment}] analyze memory ceiling is {$memory}MB, below the "
                .self::MEMORY_FLOOR.'MB a response body held, encoded and prompted needs; a worker '
                .'restarted on its ceiling mid-run loses the run.'
            );
        }
    }

    /**
     * Analyze work stays off the shared supervisor, and the dedicated one serves
     * nothing else.
     *
     * The shared supervisor runs the checks themselves at ten processes in
     * production, with a 60-second timeout. A 150-second model call there is
     * killed at 60 AND occupies a slot an outage needs, which is the one thing
     * analyze must never cost.
     */
    public function test_the_analyze_queue_is_isolated_from_the_shared_supervisor(): void
    {
        $defaults = config('horizon.defaults');

        $this->assertSame(
            [self::QUEUE],
            $defaults[self::SUPERVISOR]['queue'] ?? null,
            'The [analyze] supervisor must serve the analyze queue and nothing else.'
        );

        $this->assertNotContains(
            self::QUEUE,
            $defaults[self::SHARED_SUPERVISOR]['queue'] ?? [],
            'The shared supervisor must not take analyze work: its 60s timeout would kill the run, and '
            .'the slot it occupied is one a customer uptime check needed.'
        );

        foreach ($defaults as $name => $supervisor) {
            if ($name === self::SUPERVISOR) {
                continue;
            }

            $this->assertNotContains(
                self::QUEUE,
                $supervisor['queue'] ?? [],
                "The [{$name}] supervisor also drains the analyze queue, so a run can be picked up by a "
                .'worker sized for something else.'
            );
        }
    }

    /**
     * BOTH halves of the two-place queue registration name analyze.
     *
     * Checking one half is exactly what let this class of gap exist before (see
     * the note at the top of config/horizon.php's defaults): the server half
     * alone means `composer dev` never drains analyze and every local run hangs
     * forever, while a verification pass that hand-boots a worker still passes.
     *
     * Position is not cosmetic either. `queue:listen` drains in the order given,
     * so analyze sits after everything serving a customer check and before
     * `content`, which the two sibling tests already pin ahead of `previews`. An
     * operator is watching an analyze; nobody is watching an archive write.
     */
    public function test_both_halves_of_the_two_place_registration_name_analyze(): void
    {
        $this->assertContains(
            self::QUEUE,
            config('horizon.defaults.'.self::SUPERVISOR.'.queue') ?? [],
            'The server half is missing: no Horizon supervisor drains the analyze queue, so nothing '
            .'runs an analyze in production.'
        );

        $listener = $this->developmentQueueListener();

        $this->assertMatchesRegularExpression(
            '/--queue=[a-z,]+/',
            $listener,
            'The dev queue listener names no queue, so it drains the default queue only.'
        );

        preg_match('/--queue=([a-z,]+)/', $listener, $matches);
        $queues = explode(',', $matches[1]);

        $this->assertContains(
            self::QUEUE,
            $queues,
            'The local half is missing: the dev queue listener does not consume the analyze queue, so '
            .'`composer dev` accepts an analyze and never runs it, and the form spins forever.'
        );

        $this->assertContains(
            self::LOWER_PRIORITY_QUEUE,
            $queues,
            'The dev queue listener no longer consumes content, so the position rule below would '
            .'compare against nothing.'
        );

        $this->assertLessThan(
            array_search(self::LOWER_PRIORITY_QUEUE, $queues, true),
            array_search(self::QUEUE, $queues, true),
            'Analyze must be listed before content: queue:listen drains in the given order, and an '
            .'operator waiting on a form outranks an archive write nobody is watching.'
        );
    }

    /**
     * Keeps the pinned job timeout honest once the analyze job exists, so the
     * chain above cannot silently drift away from the job it protects.
     *
     * Only the timeout is checkable here. Whether the job actually SELECTS the
     * analyze connection is asserted by the job's own test, because a constructor
     * `onConnection()` call leaves no default property for reflection to read.
     */
    public function test_the_pinned_job_timeout_matches_the_analyze_job(): void
    {
        if (! class_exists(self::JOB_CLASS)) {
            $this->markTestSkipped(self::JOB_CLASS.' arrives with the analyze job step.');
        }

        $timeout = (new ReflectionClass(self::JOB_CLASS))->getDefaultProperties()['timeout'] ?? null;

        $this->assertSame(
            self::JOB_TIMEOUT,
            $timeout,
            'The analyze job timeout changed. Re-derive the whole chain (retry_after, worker timeout, '
            .'job timeout, AI budget) instead of only editing the job.'
        );
    }

    /**
     * The declared Horizon environments, guarded so an emptied block cannot turn
     * every loop above into a vacuous pass.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function horizonEnvironments(): array
    {
        $environments = config('horizon.environments');

        $this->assertNotEmpty($environments, 'Horizon declares no environments.');

        return $environments;
    }

    /**
     * The supervisor options Horizon actually provisions for an environment.
     *
     * Mirrors ProvisioningPlan::applyDefaultOptions(), which merges the
     * environment block over `horizon.defaults` with array_replace_recursive.
     *
     * @param  array<string, array<string, mixed>>  $supervisors
     * @return array<string, array<string, mixed>>
     */
    private function effectiveSupervisors(array $supervisors): array
    {
        return array_replace_recursive(config('horizon.defaults'), $supervisors);
    }

    /**
     * The `queue:listen` invocation from the composer `dev` script.
     */
    private function developmentQueueListener(): string
    {
        $composer = json_decode(
            file_get_contents(base_path('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($composer['scripts']['dev'] ?? [] as $line) {
            if (is_string($line) && str_contains($line, 'queue:listen')) {
                return $line;
            }
        }

        $this->fail('The composer [dev] script no longer starts a queue listener.');
    }
}
