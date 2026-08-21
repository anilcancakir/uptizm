<?php

namespace Tests\Feature\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Enums\ThresholdDirection;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting a metric closes the incident that metric opened.
 *
 * The hole this fills, one level down from the monitor-deleted one: the metric
 * lane's auto-resolve reads the trailing run of frozen bands for a metric KEY,
 * and a deleted metric produces no further samples. So the run stays whatever it
 * was at the breach forever, `metricRunIsOk` can never answer yes, and the
 * incident sits `detected` until a human notices it. Nothing else closes it:
 * `resolveIfRecovered` is scoped to `trigger_metric_key IS NULL`, and
 * `closeOrphanedBy` only fires when the MONITOR goes.
 *
 * Deliberately mirrors `closeOrphanedBy`: the close is silent (no page, no
 * public update) and the timeline entry states the reason internally, because
 * nothing recovered, the measurement stopped.
 */
class DeletedMetricIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_metric_closes_the_incident_it_opened(): void
    {
        [$monitor] = $this->makeMonitor();
        $metric = $this->makeMetric($monitor, 'cpu');
        $incident = $this->makeIncident($monitor, 'cpu');

        $metric->delete();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_the_close_says_why_on_the_timeline_and_not_in_public(): void
    {
        [$monitor] = $this->makeMonitor();
        $metric = $this->makeMetric($monitor, 'cpu');
        $incident = $this->makeIncident($monitor, 'cpu');

        $metric->delete();

        $note = $incident->updates()->latest()->first();
        $this->assertNotNull($note);
        $this->assertFalse((bool) $note->is_public);
        $this->assertStringContainsString('deleted', (string) $note->message);
    }

    public function test_another_metrics_incident_is_left_alone(): void
    {
        [$monitor] = $this->makeMonitor();
        $this->makeMetric($monitor, 'cpu');
        $disk = $this->makeMetric($monitor, 'disk');
        $cpuIncident = $this->makeIncident($monitor, 'cpu');

        $disk->delete();

        $this->assertTrue($cpuIncident->refresh()->lifecycle->isActive());
    }

    public function test_the_down_incident_on_the_same_monitor_is_left_alone(): void
    {
        [$monitor] = $this->makeMonitor();
        $metric = $this->makeMetric($monitor, 'cpu');
        // `trigger_metric_key` null is the down lane's own incident, which the
        // monitor's own recovery closes.
        $down = $this->makeIncident($monitor, null);

        $metric->delete();

        $this->assertTrue($down->refresh()->lifecycle->isActive());
    }

    /**
     * The autonomous lane owns its incidents end to end, and its
     * `trigger_metric_key` is a SIGNAL name rather than a configured metric key,
     * so a metric that happens to share the name must not close one.
     */
    public function test_an_ai_owned_incident_is_left_alone(): void
    {
        [$monitor] = $this->makeMonitor();
        $metric = $this->makeMetric($monitor, 'response_time');
        $ai = $this->makeIncident($monitor, 'response_time', aiOwned: true);

        $metric->delete();

        $this->assertTrue($ai->refresh()->lifecycle->isActive());
    }

    public function test_an_already_resolved_incident_is_not_re_stamped(): void
    {
        [$monitor] = $this->makeMonitor();
        $metric = $this->makeMetric($monitor, 'cpu');
        $incident = $this->makeIncident($monitor, 'cpu');
        $incident->update([
            'lifecycle' => IncidentStatus::Resolved,
            'resolved_at' => now()->subHour(),
        ]);
        $resolvedAt = $incident->refresh()->resolved_at;

        $metric->delete();

        $this->assertEquals($resolvedAt, $incident->refresh()->resolved_at);
        $this->assertSame(0, $incident->updates()->count());
    }

    /**
     * @return array{0: Monitor, 1: Team}
     */
    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Metric Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $team = Team::query()->create(['user_id' => $user->id, 'name' => 'Metric Team']);
        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);

        return [$monitor, $team];
    }

    protected function makeMetric(Monitor $monitor, string $key): MonitorMetric
    {
        return MonitorMetric::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'label' => strtoupper($key),
            'key' => $key,
            'type' => MetricType::Numeric,
            'source' => MetricSource::JsonPath,
            'extraction_path' => '$.'.$key,
            'threshold_direction' => ThresholdDirection::HighBad,
            'warn_bound' => 80.0,
            'critical_bound' => 95.0,
        ]);
    }

    protected function makeIncident(
        Monitor $monitor,
        ?string $metricKey,
        bool $aiOwned = false,
    ): Incident {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Something breached',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => $aiOwned ? SignalSource::AiAnomaly : SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => $aiOwned,
            'trigger_metric_key' => $metricKey,
            'started_at' => now(),
        ]);
    }
}
