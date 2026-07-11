<?php

namespace Tests\Unit\Services\StatusPages;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\ViewModels\StatusPageViewModel;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusPageAssembler;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the security-critical read core of the public status page: the
 * assembler must scope visible components at the query, roll overall status
 * up the ladder while excluding paused monitors, expose only public incident
 * updates, and produce a view-model that provably carries no secret monitor
 * fields (url / auth_config) anywhere in its array form.
 */
class StatusPageAssemblerTest extends TestCase
{
    use RefreshDatabase;

    public function test_overall_status_is_worst_of_visible_components_and_excludes_paused(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $up = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $degraded = $this->makeMonitor($team, ['last_status' => MonitorStatus::Degraded]);
        $paused = $this->makeMonitor($team, ['last_status' => MonitorStatus::Paused]);

        $page->monitors()->attach([
            $up->id => ['display_order' => 0],
            $degraded->id => ['display_order' => 1],
            $paused->id => ['display_order' => 2],
        ]);

        $viewModel = (new StatusPageAssembler)->build($page);

        // Paused monitors are dropped at the query, so they neither appear as
        // components nor influence the overall roll-up.
        $this->assertCount(2, $viewModel->components);
        $this->assertSame('degraded', $viewModel->overallStatus);
    }

    public function test_view_model_never_carries_secret_monitor_fields(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, [
            'last_status' => MonitorStatus::Up,
            'url' => 'https://secret.internal/health?token=abc123',
            'auth_config' => [
                'type' => 'bearer',
                'token' => 'super-secret-token',
            ],
        ]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $keys = $this->collectKeys((new StatusPageAssembler)->build($page)->toArray());

        // Fail-closed allowlist: the secret and internal-identifier keys must
        // not surface anywhere in the recursive array form.
        $this->assertNotContains('url', $keys);
        $this->assertNotContains('auth_config', $keys);
        $this->assertNotContains('team_id', $keys);
    }

    public function test_incidents_expose_only_public_updates(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Degraded]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API latency spike',
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::Manual,
            'lifecycle' => IncidentStatus::Investigating,
            'started_at' => now(),
        ]);
        $incident->monitors()->attach([
            $monitor->id => [
                'component_status_at_start' => 'degraded_performance',
                'component_status_current' => 'degraded_performance',
            ],
        ]);

        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'actor' => 'human',
            'author' => 'On-call',
            'status' => IncidentStatus::Investigating,
            'message' => 'Public: we are investigating.',
            'is_public' => true,
            'display_at' => now(),
        ]);
        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'actor' => 'human',
            'author' => 'On-call',
            'status' => IncidentStatus::Investigating,
            'message' => 'Internal: rotate the failing pod.',
            'is_public' => false,
            'display_at' => now(),
        ]);

        $viewModel = (new StatusPageAssembler)->build($page);

        $entries = [];
        foreach ($viewModel->incidents as $group) {
            foreach ($group['entries'] as $entry) {
                $entries[] = $entry;
            }
        }

        $this->assertCount(1, $entries);
        $this->assertCount(1, $entries[0]['updates']);
        $this->assertSame('Public: we are investigating.', $entries[0]['updates'][0]['message']);
    }

    public function test_each_component_strip_has_ninety_entries(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $viewModel = (new StatusPageAssembler)->build($page);

        $this->assertCount(90, $viewModel->components[0]['strip']);
    }

    public function test_view_model_round_trips_through_a_plain_array(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $original = (new StatusPageAssembler)->build($page);
        $rehydrated = StatusPageViewModel::fromArray($original->toArray());

        // The cache store runs with serializable_classes => false, so Step 5
        // persists toArray() and rehydrates via fromArray(); the round-trip must
        // reproduce the exact array form.
        $this->assertEquals($original->toArray(), $rehydrated->toArray());
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Status Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Status Team',
        ]);
    }

    /**
     * Creates a public status page for the given team.
     */
    protected function makePage(Team $team): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => 'uptizm-'.Str::random(6),
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => true,
            'subscriptions_enabled' => true,
        ]);
    }

    /**
     * Creates a monitor owned by the given team, shown on the status page by
     * default, with caller overrides merged over the baseline.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(Team $team, array $overrides = []): Monitor
    {
        return Monitor::query()->create(array_merge([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
        ], $overrides));
    }

    /**
     * Inserts one daily-uptime rollup row for the monitor.
     */
    protected function seedUptime(Monitor $monitor, string $date, string $worst, float $percent = 100.0): void
    {
        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'date' => $date,
            'uptime_percent' => $percent,
            'total_checks' => 100,
            'failed_checks' => 0,
            'worst_status' => $worst,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->insert($row);
    }

    /**
     * Recursively collects every string array key so a scan can assert the
     * absence of secret / internal keys anywhere in the structure.
     *
     * @param  array<mixed>  $data
     * @return array<int, string>
     */
    protected function collectKeys(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->collectKeys($value));
            }
        }

        return $keys;
    }
}
