<?php

namespace Tests\Feature\Proxy;

use App\Models\Proxy;
use App\Models\ProxySource;
use App\Services\Proxy\ProxyPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Locks {@see ProxyPool}'s three answers: `hasRegion()`'s two-part predicate
 * (configured AND non-empty), `take()`'s random selection over healthy rows
 * of one region with exclusion honoured, and the `penalise()`/`reward()`
 * full-jitter backoff that keeps a burnt exit out of rotation until its
 * window elapses without ever synchronising retries across workers.
 */
class ProxyPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_take_returns_only_healthy_proxies_of_the_requested_region(): void
    {
        $pool = new ProxyPool;

        $euWest = $this->makeProxies('eu-west', 5);
        $this->makeProxies('us-east', 3);
        // A disabled and a swept proxy in the same region must never surface.
        $this->makeProxy('eu-west', ['enabled' => false]);
        $this->makeProxy('eu-west', ['removed_at' => now()]);

        $euWestIds = $euWest->pluck('id')->all();
        $seen = [];

        for ($i = 0; $i < 40; $i++) {
            $picked = $pool->take('eu-west');

            $this->assertNotNull($picked);
            $this->assertContains($picked->id, $euWestIds);

            $seen[$picked->id] = true;
        }

        // The assertion that actually fails if the pick is not random: a
        // fixed-first or round-robin implementation would never surface
        // this many distinct ids across 40 draws from a pool of 5.
        $this->assertGreaterThanOrEqual(3, count($seen));
    }

    public function test_take_honours_the_exclude_ids_list(): void
    {
        $pool = new ProxyPool;

        $kept = $this->makeProxy('eu-west');
        $excluded = $this->makeProxies('eu-west', 2);

        for ($i = 0; $i < 10; $i++) {
            $picked = $pool->take('eu-west', $excluded->pluck('id')->all());

            $this->assertNotNull($picked);
            $this->assertTrue($picked->is($kept));
        }
    }

    public function test_take_returns_null_when_no_healthy_proxy_remains(): void
    {
        $pool = new ProxyPool;

        $proxy = $this->makeProxy('eu-west');

        $this->assertNull($pool->take('eu-west', [$proxy->id]));
        $this->assertNull($pool->take('us-east'));
    }

    public function test_a_penalised_proxy_is_excluded_from_take_until_its_backoff_elapses(): void
    {
        $pool = new ProxyPool;
        $proxy = $this->makeProxy('eu-west');

        $pool->penalise($proxy);
        $proxy->refresh();

        $this->assertNotNull($proxy->available_at);

        // Excluded for the entire backoff window, whatever the jitter draw
        // was: travel to one second before the window closes.
        $this->travelTo($proxy->available_at->subSecond());
        $this->assertNull($pool->take('eu-west'));

        // Included the instant the window closes: this is the QA scenario's
        // "travel past the cap, assert it returns", generalised to whatever
        // delay the draw actually produced.
        $this->travelTo($proxy->available_at);
        $this->assertTrue($pool->take('eu-west')->is($proxy));
    }

    public function test_penalise_increments_failed_attempts(): void
    {
        $pool = new ProxyPool;
        $proxy = $this->makeProxy('eu-west', ['failed_attempts' => 2]);

        $pool->penalise($proxy);

        $this->assertSame(3, $proxy->fresh()->failed_attempts);
    }

    public function test_penalise_backoff_is_bounded_by_the_configured_cap(): void
    {
        $pool = new ProxyPool;
        $max = (int) config('proxy.health.max_backoff_seconds');

        // A high failed_attempts count pushes 2**(attempts-1) far past the
        // cap, so every draw below must have been clamped rather than
        // overflowing the exponential term.
        for ($i = 0; $i < 20; $i++) {
            $proxy = $this->makeProxy('eu-west', ['failed_attempts' => 12]);

            $pool->penalise($proxy);
            $proxy->refresh();

            $this->assertLessThanOrEqual(
                now()->addSeconds($max)->addSecond(),
                $proxy->available_at,
            );
        }
    }

    public function test_penalise_jitter_is_not_constant_across_calls(): void
    {
        $pool = new ProxyPool;
        $deltas = [];

        // A constant (non-jittered) implementation would produce the same
        // delta every time; enough draws make that distinguishable from a
        // single lucky zero.
        for ($i = 0; $i < 20; $i++) {
            $proxy = $this->makeProxy('eu-west');

            $pool->penalise($proxy);
            $proxy->refresh();

            $deltas[] = $proxy->available_at->diffInSeconds(now());
        }

        $this->assertGreaterThan(1, count(array_unique($deltas)));
    }

    public function test_reward_decrements_failed_attempts_and_clears_availability(): void
    {
        $pool = new ProxyPool;
        $proxy = $this->makeProxy('eu-west', [
            'failed_attempts' => 3,
            'available_at' => now()->addMinutes(5),
        ]);

        $pool->reward($proxy);
        $proxy->refresh();

        $this->assertSame(2, $proxy->failed_attempts);
        $this->assertNull($proxy->available_at);
    }

    public function test_reward_does_not_decrement_failed_attempts_below_zero(): void
    {
        $pool = new ProxyPool;
        $proxy = $this->makeProxy('eu-west', ['failed_attempts' => 0]);

        $pool->reward($proxy);

        $this->assertSame(0, $proxy->fresh()->failed_attempts);
    }

    public function test_has_region_is_true_when_configured_with_a_healthy_proxy(): void
    {
        $pool = new ProxyPool;
        $this->makeProxy('eu-west');

        $this->assertTrue($pool->hasRegion('eu-west'));
    }

    public function test_has_region_is_false_for_a_configured_region_with_an_empty_pool(): void
    {
        $pool = new ProxyPool;

        // `ap` is declared in config/proxy.php's `sources` but no proxy row
        // exists for it in this test: config alone is not evidence the
        // region can actually be probed.
        $this->assertArrayHasKey('ap', config('proxy.sources'));
        $this->assertFalse($pool->hasRegion('ap'));
    }

    public function test_has_region_is_false_for_a_region_absent_from_config(): void
    {
        $pool = new ProxyPool;

        // `eu-central` is a legal MonitorRegion value but has no entry under
        // config('proxy.sources') at all.
        $this->assertArrayNotHasKey('eu-central', config('proxy.sources'));
        $this->assertFalse($pool->hasRegion('eu-central'));
    }

    /**
     * Bumped on every {@see self::makeProxy()} call so the `(host, port)`
     * unique constraint never collides across regions or repeated draws
     * inside a single test's loop.
     */
    protected int $proxySequence = 0;

    /**
     * Creates `$count` persisted, healthy proxies for a region.
     *
     * @return Collection<int, Proxy>
     */
    protected function makeProxies(string $region, int $count): Collection
    {
        return collect(range(1, $count))->map(fn (): Proxy => $this->makeProxy($region));
    }

    /**
     * Creates a single persisted, healthy proxy with sane defaults,
     * overridable per test.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeProxy(string $region, array $overrides = []): Proxy
    {
        $source = ProxySource::query()->firstOrCreate(
            ['region' => $region],
            ['kind' => 'url', 'location' => "https://example.com/{$region}.txt"],
        );

        $sequence = ++$this->proxySequence;

        return Proxy::query()->create([
            'proxy_source_id' => $source->id,
            'region' => $region,
            'host' => $overrides['host'] ?? "203.0.113.{$sequence}",
            'port' => $overrides['port'] ?? (8000 + $sequence),
            'credentials' => [
                'username' => 'exit-user',
                'password' => 'secret',
            ],
            'enabled' => true,
            'last_refreshed_at' => now(),
            ...$overrides,
        ]);
    }
}
