<?php

namespace Tests\Feature\StatusPage;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the rendered Blade output of the public status page: it shows the
 * component labels, the overall banner label, and a public incident title,
 * while never leaking the monitor's raw URL or the page's preview token
 * into the HTML.
 */
class StatusPageRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_component_labels_the_banner_label_and_an_incident_title(): void
    {
        $page = $this->makePageWithMonitor('render-me', isPublic: true);
        $monitor = $page->monitors()->first();

        $this->seedPublicIncident($page->team, $monitor, 'Elevated latency on checkout API');

        $response = $this->get('/s/render-me');

        $response->assertOk();
        $response->assertSee($monitor->name, escape: false);
        $response->assertSee('All Systems Operational');
        $response->assertSee('Elevated latency on checkout API');
    }

    public function test_it_never_leaks_the_monitor_url_or_the_preview_token(): void
    {
        $page = $this->makePageWithMonitor('render-secret', isPublic: true, previewToken: 'SUPER-SECRET-TOKEN');
        $monitor = $page->monitors()->first();

        $response = $this->get('/s/render-secret');

        $response->assertOk();
        $response->assertDontSee($monitor->url);
        $response->assertDontSee('SUPER-SECRET-TOKEN');
    }

    /**
     * Creates a status page for a fresh team with one attached, shown monitor
     * plus a daily-uptime row, so the assembler has real data to render.
     */
    protected function makePageWithMonitor(string $slug, bool $isPublic, ?string $previewToken = null): StatusPage
    {
        $team = $this->makeTeam();

        $page = new StatusPage([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => $isPublic,
            'subscriptions_enabled' => true,
        ]);

        // `preview_token` is guarded (hidden, non-fillable), so set it directly.
        $page->preview_token = $previewToken;
        $page->save();

        $monitor = $this->makeMonitor($team);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        $this->seedUptime($monitor, now()->format('Y-m-d'), 'operational');

        return $page;
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
     * Creates a monitor owned by the given team, shown on the status page.
     */
    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://secret-internal-host.example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
        ]);
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
     * Creates an active incident with one public update on the given monitor,
     * so the public page's incidents section has an entry to render.
     */
    protected function seedPublicIncident(Team $team, Monitor $monitor, string $title): Incident
    {
        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => $title,
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::Manual,
            'lifecycle' => IncidentStatus::Investigating,
            'ai_owned' => false,
            'started_at' => now(),
        ]);

        $incident->monitors()->attach([
            $monitor->id => [
                'component_status_at_start' => 'degraded',
                'component_status_current' => 'degraded',
            ],
        ]);

        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'message' => 'We are investigating elevated latency.',
            'actor' => 'human',
            'status' => IncidentStatus::Investigating,
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now(),
        ]);

        return $incident;
    }
}
