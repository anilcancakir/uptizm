<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the queue wiring behind the monitor-content archive write.
 *
 * A broken archive write is invisible, and worse than invisible: the claim row
 * is inserted by the check pipeline BEFORE the job is dispatched, so a job that
 * is never consumed leaves a row with no blob behind it. Every later identical
 * body then reads as already-archived, retention keeps touching the row instead
 * of pruning it, and the download endpoint 404s forever. Four independent
 * misconfigurations produce exactly that, so each is pinned by an assertion here
 * instead of by a comment in the config file:
 *
 * 1. The timing chain. Laravel requires the job timeout to stay below the worker
 *    timeout, and the worker timeout below the connection's `retry_after`;
 *    otherwise the queue hands a still-running job to a second worker (see the
 *    invariant comment in config/queue.php). Here that means two workers writing
 *    the same content-addressed blob through one FUSE mount.
 * 2. Supervisor coverage. Horizon sizes its workers per environment, so a
 *    `content` supervisor missing from an environment block is sized by accident
 *    rather than deliberately, and one provisioning zero processes is skipped
 *    outright by Horizon's ProvisioningPlan.
 * 3. Process count. The archive writes through an rclone FUSE mount that
 *    sustains roughly two file operations a second; the write path is built to
 *    be serial and one process is what keeps it that way.
 * 4. Local consumption, and its ORDER. `queue:listen` drains only the queues it
 *    is given, in the order given, so a dev loop that never names `content`
 *    never archives anything, and one that names it after `previews` lets a
 *    browser render starve the archive.
 */
class ContentQueueConfigTest extends TestCase
{
    /**
     * The `$timeout` of the archive job the chain below protects, cross-checked
     * against the class itself by
     * test_the_pinned_job_timeout_matches_the_archive_job, so the chain asserted
     * here cannot drift away from the job it is sized for.
     */
    private const JOB_TIMEOUT = 270;

    /**
     * The connection whose `retry_after` is the wall the chain fits under.
     *
     * NOT the shared `redis`. This queue moved to its own connection on
     * 2026-09-01 because the archive's budget went past the shared 90; reading
     * the wrong connection here would assert the chain against a number no
     * worker on this queue uses, which is the failure the assertion exists for.
     */
    private const CONNECTION = 'redis-content';

    /** The archive job. */
    private const JOB_CLASS = 'App\Jobs\ArchiveContent';

    /** The dedicated Horizon supervisor name. */
    private const SUPERVISOR = 'content';

    /** The queue that supervisor serves. */
    private const QUEUE = 'content';

    /** The supervisor that must never take archive work. */
    private const SHARED_SUPERVISOR = 'supervisor-1';

    /** The queue the dev listener must drain AFTER the archive queue. */
    private const LOWER_PRIORITY_QUEUE = 'previews';

