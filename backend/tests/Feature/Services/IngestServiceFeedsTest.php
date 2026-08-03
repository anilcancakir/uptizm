<?php

namespace Tests\Feature\Services;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\ServiceStatusSource;
use App\Jobs\IngestServiceFeed;
use App\Jobs\IngestServiceFeeds;
use App\Models\Service;
use App\Models\ServiceFeedSnapshot;
use App\Services\Services\FeedFetcher;
use App\Support\Monitoring\HostGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Locks the feed-ingestion contract: the fan-out's selection, the five polling
 * commitments {@see FeedFetcher} owns, failure-as-a-row, and the single condition
 * under which `content_changed_at` and the page cache may move.
 *
 * ## Every refusal branch is exercised in ISOLATION, on purpose
 *
 * Five separate conditions stop a request (`status_source` of `none`, unreviewed
 * terms, `feed_disabled_at`, the 60-second floor, and an SSRF rejection), plus a
 * sixth in the fan-out (`is_published`). A fixture that trips two of them at once
 * proves neither: the suite would stay green after deleting one check, because the
 * other refused instead. So each test below satisfies the other conditions and
 * breaks exactly one, and every "no request happened" case is paired with a mirror
 * control that DOES send, so the assertion cannot pass because the whole path was
 * broken.
 *
 * Outbound HTTP is faked and DNS is stubbed (an anonymous {@see HostGuard} whose
 * resolution is fixed, the technique `tests/Unit/Support/HostGuardWebhookTest.php`
 * established), so nothing here touches the network in either layer.
 */
class IngestServiceFeedsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The faked feed host. Not a real domain: `.test` cannot resolve, and the
     * stubbed guard supplies the address the connection would be pinned to.
     */
    private const string FEED_URL = 'https://status.example.test/api/v2/summary.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->stubDnsResolvingTo(['203.0.113.10']);
    }

    /** A successful fetch writes exactly one snapshot carrying parsed enum values. */
    public function test_a_successful_fetch_writes_one_snapshot_with_parsed_enum_values(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200, [
                'ETag' => '"first-etag"',
            ]),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $this->assertSame(1, ServiceFeedSnapshot::query()->count());

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();
        $this->assertSame(200, $snapshot->http_status);
        $this->assertNull($snapshot->error);
        // The provider's own word, quoted rather than translated.
        $this->assertSame('major', $snapshot->indicator);

        $statuses = [];
        foreach ((array) $snapshot->components as $component) {
            $statuses[$component['label']] = $component['status'] === null
                ? null
                : ComponentStatus::from($component['status']);
        }

        $this->assertSame(ComponentStatus::Operational, $statuses['Git Operations']);
        $this->assertSame(ComponentStatus::MajorOutage, $statuses['Actions']);
        // Statuspage's `under_maintenance` has no local case: unknown, not healthy.
        $this->assertNull($statuses['Scheduled Window']);

        $impacts = [];
        foreach ((array) $snapshot->incidents as $incident) {
            $impacts[$incident['title']] = $incident['impact'] === null
                ? null
                : IncidentImpact::from($incident['impact']);
        }

        $this->assertSame(IncidentImpact::Critical, $impacts['Actions runners are unavailable']);
        $this->assertArrayNotHasKey('Resolved: cache eviction storm', $impacts);
    }

    /** The same job reads a Google feed, which is the second arm of the adapter `match`. */
    public function test_it_reads_a_google_feed_through_the_same_job(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->googleBody(), 200),
        ]);

        $service = $this->makeService([
            'status_source' => ServiceStatusSource::GoogleCloud,
            'status_source_url' => 'https://status.example.test/incidents.json',
        ]);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();

        $this->assertNull($snapshot->indicator);
        $this->assertSame(
            [
                '5MpKAZk3gY4xhLtE1jjR',
                'L4Vfz9dCbaFdvJp4qhTX',
                'dataflowKeyGY4xhLtE1jj',
            ],
            array_column((array) $snapshot->components, 'label'),
        );
    }

    /**
     * A second fetch inside the floor makes NO request.
     *
     * Everything else is satisfied here: published, terms reviewed, a live feed
     * source, no disable. The ONLY reason nothing is sent is the age of the
     * previous snapshot.
     */
    public function test_a_second_fetch_inside_the_minimum_interval_makes_no_request(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService();
        $this->givenPreviousSnapshot($service, secondsAgo: 10);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertNothingSent();
        $this->assertSame(1, ServiceFeedSnapshot::query()->count());
    }

    /**
     * The mirror control for the floor: one second past it, the same setup DOES
     * fetch. Without this, the test above would pass on a fetcher that never
     * requested anything at all.
     */
    public function test_a_fetch_past_the_minimum_interval_makes_a_request(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService();
        $this->givenPreviousSnapshot($service, secondsAgo: FeedFetcher::MIN_INTERVAL_SECONDS + 1);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertSentCount(1);
        $this->assertSame(2, ServiceFeedSnapshot::query()->count());
    }

    /**
     * A `304` writes no duplicate row: it touches the existing one, because the
     * provider just asserted that what we already parsed is still current.
     */
    public function test_a_304_response_writes_no_duplicate_snapshot(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response('', 304),
        ]);

        $service = $this->makeService();
        $previous = $this->givenPreviousSnapshot($service, secondsAgo: 300, etag: '"cached-etag"');
        $previousFetchedAt = $previous->fetched_at;

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertSentCount(1);
        $this->assertSame(1, ServiceFeedSnapshot::query()->count());

        $touched = $previous->fresh();
        $this->assertNotNull($touched);
        $this->assertTrue(
            $touched->fetched_at->gt($previousFetchedAt),
            'A 304 must move the reading freshness, or the page cannot say when it last checked.',
        );
        // The parsed content and the ETag survive, which is what makes the NEXT
        // conditional request possible.
        $this->assertSame('"cached-etag"', $touched->etag);
        $this->assertSame('unchanged-hash', $touched->content_hash_normalized);
    }

    /** A `429` disables the feed, records the failure, and is never retried. */
    public function test_a_429_disables_the_feed_and_records_the_failure(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response('Too many requests', 429),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        // Exactly one attempt: retrying into a rate limit is how a bot earns a
        // permanent block.
        Http::assertSentCount(1);

        $service->refresh();
        $this->assertNotNull($service->feed_disabled_at);
        $this->assertStringContainsString('429', (string) $service->feed_disabled_reason);

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();
        $this->assertSame(429, $snapshot->http_status);
        $this->assertStringContainsString('429', (string) $snapshot->error);
        $this->assertNull($snapshot->content_hash_normalized);
        // A provider's rate limit is not a content change.
        $this->assertNull($service->content_changed_at);
    }

    /** A `403` does the same as a `429`. */
    public function test_a_403_disables_the_feed_and_records_the_failure(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response('Forbidden', 403),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertSentCount(1);

        $service->refresh();
        $this->assertNotNull($service->feed_disabled_at);
        $this->assertStringContainsString('403', (string) $service->feed_disabled_reason);
    }

    /**
     * A disabled feed makes no request on a later run, and nothing clears the
     * disable automatically.
     *
     * The floor is deliberately NOT tripped here (no previous snapshot at all),
     * so the only reason nothing is sent is `feed_disabled_at`.
     */
    public function test_a_disabled_feed_makes_no_request_on_a_subsequent_run(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService([
            'feed_disabled_at' => now()->subDay(),
            'feed_disabled_reason' => 'Disabled automatically after HTTP 429 from status.example.test.',
        ]);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertNothingSent();
        $this->assertSame(0, ServiceFeedSnapshot::query()->count());

        // And the fan-out will not pick it up either.
        Queue::fake();
        (new IngestServiceFeeds)->handle();
        Queue::assertNothingPushed();
    }

    /** A connection failure is recorded on a row rather than thrown. */
    public function test_a_connection_failure_writes_a_snapshot_carrying_the_error(): void
    {
        Http::fake([
            'status.example.test/*' => Http::failedConnection('cURL error 28: Operation timed out'),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();
        $this->assertNull($snapshot->http_status);
        $this->assertStringContainsString('timed out', (string) $snapshot->error);
        $this->assertSame([], (array) $snapshot->components);
        // A failure is not a content change; bumping lastmod on every provider
        // hiccup is exactly how the signal becomes untrustworthy.
        $this->assertNull($service->refresh()->content_changed_at);
    }

    /** A non-2xx that is neither 429 nor 403 is recorded and leaves the feed enabled. */
    public function test_a_server_error_is_recorded_without_disabling_the_feed(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response('nope', 503),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();
        $this->assertSame(503, $snapshot->http_status);
        $this->assertNull($service->refresh()->feed_disabled_at);
    }

    /** A body that is not JSON is recorded as a failure, not as an empty reading. */
    public function test_a_non_json_body_is_recorded_as_a_failure(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response('<html>maintenance</html>', 200),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();
        $this->assertStringContainsString('not a JSON document', (string) $snapshot->error);
    }

    /**
     * A cross-host redirect is recorded as an error and NOT followed.
     *
     * This is a real migration, not a hypothetical: `status.anthropic.com` now
     * 302s to `status.claude.com`. Following it would fetch a host whose terms
     * nobody reviewed, from an address the SSRF guard never validated.
     */
    public function test_a_cross_host_redirect_is_recorded_and_not_followed(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response('', 302, [
                'Location' => 'https://status.elsewhere.test/api/v2/summary.json',
            ]),
            'status.elsewhere.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        // One request, to the reviewed host only.
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'elsewhere'));

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();
        $this->assertSame(302, $snapshot->http_status);
        $this->assertStringContainsString('status.elsewhere.test', (string) $snapshot->error);
        $this->assertStringContainsString('not followed', (string) $snapshot->error);
        // Nothing was parsed, so nothing was published.
        $this->assertNull($snapshot->indicator);
        $this->assertNull($service->refresh()->content_changed_at);
    }

    /**
     * A host that now resolves inside the denylist is not requested at all.
     *
     * The guard runs immediately before the request rather than only at write
     * time, because a DNS record can change in between (rebinding).
     */
    public function test_a_host_resolving_to_an_internal_address_is_never_requested(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $this->stubDnsResolvingTo(['10.0.0.5']);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertNothingSent();

        $snapshot = ServiceFeedSnapshot::query()->firstOrFail();
        $this->assertStringContainsString('SSRF guard', (string) $snapshot->error);
    }

    /** The outgoing request identifies uptizm with a contact URL. */
    public function test_the_request_carries_the_configured_user_agent(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        config()->set('uptizm.bot_user_agent', 'UptizmBot/9.9 (+https://uptizm.test/bot)');

        IngestServiceFeed::dispatchSync((string) $this->makeService()->getKey());

        Http::assertSent(fn (Request $request): bool => $request->header('User-Agent')[0]
            === 'UptizmBot/9.9 (+https://uptizm.test/bot)');
    }

    /** The first fetch sends no `If-None-Match`, because there is no ETag yet. */
    public function test_the_first_request_sends_no_conditional_header(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        IngestServiceFeed::dispatchSync((string) $this->makeService()->getKey());

        Http::assertSent(fn (Request $request): bool => $request->header('If-None-Match') === []);
    }

    /**
     * The response ETag is persisted on the snapshot and replayed as
     * `If-None-Match` on the next fetch.
     */
    public function test_the_fetched_etag_is_persisted_and_replayed(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200, [
                'ETag' => '"round-trip-etag"',
            ]),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $this->assertSame('"round-trip-etag"', ServiceFeedSnapshot::query()->firstOrFail()->etag);

        // Past the floor, so the second fetch actually goes out.
        $this->travel(FeedFetcher::MIN_INTERVAL_SECONDS + 1)->seconds();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertSent(fn (Request $request): bool => ($request->header('If-None-Match')[0] ?? null)
            === '"round-trip-etag"');
    }

    /**
     * A service whose `status_source` is `none` is never fetched, and never even
     * dispatched.
     *
     * Both halves are asserted because they are different guards: the fan-out's
     * query and the fetcher's own `match`, whose `None` arm yields no adapter.
     *
     * The URL is deliberately LEFT IN PLACE. A first version of this test cleared
     * it too, and a mutation that handed the `None` arm a real adapter kept the
     * suite green, because the empty-URL guard refused instead: the test proved
     * that something refused, never that the enum arm did. A `none` row still
     * carrying a stale URL is also a real state, since nothing clears the column
     * when an operator switches the source off.
     */
    public function test_a_service_with_no_feed_source_is_never_fetched(): void
    {
        Http::fake();

        $service = $this->makeService([
            'status_source' => ServiceStatusSource::None,
            'status_source_url' => self::FEED_URL,
        ]);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertNothingSent();
        $this->assertSame(0, ServiceFeedSnapshot::query()->count());

        Queue::fake();
        (new IngestServiceFeeds)->handle();
        Queue::assertNothingPushed();
    }

    /**
     * The mirror of the case above: a live source with NO url to fetch.
     *
     * Isolated the same way, with the source left intact, so this pins the
     * empty-URL guard rather than the enum arm.
     */
    public function test_a_service_with_no_feed_url_is_never_fetched(): void
    {
        Http::fake();

        $service = $this->makeService([
            'status_source' => ServiceStatusSource::StatuspageV2,
            'status_source_url' => null,
        ]);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertNothingSent();
        $this->assertSame(0, ServiceFeedSnapshot::query()->count());

        Queue::fake();
        (new IngestServiceFeeds)->handle();
        Queue::assertNothingPushed();
    }

    /**
     * A terms-unreviewed service is never fetched even when dispatched directly.
     *
     * Isolated: it is published, has a live source and a URL, is not disabled, and
     * has no previous snapshot to trip the floor.
     */
    public function test_a_terms_unreviewed_service_is_never_fetched(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService([
            'terms_reviewed_at' => null,
        ]);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        Http::assertNothingSent();
        $this->assertSame(0, ServiceFeedSnapshot::query()->count());

        Queue::fake();
        (new IngestServiceFeeds)->handle();
        Queue::assertNothingPushed();
    }

    /**
     * The fan-out skips an unpublished service.
     *
     * Publication is the ONE predicate the fan-out owns alone: a fetch of an
     * unpublished service harms nobody (there is no page for the reading to reach),
     * so the fetcher does not re-check it and this test asserts the dispatch
     * boundary rather than the fetch.
     */
    public function test_the_fan_out_skips_an_unpublished_service(): void
    {
        $this->makeService([
            'is_published' => false,
        ]);

        Queue::fake();

        (new IngestServiceFeeds)->handle();

        Queue::assertNothingPushed();
    }

    /** The fan-out queues one job per eligible service, on the `feeds` queue. */
    public function test_the_fan_out_dispatches_one_job_per_eligible_service_on_the_feeds_queue(): void
    {
        $eligible = $this->makeService();
        $this->makeService([
            'slug' => 'second-eligible',
            'status_source' => ServiceStatusSource::GoogleCloud,
        ]);
        // Ineligible for five different reasons, exactly ONE each: a fixture that
        // failed two predicates at once would let either check be deleted while
        // this stayed green.
        $this->makeService(['slug' => 'unpublished', 'is_published' => false]);
        $this->makeService(['slug' => 'unreviewed', 'terms_reviewed_at' => null]);
        $this->makeService(['slug' => 'disabled', 'feed_disabled_at' => now()]);
        $this->makeService([
            'slug' => 'sourceless',
            'status_source' => ServiceStatusSource::None,
        ]);
        $this->makeService([
            'slug' => 'urlless',
            'status_source_url' => null,
        ]);

        Queue::fake();

        (new IngestServiceFeeds)->handle();

        Queue::assertPushed(IngestServiceFeed::class, 2);
        Queue::assertPushed(
            IngestServiceFeed::class,
            fn (IngestServiceFeed $job): bool => $job->serviceId === (string) $eligible->getKey()
                && $job->queue === 'feeds',
        );
    }

    /** `content_changed_at` moves when the normalized hash differs. */
    public function test_content_changed_at_moves_when_the_hash_differs(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService();
        $this->assertNull($service->content_changed_at);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $this->assertNotNull($service->refresh()->content_changed_at);
    }

    /**
     * `content_changed_at` does NOT move when the same payload is fetched twice.
     *
     * This is the assertion the whole sitemap depends on: `content_changed_at` is
     * the sole input to `lastmod`, and a poll that moves it on unchanged content
     * makes the signal untrustworthy sitewide.
     */
    public function test_content_changed_at_does_not_move_when_the_same_payload_is_fetched_twice(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService();

        IngestServiceFeed::dispatchSync((string) $service->getKey());
        $firstChangedAt = $service->refresh()->content_changed_at;
        $this->assertNotNull($firstChangedAt);

        $this->travel(FeedFetcher::MIN_INTERVAL_SECONDS + 1)->seconds();

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        // Two snapshots (a poll IS recorded), one content change.
        Http::assertSentCount(2);
        $this->assertSame(2, ServiceFeedSnapshot::query()->count());
        $this->assertTrue(
            $service->refresh()->content_changed_at->equalTo($firstChangedAt),
            'An unchanged poll moved content_changed_at, which would bump the sitemap lastmod.',
        );
    }

    /** The page cache is forgotten on a change, and left alone on an unchanged poll. */
    public function test_the_page_cache_is_busted_only_when_the_content_changed(): void
    {
        Http::fake([
            'status.example.test/*' => Http::response($this->statuspageBody(), 200),
        ]);

        $service = $this->makeService();
        $key = FeedFetcher::PAGE_CACHE_KEY_PREFIX.$service->slug;

        Cache::put($key, 'stale-read-model', 60);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $this->assertNull(Cache::get($key), 'A content change must forget the cached page.');

        // Second, unchanged poll: the cache must survive, or the cache is
        // pointless and every poll re-renders the page. The entry is written
        // AFTER travelling, because the array store honours the travelled clock
        // and a 60-second TTL written before the jump would expire on its own,
        // making this assertion pass for the wrong reason.
        $this->travel(FeedFetcher::MIN_INTERVAL_SECONDS + 1)->seconds();
        Cache::put($key, 'fresh-read-model', 60);

        IngestServiceFeed::dispatchSync((string) $service->getKey());

        $this->assertSame('fresh-read-model', Cache::get($key));
    }

    /** A service deleted between the fan-out and the run is a no-op. */
    public function test_a_deleted_service_is_a_no_op(): void
    {
        Http::fake();

        $service = $this->makeService();
        $id = (string) $service->getKey();
        $service->delete();

        IngestServiceFeed::dispatchSync($id);

        Http::assertNothingSent();
    }

    /**
     * A published, terms-reviewed Statuspage service with nothing suppressing a
     * fetch. Every test that asserts a refusal overrides exactly ONE of these.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeService(array $attributes = []): Service
    {
        return Service::factory()->create(array_merge([
            'slug' => 'example-provider',
            'name' => 'Example Provider',
            'category' => 'cloud',
            'status_source' => ServiceStatusSource::StatuspageV2,
            'status_source_url' => self::FEED_URL,
            'terms_reviewed_at' => now()->subMonth(),
            'is_published' => true,
        ], $attributes));
    }

    /**
     * A pre-existing snapshot of a given age, which is what the polling floor and
     * the conditional request both read.
     *
     * `content_hash_normalized` is a sentinel rather than a real hash: these
     * fixtures exist to be compared against, and a literal makes it obvious in a
     * failure message which row the assertion meant.
     */
    private function givenPreviousSnapshot(Service $service, int $secondsAgo, ?string $etag = null): ServiceFeedSnapshot
    {
        return ServiceFeedSnapshot::query()->create([
            'service_id' => $service->getKey(),
            'fetched_at' => now()->subSeconds($secondsAgo),
            'http_status' => 200,
            'indicator' => 'none',
            'components' => [],
            'incidents' => [],
            'content_hash_normalized' => 'unchanged-hash',
            'etag' => $etag,
        ]);
    }

    /**
     * Bind a {@see HostGuard} whose DNS resolution is fixed, so the SSRF
     * re-validation runs for real without a live lookup.
     *
     * @param  list<string>  $ips
     */
    private function stubDnsResolvingTo(array $ips): void
    {
        $this->app->instance(HostGuard::class, new class($ips) extends HostGuard
        {
            /**
             * @param  list<string>  $ips
             */
            public function __construct(
                private array $ips,
            ) {}

            protected function resolveHostIps(string $host): array
            {
                return $this->ips;
            }
        });
    }

    private function statuspageBody(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/feeds/statuspage-summary.json'));
    }

    private function googleBody(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/feeds/google-cloud-incidents.json'));
    }
}
