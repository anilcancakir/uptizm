<?php

namespace Tests\Feature\Proxy;

use App\Enums\MonitorType;
use App\Jobs\AlarmDarkProbeRegions;
use App\Models\Monitor;
use App\Models\ProbeRegionHealth;
use App\Models\Proxy;
use App\Models\ProxySource;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Monitoring\LocalProbeEngine;
use App\Services\Proxy\ProxyPool;
use App\Support\Services\SystemTeam;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FindsScheduledJobs;
use Tests\TestCase;

/**
 * `monitors.last_probe_error` is tenant-facing and per-monitor; a dead PROXY
 * REGION is the opposite shape: platform-wide, every catalog monitor in that
 * region in one tick, one region, self-healing. Routed only through the
 * monitor flag it produces eight identical rows and no fleet signal, and the
 * public symptom is silence rather than an error, so nothing on a monitor row
 * would ever surface it. These tests pin {@see ProbeRegionHealth} as the one
 * place that fact is visible, and {@see AlarmDarkProbeRegions} as the
 * exactly-once alarm on top of it.
 */
class DarkRegionAlarmTest extends TestCase
{
    use FindsScheduledJobs;
    use RefreshDatabase;

    protected int $proxySequence = 0;

    public function test_a_successful_probe_advances_last_success_at_and_zeroes_the_streak(): void
    {
        // Seeded with a live streak so this test fails if a success stops
        // clearing it, not only if it never advanced in the first place.
        ProbeRegionHealth::query()->create([
            'region' => 'us-east',
            'consecutive_empty_intervals' => 5,
            'alarmed_at' => now(),
        ]);

        $this->makeProxy('us-east');
        $monitor = $this->systemMonitor();
        $this->fakeTarget(200);

        $this->engine()->dispatch($monitor, 'us-east');

        $health = ProbeRegionHealth::query()->where('region', 'us-east')->sole();

        $this->assertNotNull($health->last_success_at);
        $this->assertSame(0, $health->consecutive_empty_intervals);

        // Cleared, not merely left alone: a later crossing of the threshold
        // must be able to alarm again.
        $this->assertNull($health->alarmed_at);
    }

    public function test_a_down_reading_still_counts_as_a_successful_probe(): void
    {
        // The target answering `down` still proves the region's exits carried
        // a request; only a REFUSAL (no verdict produced at all) is an empty
        // interval. Collapsing this into only `up` clearing the streak would
        // alarm on every genuinely down catalog target.
        ProbeRegionHealth::query()->create([
            'region' => 'us-east',
            'consecutive_empty_intervals' => 2,
        ]);

        $this->makeProxy('us-east');
        $monitor = $this->systemMonitor(['expected_status_code' => 200]);
        $this->fakeTarget(500);

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        $this->assertFalse($reading->probeRefused);
        $this->assertSame(0, ProbeRegionHealth::query()->where('region', 'us-east')->value('consecutive_empty_intervals'));
    }

    public function test_a_target_that_never_answered_is_still_a_reading_and_leaves_the_region_healthy(): void
    {
        // The case above answers with a status code. This one does not: the
        // tunnel is healthy and the TARGET never replies, so the reading is a
        // `down` with `status_code` null and empty timing. That is the ordinary
        // shape of a real outage on the catalog, and the region measuring it is
        // working perfectly.
        //
        // An earlier revision discriminated on `probeRefused || statusCode ===
        // null`, which filed exactly this as an empty interval: every genuinely
        // down catalog target would have counted against the region that
        // measured it, and three intervals later an operator would get a
        // dark-region alarm for a region that wrote check rows the whole time.
        // What that revision was reaching for is decided in the classifier
        // instead ({@see LocalProbeEngine::PROXY_FAULT_ERRNOS}, errno 7), where a
        // provider-wide failure becomes a refusal.
        ProbeRegionHealth::query()->create([
            'region' => 'us-east',
            'consecutive_empty_intervals' => 2,
        ]);

        $this->makeProxy('us-east');
        $monitor = $this->systemMonitor();
        $this->failTargetAmbiguously();

        $reading = $this->engine()->dispatch($monitor, 'us-east');

        // An honest verdict about the target, not a refusal about us.
        $this->assertFalse($reading->probeRefused);
        $this->assertNull($reading->statusCode);

        $health = ProbeRegionHealth::query()->where('region', 'us-east')->sole();
        $this->assertNotNull($health->last_success_at);
        $this->assertNull($health->last_failure_at);
        $this->assertSame(0, $health->consecutive_empty_intervals);

        // And the alarm job agrees: nothing to increment, nothing to alarm.
        (new AlarmDarkProbeRegions)->handle();

        $this->assertSame(0, $health->fresh()->consecutive_empty_intervals);
        $this->assertNull($health->fresh()->alarmed_at);
    }

