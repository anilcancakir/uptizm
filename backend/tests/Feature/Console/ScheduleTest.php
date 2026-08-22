<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Every recurring job this product depends on, pinned by name.
 *
 * A missing trigger is the quietest defect this codebase has produced, and
 * `routes/console.php` records two of them in its own comments: the weekly
 * digest was written, tested and plan-gated while nothing outside the suite
 * ever dispatched it, so a Business plan got a 404 forever; and expired AI
 * suggestions were stamped with an `expires_at` nothing ever acted on, so they
 * left the inbox and stayed in the table.
 *
 * Neither failed a build, because a job that is never scheduled has no failing
 * test to write against it: its unit tests pass, its queue works, and the only
 * observable is an absence. That is what this file is for. It asserts the
 * REGISTRATION, which is the one property no other test in the suite covers.
 */
class ScheduleTest extends TestCase
{
    /**
     * The named tasks, and the reason each one is load-bearing in one line.
     *
     * @var array<string, string>
     */
    private const array EXPECTED = [
        'monitoring:schedule-checks' => 'no probe ever runs',
        'monitoring:daily-uptime' => 'no day ever gets a rolled-up uptime figure',
        'monitoring:schedule-ssl-checks' => 'no certificate is ever inspected',
        'monitoring:prune-content-archive' => 'archived bodies accumulate forever',
        'monitoring:sweep-ai-suggestions' => 'no anomaly is ever proposed',
        'ai:prune-expired-suggestions' => 'expired suggestions stay in the table',
        'ai:dispatch-weekly-digests' => 'the digest endpoint answers 404 forever',
        'status-pages:bust-maintenance-boundaries' => 'a maintenance window never opens or closes on the public page',
        'services:ingest-feeds' => 'no vendor status feed is ever read',
        'proxy:refresh-sources' => 'the exit pool is never refreshed',
        'proxy:alarm-dark-regions' => 'a dark region is never reported',
        'queue:prune-failed' => 'the failed-jobs table only ever grows',
        'billing:reconcile' => 'a rail abandons a dropped webhook after hours (RevenueCat) or days (Stripe), so without this a lost EXPIRATION is a paid tier held for free forever and a lost purchase is a paying customer stuck on free with no self-serve recovery',
    ];

    public function test_every_recurring_task_is_registered(): void
    {
        $registered = $this->registeredTaskNames();

        foreach (self::EXPECTED as $name => $consequence) {
            $this->assertContains(
                $name,
                $registered,
                "The [{$name}] task is not scheduled, so {$consequence}.",
            );
        }
    }

    /**
     * The negative half, and the one that keeps the list above honest: a task
     * added without a line here would leave this file describing a schedule the
     * application no longer has.
     */
    public function test_no_task_is_scheduled_without_a_line_in_this_file(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff($this->registeredTaskNames(), array_keys(self::EXPECTED))),
            'A new scheduled task needs a line in this file naming what breaks without it.',
        );
    }

    /**
     * The names of every task the scheduler holds.
     *
     * `->name()` writes the event's `description`, so that is what is read back;
     * a task registered without one would surface as an empty string rather than
     * being silently skipped.
     *
     * The emptiness check is not defensive padding. An empty schedule, which is
     * what `routes/console.php` failing to load looks like, makes
     * {@see self::test_no_task_is_scheduled_without_a_line_in_this_file()}
     * VACUOUSLY true: `array_diff([], ...)` is `[]`. Its sibling would still go
     * red, but it would say "monitoring:schedule-checks is not scheduled" and
     * send a reader looking for a deleted task instead of a schedule that never
     * loaded. Asserting it here names the real cause in both tests at once.
     *
     * @return list<string>
     */
    private function registeredTaskNames(): array
    {
        $names = array_map(
            fn ($event): string => (string) $event->description,
            $this->app->make(Schedule::class)->events(),
        );

        $this->assertNotEmpty(
            $names,
            'The scheduler holds no events at all, so routes/console.php did not load in this context.',
        );

        return $names;
    }
}
