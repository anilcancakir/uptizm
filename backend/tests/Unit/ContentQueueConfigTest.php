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
    private const JOB_TIMEOUT = 50;

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
        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertIsInt($retryAfter, 'The redis queue connection no longer declares a retry_after.');

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
     * Every environment provisions EXACTLY one content process.
     *
     * Both bounds are failures, and different ones. Zero means Horizon's
     * ProvisioningPlan skips the supervisor outright, the queue is never
     * consumed, and every claim row stays blobless with nothing logged. More
     * than one means concurrent writers on an rclone FUSE mount that sustains
     * roughly two file operations a second: the write is content-addressed and
     * staged per process, so it stays CORRECT under concurrency, but the mount
     * is the bottleneck and parallel writers only lengthen the stall each one
     * sits in.
     *
     * Raising the ceiling therefore needs the mount's throughput measured
     * first, which is why it cannot be done by editing config alone.
     */
    public function test_every_horizon_environment_provisions_exactly_one_content_process(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $maxProcesses = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['maxProcesses'] ?? null;

            $this->assertIsInt(
                $maxProcesses,
                "The [{$environment}] environment provisions no [content] maxProcesses."
            );

            $this->assertSame(
                1,
                $maxProcesses,
                "The [{$environment}] content supervisor must provision exactly one process: "
                .'zero leaves every claimed version row without a blob, and more than one puts '
                .'concurrent writers on a mount that serialises them anyway.'
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
