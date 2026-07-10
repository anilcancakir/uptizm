<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
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
