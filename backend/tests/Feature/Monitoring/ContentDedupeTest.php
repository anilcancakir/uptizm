<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Jobs\ArchiveContent;
use App\Jobs\PerformMonitorCheck;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorContentVersion;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ContentArchive;
use App\Services\Monitoring\RelayClient;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\ContentNormalizer;
use App\Support\Monitoring\NormalizedContent;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Pins the change decision the check pipeline makes about a response body: what
 * gets archived, what gets discarded, and which address the check row records.
 *
 * The subject is a CLAIM, not a read-then-decide. Two regions of one monitor fan
 * out in the same tick, so the decision and the mutual exclusion have to be the
 * same operation; the two properties that follow from that are the ones most of
 * these tests exist for:
 *
 * - `content_hash` on a check is the ADDRESS OF THE VERSION ITS CONTENT RESOLVED
 *   TO, not the hash of its own bytes. On the unchanged path it is an EARLIER
 *   body's raw hash, which is what makes every region converge on one blob.
 * - The claim is looked up by `(monitor_id, content_hash_normalized,
 *   normalizer_version)` and by nothing else. Keying on the new body's raw hash
 *   disables dedupe entirely; keying on the previous CHECK row gives a 5-region
 *   monitor five copies of one page.
 *
 * The two real fixtures are load-bearing: `fluttersdk-home-1.html` and `-2.html`
 * are the same 182 KB page fetched twice and differ ONLY in a CSRF token, so they
 * carry DIFFERENT raw hashes and the SAME normalized hash. That pair is the only
 * honest way to tell an implementation that dedupes from one that merely compares
 * bytes. `edited-heading.html` is the genuinely changed body.
 */
class ContentDedupeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The spool-file prefix the check pipeline hands to `tempnam()`. Mirrored
     * here (rather than exposed) so this test can prove the backlog breaker
     * spooled NOTHING, which needs a name to look for.
     */
    protected const string SPOOL_PREFIX = 'uptizm-content-';

    /**
     * How far back a pre-seeded claim was last seen. Minutes because the
     * timestamp columns serialize without sub-second precision, so a shorter gap
     * could not prove the value moved.
     */
    protected const int SEEDED_AGE_MINUTES = 10;

    /**
     * Spool files that already existed when the test started, so teardown can
     * remove only the ones this test leaked (a faked archive job never runs its
     * own cleanup).
     *
     * @var array<int, string>
     */
    protected array $spoolsAtStart = [];

    /**
     * Probes run so far, spaced one second apart in `checked_at`.
     *
     * `checked_at` serializes at second precision, so probes fired inside one
     * second would share a value and any ordering assertion over them would
     * return rows in whatever order the database felt like.
     */
    protected int $probeSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spoolsAtStart = $this->spoolFiles();
    }

    protected function tearDown(): void
    {
        foreach (array_diff($this->spoolFiles(), $this->spoolsAtStart) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_the_first_check_archives_one_blob_and_records_both_hashes(): void
    {
        $monitor = $this->monitor();
        $body = $this->fixture('fluttersdk-home-1.html');
        $hashes = ContentNormalizer::normalize($body);

        $this->runCheck($monitor, 'us-east', $body);

        // 1. Exactly one blob, at the address derived from the RAW hash.
        $this->assertSame(
            [$this->blobPath($monitor, $hashes->rawHash)],
            $this->disk()->allFiles()
        );
        $this->assertSame(gzencode($body), $this->disk()->get($this->blobPath($monitor, $hashes->rawHash)));

        // 2. One version row, carrying the raw decoded length and the wire's
        //    content type.
        $version = MonitorContentVersion::query()->sole();
        $this->assertSame($hashes->rawHash, $version->content_hash);
        $this->assertSame($hashes->normalizedHash, $version->content_hash_normalized);
        $this->assertSame($hashes->normalizerVersion, $version->normalizer_version);
        $this->assertSame(strlen($body), $version->byte_size);
        $this->assertSame($monitor->team_id, $version->team_id);
        $this->assertSame('text/html; charset=utf-8', $version->content_type);
        $this->assertFalse($version->truncated);

        // 3. The check records the address it resolved to plus its own change
        //    signal.
        $check = MonitorCheck::query()->sole();
        $this->assertSame($hashes->rawHash, $check->content_hash);
        $this->assertSame($hashes->normalizedHash, $check->content_hash_normalized);
    }

    /**
     * The normalization payoff and the address rule in one: a body that differs
     * only in its CSRF token must write no second blob AND must record the FIRST
     * body's raw hash, not its own.
     */
    public function test_a_token_only_change_writes_no_new_blob_and_records_the_found_address(): void
    {
        $monitor = $this->monitor();
        $first = $this->fixture('fluttersdk-home-1.html');
        $second = $this->fixture('fluttersdk-home-2.html');

        $firstHashes = ContentNormalizer::normalize($first);
        $secondHashes = ContentNormalizer::normalize($second);

        // Guard the guard: without a differing raw hash and a matching normalized
        // hash these fixtures would prove nothing about dedupe at all.
        $this->assertNotSame($firstHashes->rawHash, $secondHashes->rawHash);
        $this->assertSame($firstHashes->normalizedHash, $secondHashes->normalizedHash);

        $this->runCheck($monitor, 'us-east', $first);

        $seenAt = MonitorContentVersion::query()->sole()->last_seen_at;

        $this->travel(2)->minutes();

        $this->runCheck($monitor, 'us-east', $second);

        // 1. One blob and one row survive the second, token-differing body.
        $this->assertCount(1, $this->disk()->allFiles());
        $this->assertSame(1, MonitorContentVersion::query()->count());

        // 2. The second check points at the blob that actually holds its content.
        $secondCheck = MonitorCheck::query()->orderByDesc('checked_at')->first();
        $this->assertSame($firstHashes->rawHash, $secondCheck->content_hash);
        $this->assertNotSame(
            $secondHashes->rawHash,
            $secondCheck->content_hash,
            'The unchanged path recorded sha256 of its own bytes, which addresses a blob that does not exist.'
        );
        $this->assertSame($secondHashes->normalizedHash, $secondCheck->content_hash_normalized);

        // 3. The FOUND row's last-seen moved, or retention deletes a live version
        //    at day 30.
        $version = MonitorContentVersion::query()->sole();
        $this->assertTrue(
            $version->last_seen_at->greaterThan($seenAt),
            'The unchanged path left `last_seen_at` frozen, so retention would expire a version still in use.'
        );
        $this->assertTrue($version->first_seen_at->lessThan($version->last_seen_at));
    }

    public function test_the_full_dedupe_matrix_over_four_checks(): void
    {
        $monitor = $this->monitor();
        $first = $this->fixture('fluttersdk-home-1.html');
        $tokenDiffering = $this->fixture('fluttersdk-home-2.html');
        $changed = $this->fixture('edited-heading.html');

        $firstAddress = ContentNormalizer::normalize($first)->rawHash;
        $changedAddress = ContentNormalizer::normalize($changed)->rawHash;

        // 1. First sighting: one blob.
        $this->runCheck($monitor, 'us-east', $first);
        $this->assertCount(1, $this->disk()->allFiles());

        // 2. Token-differing body: no new blob.
        $this->runCheck($monitor, 'us-east', $tokenDiffering);
        $this->assertCount(1, $this->disk()->allFiles());

        // 3. Genuinely changed body: a second blob.
        $this->runCheck($monitor, 'us-east', $changed);
        $this->assertCount(2, $this->disk()->allFiles());

        // 4. Back to the first content: it resolves to the first blob and
        //    rewrites nothing.
        $this->runCheck($monitor, 'us-east', $first);
        $this->assertCount(2, $this->disk()->allFiles());

        $this->assertSame(2, MonitorContentVersion::query()->count());

        $addresses = MonitorCheck::query()->orderBy('checked_at')->get()->pluck('content_hash')->all();
        $this->assertSame(
            [$firstAddress, $firstAddress, $changedAddress, $firstAddress],
            $addresses
        );
    }

    /**
     * The property a per-region chain silently loses: a 5-region monitor would
     * otherwise store five copies of every version.
     */
    public function test_two_regions_of_one_monitor_converge_on_one_blob(): void
    {
        $monitor = $this->monitor();
        $first = $this->fixture('fluttersdk-home-1.html');
        $second = $this->fixture('fluttersdk-home-2.html');

        $this->runCheck($monitor, 'us-east', $first);
        $this->runCheck($monitor, 'eu-west', $second);

        $this->assertCount(1, $this->disk()->allFiles());
        $this->assertSame(1, MonitorContentVersion::query()->count());

        $addresses = MonitorCheck::query()->get()->pluck('content_hash')->unique();
        $this->assertSame(2, MonitorCheck::query()->count());
        $this->assertCount(
            1,
            $addresses,
            'Two regions of one monitor recorded two different addresses, so each region kept its own blob.'
        );
        $this->assertSame(ContentNormalizer::normalize($first)->rawHash, $addresses->first());
    }

    /**
     * The race the claim exists for: the other region won the insert in the same
     * tick, so this one must take the touch branch even though NO check row and
     * no blob of its own exist yet, and even though its raw hash matches nothing.
     */
    public function test_a_lost_claim_touches_the_winners_row_and_dispatches_nothing(): void
    {
        $monitor = $this->monitor();
        $winnerBody = $this->fixture('fluttersdk-home-1.html');
        $loserBody = $this->fixture('fluttersdk-home-2.html');

        // 1. The winner's claim row plus its published blob, exactly the state a
        //    same-tick race leaves behind for the second region.
        $winner = $this->seedClaim($monitor, $winnerBody);
        ArchiveContent::dispatch($monitor, $this->spool($winnerBody), ContentNormalizer::normalize($winnerBody));

        $seenAt = $winner->refresh()->last_seen_at;
        $this->travel(2)->minutes();

        Bus::fake([ArchiveContent::class]);

        $this->runCheck($monitor, 'eu-west', $loserBody);

        // 2. The loser writes nothing.
        Bus::assertNotDispatched(ArchiveContent::class);
        $this->assertSame(1, MonitorContentVersion::query()->count());
        $this->assertCount(1, $this->disk()->allFiles());

        // 3. It records the WINNER's address and advances the winner's row.
        $check = MonitorCheck::query()->sole();
        $this->assertSame($winner->content_hash, $check->content_hash);
        $this->assertSame(ContentNormalizer::normalize($loserBody)->normalizedHash, $check->content_hash_normalized);
        $this->assertTrue($winner->refresh()->last_seen_at->greaterThan($seenAt));
    }

    /**
     * A row that vanishes between the claim and the re-read (a concurrent
     * `failed()` cleanup) must fail OPEN to storing, and must never be
     * dereferenced.
     */
    public function test_a_version_row_that_vanishes_after_the_claim_is_archived_not_dereferenced(): void
    {
        $monitor = $this->monitor();
        $body = $this->fixture('fluttersdk-home-2.html');
        $hashes = ContentNormalizer::normalize($body);

        // 1. A row that makes the claim collide, so the decision reaches the
        //    re-read at all.
        $this->seedClaim($monitor, $this->fixture('fluttersdk-home-1.html'));

        // 2. Delete it the instant the claim statement has run: the exact window
        //    a concurrent failure hook occupies in production.
        DB::listen(function (QueryExecuted $query): void {
            if (! str_contains($query->sql, 'insert or ignore into "monitor_content_versions"')) {
                return;
            }

            MonitorContentVersion::query()->delete();
        });

        Bus::fake([ArchiveContent::class]);

        $this->runCheck($monitor, 'us-east', $body);

        Bus::assertDispatched(ArchiveContent::class, function (ArchiveContent $job) use ($hashes): bool {
            return $job->hashes->rawHash === $hashes->rawHash;
        });

        $check = MonitorCheck::query()->sole();
        $this->assertSame(
            $hashes->rawHash,
            $check->content_hash,
            'A vanished version row must resolve to storing this body, not to a null dereference.'
        );
    }

    /**
     * The failed-write hole: the claim exists before the bytes do, so a write
     * that never lands has to release the claim or every later identical body
     * reads as already-archived with nothing on disk.
     */
    public function test_a_failed_write_releases_the_claim_so_the_next_identical_body_archives_again(): void
    {
        // A sentinel that is not there is a dead mount, which is the one write
        // failure this suite can force without touching the archive service.
        config(['content-archive.sentinel' => '.uptizm-mount-live']);

        $monitor = $this->monitor();
        $body = $this->fixture('fluttersdk-home-1.html');
        $address = ContentNormalizer::normalize($body)->rawHash;

        $this->runCheck($monitor, 'us-east', $body);

        // 1. The write failed, the claim is gone, and the check still persisted:
        //    the archive degrades, the monitoring never does.
        $this->assertSame(0, MonitorContentVersion::query()->count());
        $this->assertFalse($this->disk()->exists($this->blobPath($monitor, $address)));
        $this->assertSame(1, MonitorCheck::query()->count());

        // 2. With the mount back, the very same body claims and archives again
        //    rather than being discarded forever.
        $this->disk()->put('.uptizm-mount-live', '');

        $this->runCheck($monitor, 'us-east', $body);

        $this->assertSame(1, MonitorContentVersion::query()->count());
        $this->assertTrue($this->disk()->exists($this->blobPath($monitor, $address)));
    }

    /**
     * The other half of the failed-write hole, and the half the archive job's
     * failure hook cannot reach: a write that was never dispatched at all leaves
     * a claim nothing would ever release.
     */
    public function test_a_spool_write_that_fails_releases_the_claim_it_had_taken(): void
    {
        $monitor = $this->monitor();

        // A local disk that refuses a write is the one archive failure PHP cannot
        // be asked to produce, so the seam is overridden the way this suite
        // already overrides a browser capture it needs to fail (tests/TestCase.php).
        $job = new class($monitor, 'us-east') extends PerformMonitorCheck
        {
            protected function dispatchArchiveWrite(string $body, NormalizedContent $hashes): void
            {
                throw new RuntimeException('the local spool volume is full');
            }
        };

        $this->fakeProbe($this->fixture('fluttersdk-home-1.html'));

        $this->app->call([$job, 'handle']);

        $this->assertSame(
            0,
            MonitorContentVersion::query()->count(),
            'A claim survived a write that was never dispatched, so every later identical body reads as archived.'
        );
        $this->assertSame([], $this->disk()->allFiles());

        // And the monitoring still happened, which is the ordering that matters.
        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertNull(MonitorCheck::query()->sole()->content_hash);
    }

    /**
     * The version row is the authority, not the hash on a prior check. This
     * fails on any implementation that chains off the previous check row.
     */
    public function test_a_deleted_version_row_re_archives_even_though_the_prior_check_kept_its_hash(): void
    {
        $monitor = $this->monitor();
        $body = $this->fixture('fluttersdk-home-1.html');
        $address = ContentNormalizer::normalize($body)->rawHash;

        $this->runCheck($monitor, 'us-east', $body);

        // Retention has expired the version and its blob; the check row keeps its
        // hash on purpose, so an old check still records what its content was.
        MonitorContentVersion::query()->delete();
        $this->disk()->delete($this->blobPath($monitor, $address));

        $this->runCheck($monitor, 'us-east', $body);

        $this->assertSame(1, MonitorContentVersion::query()->count());
        $this->assertTrue(
            $this->disk()->exists($this->blobPath($monitor, $address)),
            'A matching hash on a prior check was treated as proof the content was archived.'
        );
        $this->assertSame($address, MonitorCheck::query()->orderByDesc('checked_at')->first()->content_hash);
    }

    /**
     * Defence in depth against a worker deployment older than the edge filter:
     * the PHP side re-checks the type even when `content` arrived.
     */
    public function test_a_content_type_outside_the_allowlist_is_never_archived(): void
    {
        $monitor = $this->monitor();

        Bus::fake([ArchiveContent::class]);

        $this->runCheck($monitor, 'us-east', $this->fixture('fluttersdk-home-1.html'), 'application/pdf');

        Bus::assertNotDispatched(ArchiveContent::class);
        $this->assertSame(0, MonitorContentVersion::query()->count());
        $this->assertSame([], $this->disk()->allFiles());

        $check = MonitorCheck::query()->sole();
        $this->assertNull($check->content_hash);
        $this->assertNull($check->content_hash_normalized);
    }

    /**
     * An attacker-chosen header must never be able to take the monitoring path
     * down: the value is cut to the column width where it enters, so the claim
     * insert cannot throw on it.
     */
    public function test_an_over_long_content_type_still_persists_the_check(): void
    {
        $monitor = $this->monitor();
        $body = $this->fixture('fluttersdk-home-1.html');
        $header = 'text/html; charset='.str_repeat('u', 300 - strlen('text/html; charset='));

        $this->assertSame(300, strlen($header));

        // Built through the wire boundary rather than by hand, because the
        // truncation under test lives there.
        $this->fakeRelay(fn (Monitor $target, string $region): CheckResult => CheckResult::fromWorkerPayload([
            'monitor_id' => (string) $target->id,
            'region' => $region,
            'checked_at' => now()->addSeconds($this->probeSequence++)->toAtomString(),
            'status' => MonitorStatus::Up->value,
            'status_code' => 200,
            'response_ms' => 128,
            'probe_run_id' => (string) Str::uuid(),
            'response_headers' => ['content-type' => $header],
            'content' => $body,
            'content_type' => $header,
        ]));

        $this->dispatchCheck($monitor, 'us-east');

        $this->assertSame(1, MonitorCheck::query()->count());
        $this->assertSame(128, strlen((string) MonitorContentVersion::query()->sole()->content_type));
        $this->assertCount(1, $this->disk()->allFiles());
    }

    /**
     * The backlog breaker, and the clause that matters most: NO version row. A
     * claim whose blob never lands is unreachable by both the retry path and
     * retention, so firing the breaker after the claim would be worse than not
     * firing it at all.
     */
    public function test_the_backlog_breaker_claims_nothing_spools_nothing_and_dispatches_nothing(): void
    {
        $monitor = $this->monitor();
        $body = $this->fixture('fluttersdk-home-1.html');

        config(['content-archive.queue_backlog_limit' => 1]);

        // Only the archive job is faked, so the processing hop still runs and the
        // check row still lands; the two seeded writes ARE the backlog.
        Queue::fake([ArchiveContent::class]);
        ArchiveContent::dispatch($monitor, '/tmp/not-a-real-spool-a', ContentNormalizer::normalize('a'));
        ArchiveContent::dispatch($monitor, '/tmp/not-a-real-spool-b', ContentNormalizer::normalize('b'));

        $this->assertSame(2, Queue::size((string) config('content-archive.queue')));

        $spoolsBefore = $this->spoolFiles();

        Log::spy();

        $this->runCheck($monitor, 'us-east', $body);

        // 1. Nothing claimed, nothing spooled, nothing dispatched. Compared as an
        //    ADDED set, so an unrelated temp file sharing the prefix cannot turn
        //    this into a false failure.
        $this->assertSame(0, MonitorContentVersion::query()->count());
        $this->assertSame([], array_diff($this->spoolFiles(), $spoolsBefore));
        Queue::assertPushed(ArchiveContent::class, 2);
        $this->assertSame([], $this->disk()->allFiles());

        // 2. The check itself is untouched by the degradation.
        $check = MonitorCheck::query()->sole();
        $this->assertSame(MonitorStatus::Up, $check->status);
        $this->assertNull($check->content_hash);

        // Logged ONCE, and naming the monitor: a breaker that trips silently is
        // indistinguishable from an archive that quietly stopped working.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['monitor_id'] === $monitor->id)
            ->once();
    }

    /**
     * A limit that is not a positive integer disables the breaker instead of
     * silently disabling the archive: `1 > null` is true in PHP.
     */
    public function test_a_null_backlog_limit_does_not_disable_archiving(): void
    {
        $monitor = $this->monitor();

        config(['content-archive.queue_backlog_limit' => null]);

        $this->runCheck($monitor, 'us-east', $this->fixture('fluttersdk-home-1.html'));

        $this->assertSame(1, MonitorContentVersion::query()->count());
        $this->assertCount(1, $this->disk()->allFiles());
    }

    public function test_a_probe_without_a_body_archives_nothing(): void
    {
        $monitor = $this->monitor();

        Bus::fake([ArchiveContent::class]);
        Log::spy();

        // A TCP probe: no body and no type at all.
        $this->runCheck($monitor, 'us-east', null, null);

        // A body the EDGE filtered out: the worker still reports which type it
        // rejected, so the type is present while the content is not. This is the
        // shape that reaches the hashing if the absent-body check is missing.
        $this->runCheck($monitor, 'us-east', null);

        Bus::assertNotDispatched(ArchiveContent::class);
        $this->assertSame(0, MonitorContentVersion::query()->count());
        $this->assertSame([], $this->disk()->allFiles());

        foreach (MonitorCheck::query()->get() as $check) {
            $this->assertNull($check->content_hash);
            $this->assertNull($check->content_hash_normalized);
        }

        $this->assertSame(2, MonitorCheck::query()->count());

        // Skipped, not swallowed: an absent body must never reach the hashing,
        // which would surface as a logged error rather than this clean no-op.
        Log::shouldNotHaveReceived('error');
    }

    /**
     * The queue payload carries a PATH and provably no markup; a 1 MB page in a
     * Redis payload is what the whole content design exists to avoid.
     */
    public function test_the_dispatched_job_carries_a_spool_path_and_provably_no_markup(): void
    {
        $monitor = $this->monitor();

        Bus::fake([ArchiveContent::class]);

        $this->runCheck($monitor, 'us-east', $this->fixture('fluttersdk-home-1.html'));

        Bus::assertDispatched(ArchiveContent::class, function (ArchiveContent $job): bool {
            return is_string($job->spoolPath)
                && is_file($job->spoolPath)
                && ! str_contains(serialize($job), '<html');
        });
    }

    /**
     * Run one probe through the real {@see PerformMonitorCheck} handle, with the
     * relay faked to return `$body` as the full decoded content.
     *
     * `$contentType` travels independently of `$body`, exactly as the worker
     * sends it: a body the edge filtered out still reports the type it rejected.
     */
    protected function runCheck(
        Monitor $monitor,
        string $region,
        ?string $body,
        ?string $contentType = 'text/html; charset=utf-8',
    ): void {
        $this->fakeProbe($body, $contentType);

        $this->dispatchCheck($monitor, $region);
    }

    /**
     * Bind a relay returning one successful HTTP probe carrying `$body`.
     */
    protected function fakeProbe(?string $body, ?string $contentType = 'text/html; charset=utf-8'): void
    {
        $this->fakeRelay(fn (Monitor $target, string $probeRegion): CheckResult => new CheckResult(
            monitorId: (string) $target->id,
            region: $probeRegion,
            checkedAt: now()->addSeconds($this->probeSequence++)->toDateTimeImmutable(),
            status: MonitorStatus::Up,
            statusCode: 200,
            responseMs: 128,
            errorMessage: null,
            timingDnsMs: 1,
            timingConnectMs: 2,
            timingTlsMs: 3,
            timingTtfbMs: 4,
            timingDownloadMs: 5,
            responseHeaders: $contentType === null ? [] : ['content-type' => $contentType],
            responseBodyPreview: $body === null ? null : substr($body, 0, 10240),
            probeRunId: (string) Str::uuid(),
            content: $body,
            contentType: $contentType,
        ));
    }

    /**
     * Invoke `handle()` through the container, so the dependency the archive
     * decision needs is resolved the way production resolves it.
     */
    protected function dispatchCheck(Monitor $monitor, string $region): void
    {
        $this->app->call([new PerformMonitorCheck($monitor, $region), 'handle']);
    }

    /**
     * Bind a {@see RelayClient} double returning whatever `$factory` builds, so
     * no test here performs a real HTTP call.
     */
    protected function fakeRelay(Closure $factory): void
    {
        $this->app->bind(RelayClient::class, fn (): RelayClient => new class($factory) extends RelayClient
        {
            public function __construct(protected Closure $factory) {}

            public function dispatch(Monitor $monitor, string $region): CheckResult
            {
                return ($this->factory)($monitor, $region);
            }
        });
    }

    /**
     * The claim row a competing region's `insertOrIgnore` would have won, seeded
     * far enough in the past that a later touch is observable.
     */
    protected function seedClaim(Monitor $monitor, string $body): MonitorContentVersion
    {
        $hashes = ContentNormalizer::normalize($body);
        $seenAt = now()->subMinutes(self::SEEDED_AGE_MINUTES);

        return MonitorContentVersion::query()->create([
            'monitor_id' => $monitor->getKey(),
            'team_id' => $monitor->team_id,
            'content_hash' => $hashes->rawHash,
            'content_hash_normalized' => $hashes->normalizedHash,
            'normalizer_version' => $hashes->normalizerVersion,
            'byte_size' => strlen($body),
            'content_type' => 'text/html; charset=utf-8',
            'truncated' => false,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    /**
     * A local spool file holding the gzipped body, as the check pipeline leaves
     * it for the archive job.
     */
    protected function spool(string $body): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), self::SPOOL_PREFIX);

        file_put_contents($path, gzencode($body));

        return $path;
    }

    /**
     * @return array<int, string>
     */
    protected function spoolFiles(): array
    {
        return glob(sys_get_temp_dir().'/'.self::SPOOL_PREFIX.'*') ?: [];
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/content/'.$name));
    }

    protected function blobPath(Monitor $monitor, string $contentHash): string
    {
        return app(ContentArchive::class)->blobPath($monitor->team_id, $contentHash);
    }

    protected function disk(): Filesystem
    {
        return Storage::disk((string) config('content-archive.disk'));
    }

    /**
     * An HTTP monitor owning a team, built the way the rest of this suite builds
     * one (neither model has a working factory).
     */
    protected function monitor(): Monitor
    {
        $user = User::query()->create([
            'name' => 'Dedupe Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Dedupe team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Dedupe monitor',
            'type' => MonitorType::Http,
            'url' => 'https://example.test/status',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);
    }
}
