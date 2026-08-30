<?php

namespace Tests\Feature\Notifications;

use App\Jobs\PruneNotificationDeliveries;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\FindsScheduledJobs;
use Tests\TestCase;
use Throwable;

/**
 * Pins the {@see PruneNotificationDeliveries} retention sweep: rows older
 * than the configured window are deleted, rows inside it survive, a
 * misconfigured sub-one-day window aborts instead of emptying the table, and
 * the nightly schedule entry actually dispatches this job.
 */
class PruneNotificationDeliveriesTest extends TestCase
{
    use FindsScheduledJobs;
    use RefreshDatabase;

    public function test_a_delivery_created_beyond_the_window_is_deleted(): void
    {
        $team = $this->team();
        $delivery = $this->delivery($team, $this->beyondWindow());

        $this->prune();

        $this->assertRowIsGone($delivery);
    }

    public function test_a_delivery_created_inside_the_window_survives(): void
    {
        $team = $this->team();
        $delivery = $this->delivery($team, $this->insideWindow());

        $this->prune();

        $this->assertRowSurvives($delivery);
    }

    /**
     * The boundary case asserted directly: one row on each side of the cutoff
     * in the same sweep, so the split is exact rather than "some rows go".
     */
    public function test_the_split_across_the_boundary_is_exact(): void
    {
        $team = $this->team();
        $expired = $this->delivery($team, $this->beyondWindow());
        $survivor = $this->delivery($team, $this->insideWindow());

        $this->prune();

        $this->assertRowIsGone($expired);
        $this->assertRowSurvives($survivor);
    }

    /**
     * A misconfigured window aborts loudly instead of emptying the audit
     * trail.
     *
     * `retention_days` is env-driven, and at zero the cutoff becomes `now()`,
     * so every delivery ever recorded would be deleted in a single sweep.
     * Failing the job is visible in Horizon; a silent mass delete of the
     * delivery history is not.
     */
    public function test_a_non_positive_retention_window_aborts_instead_of_deleting_everything(): void
    {
        config([
            'notification-deliveries.retention_days' => 0,
        ]);

        $team = $this->team();
        $delivery = $this->delivery($team, now()->subYear());

        $this->assertInstanceOf(
            RuntimeException::class,
            $this->captureThrowable(fn () => $this->prune()),
            'A retention window of zero days was accepted, so one bad env value deletes the '
            .'entire notification_deliveries table.'
        );

        $this->assertRowSurvives($delivery);
    }

    /**
     * The sweep names the lane it runs on.
     *
     * A job that never calls `onQueue()` lands on `default`, and `default` is
     * consumed here only because it appears in supervisor-1's queue list in
     * `config/horizon.php`. Depending on that is the silent kind of wrong:
     * remove `default` from the list and this sweep simply stops running, with
     * no error raised anywhere and `schedule:list` still looking correct.
     */
    public function test_the_sweep_runs_on_the_lane_its_config_names(): void
    {
        $this->assertSame(
            (string) config('notification-deliveries.queue'),
            (new PruneNotificationDeliveries)->queue,
        );
    }

    /**
     * More rows than fit in one chunk still all get deleted.
     *
     * The count has to CROSS `PruneNotificationDeliveries::CHUNK_SIZE`, not
     * merely be plural: an earlier version of this test seeded five rows
     * against a chunk size of 200, so it exercised exactly one page and proved
     * the opposite of its own name. The multi-page path is the one worth
     * pinning, because deleting inside a `chunkById` callback is where keyset
     * pagination either holds or silently skips a page's worth of rows.
     */
    public function test_a_backlog_larger_than_one_chunk_is_fully_deleted(): void
    {
        $team = $this->team();
        $total = PruneNotificationDeliveries::CHUNK_SIZE + 1;

        for ($i = 0; $i < $total; $i++) {
            $this->delivery($team, $this->beyondWindow());
        }

        $this->assertSame($total, NotificationDelivery::query()->count());

        $this->prune();

        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    /**
     * The nightly entry exists, runs this job, and carries both guards.
     *
     * `onOneServer()` keeps two web hosts from both dispatching the sweep on
     * the same minute, and `withoutOverlapping()` keeps a long sweep from
     * being re-entered by the next tick. Found by the JOB the entry
     * dispatches rather than by its description, so renaming the entry cannot
     * make this vacuous.
     */
    public function test_the_nightly_schedule_entry_runs_the_prune_with_both_guards(): void
    {
        $event = $this->scheduledEventDispatching(PruneNotificationDeliveries::class);

        $this->assertTrue(
            $event->onOneServer,
            'The prune is scheduled without onOneServer(), so every web host dispatches its own.'
        );
        $this->assertTrue(
            $event->withoutOverlapping,
            'The prune is scheduled without withoutOverlapping(), so a slow sweep is re-entered.'
        );
        $this->assertMatchesRegularExpression(
            '/^\d{1,2} \d{1,2} \* \* \*$/',
            $event->expression,
            'The prune must run once a day at a fixed time (dailyAt), not on a sub-daily cadence.'
        );
    }

    /**
     * Run the sweep the way the scheduler does, synchronously so its side
     * effects are observable in the assertions that follow.
     */
    protected function prune(): void
    {
        PruneNotificationDeliveries::dispatchSync();
    }

    protected function assertRowSurvives(NotificationDelivery $delivery): void
    {
        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->getKey(),
        ]);
    }

    protected function assertRowIsGone(NotificationDelivery $delivery): void
    {
        $this->assertDatabaseMissing('notification_deliveries', [
            'id' => $delivery->getKey(),
        ]);
    }

    /**
     * The configured retention window, guarded so a window of one day or less
     * could not collapse the two ages below onto the same side of the cutoff.
     */
    protected function retentionDays(): int
    {
        $days = (int) config('notification-deliveries.retention_days');

        $this->assertGreaterThan(1, $days, 'The retention window is too short to straddle.');

        return $days;
    }

    /**
     * One day past the window: expired.
     */
    protected function beyondWindow(): CarbonInterface
    {
        return now()->subDays($this->retentionDays() + 1);
    }

    /**
     * One day short of the window: still live.
     */
    protected function insideWindow(): CarbonInterface
    {
        return now()->subDays($this->retentionDays() - 1);
    }

    /**
     * A delivery row created at the given instant. `created_at` is the age
     * this sweep keys on, so it is set explicitly rather than left to `now()`.
     */
    protected function delivery(Team $team, CarbonInterface $createdAt): NotificationDelivery
    {
        $channel = NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
        ]);

        $delivery = NotificationDelivery::query()->create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => 'webhook',
            'notification_type' => 'App\\Notifications\\IncidentOpened',
            'event' => 'opened',
            'outcome' => 'delivered',
        ]);

        $delivery->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $delivery->refresh();
    }

    protected function team(): Team
    {
        return Team::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Prune Notification Deliveries Team '.Str::random(8),
        ]);
    }

    /**
     * Whatever the callable threw, or null.
     *
     * Deliberately not a `try`/`catch` around `$this->fail()`: PHPUnit's
     * AssertionFailedError extends RuntimeException, so a catch block wide
     * enough to hold the expected failure would also swallow the assertion
     * that says the failure never happened.
     */
    protected function captureThrowable(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }
}
