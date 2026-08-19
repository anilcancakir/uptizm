<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the wire contract of the response-time chart endpoint.
 *
 * This is the endpoint the monitor detail page opens with, at its default `24h`
 * range, and it had no feature coverage at all. The key set is pinned AS A WHOLE
 * rather than probed key by key, for the reason the content controller's tests
 * give: the defect worth catching is a CHANGED or MISSING key, because the client
 * decodes these rows into its chart and a rename lands as an empty graph rather
 * than an error.
 *
 * A row here is an AGGREGATED bucket, not a check. It carries a timestamp, an
 * averaged latency and the worst status in the bucket; every per-check field is
 * null by construction, and that is part of the contract rather than an accident,
 * so it is asserted too.
 */
class MonitorResponseTimesControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact JSON key set of one sample.
     */
    protected const SAMPLE_KEYS = [
        'id',
        'region',
        'status',
        'status_code',
        'response_ms',
        'timing_dns_ms',
        'timing_connect_ms',
        'timing_tls_ms',
        'timing_ttfb_ms',
        'timing_download_ms',
        'checked_at',
        'error_message',
    ];

    public function test_a_sample_carries_exactly_the_documented_key_set(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeCheck($monitor, MonitorStatus::Up, 140);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=24h");

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertNotEmpty($rows);
        $this->assertSame(self::SAMPLE_KEYS, array_keys($rows[0]));
    }

    public function test_an_aggregated_sample_carries_time_latency_and_status_and_nothing_else(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeCheck($monitor, MonitorStatus::Up, 140);

        $row = $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=24h")
            ->json('data.0');

        $this->assertSame('up', $row['status']);
        $this->assertSame(140, $row['response_ms']);

        // The exact timestamp FORMAT is part of the contract, not just its
        // presence: the client parses it, and a refactor that builds these rows
        // without the model's `immutable_datetime` cast would quietly emit a
        // different shape. Without this the safety net has a hole exactly where a
        // change is most likely.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            (string) $row['checked_at'],
        );

        // Null BY CONSTRUCTION: a bucket spans regions and checks, so none of
        // these can belong to it. The client relies on that to tell an aggregated
        // dot from a real check row.
        foreach (['id', 'region', 'status_code', 'error_message', 'timing_dns_ms',
            'timing_connect_ms', 'timing_tls_ms', 'timing_ttfb_ms',
            'timing_download_ms'] as $key) {
            $this->assertNull($row[$key], "{$key} must be null on an aggregated sample");
        }
    }

    public function test_a_bucket_averages_its_latencies_and_takes_the_worst_status(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        // Three checks inside the same 60s bucket: 100, 200 and a degraded 300.
        $at = now()->subMinutes(5)->startOfMinute()->addSeconds(5);
        $this->makeCheck($monitor, MonitorStatus::Up, 100, $at);
        $this->makeCheck($monitor, MonitorStatus::Up, 200, $at->copy()->addSeconds(10));
        $this->makeCheck($monitor, MonitorStatus::Degraded, 300, $at->copy()->addSeconds(20));

        $rows = $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=24h")
            ->json('data');

        $this->assertCount(1, $rows, 'three checks in one bucket must fold to one dot');
        $this->assertSame(200, $rows[0]['response_ms']);
        $this->assertSame('degraded', $rows[0]['status']);
    }

    public function test_a_down_check_in_the_bucket_wins_over_a_degraded_one(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $at = now()->subMinutes(7)->startOfMinute()->addSeconds(5);
        $this->makeCheck($monitor, MonitorStatus::Degraded, 300, $at);
        $this->makeCheck($monitor, MonitorStatus::Down, 900, $at->copy()->addSeconds(10));

        $rows = $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=24h")
            ->json('data');

        $this->assertSame('down', $rows[0]['status']);
    }

    public function test_an_unrecognised_range_falls_back_to_24h_rather_than_failing(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeCheck($monitor, MonitorStatus::Up, 140);

        $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=nonsense")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_check_older_than_the_window_is_not_sampled(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeCheck($monitor, MonitorStatus::Up, 140, now()->subDays(3));

        $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=24h")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_check_with_no_latency_is_not_sampled(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        // A refused or failed probe records no response_ms. Averaging a null into
        // a dot would draw a latency the target never produced.
        $this->makeCheck($monitor, MonitorStatus::Down, null);

        $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=24h")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_teams_monitor_is_masked_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreign = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $monitor = $this->makeMonitor($foreign->id);

        // The foreign monitor owns a real sample, so a dropped team scope turns
        // this into a 200 carrying another tenant's latency history rather than
        // into a differently-shaped 404.
        $this->makeCheck($monitor, MonitorStatus::Up, 140);

        $this->getJson("/api/v1/monitors/{$monitor->id}/response-times?range=24h")
            ->assertNotFound();
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    protected function makeMonitor(string $teamId): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }

    protected function makeCheck(
        Monitor $monitor,
        MonitorStatus $status,
        ?int $responseMs,
        mixed $checkedAt = null,
    ): MonitorCheck {
        return MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east',
            'checked_at' => $checkedAt ?? now()->subMinutes(2),
            'status' => $status,
            'status_code' => $status === MonitorStatus::Up ? 200 : 503,
            'response_ms' => $responseMs,
            'probe_run_id' => 'rt-'.Str::random(8),
        ]);
    }
}
