<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorType;
use App\Jobs\ArchiveContent;
use App\Models\Monitor;
use App\Models\MonitorContentVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ContentArchive;
use App\Support\Monitoring\NormalizedContent;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Pins the archive write path: the blob it produces, the claim row it finalizes,
 * and the two columns whose meaning the whole feature rests on.
 *
 * The claim comes from the check pipeline, which INSERTS the version row before
 * dispatching this job, so `store()` only ever finalizes a row that already
 * exists. Three consequences are asserted here rather than described in a
 * comment:
 *
 * - `store()` never inserts. A row it created would be a row nobody claimed, and
 *   the claim is the only thing that makes one writer per address.
 * - The failure hook DELETES the claim. It is the only cleanup path for a claim
 *   whose bytes never landed, and without it every later identical body reads as
 *   already-archived with nothing on disk.
 * - `byte_size` stays the RAW decoded length. The compressed length is roughly an
 *   eighth of it, so a single overwrite here would leave one column meaning two
 *   different things depending on which path touched the row last.
 *
 * The version rows are built with the columns the check pipeline's claim writes,
 * because the exact hashes and the seeded timestamps ARE the subject of most of
 * these assertions.
 */
class ArchiveContentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * How long before "now" a seeded claim was last seen. Minutes rather than
     * seconds because the timestamp columns serialize without sub-second
     * precision, so a shorter gap could not prove the value moved.
     */
    protected const int SEEDED_AGE_MINUTES = 10;

    /**
     * Spool files created by a test, removed on teardown for the case where a
     * failing assertion aborts before the job's own cleanup runs.
     *
     * @var array<int, string>
     */
    protected array $spoolPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->spoolPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->spoolPaths = [];

        parent::tearDown();
    }

    public function test_a_store_writes_exactly_one_blob_at_the_content_addressed_path(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);

        $this->archive()->store($version->monitor, $this->spool($body), $this->hashesFor($body));

        $path = $this->archive()->blobPath($version->team_id, $version->content_hash);

        // Exactly one file, so the per-process staging name cannot survive its
        // own rename and leave a second, truncated artifact behind.
        $this->assertSame([$path], $this->disk()->allFiles());
        $this->assertSame(gzencode($body), $this->disk()->get($path));
    }

    public function test_a_store_publishes_through_a_per_process_staging_name(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);

        $path = $this->archive()->blobPath($version->team_id, $version->content_hash);
        $staging = Str::beforeLast($path, '.gz').'.'.getmypid().'.gz.tmp';

        // A truncated leftover from an earlier attempt by THIS process, which also
        // discriminates the two possible implementations: a store that publishes
        // through the staging name overwrites and consumes it, while one that
        // writes the address directly leaves it sitting on the mount. The name has
        // to stay per process, because two monitors in one team serving identical
        // content legitimately produce two writers for one address.
        $this->disk()->put($staging, 'truncated leftover');

        $this->archive()->store($version->monitor, $this->spool($body), $this->hashesFor($body));

        $this->assertSame([$path], $this->disk()->allFiles());
        $this->assertSame(gzencode($body), $this->disk()->get($path));
    }

    public function test_a_store_advances_last_seen_at_and_leaves_first_seen_at_untouched(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $seeded = $version->last_seen_at;

        $this->archive()->store($version->monitor, $this->spool($body), $this->hashesFor($body));

        $version->refresh();

        $this->assertTrue(
            $version->last_seen_at->greaterThan($seeded),
            'The finalize step did not advance `last_seen_at`, so retention would expire a live version.'
        );
        $this->assertTrue(
            $version->first_seen_at->equalTo($seeded),
            '`first_seen_at` moved; it records when this content was FIRST observed and is not a write stamp.'
        );
    }

    public function test_a_second_store_of_the_same_hash_writes_no_new_file(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $hashes = $this->hashesFor($body);

        $this->archive()->store($version->monitor, $this->spool($body), $hashes);

        $path = $this->archive()->blobPath($version->team_id, $version->content_hash);

        // A marker rather than a timestamp: mtime resolution cannot tell a rewrite
        // inside the same second from a skipped write, and the skip is the point.
        $this->disk()->put($path, 'untouched by the second store');

        $this->archive()->store($version->monitor, $this->spool($body), $hashes);

        $this->assertSame([$path], $this->disk()->allFiles());
        $this->assertSame(
            'untouched by the second store',
            $this->disk()->get($path),
            'The second store rewrote a content-addressed blob that already existed.'
        );
    }

    public function test_a_store_keeps_byte_size_at_the_raw_body_length(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);

        $this->archive()->store($version->monitor, $this->spool($body), $this->hashesFor($body));

        $path = $this->archive()->blobPath($version->team_id, $version->content_hash);
        $storedSize = $this->disk()->size($path);

        // Guard the guard: the two numbers below can only disagree if the fixture
        // actually compresses, so prove the ratio before trusting the assertion.
        $this->assertGreaterThan(
            4,
            strlen($body) / $storedSize,
            'The fixture no longer compresses enough for this assertion to distinguish the two lengths.'
        );

        $version->refresh();

        $this->assertSame(
            strlen($body),
            $version->byte_size,
            '`byte_size` is the RAW decoded body length; the finalize step overwrote it.'
        );
        $this->assertNotSame($storedSize, $version->byte_size);
    }

    public function test_touch_advances_last_seen_at_without_writing_a_blob(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $seeded = $version->last_seen_at;

        $this->archive()->touch($version);

        $version->refresh();

        $this->assertTrue($version->last_seen_at->greaterThan($seeded));
        $this->assertTrue($version->first_seen_at->equalTo($seeded));
        $this->assertSame(strlen($body), $version->byte_size);
        $this->assertSame([], $this->disk()->allFiles(), 'The unchanged path needs no body and must write none.');
    }

    public function test_the_failure_hook_deletes_the_claimed_row_and_names_it_in_the_log(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $job = new ArchiveContent($version->monitor, $this->spool($body), $this->hashesFor($body));

        Log::spy();

        $job->failed(new RuntimeException('the mount went away mid-write'));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $context['monitor_id'] === $version->monitor_id
                && $context['content_hash'] === $version->content_hash)
            ->once();

        $this->assertDatabaseMissing('monitor_content_versions', [
            'id' => $version->getKey(),
        ]);
    }

    public function test_a_missing_sentinel_throws_and_the_failure_hook_releases_the_claim(): void
    {
        config(['content-archive.sentinel' => '.uptizm-mount-live']);

        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $spoolPath = $this->spool($body);

        $this->assertJobFails($version->monitor, $spoolPath, $this->hashesFor($body));

        $this->assertSame(
            [],
            $this->disk()->allFiles(),
            'A dead mount must abort before any write; a `local` driver over one fills the underlying disk.'
        );
        $this->assertDatabaseMissing('monitor_content_versions', [
            'id' => $version->getKey(),
        ]);
        $this->assertFileDoesNotExist($spoolPath);
    }

    public function test_a_present_sentinel_lets_the_write_proceed(): void
    {
        config(['content-archive.sentinel' => '.uptizm-mount-live']);
        $this->disk()->put('.uptizm-mount-live', '');

        $body = $this->fixtureBody();
        $version = $this->claim($body);

        $this->archive()->store($version->monitor, $this->spool($body), $this->hashesFor($body));

        $this->assertTrue(
            $this->disk()->exists($this->archive()->blobPath($version->team_id, $version->content_hash))
        );
    }

    public function test_a_null_sentinel_skips_the_mount_guard(): void
    {
        // The default, and the branch every other test here plus the live
        // verification pass relies on: an ordinary local root nobody seeds.
        $this->assertNull(config('content-archive.sentinel'));

        $body = $this->fixtureBody();
        $version = $this->claim($body);

        $this->archive()->store($version->monitor, $this->spool($body), $this->hashesFor($body));

        $this->assertTrue(
            $this->disk()->exists($this->archive()->blobPath($version->team_id, $version->content_hash))
        );
    }

    public function test_the_spool_file_is_deleted_after_a_successful_store(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $spoolPath = $this->spool($body);

        ArchiveContent::dispatch($version->monitor, $spoolPath, $this->hashesFor($body));

        $this->assertFileDoesNotExist($spoolPath, 'A finished archive write leaked its spool file onto local disk.');
    }

    public function test_the_spool_file_is_deleted_after_a_failed_store(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $spoolPath = $this->spool($body);

        // No claim row for THIS body, so the store fails loudly.
        $version->delete();

        $this->assertJobFails($version->monitor, $spoolPath, $this->hashesFor($body));

        $this->assertFileDoesNotExist($spoolPath, 'A failed archive write leaked its spool file onto local disk.');
    }

    public function test_store_never_inserts_a_version_row(): void
    {
        $body = $this->fixtureBody();
        $monitor = $this->monitor();

        $this->assertInstanceOf(
            RuntimeException::class,
            $this->captureThrowable(fn () => $this->archive()->store(
                $monitor,
                $this->spool($body),
                $this->hashesFor($body),
            )),
            'An unclaimed store must fail loudly rather than create the row it was meant to finalize.'
        );

        $this->assertSame(0, MonitorContentVersion::query()->count());
        $this->assertSame(
            [],
            $this->disk()->allFiles(),
            'An unclaimed store wrote bytes, which is exactly the orphaned blob the claim model prevents.'
        );
    }

    public function test_the_blob_path_derives_from_the_team_and_the_hash_alone(): void
    {
        $hash = hash('sha256', 'a body');

        $this->assertSame(
            'team-7/'.substr($hash, 0, 2).'/'.$hash.'.gz',
            $this->archive()->blobPath('team-7', $hash)
        );
    }

    public function test_the_blob_path_rejects_a_hash_that_is_not_sixty_four_lowercase_hex(): void
    {
        $rejected = [
            'uppercase' => strtoupper(hash('sha256', 'a body')),
            'too short' => substr(hash('sha256', 'a body'), 0, 32),
            'traversal' => '../'.str_repeat('a', 61),
        ];

        // Length alone is not the check: the traversal case is exactly 64
        // characters, which is how a path built by string length rather than by
        // shape would slip through into a delete on the backup remote.
        $this->assertSame(64, strlen($rejected['traversal']));

        foreach ($rejected as $label => $hash) {
            $this->assertInstanceOf(
                InvalidArgumentException::class,
                $this->captureThrowable(fn () => $this->archive()->blobPath('team-7', $hash)),
                "The path helper accepted a {$label} content hash."
            );
        }
    }

    public function test_the_job_payload_carries_a_path_and_provably_no_body(): void
    {
        $body = $this->fixtureBody();
        $version = $this->claim($body);
        $job = new ArchiveContent($version->monitor, $this->spool($body), $this->hashesFor($body));

        $serialized = serialize($job);

        $this->assertStringNotContainsString('<html', $serialized);
        $this->assertStringNotContainsString('monitor-row', $serialized);
        $this->assertStringNotContainsString(gzencode($body), $serialized);
        $this->assertSame((string) config('content-archive.queue'), $job->queue);
    }

    public function test_two_identical_stores_and_one_changed_store_leave_exactly_two_blobs(): void
    {
        $first = $this->fixtureBody('alpha');
        $changed = $this->fixtureBody('omega');

        $firstVersion = $this->claim($first);
        $monitor = $firstVersion->monitor;

        ArchiveContent::dispatch($monitor, $this->spool($first), $this->hashesFor($first));
        ArchiveContent::dispatch($monitor, $this->spool($first), $this->hashesFor($first));

        $changedVersion = $this->claim($changed, $monitor);

        ArchiveContent::dispatch($monitor, $this->spool($changed), $this->hashesFor($changed));

        $files = $this->disk()->allFiles();

        $this->assertCount(2, $files);

        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('#^[^/]+/[0-9a-f]{2}/[0-9a-f]{64}\.gz$#', $file);
        }

        $this->assertSame(2, MonitorContentVersion::query()->count());

        foreach ([$firstVersion, $changedVersion] as $version) {
            $version->refresh();

            $this->assertTrue(
                $version->last_seen_at->greaterThan($version->first_seen_at),
                'Every archived version must have been seen after it was first seen.'
            );
        }
    }

    /**
     * Run the job through the queue and require it to fail, so the framework's
     * own failure path (and therefore the job's `failed()` hook) is what runs,
     * not a hand-called method.
     */
    protected function assertJobFails(Monitor $monitor, string $spoolPath, NormalizedContent $hashes): void
    {
        $this->assertInstanceOf(
            RuntimeException::class,
            $this->captureThrowable(fn () => ArchiveContent::dispatch($monitor, $spoolPath, $hashes)),
            'The archive write was expected to fail and did not.'
        );
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

    /**
     * A body that compresses by roughly an order of magnitude, so the raw length
     * and the stored length can never be confused for one another.
     */
    protected function fixtureBody(string $marker = 'alpha'): string
    {
        $rows = '';

        for ($i = 0; $i < 240; $i++) {
            $rows .= '<tr id="monitor-'.$i.'"><td class="name">'.substr(md5((string) $i), 0, 12).' service</td>'
                .'<td class="status up">Operational</td><td class="latency">'.(97 + $i % 400)." ms</td></tr>\n";
        }

        return '<html><body data-marker="'.$marker.'"><table class="monitors">'.$rows.'</table></body></html>';
    }

    /**
     * The hash pair the check pipeline computes for a body.
     *
     * Built here rather than through the normalizer: this test is about the write
     * path, and the only property it needs is that the raw hash addresses the
     * blob while the normalized one keys the row.
     */
    protected function hashesFor(string $body): NormalizedContent
    {
        return new NormalizedContent(
            hash('sha256', $body),
            hash('sha256', (string) preg_replace('/\s+/', ' ', $body)),
            (int) config('content-archive.normalizer_version'),
            false,
        );
    }

    /**
     * A local spool file holding the gzipped body, as the check pipeline leaves
     * it for the job.
     */
    protected function spool(string $body): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'uptizm-content-');

        file_put_contents($path, gzencode($body));

        $this->spoolPaths[] = $path;

        return $path;
    }

    /**
     * The claim row the check pipeline inserts before dispatching the job.
     */
    protected function claim(string $body, ?Monitor $monitor = null): MonitorContentVersion
    {
        $monitor ??= $this->monitor();
        $hashes = $this->hashesFor($body);
        $seenAt = now()->subMinutes(self::SEEDED_AGE_MINUTES);

        return MonitorContentVersion::query()->create([
            'monitor_id' => $monitor->getKey(),
            'team_id' => $monitor->team_id,
            'content_hash' => $hashes->rawHash,
            'content_hash_normalized' => $hashes->normalizedHash,
            'normalizer_version' => $hashes->normalizerVersion,
            'byte_size' => strlen($body),
            'content_type' => 'text/html',
            'truncated' => false,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ])->setRelation('monitor', $monitor);
    }

    /**
     * An HTTP monitor owning a team, built the way the rest of this suite builds
     * one (neither model has a working factory).
     */
    protected function monitor(): Monitor
    {
        $team = Team::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Archive team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Archive monitor',
            'type' => MonitorType::Http,
            'url' => 'https://example.test/status',
            'check_interval_sec' => 60,
        ]);
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
