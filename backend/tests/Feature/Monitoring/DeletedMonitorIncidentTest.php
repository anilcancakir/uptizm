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
use App\Services\Monitoring\IncidentWriteService;
use App\Services\OnCall\EscalationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What happens to an incident when the monitor that opened it is deleted.
 *
 * Measured on the live database before any of this existed: deleting a monitor
 * soft-deleted it and touched nothing else, so its open incidents stayed in
 * `detected` forever. They could not close by themselves, because auto-resolve
 * is driven by the NEXT check and no check ever arrives for a deleted monitor;
 * they could not be closed by hand either, because every write path starts at
 * {@see IncidentWriteService::monitorFor()} and that threw on a missing monitor;
 * and they stayed pageable, because {@see EscalationDispatcher::pageStep()}
 * gates on lifecycle and maintenance and asks nothing about the monitor. On
 * production that was three of the eight incidents the dashboard called open.
 *
 * The answer here is Grafana's, which is the one prior art with a stated reason:
 * deleting an alert rule resolves its firing instances precisely so a firing
 * alert cannot be orphaned by its rule vanishing. The divergence is that we do
 * it SILENTLY. Grafana emits a resolved notification; here "resolved" would be a
 * false sentence, because nobody fixed anything, we stopped measuring. The
 * timeline entry says that in its own words instead.
 */
class DeletedMonitorIncidentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_monitor_closes_the_incidents_it_leaves_behind(): void
    {
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $monitor->delete();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_the_close_is_silent(): void
    {
        // Deliberately not Grafana's resolved notification. An incident whose
        // monitor was just deleted did not recover, and telling the team it did
        // is the false sentence this product exists to avoid; the operator who
        // deleted the monitor also does not need to be told about the
        // consequence they just caused.
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $this->makeIncident($monitor);

        $monitor->delete();

        Notification::assertNothingSent();
    }

    public function test_the_close_says_why_on_the_timeline(): void
    {
        // The state alone would read as an ordinary recovery a year from now.
        // The entry is internal rather than public: a status page reader was
        // never told this incident existed (the assembler scopes to visible
        // monitors, and this one is gone), so announcing its end there would be
        // the first they heard of it.
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        $monitor->delete();

        $update = $incident->updates()->latest('display_at')->first();
        $this->assertNotNull($update);
        $this->assertSame(IncidentStatus::Resolved, $update->status);
        $this->assertFalse((bool) $update->is_public);
        $this->assertSame('system', $update->actor);
    }

    public function test_an_incident_with_another_live_monitor_stays_open(): void
    {
        // The guard that keeps this from being a cascade. An incident spanning
        // two components is still live while either of them is, and closing it
        // because one was deleted would retire an outage that is still running.
        Notification::fake();
        [$monitor, $team] = $this->makeMonitor();
        $survivor = $this->makeMonitor($team)[0];

        $incident = $this->makeIncident($monitor);
        $incident->monitors()->attach($survivor->id, $this->pivot());

        $monitor->delete();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Detected, $incident->lifecycle);
        $this->assertNull($incident->resolved_at);
    }

    public function test_an_incident_attached_without_being_primary_is_closed_too(): void
    {
        // Raised in review, and it is the sharper half of the query. The lookup
        // read `primary_monitor_id` OR the `monitors()` relation, but this runs
        // on `deleted`, and that relation applies the soft-delete scope: by the
        // time it is asked, the monitor being deleted is already invisible to
        // it. So the relation arm matched nothing and only the denormalised
        // primary hint did any work, leaving every incident this monitor joined
        // as a secondary component open forever.
        Notification::fake();
        [$primary, $team] = $this->makeMonitor();
        $secondary = $this->makeMonitor($team)[0];

        // Primary elsewhere, attached here. The pivot is the only link.
        $incident = $this->makeIncident($primary);
        $incident->monitors()->attach($secondary->id, $this->pivot());
        $primary->delete();

        // The primary is gone; the incident is still live through $secondary.
        $incident->refresh();
        $this->assertSame(IncidentStatus::Detected, $incident->lifecycle);

        $secondary->delete();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
    }

    public function test_an_already_resolved_incident_is_left_alone(): void
    {
        // Idempotency, and a history guard: re-stamping `resolved_at` would move
        // the moment the outage ended to the moment somebody tidied up.
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $resolvedAt = now()->subDays(3);
        $incident->forceFill([
            'lifecycle' => IncidentStatus::Resolved,
            'resolved_at' => $resolvedAt,
        ])->save();

        $monitor->delete();

        $incident->refresh();
        $this->assertSame(
            $resolvedAt->toDateTimeString(),
            $incident->resolved_at?->toDateTimeString(),
        );
    }

    public function test_an_orphaned_incident_can_still_be_resolved_by_hand(): void
    {
        // The path that used to 500. Closing on delete stops NEW orphans; this
        // is what makes the ones that already exist, and any that arrive through
        // a route this test cannot foresee, operable at all.
        Notification::fake();
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $incident->forceFill(['lifecycle' => IncidentStatus::Detected, 'resolved_at' => null])->save();

        // Delete the monitor WITHOUT the hook, the shape every row already in
        // the database is in.
        Monitor::query()->whereKey($monitor->id)->update(['deleted_at' => now()]);
        $incident->refresh();

        $resolved = app(IncidentWriteService::class)->resolve($incident, 'Operator');

        $this->assertSame(IncidentStatus::Resolved, $resolved->lifecycle);
    }

    /**
     * @return array{0: Monitor, 1: Team}
     */
    protected function makeMonitor(?Team $team = null): array
    {
        if ($team === null) {
            $user = User::query()->create([
                'name' => 'Deletion Tester',
                'email' => Str::uuid().'@example.com',
                'password' => 'irrelevant',
            ]);

            $team = Team::query()->create([
                'user_id' => $user->id,
                'name' => 'Deletion Team',
            ]);
            $team->users()->attach($user->id, ['role' => 'admin']);
        }

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return [$monitor, $team];
    }

    /**
     * The pivot columns the real open path writes, both NOT NULL.
     *
     * @return array<string, string>
     */
    protected function pivot(): array
    {
        return [
            'component_status_at_start' => MonitorStatus::Down->value,
            'component_status_current' => MonitorStatus::Down->value,
        ];
    }

    protected function makeIncident(Monitor $monitor): Incident
    {
        $incident = Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Endpoint down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now(),
        ]);

        $incident->monitors()->attach($monitor->id, $this->pivot());

        return $incident;
    }
}
