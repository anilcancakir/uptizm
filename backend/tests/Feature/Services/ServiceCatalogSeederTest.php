<?php

namespace Tests\Feature\Services;

use App\Enums\AiMode;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Models\Monitor;
use App\Models\Service;
use App\Support\Services\SystemTeam;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $this->assertSame(MonitorRegion::values(), $monitor->regions);
            $this->assertNotNull($monitor->next_check_at, "{$service->slug}'s monitor never becomes due.");
        }
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
}
