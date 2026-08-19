<?php

namespace Tests\Feature\Broadcasting;

use App\Enums\MonitorStatus;
use App\Events\MonitorCheckRecorded;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the broadcast contract for a single recorded reading, the event that
 * makes a check visible without a status flip.
 *
 * {@see MonitorStatusChanged} answers "this monitor's health changed" and fires
 * only on a transition, which leaves every KPI derived from the reading itself
 * (average response time, rolling uptime, "last checked") frozen between flips.
 * This event answers "a reading landed" and fires on every persisted check.
 *
 * Two properties the payload keys carry deliberately:
 *
 *  1. The denormalised monitor fields are named EXACTLY as the client's model
 *     attributes (`last_status`, `last_checked_at`, `last_response_ms`), so a
 *     consumer patches its cached monitor key by key with no translation table
 *     to drift.
 *  2. This region's own verdict travels as `result`, never as `status`. The
 *     client's `Monitor.status` getter reads the ADMIN status column
 *     (`active`/`paused`) before it reads `last_status`, so a payload key called
 *     `status` would be applied to the wrong field and read a healthy monitor as
 *     paused.
 */
class MonitorCheckRecordedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_implements_broadcast_contracts(): void
    {
        $monitor = $this->makeMonitor();
        $event = new MonitorCheckRecorded($monitor, $this->makeCheck($monitor));

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
    }

    public function test_broadcasts_on_private_team_channel(): void
    {
        $monitor = $this->makeMonitor();
        $event = new MonitorCheckRecorded($monitor, $this->makeCheck($monitor));

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-teams.{$monitor->team_id}", $channels[0]->name);
    }

    public function test_broadcasts_as_check_recorded(): void
    {
        $monitor = $this->makeMonitor();
        $event = new MonitorCheckRecorded($monitor, $this->makeCheck($monitor));

        $this->assertSame('check.recorded', $event->broadcastAs());
    }

    public function test_payload_carries_the_reading_and_the_denormalised_health(): void
    {
        $monitor = $this->makeMonitor([
            'last_status' => MonitorStatus::Degraded,
            'last_response_ms' => 512,
        ]);
        $check = $this->makeCheck($monitor, [
            'region' => 'eu-west',
            'status' => MonitorStatus::Up,
            'response_ms' => 143,
        ]);

        $payload = (new MonitorCheckRecorded($monitor, $check))->broadcastWith();

        $this->assertSame($monitor->id, $payload['monitor_id']);
        $this->assertSame('eu-west', $payload['region']);
        $this->assertSame('up', $payload['result']);
        $this->assertSame(143, $payload['response_ms']);
        $this->assertSame($check->checked_at->toIso8601String(), $payload['checked_at']);

        // The monitor's post-check denorm state, keyed as the client stores it.
        $this->assertSame('degraded', $payload['last_status']);
        $this->assertSame(512, $payload['last_response_ms']);
        $this->assertSame($monitor->last_checked_at->toIso8601String(), $payload['last_checked_at']);
    }

    public function test_payload_omits_secrets(): void
    {
        $monitor = $this->makeMonitor();
        $payload = (new MonitorCheckRecorded($monitor, $this->makeCheck($monitor)))->broadcastWith();

        $this->assertArrayNotHasKey('url', $payload);
        $this->assertArrayNotHasKey('auth_config', $payload);
        $this->assertArrayNotHasKey('request_headers', $payload);
        $this->assertArrayNotHasKey('response_headers', $payload);
        $this->assertArrayNotHasKey('response_body_preview', $payload);
    }

    public function test_payload_does_not_use_the_status_key_for_a_probe_verdict(): void
    {
        $monitor = $this->makeMonitor();
        $payload = (new MonitorCheckRecorded($monitor, $this->makeCheck($monitor)))->broadcastWith();

        // `status` on the client is the admin column. Sending a probe verdict
        // under that name would pause a healthy monitor on the dashboard.
        $this->assertArrayNotHasKey('status', $payload);
    }

    public function test_a_reading_with_no_latency_carries_a_null_response_ms(): void
    {
        $monitor = $this->makeMonitor(['last_response_ms' => null]);
        $check = $this->makeCheck($monitor, [
            'status' => MonitorStatus::Down,
            'response_ms' => null,
        ]);

        $payload = (new MonitorCheckRecorded($monitor, $check))->broadcastWith();

        $this->assertNull($payload['response_ms']);
        $this->assertNull($payload['last_response_ms']);
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

    /**
     * Build a persisted check row for the given monitor.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeCheck(Monitor $monitor, array $overrides = []): MonitorCheck
    {
        return MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east',
            'checked_at' => now(),
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => 128,
            'probe_run_id' => 'reading-1',
            ...$overrides,
        ]);
    }
}
