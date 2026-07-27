<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\AiSuggestion;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AiSuggestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The demo AI suggestion must be supported by the monitor's OWN check history.
 *
 * The seeder used to attach a hardcoded "running well above baseline" claim,
 * with invented evidence numbers, to whichever monitor was created first. On the
 * demo fixture that is the HEALTHIEST monitor, so the AI inbox shipped a
 * confident claim the monitor's own data contradicted. That is the one thing the
 * product's AI boundary forbids, and the demo is where a prospective customer
 * meets the feature, so these pin the honesty rather than the wording.
 */
class AiSuggestionSeederHonestyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_the_suggestion_to_the_monitor_the_evidence_describes(): void
    {
        $team = $this->makeTeam();

        $healthy = $this->monitorWithTimings($team, 'Healthy', array_fill(0, 60, 200));
        // A genuine trend: this monitor's recent window runs well above its own
        // earlier baseline, which is the signal the detector claims to find.
        $slow = $this->monitorWithTimings(
            $team,
            'Slow',
            array_merge(array_fill(0, 48, 200), array_fill(0, 12, 900)),
        );

        $this->seed(AiSuggestionSeeder::class);

        $suggestion = AiSuggestion::query()->first();

        $this->assertNotNull($suggestion, 'a measurable anomaly should produce a suggestion');
        $this->assertSame(
            $slow->id,
            $suggestion->monitor_id,
            'the suggestion must name the monitor whose data supports it, not the healthy one',
        );
        $this->assertNotSame($healthy->id, $suggestion->monitor_id);
    }

    public function test_its_evidence_numbers_come_from_real_checks(): void
    {
        $team = $this->makeTeam();
        $this->monitorWithTimings(
            $team,
            'Slow',
            array_merge(array_fill(0, 48, 200), array_fill(0, 12, 900)),
        );

        $this->seed(AiSuggestionSeeder::class);

        $suggestion = AiSuggestion::query()->firstOrFail();
        $evidence = $suggestion->evidence;

        // The baseline is the monitor's own median (mostly 200s) and the observed
        // value is its recent window (900s). Both must be readable back out of
        // the rows, not invented.
        $this->assertSame(200.0, (float) $evidence['baseline']);
        $this->assertSame(900.0, (float) $evidence['observed']);
        $this->assertGreaterThan(1.0, (float) $suggestion->score);

        // And the prose must carry those same numbers, so an operator who opens
        // the evidence finds what the sentence claimed.
        $this->assertStringContainsString('900ms', $suggestion->recommendation);
        $this->assertStringContainsString('200ms', $suggestion->recommendation);
    }

    public function test_it_writes_nothing_when_no_monitor_supports_a_claim(): void
    {
        $team = $this->makeTeam();
        // Flat history: elevated but stationary is not an anomaly, and inventing
        // one would be exactly the defect this guards.
        $this->monitorWithTimings($team, 'Steady', array_fill(0, 60, 800));

        $this->seed(AiSuggestionSeeder::class);

        $this->assertSame(
            0,
            AiSuggestion::query()->count(),
            'a flat history must leave the inbox empty rather than produce a claim',
        );
    }

    private function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Seeder Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Seeder Team',
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);

        return $team;
    }

    /**
     * @param  list<int>  $timings  oldest first
     */
    private function monitorWithTimings(Team $team, string $name, array $timings): Monitor
    {
        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => MonitorType::Http,
            'url' => 'https://example.com/'.Str::slug($name),
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            'alert_on_down' => true,
            'alert_on_recover' => true,
        ]);

        $total = count($timings);
        foreach ($timings as $index => $ms) {
            MonitorCheck::query()->create([
                'id' => (string) Str::orderedUuid(),
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'region' => 'us-east',
                'checked_at' => now()->subMinutes($total - $index),
                'status' => MonitorStatus::Up,
                'status_code' => 200,
                'response_ms' => $ms,
            ]);
        }

        return $monitor;
    }
}
