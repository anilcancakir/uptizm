<?php

namespace Tests\Feature\Monitoring;

use App\Enums\HttpMethod;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\RelayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A monitor must read back its schema defaults WITHOUT being refreshed.
 *
 * Eloquent does not know about database defaults, so every NOT NULL column with
 * one read back as null on a freshly created model. That was not cosmetic:
 * `RelayClient::buildSpec()` sends `timeout_sec` to the edge worker, a null became
 * `AbortSignal.timeout(0)`, and the probe aborted after 0ms and reported a healthy
 * target as down. `method` was worse, raising "property value on null" before
 * sending null as the HTTP verb.
 *
 * Found by dispatching a real probe for a monitor created in the same request, so
 * the assertion here is deliberately about the UNREFRESHED model: refreshing is
 * what hid the bug.
 */
class MonitorSchemaDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_freshly_created_monitor_carries_its_defaults_unrefreshed(): void
    {
        $monitor = $this->makeMinimalMonitor();

        $this->assertSame(HttpMethod::Get, $monitor->method);
        $this->assertSame(30, $monitor->timeout_sec);
        $this->assertSame(200, $monitor->expected_status_code);
        $this->assertSame(Monitor::DEFAULT_INCIDENT_THRESHOLD, (int) $monitor->incident_threshold);
        $this->assertSame(0, (int) $monitor->consecutive_fails);
        $this->assertSame([], $monitor->request_headers);
        $this->assertSame([], $monitor->regions);
        $this->assertFalse((bool) $monitor->show_on_status_page);
        $this->assertTrue((bool) $monitor->alert_on_down);
    }

    public function test_the_probe_spec_never_carries_a_null_timeout_or_method(): void
    {
        // The spec is what the edge worker consumes. A null timeout there is a
        // 0ms abort, which reads as an outage of a target that was never reached.
        $monitor = $this->makeMinimalMonitor();

        $spec = (fn (): array => $this->buildSpec($monitor, 'us-east'))
            ->call(app(RelayClient::class));

        $this->assertSame('get', $spec['method']);
        $this->assertSame(30, $spec['timeout_seconds']);
        $this->assertSame(200, $spec['expected_status_code']);
        $this->assertNotNull($spec['type']);
    }

    public function test_the_in_memory_defaults_match_what_the_database_stores(): void
    {
        // The two must not drift: an in-memory default that lies about the column
        // is worse than none, because it looks authoritative.
        $monitor = $this->makeMinimalMonitor();
        $inMemory = [
            'method' => $monitor->method,
            'timeout_sec' => $monitor->timeout_sec,
            'expected_status_code' => $monitor->expected_status_code,
            'incident_threshold' => (int) $monitor->incident_threshold,
            'request_headers' => $monitor->request_headers,
            'regions' => $monitor->regions,
        ];

        $monitor->refresh();

        $this->assertSame($inMemory['method'], $monitor->method);
        $this->assertSame($inMemory['timeout_sec'], $monitor->timeout_sec);
        $this->assertSame($inMemory['expected_status_code'], $monitor->expected_status_code);
        $this->assertSame($inMemory['incident_threshold'], (int) $monitor->incident_threshold);
        $this->assertSame($inMemory['request_headers'], $monitor->request_headers);
        $this->assertSame($inMemory['regions'], $monitor->regions);
    }

    protected function makeMinimalMonitor(): Monitor
    {
        $user = User::factory()->create();
        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        // Only the columns with no default. Everything else must come from the
        // model, exactly as it would for a monitor created through a seeder, a
        // factory or a console command.
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Minimal',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/',
            'check_interval_sec' => 180,
        ]);
    }
}
