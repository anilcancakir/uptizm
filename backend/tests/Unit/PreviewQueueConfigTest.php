<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the queue wiring behind the status-page preview render.
 *
 * A broken render is invisible: nothing throws, nothing logs, the status page
 * row simply stays at `rendering` forever. Three independent misconfigurations
 * produce exactly that symptom, so each is pinned by an assertion here instead
 * of by a comment in the config file:
 *
 * 1. The timing chain. Laravel requires the job timeout to stay below the
 *    worker timeout, and the worker timeout below the connection's
 *    `retry_after`; otherwise the queue hands a still-running job to a second
 *    worker and the same page is rendered twice (see the invariant comment in
 *    config/queue.php).
 * 2. Supervisor coverage. Horizon sizes its workers per environment, so a
 *    `previews` supervisor that is missing from an environment block is sized
 *    by accident rather than deliberately, and one provisioning zero processes
 *    is skipped outright by Horizon's ProvisioningPlan.
 * 3. Local consumption. `queue:listen` drains only the queues it is given, so
 *    a dev loop that never names `previews` never picks a render up.
 */
class PreviewQueueConfigTest extends TestCase
{
    /**
     * The `$timeout` of the render job the chain below protects. The job
     * arrives in a later step; test_the_pinned_job_timeout_matches_the_render_job
     * cross-checks this value against the class as soon as it exists, so the
     * chain asserted here cannot drift away from the job it is sized for.
     */
    private const JOB_TIMEOUT = 40;

    /** The render job, referenced by name because it lands in a later step. */
    private const JOB_CLASS = 'App\Jobs\RenderStatusPagePreview';

    /** The dedicated Horizon supervisor name. */
    private const SUPERVISOR = 'previews';

    /** The queue that supervisor serves. */
    private const QUEUE = 'previews';

    /** The supervisor that must never take preview work. */
    private const SHARED_SUPERVISOR = 'supervisor-1';

    /**
     * The render job's timeout stays under the worker timeout, and the worker
     * timeout stays under the connection's `retry_after`, in every environment
     * Horizon provisions.
     */
    public function test_the_previews_timing_chain_holds_in_every_horizon_environment(): void
    {
        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertIsInt($retryAfter, 'The redis queue connection no longer declares a retry_after.');

        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $timeout = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['timeout'] ?? null;

            $this->assertIsInt(
                $timeout,
                "The [{$environment}] environment provisions no [previews] supervisor timeout."
            );

            $this->assertGreaterThan(
                self::JOB_TIMEOUT,
                $timeout,
                "The [{$environment}] previews worker timeout must exceed the job timeout, "
                .'or the worker kills the render before the job can record its own failure.'
            );

            $this->assertLessThan(
                $retryAfter,
                $timeout,
                "The [{$environment}] previews worker timeout must stay under retry_after, "
                .'or the queue releases a still-running render to a second worker.'
            );
        }
    }

    /**
     * Every environment block names the `previews` supervisor explicitly, so
     * adding an environment cannot silently leave preview rendering unsized.
     */
    public function test_the_previews_supervisor_is_declared_in_every_horizon_environment(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $this->assertArrayHasKey(
                self::SUPERVISOR,
                $supervisors,
                "The [{$environment}] Horizon environment does not declare the [previews] supervisor."
            );
        }
    }

    /**
     * Every environment provisions EXACTLY one previews process.
     *
     * Both bounds matter and they are different failures. Zero processes means
     * Horizon's ProvisioningPlan skips the supervisor outright and the queue is
     * never consumed, with no error anywhere. More than one means two renders of
     * the same page can overlap: the job releases its uniqueness lock when
     * processing starts, both writes target the same single key, and both stamp
     * `preview_rendered_at = now()`, so an earlier-started render finishing last
     * stores pre-change pixels under a post-change timestamp. That is the drift
     * this feature exists to remove, and it would be presented under a
     * customer-view label.
     *
     * Raising the ceiling is therefore a correctness change, not a capacity
     * tweak. It requires per-page serialization first (a cache lock in the job,
     * or one file per render plus an atomic pointer), and this assertion is here
     * so the decision cannot be made by editing config alone.
     *
     * What this assertion CANNOT see, so it is worth stating: the property that
     * actually serializes renders is one `previews` consumer GLOBALLY, and this
     * value bounds one Horizon master on one host. A second instance, the
     * `queue:listen` in composer's `dev` script running beside Horizon, or an
     * ad-hoc `queue:work --queue=previews` all restore the overlap while this
     * test stays green. See the comment in config/horizon.php.
     */
    public function test_every_horizon_environment_provisions_exactly_one_previews_process(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $maxProcesses = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['maxProcesses'] ?? null;

            $this->assertIsInt(
                $maxProcesses,
                "The [{$environment}] environment provisions no [previews] maxProcesses."
            );

            $this->assertSame(
                1,
                $maxProcesses,
                "The [{$environment}] previews supervisor must provision exactly one process: "
                .'zero leaves the queue unconsumed, and more than one lets two renders of the '
                .'same page overwrite one file and stamp the loser as current.'
            );
        }
    }

    /**
     * Preview work stays off the shared supervisor. Each render holds a
     * Chromium process, so the shared worker's small memory ceiling and high
     * process count are both wrong for it.
     */
    public function test_the_previews_queue_is_isolated_from_the_shared_supervisor(): void
    {
        $defaults = config('horizon.defaults');

        $this->assertSame(
            [self::QUEUE],
            $defaults[self::SUPERVISOR]['queue'] ?? null,
            'The [previews] supervisor must serve the previews queue and nothing else.'
        );

        $this->assertNotContains(
            self::QUEUE,
            $defaults[self::SHARED_SUPERVISOR]['queue'] ?? [],
            'The shared supervisor must not take preview work: one Chromium per process would exhaust its memory ceiling.'
        );
    }

    /**
     * The local dev loop consumes `previews`, and consumes it last: a preview
     * render is the least urgent work in this system and must never be drained
     * ahead of an uptime check.
     */
    public function test_the_development_queue_listener_consumes_the_previews_queue_last(): void
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
            'The dev queue listener does not consume the previews queue, so a render never leaves the rendering state.'
        );

        $this->assertSame(
            self::QUEUE,
            end($queues),
            'Previews must be listed last: queue:listen drains in the given order, and a render must not starve a check.'
        );
    }

    /**
     * Keeps the pinned job timeout honest once the render job exists, so the
     * chain above cannot silently drift away from the job it protects.
     */
    public function test_the_pinned_job_timeout_matches_the_render_job(): void
    {
        if (! class_exists(self::JOB_CLASS)) {
            $this->markTestSkipped(self::JOB_CLASS.' arrives with the render job step.');
        }

        $timeout = (new ReflectionClass(self::JOB_CLASS))->getDefaultProperties()['timeout'] ?? null;

        $this->assertSame(
            self::JOB_TIMEOUT,
            $timeout,
            'The render job timeout changed. Re-derive the Horizon timing chain instead of only editing the job.'
        );
    }

    /**
     * The declared Horizon environments, guarded so an emptied block cannot
     * turn every loop above into a vacuous pass.
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