    public function test_a_mixed_tick_leaves_the_region_healthy_whichever_probe_wrote_last(): void
    {
        // Eight catalog monitors share a region, so a tick can be MIXED: some
        // monitors refuse (their exit died mid-tick) while others answer. The engine
        // writes `last_failure_at`/`last_success_at` per (monitor, region), so which
        // one lands last is write-order dependent, and the alarm job's predicate
        // compares exactly those two columns.
        //
        // The pair that makes the race harmless is asserted here: the engine clears
        // the streak on EVERY non-refused probe, and the job increments at most once
        // per tick. So a region that carried even one real request cannot reach the
        // threshold, and reaching it requires whole intervals with no success at all.
        // Refusal LAST on purpose, which is the ordering that leaves
        // `last_failure_at >= last_success_at` and would otherwise increment.
        $answering = $this->systemMonitor();

        $this->makeProxy('us-east');
        $this->fakeTarget(200);
        $this->engine()->dispatch($answering, 'us-east');

        // Now empty the pool under the region and probe again: this one refuses.
        Proxy::query()->update(['enabled' => false]);
        $this->engine()->dispatch($this->systemMonitor(), 'us-east');

        $health = ProbeRegionHealth::query()->where('region', 'us-east')->sole();
        $this->assertNotNull($health->last_failure_at);
        $this->assertNotNull($health->last_success_at);

        (new AlarmDarkProbeRegions)->handle();

        $this->assertSame(
            1,
            $health->fresh()->consecutive_empty_intervals,
            'A mixed tick may flicker the streak by one; what matters is the next success clearing it.',
        );

        // The next interval carries a real request again, and that is what must
        // erase the flicker rather than letting three mixed ticks alarm.
        Proxy::query()->update(['enabled' => true]);
        $this->fakeTarget(200);
        $this->engine()->dispatch($answering, 'us-east');

        $this->assertSame(0, $health->fresh()->consecutive_empty_intervals);
        $this->assertNull($health->fresh()->alarmed_at);
    }

    public function test_a_refusal_advances_last_failure_at_and_increments_the_streak(): void
    {
        // `us-west` is a legal MonitorRegion with no entry in
        // `config('proxy.sources')`, so every dispatch here refuses.
        $monitor = $this->systemMonitor();

        $this->engine()->dispatch($monitor, 'us-west');
        $this->engine()->dispatch($monitor, 'us-west');

        $health = ProbeRegionHealth::query()->where('region', 'us-west')->sole();

        $this->assertNotNull($health->last_failure_at);

        // The engine records the TIMESTAMP and NOT the interval count. It runs once
        // per (monitor, region), so a counter it advanced would count refused
        // attempts rather than intervals, and eight catalog monitors in one dark tick
        // would cross any threshold immediately. Measured on the real catalog before
        // this moved: one tick took the streak to 8 against a threshold of 3, so the
        // alarm fired on the first dark tick and "a single missed tick is normal" was
        // dead. The scheduled job owns the counter, because it is the only thing here
        // that runs once per interval.
        $this->assertSame(0, $health->consecutive_empty_intervals);

        (new AlarmDarkProbeRegions)->handle();

        $this->assertSame(
            1,
            $health->fresh()->consecutive_empty_intervals,
            'Two refusals inside one interval must count as one interval, not two.',
        );
    }

