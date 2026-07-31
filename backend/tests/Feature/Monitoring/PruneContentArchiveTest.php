<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Jobs\PruneContentArchive;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorContentVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ContentArchive;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use ReflectionFunction;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Pins the retention sweep, whose delete target sits on a mount whose remote
 * holds this system's only PostgreSQL backups.
 *
 * Four independent ways to get this wrong, each asserted here rather than
 * described in a comment:
 *
 * - THE WRONG COLUMN. A page that changes once and then stays stable has ONE
 *   version whose `first_seen_at` (and whose file's age) passes the window while
 *   every current check still resolves to it. Keying on either would delete the
 *   blob the NEWEST check points at, which is changedetection.io's `history_trim`
 *   defect verbatim.
 * - THE WRONG KEY. A blob is addressed by `(team_id, content_hash)` while a row
 *   is keyed by `(monitor_id, content_hash, normalizer_version)`, so several rows
 *   legitimately address ONE file. Deleting a blob per expired row reintroduces
 *   the same defect through a key mismatch instead of an age comparison.
 * - THE WRONG ORDER. The survivor query has to run AFTER the row delete, so it
 *   can be a plain existence check rather than one that excludes the row being
 *   expired. A self-inclusive check makes no blob ever deletable and retention
 *   silently no-ops while the mount grows without bound.
 * - A HASH THE PATH HELPER REJECTS. Nothing may be deleted for a row whose hash
 *   is not 64 lowercase hex characters, and the rest of the sweep must still run.
 */
class PruneContentArchiveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ids assigned by hand where the sweep's ORDER is part of the claim: the
     * archive uses ordered UUID keys and `chunkById` walks them ascending, so a
     * test that needs a specific row visited first has to say so.
     */
    protected const string FIRST_ID = '00000000-0000-0000-0000-000000000001';

    protected const string SECOND_ID = '00000000-0000-0000-0000-000000000002';

    /**
     * THE HEART OF THIS STEP. Two rows in one team addressing ONE blob, only one
     * of them expired.
     *
     * A per-row blob delete passes every other test in this file and fails here,
     * taking the live row's bytes with the expired row's. The second sweep proves
     * the survivor rule is not simply "never delete a blob": once the last
     * referencing row is gone the file goes too, or retention no-ops forever.
     */
    public function test_a_shared_blob_survives_until_its_last_referencing_row_expires(): void
    {
        $monitor = $this->monitor();
        $body = 'one body, archived twice under two normalizer generations';
        $hash = hash('sha256', $body);

        $expired = $this->version($monitor, $hash, $this->beyondWindow());
        $live = $this->version($monitor, $hash, $this->insideWindow(), [
            'normalizer_version' => 2,
        ]);

        $path = $this->blob($expired, $body);

        // Guard the guard: unless both rows really resolve to the SAME file, the
        // survivor rule below is asserted against nothing.
        $this->assertSame($path, $this->archive()->blobPath($live->team_id, $live->content_hash));
        $this->assertTrue($this->disk()->exists($path));
        $this->assertSame(2, MonitorContentVersion::query()->count());

        $this->prune();

        $this->assertRowIsGone($expired);
        $this->assertRowSurvives($live);
        $this->assertTrue(
            $this->disk()->exists($path),
            'The expired row took the live row\'s bytes with it: a blob is addressed by '
            .'(team_id, content_hash), and another row still referenced this one.'
        );

        // The last reference now expires too, so the file has nothing left
        // pointing at it and must go.
        $live->update([
            'last_seen_at' => $this->beyondWindow(),
        ]);

        $this->prune();

        $this->assertRowIsGone($live);
        $this->assertFalse(
            $this->disk()->exists($path),
            'No row references this blob any more and it was still left on the mount, '
            .'so retention frees no space at all.'
        );
    }

    /**
     * The same shared-blob rule across two monitors, which is how it actually
     * arises in production: one team, two monitors, byte-identical content.
     *
     * The survivor query must therefore key on the team and the hash, exactly
     * what the path is derived from, and not on the monitor.
     */
    public function test_a_blob_shared_by_two_monitors_in_one_team_survives_the_first_expiry(): void
    {
        $team = $this->team();
        $first = $this->monitor($team);
        $second = $this->monitor($team);

        $body = 'the same status page behind two monitors';
        $hash = hash('sha256', $body);

        $expired = $this->version($first, $hash, $this->beyondWindow());
        $live = $this->version($second, $hash, $this->insideWindow());

        $path = $this->blob($expired, $body);

        $this->assertSame($path, $this->archive()->blobPath($live->team_id, $live->content_hash));
        $this->assertNotSame($expired->monitor_id, $live->monitor_id);

        $this->prune();

        $this->assertRowIsGone($expired);
        $this->assertRowSurvives($live);
        $this->assertTrue(
            $this->disk()->exists($path),
            'A survivor query keyed on the monitor rather than the team deleted a blob '
            .'a sibling monitor still references.'
        );
    }

    /**
     * Both referencing rows expiring in ONE sweep still reclaims the blob.
     *
     * This is the common case, not an edge: two monitors in a team serving
     * identical content stop serving it at the same moment, so their rows fall out
     * of the window on the same night. The survivor query has to see the deletes
     * the same sweep already made, or every shared blob would be kept forever by
     * the sibling row that is about to go, and retention would reclaim nothing.
     */
    public function test_two_rows_sharing_a_blob_and_expiring_in_one_sweep_reclaim_it(): void
    {
        $team = $this->team();
        $body = 'content two monitors stopped serving on the same day';
        $hash = hash('sha256', $body);

        $first = $this->version($this->monitor($team), $hash, $this->beyondWindow());
        $second = $this->version($this->monitor($team), $hash, $this->beyondWindow());

        $path = $this->blob($first, $body);

        $this->assertSame($path, $this->archive()->blobPath($second->team_id, $second->content_hash));

        $this->prune();

        $this->assertRowIsGone($first);
        $this->assertRowIsGone($second);
        $this->assertSame(
            [],
            $this->disk()->allFiles(),
            'A blob whose last two referencing rows both expired in this sweep was kept, so '
            .'retention never reclaims a shared blob at all.'
        );
    }

    public function test_a_version_last_seen_beyond_the_window_loses_its_row_and_its_blob(): void
    {
        $monitor = $this->monitor();
        $body = 'content nothing has resolved to for over a month';
        $version = $this->version($monitor, hash('sha256', $body), $this->beyondWindow());

        $path = $this->blob($version, $body);

        $this->assertTrue($this->disk()->exists($path));

        $this->prune();

        $this->assertRowIsGone($version);
        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_a_version_last_seen_inside_the_window_keeps_both(): void
    {
        $monitor = $this->monitor();
        $body = 'content seen well inside the window';
        $version = $this->version($monitor, hash('sha256', $body), $this->insideWindow());

        $path = $this->blob($version, $body);

        $this->prune();

        $this->assertRowSurvives($version);
        $this->assertSame([$path], $this->disk()->allFiles());
    }

    /**
     * The changedetection.io defect, asserted explicitly.
     *
     * A page that changed once and then stayed stable has a single version row
     * that is OLD and CURRENT at the same time: `first_seen_at` (and the blob's
     * own file age) is months back, while every check still resolves to it and
     * keeps advancing `last_seen_at`. Keying on either of the first two deletes
     * the file the newest check points at.
     */
    public function test_an_ancient_first_seen_at_with_a_current_last_seen_at_is_not_pruned(): void
    {
        $monitor = $this->monitor();
        $body = 'a page that changed once, a long time ago, and has been stable since';
        $version = $this->version($monitor, hash('sha256', $body), now(), [
            'first_seen_at' => now()->subDays($this->retentionDays() * 2),
        ]);

        $path = $this->blob($version, $body);

        // Guard the guard: the two timestamps have to straddle the window, or a
        // sweep keyed on `first_seen_at` would pass this test.
        $this->assertTrue($version->first_seen_at->lessThan($this->beyondWindow()));
        $this->assertTrue($version->last_seen_at->greaterThan($this->insideWindow()));

        $this->prune();

        $this->assertRowSurvives($version);
        $this->assertTrue(
            $this->disk()->exists($path),
            'A version every current check still resolves to was pruned on its age, which '
            .'is exactly the history entry changedetection.io loses.'
        );
    }

    /**
     * A hash the path helper rejects can never be turned into a delete target,
     * so nothing at all happens to that row.
     */
    public function test_a_malformed_content_hash_is_skipped_without_any_delete(): void
    {
        $monitor = $this->monitor();

        $malformed = $this->version($monitor, 'not-a-sha-256-hash', $this->beyondWindow());

        // A file belonging to an unrelated, still-live version. It is the only
        // thing on the disk, so its survival plus the file count proves no unlink
        // was issued anywhere while the malformed row was visited.
        $body = 'an unrelated live version';
        $unrelated = $this->version($monitor, hash('sha256', $body), $this->insideWindow(), [
            'content_hash_normalized' => hash('sha256', $body),
        ]);
        $path = $this->blob($unrelated, $body);

        $this->prune();

        $this->assertRowSurvives($malformed);
        $this->assertTrue(
            $this->disk()->exists($path),
            'The sweep deleted a file while handling a row whose hash cannot address one.'
        );
        $this->assertSame([$path], $this->disk()->allFiles());
    }

    /**
     * One unusable row does not abort the sweep.
     *
     * The malformed row is given the LOWER key so `chunkById` reaches it first:
     * an implementation that lets the path helper's rejection escape would throw
     * there and never look at the expired row behind it.
     */
    public function test_a_malformed_content_hash_does_not_stop_the_rest_of_the_sweep(): void
    {
        $monitor = $this->monitor();

        $malformed = $this->version($monitor, 'still-not-a-hash', $this->beyondWindow(), [
            'id' => self::FIRST_ID,
        ]);

        $body = 'an expired version queued behind the malformed one';
        $expired = $this->version($monitor, hash('sha256', $body), $this->beyondWindow(), [
            'id' => self::SECOND_ID,
            'content_hash_normalized' => hash('sha256', $body),
        ]);
        $path = $this->blob($expired, $body);

        $this->assertTrue($malformed->getKey() < $expired->getKey());

        $this->prune();

        $this->assertRowSurvives($malformed);
        $this->assertRowIsGone($expired);
        $this->assertSame([], $this->disk()->allFiles());
    }

    /**
     * A pruned version leaves the check rows that pointed at it alone.
     *
     * An older check honestly records "the content was this, it is no longer
     * archived"; nulling the column would lose the fact instead of the file.
     */
    public function test_monitor_check_content_hashes_survive_the_prune(): void
    {
        $monitor = $this->monitor();
        $body = 'content a recorded check still names';
        $hash = hash('sha256', $body);

        $version = $this->version($monitor, $hash, $this->beyondWindow());
        $this->blob($version, $body);

        $check = MonitorCheck::query()->create([
            'monitor_id' => $monitor->getKey(),
            'team_id' => $monitor->team_id,
            'checked_at' => now()->subDays($this->retentionDays() + 1),
            'region' => MonitorRegion::EUCentral->value,
            'status' => MonitorStatus::Up,
            'response_ms' => 120,
            'content_hash' => $hash,
            'content_hash_normalized' => $hash,
        ]);

        $this->prune();

        $this->assertRowIsGone($version);

        $check->refresh();

        $this->assertSame($hash, $check->content_hash);
        $this->assertSame($hash, $check->content_hash_normalized);
    }

    /**
     * Two sweeps cannot run at once. The schedule already carries
     * `withoutOverlapping()`, but a hand-dispatched run is not scheduled work, and
     * concurrent sweeps would race the survivor query: both could read a row the
     * other is about to delete and both decide the blob is still referenced.
     */
    public function test_a_second_sweep_bows_out_while_another_holds_the_lock(): void
    {
        $monitor = $this->monitor();
        $body = 'content an interleaved second sweep must not touch';
        $version = $this->version($monitor, hash('sha256', $body), $this->beyondWindow());
        $path = $this->blob($version, $body);

        $held = Cache::lock(PruneContentArchive::LOCK_KEY, 60);

        $this->assertTrue($held->get(), 'The lock was already held before the test acquired it.');

        try {
            $this->prune();
        } finally {
            $held->release();
        }

        $this->assertRowSurvives($version);
        $this->assertTrue($this->disk()->exists($path));
    }

    /**
     * A misconfigured window aborts loudly instead of expiring the archive.
     *
     * `retention_days` is env-driven, and at zero the cutoff becomes `now()`, so
     * every version ever archived would be expired in a single sweep, against a
     * mount whose remote holds the only PostgreSQL backups. Failing the job is
     * visible in Horizon; deleting everything is not.
     */
    public function test_a_non_positive_retention_window_aborts_instead_of_expiring_everything(): void
    {
        config([
            'content-archive.retention_days' => 0,
        ]);

        $monitor = $this->monitor();
        $body = 'content a zeroed window would have deleted';
        $version = $this->version($monitor, hash('sha256', $body), now()->subYear());
        $path = $this->blob($version, $body);

        $this->assertInstanceOf(
            RuntimeException::class,
            $this->captureThrowable(fn () => $this->prune()),
            'A retention window of zero days was accepted, so one bad env value expires the '
            .'entire archive.'
        );

        $this->assertRowSurvives($version);
        $this->assertTrue($this->disk()->exists($path));
    }

    /**
     * The nightly entry exists, runs this job, and carries both guards.
     *
     * `onOneServer()` keeps two web hosts from both dispatching the sweep on the
     * same minute, and `withoutOverlapping()` keeps a long sweep from being
     * re-entered by the next tick. Found by the JOB the entry dispatches rather
     * than by its description, so renaming the entry cannot make this vacuous.
     */
    public function test_the_nightly_schedule_entry_runs_the_prune_with_both_guards(): void
    {
        $event = $this->scheduledPruneEvent();

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
     * The scheduled entry whose closure dispatches {@see PruneContentArchive}.
     */
    protected function scheduledPruneEvent(): Event
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty(
            $events,
            'The scheduler holds no events at all, so routes/console.php was never loaded and '
            .'every assertion about the entry below would pass over an empty list.'
        );

        foreach ($events as $event) {
            if ($this->scheduledJob($event) instanceof PruneContentArchive) {
                return $event;
            }
        }

        $this->fail('No scheduled entry dispatches '.PruneContentArchive::class.'.');
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

    /**
     * Run the sweep the way the scheduler does, synchronously so its side effects
     * are observable in the assertions that follow.
     */
    protected function prune(): void
    {
        PruneContentArchive::dispatchSync();
    }

    protected function assertRowSurvives(MonitorContentVersion $version): void
    {
        $this->assertDatabaseHas('monitor_content_versions', [
            'id' => $version->getKey(),
        ]);
    }

    protected function assertRowIsGone(MonitorContentVersion $version): void
    {
        $this->assertDatabaseMissing('monitor_content_versions', [
            'id' => $version->getKey(),
        ]);
    }

    /**
     * The configured retention window, guarded so a window of one day or less
     * could not collapse the two ages below onto the same side of the cutoff.
     */
    protected function retentionDays(): int
    {
        $days = (int) config('content-archive.retention_days');

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
     * A version row in the shape the check pipeline's claim writes it.
     *
     * `first_seen_at` defaults to the same instant as `last_seen_at` so a test
     * that cares about the difference has to state it, and cannot pass by
     * accident.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function version(
        Monitor $monitor,
        string $contentHash,
        CarbonInterface $lastSeenAt,
        array $overrides = [],
    ): MonitorContentVersion {
        return MonitorContentVersion::query()->create(array_merge([
            'monitor_id' => $monitor->getKey(),
            'team_id' => $monitor->team_id,
            'content_hash' => $contentHash,
            'content_hash_normalized' => $contentHash,
            'byte_size' => 4096,
            'content_type' => 'text/html',
            'truncated' => false,
            'normalizer_version' => 1,
            'first_seen_at' => $lastSeenAt,
            'last_seen_at' => $lastSeenAt,
        ], $overrides));
    }

    /**
     * The blob a version addresses, written through the same path helper the
     * sweep resolves its delete target with.
     */
    protected function blob(MonitorContentVersion $version, string $body): string
    {
        $path = $this->archive()->blobPath($version->team_id, $version->content_hash);

        $this->disk()->put($path, gzencode($body));

        return $path;
    }

    /**
     * An HTTP monitor, built the way the rest of this suite builds one (neither
     * Monitor nor Team has a working factory).
     */
    protected function monitor(?Team $team = null): Monitor
    {
        $team ??= $this->team();

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Retention monitor '.(Monitor::query()->count() + 1),
            'type' => MonitorType::Http,
            'url' => 'https://example.test/status',
            'check_interval_sec' => 60,
        ]);
    }

    protected function team(): Team
    {
        return Team::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Retention team',
        ]);
    }

    /**
     * Whatever the callable threw, or null.
     *
     * Deliberately not a `try`/`catch` around `$this->fail()`: PHPUnit's
     * AssertionFailedError extends RuntimeException, so a catch block wide enough
     * to hold the expected failure would also swallow the assertion that says the
     * failure never happened.
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

    protected function archive(): ContentArchive
    {
        return app(ContentArchive::class);
    }

    protected function disk(): Filesystem
    {
        return Storage::disk((string) config('content-archive.disk'));
    }
}
