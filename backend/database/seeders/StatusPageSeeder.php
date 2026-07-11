<?php

namespace Database\Seeders;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\StatusPage;
use App\Models\Team;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a demo public status page so `/s/acme` renders end-to-end.
 *
 * `DatabaseSeeder` only creates a demo user + personal team; there is no
 * monitor factory, so `migrate:fresh --seed` alone would leave the demo page
 * empty. This seeder first ensures the demo team has a handful of monitors
 * (with daily-uptime rollups and a public incident), then publishes one
 * status page attached to them.
 */
class StatusPageSeeder extends Seeder
{
    /**
     * The demo status page's public slug, matching the interview's `/s/acme` example.
     */
    private const DEMO_SLUG = 'acme';

    /**
     * Number of days of `monitor_daily_uptime` rollup rows to backfill per monitor,
     * enough for the public page's 90-day strip to show real segments.
     */
    private const UPTIME_DAYS = 14;

    /**
     * Seed the demo public status page.
     */
    public function run(): void
    {
        // 1. Never seed demo data outside local/dev; the page carries a stable
        //    slug + a preview token, so it should not exist on a real host.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // 2. Skip when the demo page already exists, guarding against
        //    duplicate slugs on repeated seed runs.
        if (StatusPage::query()->where('slug', self::DEMO_SLUG)->exists()) {
            return;
        }

        $team = Team::query()->first();

        if (! $team) {
            return;
        }

        // 3. There is no monitor factory, so ensure the team has monitors to show.
        //    Team has no `monitors()` relation of its own, so query directly.
        $monitors = Monitor::query()
            ->where('team_id', $team->id)
            ->where('show_on_status_page', true)
            ->get();

        if ($monitors->isEmpty()) {
            $monitors = $this->createDemoMonitors($team);
        }

        // 4. Backfill uptime rollups so the 90-day strip has real segments.
        foreach ($monitors as $monitor) {
            $this->seedDailyUptime($monitor);
            $this->seedRecentChecks($monitor);
        }

        // 5. Give the timeline a non-empty public incident on the degraded monitor.
        $this->seedPublicIncident($team, $monitors);

        // 6. Publish the page and attach the monitors in display order.
        $statusPage = StatusPage::create([
            'team_id' => $team->id,
            'name' => 'Acme Status',
            'slug' => self::DEMO_SLUG,
            'description' => 'Live status for Acme services.',
            'is_public' => true,
            'subscriptions_enabled' => true,
            'preview_token' => Str::random(40),
        ]);

        foreach ($monitors->values() as $index => $monitor) {
            $statusPage->monitors()->attach($monitor->id, [
                'display_order' => $index,
                'custom_label' => $monitor->name,
            ]);
        }
    }

    /**
     * Create three demo monitors for the team, with varied `last_status`
     * values so the public banner has something interesting to render.
     *
     * @return \Illuminate\Support\Collection<int, Monitor>
     */
    private function createDemoMonitors(Team $team): \Illuminate\Support\Collection
    {
        $monitors = collect([
            [
                'name' => 'API',
                'url' => 'https://api.acme.test/health',
                'last_status' => MonitorStatus::Up,
            ],
            [
                'name' => 'Website',
                'url' => 'https://www.acme.test',
                'last_status' => MonitorStatus::Up,
            ],
            [
                'name' => 'Checkout',
                'url' => 'https://checkout.acme.test/health',
                'last_status' => MonitorStatus::Degraded,
            ],
        ])->map(fn (array $attributes): Monitor => Monitor::create([
            'team_id' => $team->id,
            'name' => $attributes['name'],
            'type' => MonitorType::Http,
            'url' => $attributes['url'],
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'last_status' => $attributes['last_status'],
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinute(),
        ]));

        return $monitors;
    }

