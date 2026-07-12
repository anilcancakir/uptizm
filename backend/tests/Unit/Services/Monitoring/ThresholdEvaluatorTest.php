<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Enums\ThresholdDirection;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the threshold-banding math and the threshold-driven incident-open
 * path: {@see ThresholdEvaluator::band()} must match the v2 boundary rules
 * exactly, and opening must stay idempotent so a sustained outage never
 * pages twice for the same unresolved incident.
 */
class ThresholdEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_band_high_bad_ok_below_warn(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::HighBad, 70.0, 80.0, 95.0);

        $this->assertSame('ok', $band->value);
    }

    public function test_band_high_bad_warn_at_or_above_warn_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::HighBad, 85.0, 80.0, 95.0);

        $this->assertSame('warn', $band->value);
    }

    public function test_band_high_bad_critical_at_or_above_critical_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::HighBad, 97.0, 80.0, 95.0);

        $this->assertSame('critical', $band->value);
    }

    public function test_band_low_bad_ok_above_warn(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::LowBad, 30.0, 20.0, 5.0);

        $this->assertSame('ok', $band->value);
    }

    public function test_band_low_bad_warn_at_or_below_warn_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::LowBad, 15.0, 20.0, 5.0);

        $this->assertSame('warn', $band->value);
    }

    public function test_band_low_bad_critical_at_or_below_critical_bound(): void
    {
        $band = ThresholdEvaluator::band(ThresholdDirection::LowBad, 3.0, 20.0, 5.0);

        $this->assertSame('critical', $band->value);
    }

    public function test_two_consecutive_fails_open_exactly_one_incident_and_a_third_opens_none(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $evaluator = new ThresholdEvaluator;

        // 1. Second consecutive failure crosses the threshold: opens one incident.
        $monitor->consecutive_fails = 2;
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), []);

        $this->assertSame(1, Incident::query()->count());

        // 2. A third consecutive failure must not open a second incident while
        //    the first is still unresolved.
        $monitor->consecutive_fails = 3;
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), []);

        $this->assertSame(1, Incident::query()->count());

        $incident = Incident::query()->sole();
        $this->assertSame('user_threshold', $incident->signal_source->value);
        $this->assertSame('detected', $incident->lifecycle->value);
        $this->assertFalse($incident->ai_owned);
        $this->assertNull($incident->trigger_metric_key);
    }

    public function test_a_threshold_down_opens_even_when_an_ai_incident_is_active(): void
    {
        $monitor = $this->makeMonitor(incidentThreshold: 2);
        $evaluator = new ThresholdEvaluator;

        // 1. An autonomous AI incident is already open on the monitor. Because
        //    the threshold dedupe is signal-scoped to non-AI incidents, this AI
        //    incident must NOT mask a later threshold-DOWN detection.
        $this->makeActiveAiIncident($monitor);

        // 2. The consecutive-fail threshold crosses: a NON-AI threshold incident
        //    opens alongside the active AI incident (it is not suppressed).
        $monitor->consecutive_fails = 2;
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), []);

        $threshold = Incident::query()->where('ai_owned', false)->sole();
        $this->assertSame(SignalSource::UserThreshold, $threshold->signal_source);
        $this->assertNull($threshold->trigger_metric_key);

        // 3. Both lanes coexist: the AI incident stays, the threshold opens once.
        $this->assertSame(1, Incident::query()->where('ai_owned', true)->count());
        $this->assertSame(2, Incident::query()->count());
    }

    public function test_create_incident_opens_a_manual_incident_without_a_check(): void
    {
        $monitor = $this->makeMonitor();
        $evaluator = new ThresholdEvaluator;

        // 1. A human files an incident directly: no check row, Manual source.
        $incident = $evaluator->createIncident(
            monitor: $monitor,
            source: SignalSource::Manual,
            check: null,
            severity: IncidentSeverity::Critical,
            title: 'Manual outage report',
            triggerMetricKey: null,
        );

        // 2. The incident carries the manual provenance and is not AI-owned.
        $this->assertSame('manual', $incident->signal_source->value);
        $this->assertFalse($incident->ai_owned);
        $this->assertSame('detected', $incident->lifecycle->value);
        $this->assertNull($incident->trigger_metric_key);
        $this->assertSame($monitor->id, $incident->primary_monitor_id);
        $this->assertSame($monitor->team_id, $incident->team_id);

        // 3. With no check, the incident stamps started_at at creation time.
        $this->assertNotNull($incident->started_at);

        // 4. The affected-component pivot is attached so the incident narrates
        //    which monitor it covers, exactly as the automated path does.
        $this->assertTrue(
            $incident->monitors()->where('monitor_id', $monitor->id)->exists(),
        );
    }

    /**
     * Creates a monitor owned by a freshly created team, with the given
     * `incident_threshold` (defaulting to {@see Monitor::DEFAULT_INCIDENT_THRESHOLD}).
     */
    protected function makeMonitor(?int $incidentThreshold = null): Monitor
    {
        $user = User::query()->create([
            'name' => 'Threshold Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Threshold Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => $incidentThreshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD,
            'consecutive_fails' => 0,
        ]);
    }

    /**
     * Persist an active, autonomous AI-owned incident on the monitor so the
     * signal-scoped threshold dedupe can be exercised against it.
     */
    protected function makeActiveAiIncident(Monitor $monitor): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => "Anomaly detected on {$monitor->name}",
            'impact' => IncidentImpact::Major,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::AiAnomaly,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => true,
            'trigger_metric_key' => 'response_time',
            'started_at' => now(),
        ]);
    }

    /**
     * Creates a persisted check row for the given monitor.
     */
    protected function makeCheck(Monitor $monitor, MonitorStatus $status): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'id' => (string) Str::orderedUuid(),
            'checked_at' => now(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east-1',
            'status' => $status,
        ]);
    }
}