    /**
     * The QA scenario: emptying one region's pool and probing every catalog
     * monitor that carries it must move ONE health row, not one per monitor.
     */
    public function test_a_dark_region_moves_one_health_row_rather_than_eight_monitor_rows(): void
    {
        $monitors = collect(range(1, 8))->map(fn (): Monitor => $this->systemMonitor());

        foreach ($monitors as $monitor) {
            $result = $this->engine()->dispatch($monitor, 'us-west');

            // The monitor-level flag is CheckPersistenceService's write, not the
            // engine's; drive it too so the "eight identical rows" side of the
            // comparison is real rather than assumed.
            app(CheckPersistenceService::class)->persist($monitor, $result);
        }

        $this->assertSame(1, ProbeRegionHealth::query()->where('region', 'us-west')->count());

        // Eight refusals in ONE tick are one dark interval, not eight. This is the
        // assertion that fails if the counter ever moves back into the engine, and it
        // is the whole reason the threshold means what it says.
        $this->assertSame(0, ProbeRegionHealth::query()->where('region', 'us-west')->value('consecutive_empty_intervals'));

        (new AlarmDarkProbeRegions)->handle();

        $this->assertSame(1, ProbeRegionHealth::query()->where('region', 'us-west')->value('consecutive_empty_intervals'));

        // The monitor-level flag still fires eight times independently; that
        // multiplicity is exactly what the region-health row exists to spare
        // an operator from having to read eight times.
        $this->assertSame(
            8,
            Monitor::query()->whereKey($monitors->pluck('id'))->whereNotNull('last_probe_error')->count(),
        );
    }

    public function test_healthy_proxy_count_is_read_live_from_the_pool(): void
    {
        // The refresher that owns this column's stated cadence is out of this
        // step's files; the engine reads a live count instead, so a proxy that
        // disappears BETWEEN refreshes is reflected on the very next attempt.
        $this->makeProxy('us-east');
        $this->makeProxy('us-east');
        $monitor = $this->systemMonitor();
        $this->fakeTarget(200);

        $this->engine()->dispatch($monitor, 'us-east');

        $this->assertSame(
            2,
            ProbeRegionHealth::query()->where('region', 'us-east')->value('healthy_proxy_count'),
        );
    }

    public function test_the_alarm_fires_exactly_once_per_crossing_not_once_per_tick(): void
    {
        $threshold = (int) config('proxy.health.failure_threshold');

        ProbeRegionHealth::query()->create([
            'region' => 'ap',
            'consecutive_empty_intervals' => $threshold,
        ]);

        Log::spy();

        // Two ticks at the SAME state: the second must be a no-op, which is
        // the property that keeps an hour-long outage from becoming an
        // hour-long stream of identical log lines.
        (new AlarmDarkProbeRegions)->handle();
        (new AlarmDarkProbeRegions)->handle();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no reading')
                && $context['region'] === 'ap'
                && $context['consecutive_empty_intervals'] === $threshold)
            ->once();

