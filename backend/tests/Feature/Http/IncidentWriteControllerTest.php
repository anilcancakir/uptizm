<?php

namespace Tests\Feature\Http;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the operator incident-write HTTP surface added on top of
 * {@see IncidentWriteService}: create/resolve/
 * acknowledge/reopen/post-update each delegate to the service, are
 * team-scoped via `authorizeTeam` (404-mask, never 403), and validate their
 * payload via a dedicated FormRequest.
 */
class IncidentWriteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_opens_a_manual_incident_for_the_current_teams_monitor(): void
    {
        Notification::fake();
        [$team, $monitor] = $this->actingAsTeamMemberWithMonitor();

        $response = $this->postJson('/api/v1/incidents', [
            'monitor_id' => $monitor->id,
            'severity' => 'critical',
            'title' => 'Manual outage report',
            'message' => 'Customer reported the API is unreachable.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Manual outage report');
        $response->assertJsonPath('data.signal_source', 'manual');
        $response->assertJsonPath('data.lifecycle', 'detected');

        $incident = Incident::query()->where('team_id', $team->id)->sole();
        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_store_rejects_a_monitor_from_another_team(): void
    {
        Notification::fake();
        $this->actingAsTeamMemberWithMonitor();

        $foreignTeam = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);

        $response = $this->postJson('/api/v1/incidents', [
            'monitor_id' => $foreignMonitor->id,
            'severity' => 'critical',
            'title' => 'Manual outage report',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('monitor_id');
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsTeamMemberWithMonitor();

        $response = $this->postJson('/api/v1/incidents', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['monitor_id', 'severity', 'title']);
    }

    public function test_resolve_transitions_an_active_incident(): void
    {
        Notification::fake();
        [$team, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Investigating);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/resolve", [
            'message' => 'Fixed via failover.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.lifecycle', 'resolved');
        $this->assertNotNull($incident->fresh()->resolved_at);
    }

    public function test_resolve_masks_a_foreign_teams_incident_as_404(): void
    {
        $this->actingAsTeamMemberWithMonitor();

        $foreignTeam = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);
        $foreignIncident = $this->makeIncident($foreignMonitor, IncidentStatus::Investigating);

        $response = $this->postJson("/api/v1/incidents/{$foreignIncident->id}/resolve");

        $response->assertStatus(404);
    }

    public function test_acknowledge_moves_a_detected_incident_to_investigating(): void
    {
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Detected);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/acknowledge");

        $response->assertStatus(200);
        $response->assertJsonPath('data.lifecycle', 'investigating');
    }

    public function test_reopen_reactivates_a_resolved_incident(): void
    {
        Notification::fake();
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Resolved);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/reopen");

        $response->assertStatus(200);
        $response->assertJsonPath('data.lifecycle', 'investigating');
        $this->assertNull($incident->fresh()->resolved_at);
    }

    public function test_post_update_appends_to_the_timeline(): void
    {
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Investigating);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/updates", [
            'message' => 'Root cause identified, deploying a fix.',
            'is_public' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.message', 'Root cause identified, deploying a fix.');
        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_post_update_requires_a_message(): void
    {
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Investigating);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/updates", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('message');
    }

    public function test_post_update_masks_a_foreign_teams_incident_as_404(): void
    {
        $this->actingAsTeamMemberWithMonitor();

        $foreignTeam = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);
        $foreignIncident = $this->makeIncident($foreignMonitor, IncidentStatus::Investigating);

        $response = $this->postJson("/api/v1/incidents/{$foreignIncident->id}/updates", [
            'message' => 'Should never land.',
        ]);

        $response->assertStatus(404);
    }

    /**
     * Authenticate as a user whose current team owns a freshly created
     * monitor, mirroring {@see MonitorControllerTest::actingAsTeamMember()}.
     *
     * @return array{0: Team, 1: Monitor}
     */
    protected function actingAsTeamMemberWithMonitor(): array
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return [$team, $this->makeMonitor($team->id)];
    }

    /**
     * Build a persisted monitor for the given team.
     */
    protected function makeMonitor(string $teamId): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health',
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

    /**
     * Build a persisted incident on the given monitor at the given lifecycle.
     */
    protected function makeIncident(Monitor $monitor, IncidentStatus $lifecycle): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => $lifecycle,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }
}
