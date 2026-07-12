<?php

namespace Tests\Unit\Services\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\AiSuggestion;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiIncidentOpener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the AI-owned incident creator's dedupe rule: a monitor already carrying
 * an active AI incident must not open a second one (the AI open lane dedupes
 * within itself), yet an active non-AI (threshold/manual) incident must NOT
 * block an AI open, because the two detection lanes are independent.
 */
class AiIncidentOpenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_dedupes_against_an_active_ai_incident_on_the_monitor(): void
    {
        $monitor = $this->makeMonitor();
        $opener = new AiIncidentOpener;

        // 1. The first suggestion opens a fresh AI incident on the monitor.
        $first = $opener->open($this->makeSuggestion($monitor));
        $this->assertTrue($first->wasRecentlyCreated);

        // 2. A second, distinct suggestion on the same monitor while the first
        //    AI incident is still active must fold into it, not double-open.
        $second = $opener->open($this->makeSuggestion($monitor));

        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_open_is_not_blocked_by_an_active_non_ai_incident(): void
    {
        $monitor = $this->makeMonitor();
        $this->makeActiveThresholdIncident($monitor);
        $opener = new AiIncidentOpener;

        // A threshold incident lives in a different detection lane, so the AI
        // open proceeds and both incidents coexist on the monitor.
        $incident = $opener->open($this->makeSuggestion($monitor));

        $this->assertTrue($incident->wasRecentlyCreated);
        $this->assertTrue($incident->ai_owned);
        $this->assertSame(2, Incident::query()->count());
    }

    protected function makeMonitor(): Monitor
    {
        $user = User::query()->create([
            'name' => 'Opener Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Opener Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'last_status' => MonitorStatus::Down,
        ]);
    }

    protected function makeSuggestion(Monitor $monitor): AiSuggestion
    {
        $suggestion = AiSuggestion::query()->create([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => 'response_time',
            'method' => 'mad',
            'score' => 6.0,
            'severity' => IncidentSeverity::Critical->value,
            'confidence' => AiConfidence::High,
            'source' => 'llm',
            'recommendation' => 'Investigate the sustained response-time spike.',
            'evidence' => [
                'observed' => 2500.0,
            ],
            'dedupe_key' => 'dedupe:'.Str::uuid(),
            'status' => AiSuggestionStatus::Pending,
            'expires_at' => now()->addDays(7),
        ]);
        $suggestion->setRelation('monitor', $monitor);

        return $suggestion;
    }

    protected function makeActiveThresholdIncident(Monitor $monitor): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => "{$monitor->name} is down",
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }
}
