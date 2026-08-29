<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Sweeps EVERY queued job against the one queue invariant that fails silently.
 *
 * `config/queue.php` states it: a connection's `retry_after` must stay strictly
 * greater than the timeout of any job it carries, because Redis releases a
 * reserved job once `retry_after` elapses whether or not a worker is still
 * running it. The second worker then finds `attempts()` past `$tries` and fails
 * the job, while the first is still mid-run. Nothing throws where the mistake
 * is, the work usually still completes, and the only outward sign is a failed
 * job and an exception in Sentry naming a class that did nothing wrong.
 *
 * Three chains already have a dedicated test each ({@see AnalyzeQueueConfigTest},
 * {@see PreviewQueueConfigTest}, {@see ContentQueueConfigTest}), and those go
 * deeper than this one: they pin the supervisor, the process count, the memory
 * floor and the two-place registration as well. This test is the complement, not
 * a duplicate. It answers the question none of them can, because each is written
 * for a job somebody already knew needed a chain: is there a job here that needs
 * one and does not have one?
 *
 * There was. `PublishAiIncidentUpdate` declared a 180-second timeout and rode the
 * shared connection at `retry_after` 90, and it had been double-delivered in
 * production since 2026-08-17. The `analyze` chain's own comment called that job
 * "the first in the repo to cross that 90", which was written in good faith and
 * was already untrue.
 *
 * HOW THE CONNECTION IS RESOLVED, AND WHY IT IS A CONSTANT
 *
 * A job selects its connection in the constructor (`$this->onConnection(...)`),
 * which leaves nothing for reflection to read. So the convention this test
 * enforces is that the value ALSO lives in a `CONNECTION` class constant, which
 * both the constructor and this test read. A job that names no constant and no
 * `$connection` property is taken to ride `queue.default`, which is the truth for
 * every job in the repo that does neither.
 *
 * The failure mode this leaves open is a job that declares `CONNECTION` and then
 * forgets to call `onConnection()`, which would pass here and ride the default in
 * production. That is what the per-chain tests' own job-side assertions are for;
 * a constant cannot see a method call it was never passed to.
 */
class JobTimeoutFitsItsConnectionTest extends TestCase
{
    /**
     * The connection every job rides unless it names another.
     *
     * Spelled out rather than read from `queue.default`, because `phpunit.xml`
     * pins that to `sync` and `sync` has no `retry_after` at all: reading it
     * would make this test assert nothing in exactly the environment it runs in.
     * {@see AnalyzeQueueConfigTest} names the same constant for the same reason.
     */
    private const SHARED_CONNECTION = 'redis';

    /**
     * Every job that declares a timeout fits inside its connection's window.
     */
    public function test_no_job_outlives_the_retry_after_of_the_connection_it_rides(): void
    {
        $checked = 0;

        foreach ($this->jobClasses() as $class) {
            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

            if (! is_int($timeout)) {
                continue;
            }

            $connection = $this->connectionFor($class);
            $retryAfter = config("queue.connections.{$connection}.retry_after");

            $this->assertIsInt(
                $retryAfter,
                "[{$class}] rides the [{$connection}] connection, which declares no retry_after."
            );

            $this->assertLessThan(
                $retryAfter,
                $timeout,
                "[{$class}] runs for up to {$timeout}s on the [{$connection}] connection, whose "
                ."retry_after is {$retryAfter}s. Redis will release the job to a second worker while "
                .'the first is still running it: two spends on one run, and a failed job for work that '
                .'succeeded. Give the job its own connection with a wider retry_after (see '
                .'`redis-analyze`), or bring the timeout under this one.'
            );

            $checked++;
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'No job declared a timeout, so this test asserted nothing. Either the discovery below '
            .'stopped finding jobs, or every timeout moved somewhere reflection cannot read.'
        );
    }

    /**
     * The connection a job will be dispatched on.
     *
     * A `CONNECTION` constant first (the constructor reads the same one), then a
     * `$connection` property, then the application default.
     */
    private function connectionFor(string $class): string
    {
        $reflection = new ReflectionClass($class);

        // Reflection rather than `defined()`: the constant is `protected` on
        // every job that declares one, and `defined()` answers from the calling
        // scope, so it would report false here and quietly fall through to the
        // shared connection for exactly the jobs that do not ride it.
        $constant = $reflection->getConstants()['CONNECTION'] ?? null;

        if (is_string($constant) && $constant !== '') {
            return $constant;
        }

        $declared = $reflection->getDefaultProperties()['connection'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return self::SHARED_CONNECTION;
    }

    /**
     * Every concrete class under `app/Jobs`.
     *
     * Discovered from the filesystem rather than listed, so a job added without
     * a chain test is covered the moment its file lands.
     *
     * @return array<int, class-string>
     */
    private function jobClasses(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->name('*.php')->in(app_path('Jobs')) as $file) {
            $class = 'App\\Jobs\\'.Str::of($file->getRelativePathname())
                ->replace(['/', '.php'], ['\\', ''])
                ->toString();

            if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