    /**
     * The archive job's timeout stays under the worker timeout, and the worker
     * timeout stays under the connection's `retry_after`, in every environment
     * Horizon provisions.
     */
    public function test_the_content_timing_chain_holds_in_every_horizon_environment(): void
    {
        $retryAfter = config('queue.connections.'.self::CONNECTION.'.retry_after');

        $this->assertIsInt(
            $retryAfter,
            'The ['.self::CONNECTION.'] queue connection no longer declares a retry_after.'
        );

        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $timeout = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['timeout'] ?? null;

            $this->assertIsInt(
                $timeout,
                "The [{$environment}] environment provisions no [content] supervisor timeout."
            );

            $this->assertGreaterThan(
                self::JOB_TIMEOUT,
                $timeout,
                "The [{$environment}] content worker timeout must exceed the job timeout, "
                .'or the worker kills the write before the job can run its own failure hook, '
                .'and that hook is the only thing that releases the claimed version row.'
            );

            $this->assertLessThan(
                $retryAfter,
                $timeout,
                "The [{$environment}] content worker timeout must stay under retry_after, "
                .'or the queue releases a still-running write to a second worker and two '
                .'processes write the same blob through one FUSE mount.'
            );
        }
    }

    /**
     * Every environment block names the `content` supervisor explicitly, so
     * adding an environment cannot silently leave the archive unsized and its
     * claim rows blobless.
     */
    public function test_the_content_supervisor_is_declared_in_every_horizon_environment(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $this->assertArrayHasKey(
                self::SUPERVISOR,
                $supervisors,
                "The [{$environment}] Horizon environment does not declare the [content] supervisor."
            );
        }
    }

    /**
     * Every environment provisions at least two content processes, and few.
     *
     * Both bounds are failures, and different ones.
     *
     * Fewer than two is the one that changed. It used to be exactly one, on the
     * argument that the mount serialises writers anyway so a second buys no
     * throughput. That argument is still true about THROUGHPUT and stopped being
     * the whole story on 2026-09-01, when the job budget went to 270 seconds to
     * outlast a rate-limited directory listing: behind a single consumer, a
     * budget that long means one stalled write holds the entire lane for the
     * length of it, so the raise would have traded a lost archive for a blocked
     * queue. The second process is availability, not capacity. (Zero remains its
     * own failure: Horizon's ProvisioningPlan skips the supervisor outright, the
     * queue is never consumed, and every claim row stays blobless with nothing
     * logged.)
     *
     * The upper bound is the original reasoning, unchanged. The mount is the
     * bottleneck at roughly two file operations a second and an archive spends
     * three of them, so more workers only lengthen the stall each one sits in.
     * The write stays CORRECT under concurrency either way (content-addressed
     * target, per-process staging name), so this is a throughput bound and never
     * a correctness one.
     *
     * Moving either bound needs the mount measured again, which is why it cannot
     * be done by editing config alone.
     */
    public function test_every_horizon_environment_provisions_a_small_content_pool(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $maxProcesses = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['maxProcesses'] ?? null;

            $this->assertIsInt(
                $maxProcesses,
                "The [{$environment}] environment provisions no [content] maxProcesses."
            );

            $this->assertGreaterThanOrEqual(
                2,
                $maxProcesses,
                "The [{$environment}] content supervisor must provision at least two processes: "
                .'zero leaves every claimed version row without a blob, and one lets a single '
                .'stalled write hold the lane for the whole '.self::JOB_TIMEOUT.'s job budget.'
            );

            $this->assertLessThanOrEqual(
                2,
                $maxProcesses,
                "The [{$environment}] content supervisor provisions more processes than the mount "
                .'can use: it sustains roughly two file operations a second and an archive spends '
                .'three, so extra workers only lengthen the stall each one sits in.'
            );
        }
    }

    /**
     * Archive work stays off the shared supervisor, and the dedicated one serves
     * the queue the archive actually dispatches onto.
     *
     * The shared supervisor runs the checks themselves at ten processes in
     * production; a stalled FUSE write parked in one of those slots costs a
     * monitoring probe, which is the one thing the archive must never do.
     */
    public function test_the_content_queue_is_isolated_from_the_shared_supervisor(): void
    {
        $defaults = config('horizon.defaults');

        $this->assertSame(
            [self::QUEUE],
            $defaults[self::SUPERVISOR]['queue'] ?? null,
            'The [content] supervisor must serve the content queue and nothing else.'
        );

        $this->assertNotContains(
            self::QUEUE,
            $defaults[self::SHARED_SUPERVISOR]['queue'] ?? [],
            'The shared supervisor must not take archive work: a stalled mount write would '
            .'occupy a slot the monitoring checks need.'
        );

        $this->assertSame(
            self::QUEUE,
            config('content-archive.queue'),
            'The archive dispatches onto config(content-archive.queue); a supervisor serving a '
            .'different name consumes nothing.'
        );

        // The CONSUMER's connection, and it is the load-bearing one: `retry_after`
        // is a property of the connection the worker runs on, so this is what
        // decides when a still-running write is released to a second worker.
        //
        // Asserted here rather than on the job, because the job reads
        // `content-archive.connection` and phpunit.xml pins that to `sync` so the
        // suite can run the archive inline. That override is what keeps the
        // archive tests honest, and it is also why nothing else in this file can
        // see the production value.
        $this->assertSame(
            self::CONNECTION,
            $defaults[self::SUPERVISOR]['connection'] ?? null,
            'The [content] supervisor must run on ['.self::CONNECTION.']: on the shared [redis] '
            .'connection its 90s retry_after releases a '.self::JOB_TIMEOUT.'s write to a second '
            .'worker long before it finishes.'
        );

        // The producer half, and the reason it is asserted against a CONSTANT
        // rather than against `config('content-archive.connection')`: phpunit.xml
        // pins that config to `sync` so the archive runs inline and the write
        // tests stay real, which means the running value here is never the
        // production one. The constant is what JobTimeoutFitsItsConnectionTest
        // reads to size this job's budget, so pinning it against the supervisor
        // is what stops the two halves of the chain from drifting apart while
        // both look green.
        $this->assertSame(
            self::CONNECTION,
            (new ReflectionClass(self::JOB_CLASS))->getConstants()['CONNECTION'] ?? null,
            'The archive job names a different connection than the supervisor draining its queue, '
            .'so the budget is sized against one retry_after and governed by another.'
        );
    }

    /**
     * The local dev loop consumes `content`, and consumes it BEFORE `previews`.
     *
     * Position is not cosmetic. `queue:listen` drains in the given order, and
     * PreviewQueueConfigTest pins `previews` as the last entry precisely because
     * a browser render is the least urgent work in this system. Appending
     * `content` after it would take that green assertion red and put an archive
     * write behind a render.
     */
    public function test_the_development_queue_listener_consumes_content_before_previews(): void
    {
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
            'The dev queue listener does not consume the content queue, so local development '
            .'claims version rows and never archives a single blob.'
        );

        $this->assertContains(
            self::LOWER_PRIORITY_QUEUE,
            $queues,
            'The dev queue listener no longer consumes previews, so the position rule below '
            .'would compare against nothing.'
        );

        $this->assertLessThan(
            array_search(self::LOWER_PRIORITY_QUEUE, $queues, true),
            array_search(self::QUEUE, $queues, true),
            'Content must be listed before previews: queue:listen drains in the given order, '
            .'previews is pinned last by PreviewQueueConfigTest, and an archive write must not '
            .'wait behind a browser render.'
        );
    }

    /**
     * Keeps the pinned job timeout honest, so the chain above cannot silently
     * drift away from the job it protects.
     */
    public function test_the_pinned_job_timeout_matches_the_archive_job(): void
    {
        $this->assertTrue(
            class_exists(self::JOB_CLASS),
            self::JOB_CLASS.' does not exist, so the timing chain above protects nothing.'
        );

        $timeout = (new ReflectionClass(self::JOB_CLASS))->getDefaultProperties()['timeout'] ?? null;

        $this->assertSame(
            self::JOB_TIMEOUT,
            $timeout,
            'The archive job timeout changed. Re-derive the Horizon timing chain instead of only '
            .'editing the job.'
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