    /**
     * Backfill `monitor_daily_uptime` rows for the last {@see self::UPTIME_DAYS}
     * days so the public page's uptime strip renders real segments instead of
     * blank days.
     *
     * Uses a direct `DB::table` insert (not the Eloquent model) because there is
     * no `MonitorDailyUptime` model; the primary-key uuid must be generated by
     * hand here since the `ConditionallyUsesUuids` creating hook only fires on
     * Eloquent `create()`.
     */
    private function seedDailyUptime(Monitor $monitor): void
    {
        $usesUuids = MigrationHelper::usesUuids();

        for ($daysAgo = self::UPTIME_DAYS - 1; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo);
            $isDegradedDay = $monitor->last_status === MonitorStatus::Degraded && $daysAgo < 2;

            DB::table('monitor_daily_uptime')->insert([
                'id' => $usesUuids ? (string) Str::orderedUuid() : null,
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'date' => $date->toDateString(),
                'uptime_percent' => $isDegradedDay ? 97.50 : 100.00,
                'total_checks' => 1440,
                'failed_checks' => $isDegradedDay ? 36 : 0,
                'worst_status' => $isDegradedDay ? MonitorStatus::Degraded->value : MonitorStatus::Up->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Backfill ~60 recent {@see MonitorCheck} rows over the last hour (60s
     * interval) so the monitor detail's recent-checks table + response-time
     * chart and the dashboard/monitors avg-response KPI render real data
     * instead of empty/zero. Response time is a realistic per-status band (the
     * degraded monitor runs hotter with jitter); the newest check refreshes the
     * monitor's denormalized `last_response_ms`.
     */
    private function seedRecentChecks(Monitor $monitor): void
    {
        $isDegraded = $monitor->last_status === MonitorStatus::Degraded;
        $baseMs = $isDegraded ? 620 : 180;
        $latestMs = null;

        for ($i = 59; $i >= 0; $i--) {
            $checkedAt = now()->subSeconds($i * 60);
            // Deterministic jitter so the chart is not a flat line; the degraded
            // monitor spikes higher.
            $responseMs = $baseMs + ($i % 7) * ($isDegraded ? 45 : 18);
            if ($i === 0) {
                $latestMs = $responseMs;
            }

            MonitorCheck::create([
                'id' => (string) Str::orderedUuid(),
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'region' => $i % 2 === 0 ? 'us-east' : 'eu-west',
                'checked_at' => $checkedAt,
                'status' => $monitor->last_status,
                'status_code' => $monitor->last_status === MonitorStatus::Up ? 200 : 503,
                'response_ms' => $responseMs,
                'timing_dns_ms' => 4,
                'timing_connect_ms' => 12,
                'timing_tls_ms' => 20,
                'timing_ttfb_ms' => (int) ($responseMs * 0.7),
                'timing_download_ms' => (int) ($responseMs * 0.1),
                'probe_run_id' => (string) Str::orderedUuid(),
            ]);
        }

        if ($latestMs !== null) {
            $monitor->forceFill([
                'last_response_ms' => $latestMs,
                'last_checked_at' => now(),
            ])->save();
        }
    }

    /**
     * Open one public incident on the degraded monitor (falling back to the
     * first monitor when none is degraded) with a single public update, so the
     * status page's timeline is non-empty.
     *
     * @param \Illuminate\Support\Collection<int, Monitor> $monitors
     */
    private function seedPublicIncident(Team $team, \Illuminate\Support\Collection $monitors): void
    {
        $primaryMonitor = $monitors->firstWhere('last_status', MonitorStatus::Degraded) ?? $monitors->first();

        if (! $primaryMonitor) {
            return;
        }

        $incident = Incident::create([
            'team_id' => $team->id,
            'primary_monitor_id' => $primaryMonitor->id,
            'title' => "Elevated latency on {$primaryMonitor->name}",
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Monitoring,
            'started_at' => now()->subHours(2),
        ]);

        $incident->monitors()->attach($primaryMonitor->id, [
            'component_status_at_start' => ComponentStatus::PartialOutage->value,
            'component_status_current' => ComponentStatus::DegradedPerformance->value,
        ]);

        $incident->updates()->create([
            'actor' => 'human',
            'author' => 'Demo Ops',
            'status' => IncidentStatus::Monitoring,
            'message' => 'We are seeing elevated response times and are monitoring the situation.',
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now()->subHours(2),
        ]);
    }
}
