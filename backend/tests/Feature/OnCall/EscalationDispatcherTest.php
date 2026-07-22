<?php

namespace Tests\Feature\OnCall;

use App\Enums\EscalationTargetType;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\SignalSource;
use App\Jobs\DispatchEscalationStep;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\OnCall\EscalationDispatcher;
use App\Services\OnCall\RotationResolver;
use FlutterSdk\MagicStarter\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers {@see EscalationDispatcher}: an open pages the currently on-call
 * responder, each policy step is queued at its cumulative delay, a resolved
 * incident cancels every pending step, a re-dispatch never double-pages, and a
 * channel the notifiable disabled is never paged.
 */
class EscalationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_call_step_pages_the_currently_on_call_responder(): void
    {
        Notification::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertSentTo($responder, IncidentOpened::class);
    }

    public function test_steps_are_queued_at_cumulative_delays_in_order(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        [$team] = $this->teamWithOnCall();
        $policy = $this->makePolicy($team);
        $steps = collect([
            ['position' => 0, 'delay' => 0],
            ['position' => 1, 'delay' => 5],
            ['position' => 2, 'delay' => 10],
        ])->map(fn (array $spec): EscalationStep => $this->makeStep($policy, $spec['position'], $spec['delay']));
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->escalate($incident);

        Queue::assertPushed(DispatchEscalationStep::class, 3);
        $expected = [
            [$steps[0]->id, 0],
            [$steps[1]->id, 5],
            [$steps[2]->id, 15],
        ];
        foreach ($expected as [$stepId, $cumulative]) {
            Queue::assertPushed(
                DispatchEscalationStep::class,
                fn (DispatchEscalationStep $job): bool => $job->stepId === $stepId
                    && $job->incidentId === $incident->id
                    && $job->delay instanceof \DateTimeInterface
                    && $job->delay->getTimestamp() === now()->addMinutes($cumulative)->getTimestamp(),
            );
        }
    }

    public function test_a_resolved_incident_cancels_pending_steps(): void
    {
        Notification::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy, IncidentStatus::Resolved);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertNothingSentTo($responder);
    }

    public function test_re_dispatch_pages_a_step_only_once(): void
    {
        Notification::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);
        $stepId = $policy->steps->first()->id;

        $this->dispatcher()->pageStep($incident->id, $stepId);
        $this->dispatcher()->pageStep($incident->id, $stepId);

        Notification::assertSentToTimes($responder, IncidentOpened::class, 1);
    }

    public function test_a_disabled_channel_is_not_paged(): void
    {
        config(['magic-starter.features' => array_values(array_unique([
            ...config('magic-starter.features', []),
            Features::onesignal(),
        ]))]);
        Notification::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $responder->notificationSettings()->create([
            'type' => 'incident_opened',
            'channel' => 'onesignal',
            'is_enabled' => false,
        ]);
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertSentTo(
            $responder,
            IncidentOpened::class,
            fn (IncidentOpened $notification, array $channels): bool => in_array('mail', $channels, true)
                && ! in_array('onesignal', $channels, true),
        );
    }

    public function test_a_user_target_pages_that_specific_user(): void
    {
        Notification::fake();
        [$team] = $this->teamWithOnCall();
        $target = User::query()->create([
            'name' => 'Named Target',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $policy = $this->makePolicy($team);
        $step = EscalationStep::query()->create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::User,
            'target_id' => $target->id,
        ]);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $step->id);

        Notification::assertSentTo($target, IncidentOpened::class);
    }

    protected function dispatcher(): EscalationDispatcher
    {
        return $this->app->make(EscalationDispatcher::class);
    }

    /**
     * A team whose single on-call schedule points its whole rotation at one
     * responder, so {@see RotationResolver} resolves that
     * user for any instant.
     *
     * @return array{0: Team, 1: User}
     */
    protected function teamWithOnCall(): array
    {
        $responder = User::query()->create([
            'name' => 'On Call',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $responder->id,
            'name' => 'Escalation Team',
        ]);
        $team->users()->attach($responder->id, ['role' => 'admin']);

        $schedule = OnCallSchedule::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary Schedule',
            'timezone' => 'UTC',
        ]);
        OnCallRotation::query()->create([
            'schedule_id' => $schedule->id,
            'user_id' => $responder->id,
            'position' => 0,
            'shift_hours' => 24,
        ]);

        return [$team, $responder];
    }

    protected function policyWithOnCallStep(Team $team): EscalationPolicy
    {
        $policy = $this->makePolicy($team);
        $this->makeStep($policy, 0, 0);

        return $policy->fresh('steps');
    }

    protected function makePolicy(Team $team): EscalationPolicy
    {
        return EscalationPolicy::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary On-Call Policy',
        ]);
    }

    protected function makeStep(
        EscalationPolicy $policy,
        int $position,
        int $delayMinutes,
    ): EscalationStep {
        return EscalationStep::query()->create([
            'escalation_policy_id' => $policy->id,
            'position' => $position,
            'delay_minutes' => $delayMinutes,
            'target_type' => EscalationTargetType::OnCall,
        ]);
    }

    protected function openIncident(
        Team $team,
        EscalationPolicy $policy,
        IncidentStatus $lifecycle = IncidentStatus::Detected,
    ): Incident {
        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => 'http',
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'escalation_policy_id' => $policy->id,
        ]);

        return Incident::query()->create([
            'team_id' => $team->id,
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
