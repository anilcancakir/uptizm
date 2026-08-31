<?php

namespace Tests\Feature\Ai;

use App\Enums\AiConfidence;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\AiIncidentAnalysis;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\IncidentAnalysisGateway;
use App\Services\Ai\IncidentAnalysisPayload;
use App\Services\Ai\IncidentAnalysisResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers WHICH checks reach the analysis payload: the window they are drawn
 * from and the twenty that survive the cap.
 *
 * Both are about a RESOLVED incident, which is the state the rest of the
 * analysis suite never puts one in. Every fixture in `AnalysisFeedbackTest`,
 * `IncidentAnalysisControllerTest` and `AutonomousStatusUpdateTest` leaves
 * `resolved_at` null, so a window that never closes and a window closed at the
 * right instant select the identical rows there and both tests below passed
 * against the defect they were written for.
 */
class IncidentAnalysisWindowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A closed incident is a settled question, so its evidence has to stop
     * moving when it does.
     *
     * Measured on production before this test existed: an incident that ran
     * from 05:20 to 05:22 was analysed at 21:00 from twenty checks recorded
     * between 20:39 and 20:59, all of them healthy, and not one of the twelve
     * that span the outage. The window had a lower bound and no upper one, so
     * `orderByDesc` plus a cap of twenty means "the newest twenty this monitor
     * has" for every read after the incident closes.
     *
     * It costs money as well as accuracy. The evidence fingerprint is what the
     * store is keyed on, so evidence that moves every minute is a key that
     * moves every minute: the stored answer can never be found again and every
     * later reader buys a new one.
     */
    public function test_a_resolved_incidents_evidence_does_not_drift_as_new_checks_land(): void
    {
        $gateway = $this->bindCountingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeResolvedIncident($monitor, now()->subMinutes(30), now()->subMinutes(25));

        $this->makeCheck($monitor, MonitorStatus::Down, 503, now()->subMinutes(28));
        $this->makeCheck($monitor, MonitorStatus::Down, 503, now()->subMinutes(27));
        $this->makeCheck($monitor, MonitorStatus::Down, 503, now()->subMinutes(26));

        $first = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        // The monitor recovered and has been answering ever since, which is what
        // every monitor does after every incident. A verdict the incident never
        // saw must not become the incident's evidence.
        $this->makeCheck($monitor, MonitorStatus::Up, 200, now()->subMinute());
        $this->makeCheck($monitor, MonitorStatus::Up, 200, now());

        $second = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis");

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame(
            1,
            $gateway->calls,
            'Checks recorded after the incident resolved moved the evidence, so the stored answer could not be found.',
        );
        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'Both reads should name the same stored analysis.',
        );
        $this->assertSame(1, AiIncidentAnalysis::query()->count());
    }

    /**
     * A long incident has to reach the model with the failure that started it,
     * not only the recovery that ended it.
     *
     * The ten-check lookback in front of `started_at` exists precisely to catch
     * the onset, because a threshold trips on CONSECUTIVE failures and the ones
     * that caused it are all before the moment the incident opened. Selecting
     * the newest twenty then throws that lookback away for any incident longer
     * than the cap, which on production is 26 of 142 incidents, the longest
     * carrying 2028 checks inside its own window.
     *
     * The two markers are what make this test bite. `599` appears once, on the
     * first check, and `200` once, on the last. An assertion on either alone
     * would pass on a selection taking one end only.
     */
    public function test_a_long_incident_carries_its_onset_and_not_only_its_tail(): void
    {
        $spy = $this->bindCapturingGateway();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeResolvedIncident($monitor, now()->subMinutes(60), now()->subMinutes(25));

        // Thirty checks at the monitor's own cadence, every one of them inside
        // the window and before the incident closed.
        for ($minute = 58; $minute >= 29; $minute--) {
            [$status, $code] = match ($minute) {
                58 => [MonitorStatus::Down, 599],
                29 => [MonitorStatus::Up, 200],
                default => [MonitorStatus::Down, 503],
            };

            $this->makeCheck($monitor, $status, $code, now()->subMinutes($minute));
        }

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/incidents/{$incident->id}/analysis")
            ->assertStatus(200);

        $this->assertNotNull($spy->captured);
        $codes = array_column($spy->captured->checks, 'status_code');

        $this->assertContains(599, $codes, 'The failure that opened the incident never reached the model.');
        $this->assertContains(200, $codes, 'The recovery that closed the incident never reached the model.');
        $this->assertLessThanOrEqual(20, count($codes), 'The evidence cap must still hold.');
    }

    /**
     * Bind a gateway that counts how many times it was asked.
     *
     * Same shape as `AnalysisFeedbackTest::bindCountingGateway()`, and
     * deliberately duplicated rather than shared: what is under test here is
     * how many times the model is asked, and a helper reached across two test
     * classes is a helper one of them can silently change.
     */
    protected function bindCountingGateway(): object
    {
        $gateway = new class implements IncidentAnalysisGateway
        {
            public int $calls = 0;

            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                $this->calls++;

                return new IncidentAnalysisResult(
                    summary: 'Counting stub answer number '.$this->calls.'.',
                    confidence: AiConfidence::Medium,
                    contributingFactors: [],
                );
            }
        };

        $this->app->instance(IncidentAnalysisGateway::class, $gateway);

        return $gateway;
    }

    /**
     * Bind a gateway that keeps the payload it was handed.
     */
    protected function bindCapturingGateway(): object
    {
        $gateway = new class implements IncidentAnalysisGateway
        {
            public ?IncidentAnalysisPayload $captured = null;

            public function analyze(IncidentAnalysisPayload $payload): IncidentAnalysisResult
            {
                $this->captured = $payload;

                return new IncidentAnalysisResult(
                    summary: 'Capturing stub answer.',
                    confidence: AiConfidence::Medium,
                    contributingFactors: [],
                );
            }
        };

        $this->app->instance(IncidentAnalysisGateway::class, $gateway);

        return $gateway;
    }

    /**
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Analysis Window Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Analysis Window Team',
            // AI incident analysis is an analysis-tier (Pro+) feature.
            'plan' => 'pro',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return [$monitor, $user];
    }

    protected function makeResolvedIncident(Monitor $monitor, Carbon $startedAt, Carbon $resolvedAt): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Resolved,
            'ai_owned' => false,
            'started_at' => $startedAt,
            'resolved_at' => $resolvedAt,
        ]);
    }

    protected function makeCheck(Monitor $monitor, MonitorStatus $status, int $code, Carbon $checkedAt): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'region' => 'eu-central',
            'status' => $status,
            'status_code' => $code,
            'response_ms' => 4100,
            'checked_at' => $checkedAt,
        ]);
    }
}
