<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionFunction;
use ReflectionProperty;

/**
 * Finds the scheduler entry that dispatches a given queued job.
 *
 * ## Why a job's trigger needs asserting at all
 *
 * `GenerateWeeklyDigest` was written, tested and plan-gated while nothing outside
 * the test suite ever dispatched it, so `GET /incidents/digest` answered 404
 * forever. Every test of a queued job builds the job by hand, which means the
 * whole suite stays green with the `Schedule::job()` entry deleted from
 * `routes/console.php`: the trigger is the one part of a scheduled job that its
 * own unit tests structurally cannot cover.
 *
 * ## Extracted at the third caller, not the second
 *
 * `MaintenanceSuppressionTest` and `PruneContentArchiveTest` each carried a
 * byte-identical private copy of this and `PruneExpiredAiSuggestionsTest` would
 * have been a third. Consolidating beats tolerating here for a specific reason:
 * the finder reads a PRIVATE framework property (`Event::$callback`) and a
 * closure's bound variables through reflection, so a Laravel upgrade that moves
 * either breaks every copy at once. One file to fix beats three that nobody
 * remembers are related.
 *
 * It lives in `Tests\Concerns` rather than on `Tests\TestCase` because that base
 * class is shared by every suite in the repo and scheduler reflection is
 * machinery three of them need.
 */
trait FindsScheduledJobs
{
    /**
     * The scheduled entry whose closure dispatches an instance of `$job`.
     *
     * Matched on the JOB the entry dispatches rather than on its `name()`
     * description, so renaming an entry cannot quietly turn a caller's
     * assertions vacuous.
     *
     * @param  class-string  $job
     */
    protected function scheduledEventDispatching(string $job): Event
    {
        $events = app(Schedule::class)->events();

        // Without this, a `routes/console.php` that never loaded would leave the
        // loop below iterating nothing and every caller asserting over an entry
        // it never found.
        $this->assertNotEmpty(
            $events,
            'The scheduler holds no events at all, so routes/console.php was never loaded and '
            .'every assertion about the entry below would pass over an empty list.'
        );

        foreach ($events as $event) {
            if ($this->scheduledJob($event) instanceof $job) {
                return $event;
            }
        }

        $this->fail('No scheduled entry dispatches '.$job.'.');
    }

    /**
     * The job instance a `Schedule::job()` entry closes over, or null when the
     * event is not one.
     */
    protected function scheduledJob(Event $event): ?object
    {
        if (! $event instanceof CallbackEvent) {
            return null;
        }

        $callback = (new ReflectionProperty($event, 'callback'))->getValue($event);

        if (! $callback instanceof Closure) {
            return null;
        }

        $job = (new ReflectionFunction($callback))->getClosureUsedVariables()['job'] ?? null;

        return is_object($job) ? $job : null;
    }
}
