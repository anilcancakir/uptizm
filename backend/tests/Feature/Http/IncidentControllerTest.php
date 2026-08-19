<?php

namespace Tests\Feature\Http;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Locks {@see IncidentController}'s read-only surface: `index` is team-scoped
 * (a seeded incident on another team never leaks in) and `show` loads the
 * affected-monitors pivot plus the update timeline. Also locks
 * {@see IncidentUpdateResource}'s wire shape: `message`, not `body`.
 *
 * Routes are not registered yet (Step 19), so the controller is invoked
 * directly against manually-resolved request objects.
 */
class IncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_and_show_expose_the_structured_title_alongside_title(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $authored = $this->makeIncident($monitor);
        $composed = $this->makeIncident(
            $monitor,
            titleKey: 'incidents.monitor_down',
            titleParams: ['monitor' => $monitor->name],
        );

        $request = Request::create('/incidents', 'GET');
        $request->setUserResolver(fn () => $user);

        $controller = $this->app->make(IncidentController::class);
        $indexPayload = $controller->index($request)->response($request)->getData(true);

        foreach ($indexPayload['data'] as $row) {
            $this->assertArrayHasKey('title', $row);
            $this->assertArrayHasKey('title_key', $row);
            $this->assertArrayHasKey('title_params', $row);
        }

        $showRequest = Request::create('/incidents/'.$composed->id, 'GET');
        $showRequest->setUserResolver(fn () => $user);
        $showPayload = $controller->show($showRequest, $composed)->response($showRequest)->getData(true)['data'];

        $this->assertArrayHasKey('title', $showPayload);
        $this->assertArrayHasKey('title_key', $showPayload);
        $this->assertArrayHasKey('title_params', $showPayload);
        $this->assertSame('incidents.monitor_down', $showPayload['title_key']);
        $this->assertSame(['monitor' => $monitor->name], $showPayload['title_params']);

        $authoredShowRequest = Request::create('/incidents/'.$authored->id, 'GET');
        $authoredShowRequest->setUserResolver(fn () => $user);
        $authoredShowPayload = $controller->show($authoredShowRequest, $authored)
            ->response($authoredShowRequest)
            ->getData(true)['data'];

        $this->assertNull($authoredShowPayload['title_key']);
        $this->assertSame([], $authoredShowPayload['title_params']);
    }

    public function test_index_lists_only_the_current_teams_incidents(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        // A second team's incident must never leak into the first team's list.
        [$otherMonitor] = $this->makeMonitor();
        $this->makeIncident($otherMonitor);

        $request = Request::create('/incidents', 'GET');
        $request->setUserResolver(fn () => $user);

        $controller = $this->app->make(IncidentController::class);
        $collection = $controller->index($request)->response($request)->getData(true);

        $ids = array_column($collection['data'], 'id');
        $this->assertContains($incident->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_index_filters_by_lifecycle(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $this->makeIncident($monitor, lifecycle: IncidentStatus::Detected);
        $this->makeIncident($monitor, lifecycle: IncidentStatus::Resolved);

        $request = Request::create('/incidents', 'GET', [
            'lifecycle' => IncidentStatus::Resolved->value,
        ]);
        $request->setUserResolver(fn () => $user);

        $controller = $this->app->make(IncidentController::class);
        $collection = $controller->index($request)->response($request)->getData(true);

        $this->assertCount(1, $collection['data']);
        $this->assertSame('resolved', $collection['data'][0]['lifecycle']);
    }

    public function test_show_loads_monitors_pivot_and_updates_timeline(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => 'major_outage',
            'component_status_current' => 'operational',
        ]);
        $incident->updates()->create([
            'actor' => 'system',
            'author' => 'Threshold Engine',
            'status' => IncidentStatus::Detected->value,
            'message' => 'Latency crossed the critical bound.',
            'is_public' => true,
            'autonomous' => true,
            'display_at' => now(),
        ]);

        $request = Request::create('/incidents/'.$incident->id, 'GET');
        $request->setUserResolver(fn () => $user);

        $controller = $this->app->make(IncidentController::class);
        $payload = $controller->show($request, $incident)->response($request)->getData(true)['data'];

        $this->assertSame($monitor->id, $payload['monitors'][0]['monitor_id']);
        $this->assertSame('major_outage', $payload['monitors'][0]['component_status_at_start']);
        $this->assertCount(1, $payload['updates']);
        $this->assertSame('Latency crossed the critical bound.', $payload['updates'][0]['message']);
        $this->assertArrayNotHasKey('body', $payload['updates'][0]);
        $this->assertTrue($payload['updates'][0]['is_public']);
    }

    public function test_index_serializes_the_affected_monitor_id_and_name(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => 'down',
            'component_status_current' => 'down',
        ]);

        $request = Request::create('/incidents', 'GET');
        $request->setUserResolver(fn () => $user);

        $controller = $this->app->make(IncidentController::class);
        $payload = $controller->index($request)->response($request)->getData(true)['data'];

        // The list serializes the affected-component pivot so the Flutter view
        // renders a non-zero affected count and the primary monitor's name.
        $this->assertCount(1, $payload);
        $affected = $payload[0]['monitors'];
        $this->assertCount(1, $affected);
        $this->assertSame($monitor->id, $affected[0]['monitor_id']);
        $this->assertSame($monitor->name, $affected[0]['name']);
        $this->assertSame('down', $affected[0]['component_status_current']);
    }

    /**
     * A monitor whose state moved after the incident opened reports the state it
     * is in NOW.
     *
     * `component_status_current` was a pivot column written once, at open, equal
     * to `component_status_at_start` by all three openers, and never updated by
     * anything. The client renders this row under "Affected monitors" with a live
     * status badge, so a monitor the customer paused sat there reading "Major
     * outage" while nothing was probing it.
     */
    public function test_index_reports_the_affected_monitor_status_as_it_is_now(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => 'down',
            'component_status_current' => 'down',
        ]);

        // The customer pauses it mid-incident: `pause()` writes the
        // administrative column and leaves the final reading alone.
        $monitor->forceFill([
            'status' => Monitor::STATUS_PAUSED,
            'next_check_at' => null,
        ])->save();

        $request = Request::create('/incidents', 'GET');
        $request->setUserResolver(fn () => $user);

        $controller = $this->app->make(IncidentController::class);
        $payload = $controller->index($request)->response($request)->getData(true)['data'];

        $affected = $payload[0]['monitors'][0];
        $this->assertSame(
            'paused',
            $affected['component_status_current'],
            'the row is labelled current, so it has to follow the monitor',
        );
        $this->assertSame(
            'down',
            $affected['component_status_at_start'],
            'what the incident opened with is a historical fact and stays put',
        );
    }

    public function test_show_404s_a_team_that_does_not_own_the_incident(): void
    {
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        [, $otherUser] = $this->makeMonitor();

        $request = Request::create('/incidents/'.$incident->id, 'GET');
        $request->setUserResolver(fn () => $otherUser);

        $this->expectException(NotFoundHttpException::class);

        $this->app->make(IncidentController::class)->show($request, $incident);
    }

    public function test_incident_update_resource_exposes_message_not_body(): void
    {
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $update = $incident->updates()->create([
            'actor' => 'human',
            'author' => 'Operator',
            'status' => IncidentStatus::Investigating->value,
            'message' => 'We are looking into it.',
            'is_public' => true,
            'autonomous' => false,
            'display_at' => now(),
        ]);

        $payload = IncidentUpdateResource::make($update)->response()->getData(true)['data'];

        $this->assertSame('We are looking into it.', $payload['message']);
        $this->assertTrue($payload['is_public']);
        $this->assertFalse($payload['autonomous']);
        $this->assertArrayNotHasKey('body', $payload);
    }

    /**
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(?MonitorStatus $lastStatus = MonitorStatus::Down): array
    {
        $user = User::query()->create([
            'name' => 'Incident Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Incident Team',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            'last_status' => $lastStatus,
        ]);

        return [$monitor, $user];
    }

    /**
     * @param  array<string, mixed>  $titleParams
     */
    protected function makeIncident(
        Monitor $monitor,
        IncidentStatus $lifecycle = IncidentStatus::Detected,
        ?string $titleKey = null,
        array $titleParams = [],
    ): Incident {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'title_key' => $titleKey,
            'title_params' => $titleParams,
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => $lifecycle,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }
}
