<?php

namespace Tests\Feature\Services;

use App\Enums\AiMode;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Models\Monitor;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Support\Services\SystemTeam;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the catalog seeder: eight services, one own-measurement monitor each on
 * the system team, every row unpublished with its terms unreviewed, and a second
 * run that changes nothing.
 *
 * The unpublished + unreviewed assertions are not bookkeeping. Publishing is a
 * human decision taken after the terms register is filled in, and a seeder that
 * shipped `is_published` true would put eight pages live on a fresh install with
 * nobody having reviewed a single provider's terms.
 */
class ServiceCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The catalog size is asserted as a literal rather than read off the
     * seeder's own constant: deriving the expectation from the code under test
     * would certify whatever the constant happened to contain.
     */
    private const int EXPECTED_SERVICE_COUNT = 8;

    /**
     * A default of three healthy proxy sources, mirroring what a working
     * deployment's `config/proxy.php` looks like once every region has been
     * sourced. `.env` ships every `UPTIZM_PROXY_*_SOURCE_LOCATION` empty by
     * default, which `ServiceCatalogSeeder::catalogRegions()` now treats as
     * "declared but unusable" and would refuse to seed from at all, so every
     * test in this file that is not itself EXERCISING the region count needs
     * a working default rather than the shipped one. The two tests that ARE
     * exercising it override this in their own body.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['proxy.sources' => [
            'eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt'],
            'us-east' => ['kind' => 'url', 'location' => 'https://example.test/us-east.txt'],
            'ap' => ['kind' => 'url', 'location' => 'https://example.test/ap.txt'],
        ]]);
    }

    public function test_it_seeds_eight_services(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        $this->assertSame(self::EXPECTED_SERVICE_COUNT, Service::query()->count());
        $this->assertSame(
            [
                'claude',
                'cloudflare',
                'github',
                'google-cloud',
                'openai',
                'slack',
                'stripe',
                'vercel',
            ],
            Service::query()->orderBy('slug')->pluck('slug')->all(),
        );
    }

    /** Running it twice creates eight, not sixteen. */
    public function test_it_is_idempotent_by_slug(): void
    {
        $this->seed(ServiceCatalogSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);

        $this->assertSame(self::EXPECTED_SERVICE_COUNT, Service::query()->count());
        $this->assertSame(self::EXPECTED_SERVICE_COUNT, Monitor::query()->count());
    }

    /** Every service carries exactly one own-measurement monitor. */
    public function test_every_service_has_exactly_one_attached_monitor(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        foreach (Service::query()->get() as $service) {
            $this->assertSame(
                1,
                $service->monitors()->count(),
                "{$service->slug} does not have exactly one attached monitor.",
            );
        }
    }

    /**
     * Every attached monitor belongs to the system team, with `ai_mode` off and
     * `alert_on_down` false.
     *
     * All three are exposure guards rather than tidiness. `SweepAiSuggestions`
     * selects the `suggest`/`auto` fleet with no team filter and then spends the
     * OWNING team's AI budget, and paging on a third party's outage would wake
     * somebody for an event they cannot fix.
     */
    public function test_every_attached_monitor_is_a_muted_system_team_monitor(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        $systemTeamId = SystemTeam::resolve()->getKey();

        foreach (Service::query()->get() as $service) {
            $monitor = $service->monitors()->first();

            $this->assertNotNull($monitor);
            $this->assertSame($systemTeamId, $monitor->team_id, "{$service->slug}'s monitor is not on the system team.");
            $this->assertSame(AiMode::Off, $monitor->ai_mode, "{$service->slug}'s monitor is not ai_mode off.");
            $this->assertFalse((bool) $monitor->alert_on_down, "{$service->slug}'s monitor pages on down.");
            $this->assertSame(MonitorType::Http, $monitor->type);
            $this->assertSame(['eu-west', 'us-east', 'ap'], $monitor->regions);
            $this->assertNotNull($monitor->next_check_at, "{$service->slug}'s monitor never becomes due.");
        }
    }

    /**
     * A catalog monitor's `regions` equals the regions actually configured
     * with a proxy source, not every {@see MonitorRegion} case.
     *
     * Configured explicitly to two rather than trusting the deployment's own
     * `config/proxy.php` (which already carries three): a test relying on the
     * shipped count could not tell "seeded from config" from "seeded from the
     * enum, and config happens to list every case too".
     */
    public function test_every_monitor_carries_exactly_the_configured_proxy_regions(): void
    {
        config(['proxy.sources' => [
            'eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt'],
            'us-east' => ['kind' => 'url', 'location' => 'https://example.test/us-east.txt'],
        ]]);

        $this->seed(ServiceCatalogSeeder::class);

        foreach (Service::query()->get() as $service) {
            $monitor = $service->monitors()->firstOrFail();

            $this->assertSame(
                ['eu-west', 'us-east'],
                $monitor->regions,
                "{$service->slug}'s monitor does not carry exactly the configured proxy regions.",
            );
        }
    }

    /**
     * Below two configured regions the seeder refuses outright, because two
     * is `MIN_AGREEING_REGIONS`: an outage verdict is mathematically
     * unreachable with fewer, regardless of what those regions measure.
     */
    public function test_it_throws_when_fewer_than_two_proxy_regions_are_configured(): void
    {
        config(['proxy.sources' => [
            'eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt'],
        ]]);

        $this->expectException(RuntimeException::class);

        $this->seed(ServiceCatalogSeeder::class);
    }

    /**
     * A region DECLARED in `config('proxy.sources')` with an empty `location`
     * does not count as configured.
     *
     * `config/proxy.php` declares all three regions statically; only the
     * env-driven `location` says whether a deployment actually sourced one,
     * and its own docblock calls an empty location "DECLARED BUT UNUSABLE".
     * A seeder trusting key membership alone (this file's first version of
     * the fix did exactly that) would keep seeding a region an operator had
     * deliberately blanked out to disable it, and `ProxyPool::hasRegion()`
     * refuses to trust key membership alone for the identical reason.
     */
    public function test_a_declared_region_with_no_location_does_not_count_as_configured(): void
    {
        config(['proxy.sources' => [
            'eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt'],
            'us-east' => ['kind' => 'url', 'location' => 'https://example.test/us-east.txt'],
            'ap' => ['kind' => 'url', 'location' => ''],
        ]]);

        $this->seed(ServiceCatalogSeeder::class);

        $monitor = Monitor::query()->whereHas('services')->firstOrFail();

        $this->assertSame(['eu-west', 'us-east'], $monitor->regions);
    }

    /**
     * The throw side of the same guard: three regions are DECLARED but only
     * one carries a location, so the seeder must refuse exactly as it would
     * if the other two keys were absent outright.
     */
    public function test_it_throws_when_only_one_declared_region_actually_has_a_location(): void
    {
        config(['proxy.sources' => [
            'eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt'],
            'us-east' => ['kind' => 'url', 'location' => ''],
            'ap' => ['kind' => 'url', 'location' => ''],
        ]]);

        $this->expectException(RuntimeException::class);

        $this->seed(ServiceCatalogSeeder::class);
    }

    /**
     * Every service starts unpublished with a null `terms_reviewed_at`, so
     * nothing is publishable and nothing is fetchable until a human decides.
     */
    public function test_every_service_starts_unpublished_with_unreviewed_terms(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        foreach (Service::query()->get() as $service) {
            $this->assertFalse($service->is_published, "{$service->slug} was seeded published.");
            $this->assertNull($service->terms_reviewed_at, "{$service->slug} was seeded with reviewed terms.");
            $this->assertNull($service->published_at);
            $this->assertNull($service->content_changed_at);
            $this->assertFalse($service->canPublish(), "{$service->slug} is publishable straight out of the seeder.");
        }
    }

    /**
     * A service with a feed source has a URL to fetch, and one without a source
     * has none.
     *
     * The pairing is what matters: a `statuspage_v2` row with no URL would be
     * selected by the fan-out and refuse at the fetcher forever, and a `none` row
     * carrying a URL would look configured while nothing ever read it.
     */
    public function test_a_feed_source_and_its_url_always_agree(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        foreach (Service::query()->get() as $service) {
            if ($service->status_source === ServiceStatusSource::None) {
                $this->assertNull($service->status_source_url, "{$service->slug} has a url but no feed source.");

                continue;
            }

            $this->assertIsString($service->status_source_url);
            $this->assertStringStartsWith('https://', (string) $service->status_source_url);
        }
    }

    /**
     * Every row carries the terms note the reviewer needs, and it names a
     * verification date rather than an assertion.
     */
    public function test_every_service_carries_a_terms_note_for_the_reviewer(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        foreach (Service::query()->get() as $service) {
            $this->assertNotEmpty($service->terms_note, "{$service->slug} has no terms note.");
            $this->assertStringContainsString('2026-08-03', (string) $service->terms_note);
        }
    }

    /**
     * The GitHub row exists under exactly the slug a later verification step
     * resolves with `Service::where('slug', 'github')->firstOrFail()`.
     */
    public function test_the_github_row_is_addressable_by_its_slug(): void
    {
        $this->seed(ServiceCatalogSeeder::class);

        $github = Service::query()->where('slug', 'github')->firstOrFail();

        $this->assertSame(ServiceStatusSource::StatuspageV2, $github->status_source);
        $this->assertSame('https://www.githubstatus.com/api/v2/summary.json', $github->status_source_url);
    }

    public function test_a_re_seed_backfills_the_identifying_user_agent_on_existing_monitors(): void
    {
        /*
         * The already-exists branch, which nothing covered before this and which is
         * where the first version of the User-Agent fix silently did nothing.
         *
         * `run()` skips a service whose slug is present, so setting `request_headers`
         * only on the CREATE path left every environment seeded before that change
         * probing anonymously: `RelayClient` forwards `$monitor->request_headers` and
         * the edge worker sends `probe.request_headers ?? {}`, with no User-Agent
         * injected anywhere else. Meanwhile `/bot`, the page addressed to exactly the
         * operators receiving that traffic, had begun stating that both channels
         * identify themselves. A page claiming a courtesy the traffic does not extend
         * is worse than no page.
         */
        $this->seed(ServiceCatalogSeeder::class);

        $monitors = Monitor::query()->whereHas('services')->get();
        $this->assertNotEmpty($monitors, 'No catalog monitors were seeded, so this asserts nothing.');

        // Simulate an environment seeded before the fix. The column is NOT NULL, so
        // the real pre-fix state is an EMPTY array rather than null, which carries no
        // User-Agent just the same.
        foreach ($monitors as $monitor) {
            $monitor->forceFill(['request_headers' => []])->save();
        }

        $this->seed(ServiceCatalogSeeder::class);

        $agent = (string) config('uptizm.bot_user_agent');

        foreach (Monitor::query()->whereHas('services')->get() as $monitor) {
            $this->assertSame(
                $agent,
                $monitor->request_headers['User-Agent'] ?? null,
                'A re-seed left an existing catalog monitor probing without a User-Agent.',
            );
        }
    }

    public function test_the_backfill_preserves_a_header_an_operator_added(): void
    {
        // Converges rather than overwrites: only the User-Agent key is asserted.
        $this->seed(ServiceCatalogSeeder::class);

        $monitor = Monitor::query()->whereHas('services')->firstOrFail();
        $monitor->forceFill(['request_headers' => ['X-Operator' => 'keep-me']])->save();

        $this->seed(ServiceCatalogSeeder::class);

        $headers = $monitor->fresh()->request_headers;

        $this->assertSame('keep-me', $headers['X-Operator'] ?? null);
        $this->assertSame((string) config('uptizm.bot_user_agent'), $headers['User-Agent'] ?? null);
    }

    public function test_the_backfill_never_touches_a_monitor_the_system_team_does_not_own(): void
    {
        /*
         * `ServiceForm`'s monitor select is deliberately cross-team, so an operator can
         * attach a CUSTOMER's monitor to a catalog service. This seeder runs in every
         * environment on every `db:seed`, and the first version of the backfill
         * iterated every attached monitor, so it would have rewritten that customer's
         * outbound probe headers. A seeder must not write rows it does not own.
         */
        $this->seed(ServiceCatalogSeeder::class);

        $service = Service::query()->where('slug', 'github')->firstOrFail();

        $customerTeam = Team::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Ops',
            'personal_team' => false,
        ]);

        $customerMonitor = Monitor::query()->create([
            'team_id' => $customerTeam->getKey(),
            'name' => 'Acme API',
            'type' => MonitorType::Http,
            'url' => 'https://acme.test/health',
            'check_interval_sec' => 60,
            'request_headers' => ['X-Acme' => 'theirs'],
        ]);

        $service->monitors()->attach($customerMonitor->getKey());

        $this->seed(ServiceCatalogSeeder::class);

        $headers = $customerMonitor->fresh()->request_headers;

        $this->assertSame('theirs', $headers['X-Acme'] ?? null);
        $this->assertArrayNotHasKey(
            'User-Agent',
            $headers,
            'The catalog seeder rewrote the headers of a monitor belonging to a customer team.',
        );

        // Control: the system team's own monitor for that service DID get the header,
        // so this test cannot pass because the backfill stopped working entirely.
        $ours = $service->fresh()->monitors
            ->firstWhere('team_id', SystemTeam::resolve()->getKey());

        $this->assertNotNull($ours);
        $this->assertSame(
            (string) config('uptizm.bot_user_agent'),
            $ours->request_headers['User-Agent'] ?? null,
        );
    }
}
