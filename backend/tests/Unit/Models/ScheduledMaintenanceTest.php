<?php

namespace Tests\Unit\Models;

use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see ScheduledMaintenance} shape: team- and status-page-scoped,
 * `starts_at`/`ends_at` cast to `immutable_datetime`, `suppress_alerts`
 * defaulting true both in the database and in a freshly built in-memory
 * model, `announced_at` nullable, and the affected-monitors pivot mirroring
 * `incident_monitors`.
 */
class ScheduledMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_starts_at_and_ends_at_round_trip_as_immutable_datetime(): void
    {
        $maintenance = $this->makeMaintenance();

        $fresh = $maintenance->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $fresh->starts_at);
        $this->assertInstanceOf(CarbonImmutable::class, $fresh->ends_at);
    }

    public function test_suppress_alerts_defaults_to_true_in_the_database(): void
    {
        $maintenance = $this->makeMaintenance();

        $this->assertTrue($maintenance->fresh()->suppress_alerts);
    }

    public function test_suppress_alerts_defaults_to_true_on_a_freshly_built_model(): void
    {
        // The schema default alone is not enough: a row not yet refreshed from
        // the database must already read `true`, mirroring the trap this
        // codebase hit on StatusPage.domain_mode.
        $maintenance = new ScheduledMaintenance;

        $this->assertTrue($maintenance->suppress_alerts);
    }

    public function test_announced_at_is_nullable_and_null_by_default(): void
    {
        $maintenance = $this->makeMaintenance();

        $this->assertNull($maintenance->fresh()->announced_at);
    }

    public function test_belongs_to_its_team(): void
    {
        $team = $this->makeTeam();
        $maintenance = $this->makeMaintenance($team);

        $this->assertTrue($maintenance->team->is($team));
    }

    public function test_belongs_to_its_status_page(): void
    {
        $team = $this->makeTeam();
        $statusPage = $this->makeStatusPage($team);
        $maintenance = $this->makeMaintenance($team, $statusPage);

        $this->assertTrue($maintenance->statusPage->is($statusPage));
    }

    public function test_factory_produces_a_valid_window_attached_to_monitors(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, 'API');

        $maintenance = ScheduledMaintenance::factory()
            ->create([
                'team_id' => $team->id,
                'status_page_id' => $this->makeStatusPage($team)->id,
            ]);
        $maintenance->monitors()->attach($monitor->id);

        $this->assertCount(1, $maintenance->monitors()->get());
        $this->assertTrue($maintenance->monitors()->first()->is($monitor));
    }

    /**
     * Creates a persisted scheduled maintenance window for the given team and
     * status page, creating either when omitted.
     */
    protected function makeMaintenance(?Team $team = null, ?StatusPage $statusPage = null): ScheduledMaintenance
    {
        $team ??= $this->makeTeam();
        $statusPage ??= $this->makeStatusPage($team);

        return ScheduledMaintenance::factory()->create([
            'team_id' => $team->id,
            'status_page_id' => $statusPage->id,
        ]);
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Scheduled Maintenance Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Scheduled Maintenance Team',
        ]);
    }

    /**
     * Creates a persisted status page for the given team.
     */
    protected function makeStatusPage(Team $team): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Public Status',
            'slug' => Str::uuid().'-status',
        ]);
    }

    /**
     * Creates a persisted monitor for the given team.
     */
    protected function makeMonitor(Team $team, string $name): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => 'http',
            'url' => 'https://example.com/'.Str::slug($name),
            'check_interval_sec' => 60,
        ]);
    }
}
