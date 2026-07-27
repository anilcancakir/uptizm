<?php

namespace Tests\Feature\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Monitoring\IncidentWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the operator incident-write contract in {@see IncidentWriteService}: a
 * manual create opens via the shared creator and dedupes against an active
 * incident, a resolve is idempotent under the per-monitor lock (a double
 * resolve returns the terminal state with no second update row and no second
 * page), an operator resolve ignores monitor health (unlike the auto-resolve),
 * acknowledge/reopen transition the lifecycle, and a post-update appends to the
 * unified timeline.
 */
class IncidentWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_double_resolve_is_idempotent_and_pages_the_team_once(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeActiveIncident($monitor);
        $service = $this->service();

        // 1. The first resolve transitions the incident and pages the team.
        $service->resolve($incident, author: 'Operator');

        // 2. The second resolve races the first: it re-reads the terminal state
        //    under the monitor lock and no-ops (no second update, no second page).
        $service->resolve($incident, author: 'Operator');

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        $this->assertNotNull($incident->resolved_at);

        // 3. Exactly one operator resolve note and exactly one recovery page.
        $this->assertSame(
            1,
            $incident->updates()->where('status', IncidentStatus::Resolved->value)->count(),
        );
        Notification::assertSentToTimes($user, IncidentResolved::class, 1);
    }

    public function test_an_operator_resolve_succeeds_while_the_monitor_is_still_down(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();

        // 1. The monitor is unhealthy: last status down with a live fail streak,
        //    the exact state where the automated resolveIfRecovered would no-op.
        $monitor->update([
            'last_status' => MonitorStatus::Down,
            'consecutive_fails' => 3,
        ]);
        $incident = $this->makeActiveIncident($monitor);
        $service = $this->service();

        // 2. The operator resolve ignores monitor health entirely and resolves.
        $service->resolve($incident, author: 'Operator');

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        Notification::assertSentTo($user, IncidentResolved::class);
    }

    public function test_a_manual_open_dedupes_against_an_active_incident(): void
    {
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $existing = $this->makeActiveIncident($monitor);
        $service = $this->service();

        // A manual open while an incident is already active must not double-open:
        // it returns the existing active incident and creates no second row.
        $result = $service->createManual(
            $monitor,
            severity: IncidentSeverity::Critical,
            title: 'Manual outage report',
            author: 'Operator',
        );

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_a_manual_open_creates_an_incident_and_pages_the_team(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $service = $this->service();

        $incident = $service->createManual(
            $monitor,
            severity: IncidentSeverity::Critical,
            title: 'Manual outage report',
            author: 'Operator',
        );

        $this->assertSame(SignalSource::Manual, $incident->signal_source);
        $this->assertTrue($incident->lifecycle->isActive());
        $this->assertSame(1, $incident->monitors()->count());
        Notification::assertSentTo($user, IncidentOpened::class);
    }

    public function test_a_post_update_appends_to_the_timeline(): void
    {
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeActiveIncident($monitor);
        $service = $this->service();

        $update = $service->postUpdate(
            $incident,
            message: 'We have identified the root cause.',
            author: 'Operator',
        );

        $this->assertSame('We have identified the root cause.', $update->message);
        $this->assertTrue($update->is_public);
        $this->assertSame('human', $update->actor);
        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_acknowledge_moves_a_detected_incident_to_investigating(): void
    {
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeActiveIncident($monitor);
        $service = $this->service();

        $service->acknowledge($incident, author: 'Operator');
        $incident->refresh();
        $this->assertSame(IncidentStatus::Investigating, $incident->lifecycle);

        // A second acknowledge is a no-op: the incident is no longer detected.
        $service->acknowledge($incident, author: 'Operator');
        $this->assertSame(
            1,
            $incident->updates()->where('status', IncidentStatus::Investigating->value)->count(),
        );
    }

    public function test_reopen_reactivates_a_resolved_incident_and_pages_again(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeActiveIncident($monitor);
        $service = $this->service();

        $service->resolve($incident, author: 'Operator');
        $service->reopen($incident, author: 'Operator');

        $incident->refresh();
        $this->assertTrue($incident->lifecycle->isActive());
        $this->assertNull($incident->resolved_at);
        Notification::assertSentTo($user, IncidentOpened::class);
    }

    public function test_assign_notes_a_change_once_and_no_ops_on_the_same_owner(): void
    {
        Notification::fake();
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeActiveIncident($monitor);
        $service = $this->service();

        // 1. The first assign records the owner and an internal timeline note.
        $service->assign($incident, $user, author: 'Operator');
        $incident->refresh();
        $this->assertSame((string) $user->id, (string) $incident->assigned_to_user_id);
        $this->assertSame(1, $incident->updates()->count());
        $this->assertFalse($incident->updates()->sole()->is_public);

        // 2. Re-assigning the same owner is a no-op: no second note.
        $service->assign($incident, $user, author: 'Operator');
        $this->assertSame(1, $incident->fresh()->updates()->count());

        // 3. Clearing the owner records the unassignment.
        $service->assign($incident, null, author: 'Operator');
        $this->assertNull($incident->fresh()->assigned_to_user_id);
        $this->assertSame(2, $incident->fresh()->updates()->count());
    }

    public function test_a_postmortem_publishes_once_and_keeps_its_original_stamp(): void
    {
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeActiveIncident($monitor);
        $service = $this->service();

        // 1. A draft save stores the body without publishing it.
        $service->savePostmortem($incident, 'Draft body.', publish: false, author: 'Operator');
        $incident->refresh();
        $this->assertSame('Draft body.', $incident->postmortem_body);
        $this->assertNull($incident->postmortem_published_at);
        $this->assertFalse($incident->postmortemIsPublished());

        // 2. Publishing stamps the publication time.
        $service->savePostmortem($incident, 'Published body.', publish: true, author: 'Operator');
        $incident->refresh();
        $publishedAt = $incident->postmortem_published_at;
        $this->assertNotNull($publishedAt);
        $this->assertTrue($incident->postmortemIsPublished());

        // 3. A later edit of a published postmortem keeps the ORIGINAL stamp:
        //    "published at" is when customers could first read it.
        $this->travel(5)->minutes();
        $service->savePostmortem($incident, 'Corrected body.', publish: true, author: 'Operator');
        $incident->refresh();
        $this->assertSame('Corrected body.', $incident->postmortem_body);
        $this->assertTrue($publishedAt->equalTo($incident->postmortem_published_at));
    }

    /**
     * Resolve the service with its real collaborators from the container.
     */
    protected function service(): IncidentWriteService
    {
        return $this->app->make(IncidentWriteService::class);
    }

    /**
     * Create a monitor owned by a team whose single member is notifiable, so
     * `incident->team->users` resolves to a non-empty recipient set.
     *
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Write Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Write Team',
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            'alert_on_down' => true,
            'alert_on_recover' => true,
        ]);

        return [$monitor, $user];
    }

    /**
     * Persist an active down incident whose primary monitor is the given one,
     * attached to the affected-component pivot so dispatch reads a full graph.
     */
    protected function makeActiveIncident(Monitor $monitor): Incident
    {
        $incident = Incident::query()->create([
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

        $componentStatus = $monitor->last_status?->value ?? MonitorStatus::Down->value;
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => $componentStatus,
            'component_status_current' => $componentStatus,
        ]);

        return $incident;
    }
}
