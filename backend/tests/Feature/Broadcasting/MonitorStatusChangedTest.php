<?php

namespace Tests\Feature\Broadcasting;

use App\Enums\MonitorStatus;
use App\Events\MonitorStatusChanged;
use App\Models\Monitor;
use App\Models\User;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the broadcast contract for a monitor health transition: the private
 * per-team channel, the wire event name, and a payload that carries the
 * monitor's health only, never its url or auth_config secrets.
 */
class MonitorStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_implements_broadcast_contracts(): void
    {
        $event = new MonitorStatusChanged($this->makeMonitor(), MonitorStatus::Up, MonitorStatus::Down);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_broadcasts_on_private_team_channel(): void
    {
        $monitor = $this->makeMonitor();
        $event = new MonitorStatusChanged($monitor, MonitorStatus::Up, MonitorStatus::Down);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-teams.{$monitor->team_id}", $channels[0]->name);
    }

    public function test_broadcasts_as_monitor_status(): void
    {
        $event = new MonitorStatusChanged($this->makeMonitor(), MonitorStatus::Up, MonitorStatus::Down);

        $this->assertSame('monitor.status', $event->broadcastAs());
    }

    public function test_payload_carries_health_and_omits_secrets(): void
    {
        $monitor = $this->makeMonitor([
            'last_response_ms' => 142,
        ]);
        $event = new MonitorStatusChanged($monitor, MonitorStatus::Up, MonitorStatus::Down);

        $payload = $event->broadcastWith();

        $this->assertSame($monitor->id, $payload['monitor_id']);
        $this->assertSame('API Health', $payload['name']);
        $this->assertSame('down', $payload['status']);
        $this->assertSame('up', $payload['previous_status']);
        $this->assertSame($monitor->last_checked_at->toIso8601String(), $payload['last_checked_at']);
        $this->assertSame(142, $payload['last_response_ms']);

        // Secrets must never ride the broadcast payload.
        $this->assertArrayNotHasKey('url', $payload);
        $this->assertArrayNotHasKey('auth_config', $payload);
        $this->assertArrayNotHasKey('request_headers', $payload);
    }

    public function test_previous_status_is_null_on_first_known_status(): void
    {
        $event = new MonitorStatusChanged($this->makeMonitor(), null, MonitorStatus::Up);

        $this->assertNull($event->broadcastWith()['previous_status']);
    }

    /**
     * Build a persisted monitor owned by a fresh personal team.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(array $overrides = []): Monitor
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        return Monitor::create([
            'team_id' => $team->id,
            'name' => 'API Health',
            'type' => 'http',
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'last_status' => 'up',
            'last_checked_at' => now(),
            'last_response_ms' => 128,
            'next_check_at' => now(),
            ...$overrides,
        ]);
    }
}
