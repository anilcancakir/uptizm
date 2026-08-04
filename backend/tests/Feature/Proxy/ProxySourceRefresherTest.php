<?php

namespace Tests\Feature\Proxy;

use App\Jobs\RefreshProxySources;
use App\Models\Proxy;
use App\Models\ProxySource;
use App\Services\Proxy\ProxySourceRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FindsScheduledJobs;
use Tests\TestCase;

/**
 * Locks {@see ProxySourceRefresher}'s upsert-and-sweep contract and
 * {@see RefreshProxySources}'s per-region fan-in, against the design
 * reference's own test suite (package-booster/internal/proxysrc/service_test.go,
 * read-only, not a dependency).
 *
 * The empty-list refusal is the single most important case here: a provider
 * answering 200 with a maintenance page, a truncated body, or an HTML error
 * parses to zero rows with no exception raised anywhere, and sweeping on that
 * would disable every exit in the region in one tick. The test asserting it
 * is written so deleting the refresher's empty-list guard clause reddens it
 * (see the class docblock's step 3): the healthy proxy seeded before the
 * empty refresh must survive, and `last_error` must be recorded.
 */
class ProxySourceRefresherTest extends TestCase
{
    use FindsScheduledJobs;
    use RefreshDatabase;

    private const string SOURCE_URL = 'https://proxy-provider.test/download/eu-west.txt';

