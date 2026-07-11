<?php

namespace Tests\Feature\Broadcasting;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Events\IncidentBroadcast;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the real-time incident broadcast contract the Flutter Echo client
 * subscribes to on the private `teams.{team_id}` channel. The payload is the
 * leak boundary: it must carry only team-owned incident fields and never the
 * primary monitor's url, auth_config, password, or headers.
 */
class IncidentBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_declares_after_commit_broadcast_contract(): void
    {
        $incident = $this->makeIncident();

        $event = new IncidentBroadcast($incident, 'opened');

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_broadcasts_on_the_private_team_channel(): void
    {
        $incident = $this->makeIncident();

        $channels = (new IncidentBroadcast($incident, 'opened'))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame("private-teams.{$incident->team_id}", $channels[0]->name);
    }

    public function test_wire_name_varies_by_kind(): void
    {
        $incident = $this->makeIncident();

        $this->assertSame(
            'incident.opened',
            (new IncidentBroadcast($incident, 'opened'))->broadcastAs(),
        );
        $this->assertSame(
            'incident.resolved',
            (new IncidentBroadcast($incident, 'resolved'))->broadcastAs(),
        );
    }

    public function test_payload_carries_the_team_owned_allowlist(): void
    {
        $incident = $this->makeIncident();

        $payload = (new IncidentBroadcast($incident, 'opened'))->broadcastWith();

        $this->assertSame($incident->id, $payload['id']);
        $this->assertSame($incident->team_id, $payload['team_id']);
        $this->assertSame('Checkout API is down', $payload['title']);
        $this->assertSame(IncidentStatus::Detected->value, $payload['lifecycle']);
        $this->assertSame(IncidentSeverity::Critical->value, $payload['severity']);
        $this->assertSame(IncidentImpact::Critical->value, $payload['impact']);
        $this->assertSame(SignalSource::UserThreshold->value, $payload['signal_source']);
        $this->assertFalse($payload['ai_owned']);
        $this->assertSame($incident->primary_monitor_id, $payload['primary_monitor_id']);
        $this->assertSame('Checkout API', $payload['monitor_name']);
        $this->assertSame('response_time', $payload['trigger_metric_key']);
        $this->assertSame($incident->started_at?->toIso8601String(), $payload['started_at']);
        $this->assertNull($payload['resolved_at']);
    }

    public function test_payload_falls_back_when_primary_monitor_is_absent(): void
    {
        $incident = $this->makeIncident(['primary_monitor_id' => null]);

        $payload = (new IncidentBroadcast($incident, 'opened'))->broadcastWith();

        $this->assertSame('A monitor', $payload['monitor_name']);
    }

    public function test_payload_never_leaks_a_monitor_secret(): void
    {
        $incident = $this->makeIncident();
        $encoded = json_encode((new IncidentBroadcast($incident, 'opened'))->broadcastWith());

        // The allowlist redacts every monitor connection detail: neither the
        // secret values nor their key names may survive into the wire payload.
        $this->assertStringNotContainsString('https://internal.example.com/health', $encoded);
        $this->assertStringNotContainsString('super-secret-token', $encoded);
        $this->assertStringNotContainsString('X-Internal-Auth', $encoded);
        $this->assertArrayNotHasKey('url', json_decode($encoded, true));
        $this->assertArrayNotHasKey('auth_config', json_decode($encoded, true));
        $this->assertArrayNotHasKey('password', json_decode($encoded, true));
        $this->assertArrayNotHasKey('headers', json_decode($encoded, true));
    }

    /**
     * Persist an incident whose primary monitor carries connection secrets, so
     * the redaction assertions have something to fail against.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeIncident(array $overrides = []): Incident
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team);

        return Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Checkout API is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'trigger_metric_key' => 'response_time',
            'started_at' => now(),
            'resolved_at' => null,
            ...$overrides,
        ]);
    }

    protected function makeTeam(): Team
    {
        $owner = User::query()->create([
            'name' => 'Team Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $owner->id,
            'name' => 'Broadcast Team',
        ]);
    }

    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Checkout API',
            'type' => MonitorType::Http,
            'url' => 'https://internal.example.com/health',
            'check_interval_sec' => 60,
            'last_status' => MonitorStatus::Down,
            'request_headers' => [
                'X-Internal-Auth' => 'super-secret-token',
            ],
            'auth_config' => [
                'type' => 'bearer',
                'password' => 'super-secret-token',
            ],
        ]);
    }
}
