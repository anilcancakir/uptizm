<?php

namespace Tests\Feature\Services;

use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Models\Monitor;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Database\Factories\ServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Covers the service-catalog domain introduced by this step: the
 * `ServiceStatusSource` cast, the `canPublish()` enforcement point for the
 * plan's Must Have that a page never goes live on a re-rendered provider
 * feed alone, the `HostGuard` rekey onto `status_source_url`, and the
 * `service_monitor` pivot round trip from both directions.
 *
 * Neither {@see Monitor} nor {@see Team} exposes a working `factory()` (see
 * `MonitorContentVersionFactory`'s docblock), so both are built the same way
 * the rest of the suite builds them: a `Team::query()->create()` owned by a
 * `User::factory()`, in turn owning a `Monitor::query()->create()`. This
 * step's own {@see Service} does have a factory
 * ({@see ServiceFactory}), used throughout.
 *
 * Per this plan's dependency notes, Step 3 (the system team) may not have
 * landed yet when this test runs, so every monitor here is attached to an
 * ordinary factory-made team, never the system team.
 */
class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_source_casts_to_the_enum(): void
    {
        $service = Service::factory()->create([
            'status_source' => ServiceStatusSource::StatuspageV2,
        ]);

        $this->assertInstanceOf(ServiceStatusSource::class, $service->status_source);
        $this->assertSame(ServiceStatusSource::StatuspageV2, $service->fresh()->status_source);
    }

    public function test_can_publish_is_false_when_terms_are_not_reviewed(): void
    {
        $service = Service::factory()->create([
            'terms_reviewed_at' => null,
        ]);

        $this->assertFalse($service->canPublish());
    }

    public function test_can_publish_is_false_when_terms_are_reviewed_but_no_monitor_is_attached(): void
    {
        $service = Service::factory()->termsReviewed()->create();

        $this->assertFalse($service->canPublish());
    }

    public function test_can_publish_is_true_only_when_terms_are_reviewed_and_a_monitor_is_attached(): void
    {
        $service = Service::factory()->termsReviewed()->create();
        $monitor = $this->makeMonitor($this->makeTeam());

        $service->monitors()->attach($monitor->id);

        $this->assertTrue($service->canPublish());
    }

    public function test_status_source_url_blocked_host_error_surfaces_under_status_source_url_key(): void
    {
        try {
            Service::assertStatusSourceUrlAllowed('https://127.0.0.1/status');

            $this->fail('Expected a ValidationException for a loopback status_source_url.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            // The rekey is the whole point of this test: a substring match on
            // the message would also pass on the un-rekeyed `url` version, so
            // assert on the error bag's actual KEYS instead.
            $this->assertArrayHasKey('status_source_url', $errors);
            $this->assertArrayNotHasKey('url', $errors);
        }
    }

    public function test_status_source_url_rejects_loopback_and_internal_hosts(): void
    {
        foreach (['https://127.0.0.1/status', 'https://feed.internal/status'] as $url) {
            try {
                Service::assertStatusSourceUrlAllowed($url);

                $this->fail("Expected {$url} to be rejected as an internal host.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status_source_url', $exception->errors());
            }
        }
    }

    public function test_status_source_url_allows_a_null_or_empty_value(): void
    {
        // A service with no feed source has nothing to validate; this must
        // not throw, or a fresh unpublished service could never be created.
        Service::assertStatusSourceUrlAllowed(null);
        Service::assertStatusSourceUrlAllowed('');

        $this->expectNotToPerformAssertions();
    }

    public function test_pivot_label_round_trips_from_both_directions(): void
    {
        $service = Service::factory()->create();
        $monitor = $this->makeMonitor($this->makeTeam());

        $service->monitors()->attach($monitor->id, [
            'label' => 'API endpoint',
        ]);

        $viaService = $service->monitors()->get();
        $this->assertNotEmpty($viaService, 'Expected the just-attached monitor to be present.');
        $this->assertSame('API endpoint', $viaService->first()->pivot->label);

        $viaMonitor = $monitor->services()->get();
        $this->assertNotEmpty($viaMonitor, 'Expected the just-attached service to be present.');
        $this->assertSame('API endpoint', $viaMonitor->first()->pivot->label);
    }

    /**
     * Build a persisted team owned by a fresh factory user, mirroring the
     * rest of the suite's team construction (see `MonitorTestEndpointTest`).
     */
    private function makeTeam(): Team
    {
        return Team::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
    }

    /**
     * Build a persisted monitor for the given team, mirroring the rest of
     * the suite's monitor construction.
     */
    private function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Health',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);
    }
}
