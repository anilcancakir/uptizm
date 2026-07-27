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
 * acknowledge/reopen/post-update/assign/postmortem each delegate to the
 * service, are team-scoped via `authorizeTeam` (404-mask, never 403), and
 * validate their payload via a dedicated FormRequest.
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

    public function test_assign_sets_the_assignee_and_appends_a_timeline_entry(): void
    {
        [$team, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Investigating);
        $responder = $this->makeTeamMember($team, 'Ravi Shah');

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/assign", [
            'assignee_id' => $responder->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.assignee.id', $responder->id);
        $response->assertJsonPath('data.assignee.name', 'Ravi Shah');
        $this->assertSame((string) $responder->id, (string) $incident->fresh()->assigned_to_user_id);
        $this->assertSame(1, $incident->updates()->count());
        $this->assertStringContainsString('Ravi Shah', $incident->updates()->sole()->message);
    }

    public function test_assign_clears_the_assignee_when_the_id_is_null(): void
    {
        [$team, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Investigating);
        $responder = $this->makeTeamMember($team, 'Ravi Shah');
        $incident->forceFill(['assigned_to_user_id' => $responder->id])->save();

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/assign", [
            'assignee_id' => null,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.assignee', null);
        $this->assertNull($incident->fresh()->assigned_to_user_id);
        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_assign_rejects_a_user_outside_the_incidents_team(): void
    {
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Investigating);
        $outsider = User::factory()->create();

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/assign", [
            'assignee_id' => $outsider->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('assignee_id');
        $this->assertNull($incident->fresh()->assigned_to_user_id);
    }

    public function test_assign_masks_a_foreign_teams_incident_as_404(): void
    {
        $this->actingAsTeamMemberWithMonitor();

        $foreignTeam = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);
        $foreignIncident = $this->makeIncident($foreignMonitor, IncidentStatus::Investigating);

        $response = $this->postJson("/api/v1/incidents/{$foreignIncident->id}/assign", [
            'assignee_id' => null,
        ]);

        $response->assertStatus(404);
    }

    public function test_postmortem_saves_a_draft_without_publishing_it(): void
    {
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Resolved);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/postmortem", [
            'body' => 'The origin pool ran out of workers under the release traffic.',
            'publish' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath(
            'data.postmortem_body',
            'The origin pool ran out of workers under the release traffic.',
        );
        $response->assertJsonPath('data.postmortem_published_at', null);
        $this->assertNull($incident->fresh()->postmortem_published_at);
        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_postmortem_stamps_published_at_when_publishing(): void
    {
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Resolved);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/postmortem", [
            'body' => 'Root cause: the release doubled the connection pool wait.',
            'publish' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath(
            'data.postmortem_body',
            'Root cause: the release doubled the connection pool wait.',
        );
        $this->assertNotNull($response->json('data.postmortem_published_at'));
        $this->assertNotNull($incident->fresh()->postmortem_published_at);
    }

    public function test_postmortem_requires_a_body(): void
    {
        [, $monitor] = $this->actingAsTeamMemberWithMonitor();
        $incident = $this->makeIncident($monitor, IncidentStatus::Resolved);

        $response = $this->postJson("/api/v1/incidents/{$incident->id}/postmortem", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('body');
    }

    public function test_postmortem_masks_a_foreign_teams_incident_as_404(): void
    {
        $this->actingAsTeamMemberWithMonitor();

        $foreignTeam = Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
        $foreignMonitor = $this->makeMonitor($foreignTeam->id);
        $foreignIncident = $this->makeIncident($foreignMonitor, IncidentStatus::Resolved);

        $response = $this->postJson("/api/v1/incidents/{$foreignIncident->id}/postmortem", [
            'body' => 'Should never land.',
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
     * Attach a freshly created user to the team as a member (the pivot half of
     * the owner + `team_user` roster the assignee rule validates against).
     */
    protected function makeTeamMember(Team $team, string $name): User
    {
        $member = User::factory()->create(['name' => $name]);
        $team->users()->attach($member->id, ['role' => 'editor']);

        return $member;
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