        $this->assertNotNull(ProbeRegionHealth::query()->where('region', 'ap')->value('alarmed_at'));
    }

    public function test_the_alarm_does_not_fire_below_the_threshold(): void
    {
        // A single missed tick is normal; alarming on it would page an
        // operator for the routine case the whole design exists to absorb.
        $threshold = (int) config('proxy.health.failure_threshold');

        ProbeRegionHealth::query()->create([
            'region' => 'ap',
            'consecutive_empty_intervals' => $threshold - 1,
        ]);

        Log::spy();

        (new AlarmDarkProbeRegions)->handle();

        Log::shouldNotHaveReceived('error');
        $this->assertNull(ProbeRegionHealth::query()->where('region', 'ap')->value('alarmed_at'));
    }

    public function test_a_region_that_recovers_and_darkens_again_alarms_a_second_time(): void
    {
        $threshold = (int) config('proxy.health.failure_threshold');

        $health = ProbeRegionHealth::query()->create([
            'region' => 'ap',
            'consecutive_empty_intervals' => $threshold,
        ]);

        (new AlarmDarkProbeRegions)->handle();
        $this->assertNotNull($health->fresh()->alarmed_at);

        // A success in between clears both the streak and the alarm flag; see
        // LocalProbeEngine::recordRegionHealth().
        $this->makeProxy('ap');
        $monitor = $this->systemMonitor();
        $this->fakeTarget(200);
        $this->engine()->dispatch($monitor, 'ap');

        $this->assertNull($health->fresh()->alarmed_at);
        $this->assertSame(0, $health->fresh()->consecutive_empty_intervals);

        // Darkens again, and has to earn the second alarm the same way as the
        // first: one probe cycle plus one job tick is ONE dark interval, so the
        // threshold takes `$threshold` of them. Seeding the counter instead would
        // let this test pass against a job that alarms on a single dark tick.
        Proxy::query()->update(['enabled' => false]);

        for ($interval = 1; $interval < $threshold; $interval++) {
            // `ap` still has a configured source but zero healthy proxies now,
            // so every dispatch refuses.
            $this->engine()->dispatch($monitor, 'ap');
            (new AlarmDarkProbeRegions)->handle();

            $this->assertNull(
                $health->fresh()->alarmed_at,
                "The second alarm fired after {$interval} dark interval(s), before the threshold of {$threshold}.",
            );
        }

        $this->engine()->dispatch($monitor, 'ap');

        Log::spy();
        (new AlarmDarkProbeRegions)->handle();

        Log::shouldHaveReceived('error')->once();
    }

    public function test_the_alarm_is_scheduled_without_overlapping_on_one_server(): void
    {
        $event = $this->scheduledEventDispatching(AlarmDarkProbeRegions::class);

        $this->assertTrue(
            $event->withoutOverlapping,
            'A slow alarm tick must not overlap the next scheduled one.',
        );
        $this->assertTrue(
            $event->onOneServer,
            'Only one server may run the alarm, or two would race the alarmed_at guard.',
        );
    }

    protected function engine(): LocalProbeEngine
    {
        return new LocalProbeEngine(new ProxyPool);
    }

    /**
     * Stub the target with a fixed status; the engine's own field-mapping
     * rules are out of scope here, only the refused/reading discriminator is.
     */
    protected function fakeTarget(int $status): void
    {
        Http::fake(fn (Request $request) => Http::response('<html></html>', $status, ['Content-Type' => 'text/html']));
    }

    /**
     * Fail every attempt the way a healthy tunnel to a dead target fails.
     *
     * errno 28 (`CURLE_OPERATION_TIMEDOUT`) rather than 7: 7 names the proxy on
     * this path and would produce a refusal, which is the opposite of what this
     * scenario is about. See
     * `LocalProbeFailureAttributionTest` for the
     * measured errno taxonomy.
     */
    protected function failTargetAmbiguously(): void
    {
        Http::fake(function (Request $request): never {
            $context = [
                'errno' => 28,
                'error' => 'Operation timed out after 10001 milliseconds with 0 bytes received',
            ];

            throw new GuzzleConnectException(
                'cURL error 28: Operation timed out',
                $request->toPsrRequest(),
                null,
                $context,
            );
        });
    }

    /**
     * A monitor owned by the one internal team this engine may probe for.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function systemMonitor(array $attributes = []): Monitor
    {
        return Monitor::query()->create([
            'team_id' => SystemTeam::resolve()->id,
            'name' => 'Catalog probe',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'regions' => ['us-east'],
            'check_interval_sec' => 60,
            'timeout_sec' => 10,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            ...$attributes,
        ]);
    }

    /**
     * A persisted, healthy exit in the given region.
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
            'host' => "203.0.113.{$sequence}",
            'port' => 8000 + $sequence,
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
