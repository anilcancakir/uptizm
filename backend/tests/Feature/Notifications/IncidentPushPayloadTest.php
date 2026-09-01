<?php

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\IncidentEscalated;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Monitoring\IncidentTitle;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the `additionalData` map `toOneSignal()` attaches to a push: the
 * in-app-row vocabulary reused verbatim from `toArray()`, the `deep_link` a
 * tap needs to open the right incident, and the per-recipient `subject` a
 * later client-side guard compares against the device's signed-in identity.
 *
 * On HEAD `toOneSignal()` never calls `setData()`, so every assertion here
 * fails against a null `getData()`.
 */
class IncidentPushPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_opened_push_data_carries_the_incident_identity_and_deep_link(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toOneSignal($user);
        $data = $payload->getData();

        $this->assertIsArray($data);
        $this->assertSame($incident->id, $data['incident_id']);
        $this->assertSame('/incidents/'.$incident->id, $data['deep_link']);
        $this->assertSame('incident_opened', $data['type']);
        $this->assertSame($incident->primary_monitor_id, $data['monitor_id']);
        $this->assertSame('API Health', $data['monitor_name']);
        $this->assertSame('critical', $data['severity']);
        $this->assertSame('incident', $data['kind']);
    }

    public function test_incident_resolved_push_data_carries_the_incident_identity_and_deep_link(): void
    {
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentResolved($incident))->toOneSignal($user);
        $data = $payload->getData();

        $this->assertIsArray($data);
        $this->assertSame($incident->id, $data['incident_id']);
        $this->assertSame('/incidents/'.$incident->id, $data['deep_link']);
        $this->assertSame('incident_resolved', $data['type']);
        $this->assertSame('resolved', $data['kind']);
    }

    /**
     * `IncidentEscalated` inherits `IncidentOpened`'s builder, and its own
     * docblock stresses its own `data.type`. A push that reused the parent's
     * literal `type` would let an escalation and a fresh page look identical
     * to the client-side feed, which is the exact bug the class exists to
     * avoid on the in-app row.
     */
    public function test_incident_escalated_push_data_carries_its_own_type(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentEscalated($incident))->toOneSignal($user);
        $data = $payload->getData();

        $this->assertIsArray($data);
        $this->assertSame($incident->id, $data['incident_id']);
        $this->assertSame('/incidents/'.$incident->id, $data['deep_link']);
        $this->assertSame('incident_escalated', $data['type']);
    }

    /**
     * The assertion that matters most: a single-recipient test cannot tell a
     * per-recipient key from a hardcoded constant. Two notifiables of the SAME
     * incident have to carry two different `subject` values, each the
     * `user_{id}` alias OneSignal itself is addressed by.
     */
    public function test_subject_is_the_per_recipient_external_id_not_a_constant(): void
    {
        $incident = $this->makeIncident();
        $notification = new IncidentOpened($incident);

        $first = User::factory()->create();
        $second = User::factory()->create();

        $firstData = $notification->toOneSignal($first)->getData();
        $secondData = $notification->toOneSignal($second)->getData();

        $this->assertSame('user_'.$first->getKey(), $firstData['subject']);
        $this->assertSame('user_'.$second->getKey(), $secondData['subject']);
        $this->assertNotSame($firstData['subject'], $secondData['subject']);
    }

    /**
     * Build a persisted incident with a primary monitor for a fresh team.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeIncident(array $overrides = []): Incident
    {
        $owner = User::factory()->create();

        $team = Team::create([
            'user_id' => $owner->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $monitor = Monitor::create([
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
            'next_check_at' => now(),
        ]);

        return Incident::create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Health is down',
            'title_key' => IncidentTitle::MONITOR_DOWN,
            'title_params' => ['monitor' => 'API Health'],
            'impact' => 'critical',
            'severity' => 'critical',
            'signal_source' => 'user_threshold',
            'lifecycle' => 'detected',
            'started_at' => now(),
            ...$overrides,
        ]);
    }
}