    public function test_refresh_upserts_new_proxies_parsed_from_the_source(): void
    {
        $source = $this->makeSource();

        Http::fake([
            self::SOURCE_URL => Http::response("1.1.1.1:8080:user1:pass1\n2.2.2.2:9090:user2:pass2\n", 200),
        ]);

        $result = $this->refresher()->refresh($source);

        $this->assertSame(2, $result['upserted']);
        $this->assertSame(0, $result['dropped']);
        $this->assertSame(0, $result['swept']);

        $this->assertDatabaseHas('proxies', [
            'proxy_source_id' => $source->id,
            'region' => $source->region,
            'host' => '1.1.1.1',
            'port' => 8080,
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('proxies', [
            'proxy_source_id' => $source->id,
            'region' => $source->region,
            'host' => '2.2.2.2',
            'port' => 9090,
            'enabled' => true,
        ]);
    }

    /**
     * The guard-clause test the QA scenario mutation-verifies: an empty parse
     * must leave every existing proxy of the source untouched and record
     * `last_error`, never sweeping.
     */
    public function test_an_empty_parsed_list_refuses_to_sweep_and_records_the_error(): void
    {
        $source = $this->makeSource();
        $survivor = $this->makeProxy($source, host: '9.9.9.9', port: 8080);

        Http::fake([
            self::SOURCE_URL => Http::response("# maintenance, please check back later\n", 200),
        ]);

        $result = $this->refresher()->refresh($source);

        $this->assertSame(0, $result['upserted']);
        $this->assertSame(0, $result['swept']);

        $survivor->refresh();
        $this->assertTrue($survivor->enabled, 'A zero-row parse must never disable an existing proxy.');
        $this->assertNull($survivor->removed_at);

        $this->assertNotNull(
            $source->fresh()->last_error,
            'An empty parse must record last_error so an operator can see why the region went stale.',
        );
    }

    public function test_a_refresh_of_one_region_never_disables_another_regions_proxy(): void
    {
        $sourceA = $this->makeSource(region: 'eu-west', location: self::SOURCE_URL);
        $sourceB = $this->makeSource(region: 'us-east', location: 'https://proxy-provider.test/download/us-east.txt');

        // Region B's proxy is already stale (older last_refreshed_at) relative to the
        // import that is about to run for region A; it must survive regardless.
        $regionBProxy = $this->makeProxy($sourceB, host: '5.5.5.5', port: 8080, lastRefreshedAt: now()->subDay());

        Http::fake([
            self::SOURCE_URL => Http::response("1.1.1.1:8080:user1:pass1\n", 200),
        ]);

        $result = $this->refresher()->refresh($sourceA);

        $this->assertSame(0, $result['swept'], 'Region A\'s refresh must not sweep region B\'s rows.');

        $regionBProxy->refresh();
        $this->assertTrue($regionBProxy->enabled);
        $this->assertNull($regionBProxy->removed_at);
    }

    /**
     * An endpoint listed by two regions is REASSIGNED to whichever listed it last,
     * and that reassignment is logged rather than silent.
     *
     * The conflict target is global `(host, port)`, because one host:port is one
     * physical exit and claiming it sits in two regions at once would fabricate the
     * geography this engine exists to report honestly. So taking it is correct: a
     * provider moving an exit between country pools is a real event and the newest
     * list is the better evidence.
     *
     * What was wrong before this test existed is that the take happened in silence.
     * Measured then: region A refreshed to 2 healthy exits, region B refreshed, and
     * A dropped to 1 with nothing anywhere saying why. Two regions permanently
     * listing the same exit would ping-pong it on every refresh, both pools would
     * oscillate, and `hasRegion()` could flip false for a region whose only exits
     * are shared. The warning is what lets an operator tell one genuine move (a
     * single line) from that fight (a line every hour).
     */
    public function test_an_endpoint_listed_by_two_regions_is_reassigned_and_the_move_is_logged(): void
    {
        $shared = '203.0.113.50';
        $sourceA = $this->makeSource(region: 'eu-west', location: self::SOURCE_URL);
        $sourceB = $this->makeSource(region: 'us-east', location: 'https://proxy-provider.test/download/us-east.txt');

        $this->makeProxy($sourceA, host: $shared, port: 8080);

        Http::fake([
            'https://proxy-provider.test/download/us-east.txt' => Http::response("{$shared}:8080:userB:passB\n", 200),
        ]);

        Log::spy();

        $this->refresher()->refresh($sourceB);

        $reassigned = Proxy::query()->where('host', $shared)->sole();

        $this->assertSame(
            'us-east',
            $reassigned->region,
            'The newest list must own the exit; a provider moving it between country pools is a real event.',
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'reassigned between regions')
                && $context['from_region'] === 'eu-west'
                && $context['to_region'] === 'us-east')
            ->once();
    }

    public function test_sweep_disables_a_proxy_missing_from_a_re_import(): void
    {
        $source = $this->makeSource();

        // Registered ONCE as a sequence, not as two separate Http::fake() calls: a
        // second Http::fake() for the SAME url does not replace the first, it queues
        // behind it, so the earlier full-list response would still answer the
        // re-import and the sweep would never see a shrunk list.
        Http::fake([
            self::SOURCE_URL => Http::sequence()
                ->push("1.1.1.1:8080:user1:pass1\n2.2.2.2:9090:user2:pass2\n", 200)
                ->push("1.1.1.1:8080:user1:pass1\n", 200),
        ]);
        $this->refresher()->refresh($source);

        // The sweep predicate is `last_refreshed_at < importStartedAt`, and the column
        // is whole-second precision; travel forward so the re-import's stamp is
        // strictly later than the first, as a real refresh an hour apart would be.
        $this->travel(1)->minute();

        $result = $this->refresher()->refresh($source);

        $this->assertSame(1, $result['swept']);

        $vanished = Proxy::query()->where('host', '2.2.2.2')->firstOrFail();
        $this->assertFalse($vanished->enabled);
        $this->assertNotNull($vanished->removed_at);

        $survivor = Proxy::query()->where('host', '1.1.1.1')->firstOrFail();
        $this->assertTrue($survivor->enabled);
    }

    public function test_re_sweeping_an_already_swept_proxy_reports_zero_and_preserves_removed_at(): void
    {
        $source = $this->makeSource();

        // One sequence covering all three refreshes below; see
        // test_sweep_disables_a_proxy_missing_from_a_re_import for why a second
        // Http::fake() call for the same url would not actually replace the first.
        Http::fake([
            self::SOURCE_URL => Http::sequence()
                ->push("1.1.1.1:8080:user1:pass1\n2.2.2.2:9090:user2:pass2\n", 200)
                ->push("1.1.1.1:8080:user1:pass1\n", 200)
                ->push("1.1.1.1:8080:user1:pass1\n", 200),
        ]);
        $this->refresher()->refresh($source);

        // See test_sweep_disables_a_proxy_missing_from_a_re_import: the sweep predicate
        // needs a strictly later stamp than the column's whole-second precision.
        $this->travel(1)->minute();

        $this->refresher()->refresh($source);

        $firstRemovedAt = Proxy::query()->where('host', '2.2.2.2')->value('removed_at');
        $this->assertNotNull($firstRemovedAt);

        $this->travel(1)->minute();

        // Re-run the same reduced import: the already-disabled proxy must not be
        // re-swept, and its removed_at must record the FIRST disappearance.
        $result = $this->refresher()->refresh($source);

        $this->assertSame(0, $result['swept'], 'Re-sweeping an already-disabled proxy must not count.');

        $secondRemovedAt = Proxy::query()->where('host', '2.2.2.2')->value('removed_at');
        $this->assertSame(
            (string) $firstRemovedAt,
            (string) $secondRemovedAt,
            'removed_at must record when the exit first disappeared, not the most recent sweep.',
        );
    }

    public function test_a_returning_proxy_is_resurrected_on_re_appearing_in_the_list(): void
    {
        $source = $this->makeSource();
        $swept = $this->makeProxy(
            $source,
            host: '3.3.3.3',
            port: 8080,
            enabled: false,
            removedAt: now()->subHour(),
            failedAttempts: 4,
        );

        Http::fake([
            self::SOURCE_URL => Http::response("3.3.3.3:8080:newuser:newpass\n", 200),
        ]);

        $this->refresher()->refresh($source);

        $swept->refresh();
        $this->assertTrue($swept->enabled, 'A returning proxy must be resurrected as enabled.');
        $this->assertNull($swept->removed_at);
        $this->assertSame(0, $swept->failed_attempts);
        $this->assertSame('newuser', $swept->credentials['username']);
    }

    /**
     * The inverse of the usual SSRF story: the endpoint arrives from a URL a
     * third party controls, not from an operator, so a loopback address must
     * be dropped and never stored, or this server would connect to its own
     * services carrying the proxy credentials as the payload.
     */
    public function test_a_blocked_range_endpoint_is_dropped_and_never_stored(): void
    {
        $source = $this->makeSource();

        Http::fake([
            self::SOURCE_URL => Http::response("127.0.0.1:8080:user:pass\n9.9.9.9:8080:user2:pass2\n", 200),
        ]);

        $result = $this->refresher()->refresh($source);

        $this->assertSame(1, $result['dropped']);
        $this->assertSame(1, $result['upserted']);

        $this->assertDatabaseMissing('proxies', ['host' => '127.0.0.1']);
        $this->assertDatabaseHas('proxies', ['host' => '9.9.9.9']);
    }

    public function test_the_job_refreshes_every_region_with_a_non_empty_configured_location(): void
    {
        config([
            'proxy.sources' => [
                'eu-west' => [
                    'kind' => 'url',
                    'location' => 'https://proxy-provider.test/download/eu-west.txt',
                ],
                'us-east' => [
                    'kind' => 'url',
                    // An unconfigured region must be skipped, not fetched.
                    'location' => '',
                ],
                'ap' => [
                    'kind' => 'url',
                    'location' => 'https://proxy-provider.test/download/ap.txt',
                ],
            ],
        ]);

        Http::fake([
            'https://proxy-provider.test/download/eu-west.txt' => Http::response("1.1.1.1:8080:u:p\n", 200),
            'https://proxy-provider.test/download/ap.txt' => Http::response("2.2.2.2:9090:u:p\n", 200),
        ]);

        (new RefreshProxySources)->handle(app(ProxySourceRefresher::class));

        $this->assertDatabaseHas('proxy_sources', ['region' => 'eu-west']);
        $this->assertDatabaseHas('proxy_sources', ['region' => 'ap']);
        $this->assertDatabaseMissing('proxy_sources', ['region' => 'us-east']);

        $this->assertDatabaseHas('proxies', ['host' => '1.1.1.1', 'region' => 'eu-west']);
        $this->assertDatabaseHas('proxies', ['host' => '2.2.2.2', 'region' => 'ap']);

        Http::assertSentCount(2, fn (Request $request): bool => str_contains($request->url(), 'download/'));
    }

    public function test_one_regions_fetch_failure_does_not_block_the_others(): void
    {
        config([
            'proxy.sources' => [
                'eu-west' => [
                    'kind' => 'url',
                    'location' => 'https://proxy-provider.test/download/eu-west.txt',
                ],
                'ap' => [
                    'kind' => 'url',
                    'location' => 'https://proxy-provider.test/download/ap.txt',
                ],
            ],
        ]);

        Http::fake([
            'https://proxy-provider.test/download/eu-west.txt' => Http::response('', 401),
            'https://proxy-provider.test/download/ap.txt' => Http::response("2.2.2.2:9090:u:p\n", 200),
        ]);

        (new RefreshProxySources)->handle(app(ProxySourceRefresher::class));

        $this->assertDatabaseHas('proxies', ['host' => '2.2.2.2', 'region' => 'ap']);
        $this->assertNotNull(ProxySource::query()->where('region', 'eu-west')->value('last_error'));
    }

    public function test_the_refresh_is_scheduled_without_overlapping_on_one_server(): void
    {
        $event = $this->scheduledEventDispatching(RefreshProxySources::class);

        $this->assertTrue($event->withoutOverlapping, 'A slow refresh must not overlap the next scheduled tick.');
        $this->assertTrue($event->onOneServer, 'Only one server may run the refresh, or two would double-fetch every source.');
    }

    /**
     * Creates a persisted proxy source for a region, overridable per test.
     */
    protected function makeSource(string $region = 'eu-west', string $location = self::SOURCE_URL): ProxySource
    {
        return ProxySource::query()->create([
            'region' => $region,
            'kind' => 'url',
            'location' => $location,
        ]);
    }

    /**
     * Creates a persisted proxy belonging to the given source, with sane
     * defaults overridable per test.
     */
    protected function makeProxy(
        ProxySource $source,
        string $host,
        int $port,
        bool $enabled = true,
        ?\DateTimeInterface $removedAt = null,
        ?\DateTimeInterface $lastRefreshedAt = null,
        int $failedAttempts = 0,
    ): Proxy {
        return Proxy::query()->create([
            'proxy_source_id' => $source->id,
            'region' => $source->region,
            'host' => $host,
            'port' => $port,
            'credentials' => [
                'username' => 'exit-user',
                'password' => 'secret',
            ],
            'enabled' => $enabled,
            'removed_at' => $removedAt,
            'failed_attempts' => $failedAttempts,
            'last_refreshed_at' => $lastRefreshedAt ?? now(),
        ]);
    }

    protected function refresher(): ProxySourceRefresher
    {
        return app(ProxySourceRefresher::class);
    }
}
