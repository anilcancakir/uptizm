<?php

namespace Tests\Feature\OnCall;

use App\Enums\EscalationTargetType;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Jobs\DispatchEscalationStep;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Models\PushDevice;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\OnCall\EscalationDispatcher;
use App\Services\OnCall\RotationResolver;
use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CapturesEvidenceLog;
use Tests\TestCase;

/**
 * Covers {@see EscalationDispatcher}: an open pages the currently on-call
 * responder, each policy step is queued at its cumulative delay, a resolved
 * incident cancels every pending step, a re-dispatch never double-pages, and a
 * channel the notifiable disabled is never paged.
 */
class EscalationDispatcherTest extends TestCase
{
    use CapturesEvidenceLog;
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

    /**
     * An acknowledgement has to stop the ladder, which is the whole contract of
     * acknowledging. Before this, only `Resolved` cancelled a pending step, so
     * every rung still fired at an operator who had already taken the incident,
     * and the only way to silence a page was to declare an outage over that
     * nobody had fixed.
     *
     * Asserted at FIRE time (`pageStep` directly) rather than at queue time: the
     * ladder is a set of delayed jobs, so what the queue looked like when the
     * incident opened says nothing about what happens minutes later.
     */
    public function test_an_acknowledged_incident_cancels_pending_steps(): void
    {
        Notification::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->policyWithOnCallStep($team);
        // Acknowledgement is this transition: IncidentWriteService::acknowledge
        // moves a Detected incident to Investigating and writes a timeline note.
        $incident = $this->openIncident($team, $policy, IncidentStatus::Investigating);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertNothingSentTo($responder);
    }

    /**
     * Every non-Detected state means a human has engaged, not only the one an
     * acknowledgement produces: an operator posting a status update moves the
     * incident along the same lifecycle.
     */
    public function test_a_human_moved_lifecycle_cancels_pending_steps(): void
    {
        Notification::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->policyWithOnCallStep($team);

        foreach ([IncidentStatus::Identified, IncidentStatus::Monitoring] as $stage) {
            $incident = $this->openIncident($team, $policy, $stage);

            $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);
        }

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

    /**
     * The hole this closes: an escalation step that resolves to NOBODY returned
     * silently, after claiming its idempotency marker.
     *
     * So a team with an escalation policy and no on-call schedule (or a schedule
     * whose rotation is empty, or a step pinned to a user since deleted) climbed
     * its whole ladder paging nobody, and the only evidence was silence. That is
     * the worst shape an alerting product has: the monitor is down, the incident
     * is open, the policy is configured, and no human hears about it.
     *
     * The evidence channel is where "why did nobody get paged" is answered after
     * the fact; it already carries the two suppression lines for the same
     * question.
     */
    public function test_an_on_call_step_with_no_schedule_records_that_it_reached_nobody(): void
    {
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        // A team with a policy but no schedule at all.
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $team = Team::query()->create(['user_id' => $owner->id, 'name' => 'Rotaless Team']);
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertNothingSent();
        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
        $this->assertStringContainsString($incident->id, $this->evidenceLogContents());
    }

    /**
     * The second shape of the same hole: the schedule exists, so the step gets
     * past the null-schedule guard, and the ring is empty.
     */
    public function test_an_on_call_step_with_an_empty_rotation_records_that_it_reached_nobody(): void
    {
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $team = Team::query()->create(['user_id' => $owner->id, 'name' => 'Empty Ring Team']);
        OnCallSchedule::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary Schedule',
            'timezone' => 'UTC',
        ]);
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertNothingSent();
        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * The third shape: a step pinned to a specific user who has since been
     * removed. `User::find` answers null and the step used to end there.
     */
    public function test_a_user_step_whose_target_is_gone_records_that_it_reached_nobody(): void
    {
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team] = $this->teamWithOnCall();
        $policy = $this->makePolicy($team);
        $step = EscalationStep::query()->create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::User,
            'target_id' => (string) Str::uuid(),
        ]);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $step->id);

        Notification::assertNothingSent();
        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * The negative: a step that DID page somebody writes no such line, so the
     * evidence channel stays a record of the exceptional case.
     */
    public function test_a_step_that_pages_someone_records_no_unreachable_line(): void
    {
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertSentTo($responder, IncidentOpened::class);
        $this->assertStringNotContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    // -------------------------------------------------------------------------
    // Push reachability: a rung that reached nobody because nothing could leave
    // the app
    // -------------------------------------------------------------------------

    /**
     * The QA scenario, end to end: a responder whose only outward channel is
     * push, on a device that has told the server it cannot receive one.
     *
     * Nothing about this is visible from the server without the device's own
     * report: the permission, the opt-in flag and the subscription id all live
     * on the phone. Without it, the ladder queued a push into OneSignal, the
     * push was accepted, nobody was woken, and the incident sat in `Detected`
     * with a perfectly clean log.
     *
     * The ladder MOVING ON is asserted beside the log line, because a rung that
     * reached nobody is only half the story: the value of knowing is the next
     * responder getting their turn.
     */
    public function test_a_push_only_responder_on_an_unreachable_device_records_that_it_reached_nobody(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        Queue::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->leaveOnlyPushEnabled($responder);
        $this->reportDevice($responder, 'off');

        $policy = $this->makePolicy($team);
        $first = $this->makeStep($policy, 0, 0);
        $second = $this->makeStep($policy, 1, 5);
        $incident = $this->openIncident($team, $policy->fresh('steps'));

        $this->dispatcher()->escalate($incident);
        $this->dispatcher()->pageStep($incident->id, $first->id);

        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
        Queue::assertPushed(
            DispatchEscalationStep::class,
            fn (DispatchEscalationStep $job): bool => $job->stepId === $second->id,
        );
    }

    /**
     * The second QA scenario, and the trap the naive rule falls into: the same
     * responder, the same unreachable phone, with email left on.
     *
     * "No push" is NOT "unreachable". Mail is in the default set and needs no
     * device to arrive, so this rung reached somebody and skipping ahead would
     * page the next person for an incident the first one was told about.
     */
    public function test_a_responder_with_email_still_on_is_never_unreachable_whatever_their_push_state(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->reportDevice($responder, 'blocked');
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertSentTo($responder, IncidentOpened::class);
        $this->assertStringNotContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * The positive of the push-only case: a device that reported it CAN be
     * reached is a rung that reached somebody, even with every other channel
     * switched off. Without this the feature would read as "push-only is always
     * unreachable", which is a different (and equally wrong) rule.
     */
    public function test_a_push_only_responder_on_a_reachable_device_is_not_unreachable(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->leaveOnlyPushEnabled($responder);
        $this->reportDevice($responder, 'on');
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertSentTo($responder, IncidentOpened::class);
        $this->assertStringNotContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * A month-old `on` is not evidence that a phone still rings. The device may
     * have been wiped, reinstalled, or had its permission revoked, and every
     * one of those is silent from here.
     */
    public function test_a_stale_report_does_not_count_as_a_working_device(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->leaveOnlyPushEnabled($responder);
        $this->reportDevice(
            $responder,
            'on',
            reportedAt: now()->subHours(PushDevice::FRESH_FOR_HOURS + 1),
        );
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * The same rule at an ABSOLUTE age, which is the only form of it that says
     * anything about the horizon.
     *
     * The case above spells its age as `FRESH_FOR_HOURS + 1`, so it passes
     * whatever that constant holds. This one fixes a day and a bit, because
     * that is where the two errors sit either side of each other: a window that
     * expires while a responder is asleep costs one log line and no operational
     * change (the ladder walks on, and mail and the in-app row are untouched),
     * while a window that outlives a wiped, reinstalled or silenced phone costs
     * the whole feature, since every rung paging it is recorded as having
     * reached somebody.
     */
    public function test_a_device_silent_since_yesterday_is_not_evidence_it_still_rings(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->leaveOnlyPushEnabled($responder);
        $this->reportDevice($responder, 'on', reportedAt: now()->subHours(30));
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * A responder who has never reported anything is in exactly the same
     * position as one whose report went stale: the server has no evidence the
     * phone can be paged, so it must not act as though it has.
     */
    public function test_a_responder_who_never_reported_a_device_is_unreachable_on_push_alone(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->leaveOnlyPushEnabled($responder);
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * One person, three devices, one of which rings. A responder carrying a
     * phone and two dead browser tabs is reachable, and an ANY rule is the only
     * one that says so; an "every device" rule would skip a responder whose
     * phone is in their hand.
     */
    public function test_one_reachable_device_among_several_is_enough(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->leaveOnlyPushEnabled($responder);
        $this->reportDevice($responder, 'blocked', subscriptionId: 'sub-laptop');
        $this->reportDevice($responder, 'off', subscriptionId: 'sub-tablet');
        $this->reportDevice($responder, 'on', subscriptionId: 'sub-phone');
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertSentTo($responder, IncidentOpened::class);
        $this->assertStringNotContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * A device subscribed as somebody else is not this responder's device, and
     * a push addressed to `user_<them>` never arrives on it. Storing the alias
     * the device reported is what lets the read say so; without it, one phone
     * handed to a colleague would vouch for both of them.
     */
    public function test_a_device_subscribed_as_somebody_else_does_not_vouch_for_this_responder(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $this->leaveOnlyPushEnabled($responder);
        $this->reportDevice($responder, 'on', externalId: 'user_'.Str::uuid());
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * The same rule on the other target type. A step pinned to a named person
     * reaches them through exactly the same channel set, so a push-only pinned
     * responder on a dead device is the same gap and must read the same way.
     */
    public function test_a_pinned_user_target_is_measured_by_the_same_rule(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team] = $this->teamWithOnCall();
        $target = User::query()->create([
            'name' => 'Pinned Target',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $this->leaveOnlyPushEnabled($target);
        $this->reportDevice($target, 'off');

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

        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    /**
     * Recording the gap must not create a second one. The responder still has
     * their in-app row and whatever else survived their preferences, so the
     * send happens either way: this feature reports that a page did not land,
     * it does not withhold one.
     */
    public function test_an_unreachable_rung_still_delivers_what_it_can(): void
    {
        $this->provisionPushChannel();
        Notification::fake();
        $this->captureLogsUnderProductionLevels();

        [$team, $responder] = $this->teamWithOnCall();
        $responder->notificationSettings()->create([
            'type' => 'incident_opened',
            'channel' => 'mail',
            'is_enabled' => false,
        ]);
        $this->reportDevice($responder, 'off');
        $policy = $this->policyWithOnCallStep($team);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Notification::assertSentTo($responder, IncidentOpened::class);
        $this->assertStringContainsString(
            'Escalation step reached nobody',
            $this->evidenceLogContents(),
        );
    }

    // -------------------------------------------------------------------------
    // repeat_last_step
    // -------------------------------------------------------------------------

    public function test_a_repeating_policy_re_queues_its_last_rung(): void
    {
        Notification::fake();
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        [$team] = $this->teamWithOnCall();
        $policy = $this->repeatingPolicy($team, [0, 5]);
        $last = $policy->steps->last();
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $last->id);

        Queue::assertPushed(
            DispatchEscalationStep::class,
            fn (DispatchEscalationStep $job): bool => $job->stepId === $last->id
                && $job->incidentId === $incident->id
                && $job->attempt === 1
                && $job->delay->getTimestamp() === now()->addMinutes(5)->getTimestamp(),
        );
    }

    public function test_only_the_last_rung_repeats(): void
    {
        Notification::fake();
        Queue::fake();
        [$team] = $this->teamWithOnCall();
        $policy = $this->repeatingPolicy($team, [0, 5]);
        $incident = $this->openIncident($team, $policy);

        // The FIRST rung of the same repeating policy. Repeating a middle rung
        // would page a responder the ladder has already moved past.
        $this->dispatcher()->pageStep($incident->id, $policy->steps->first()->id);

        Queue::assertNothingPushed();
    }

    public function test_a_policy_without_the_flag_does_not_repeat(): void
    {
        Notification::fake();
        Queue::fake();
        [$team] = $this->teamWithOnCall();
        $policy = $this->makePolicy($team);
        $this->makeStep($policy, 0, 5);
        $policy = $policy->fresh('steps');
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->last()->id);

        Queue::assertNothingPushed();
    }

    public function test_an_acknowledged_incident_ends_the_repeat_chain(): void
    {
        Notification::fake();
        Queue::fake();
        [$team] = $this->teamWithOnCall();
        $policy = $this->repeatingPolicy($team, [5]);
        $incident = $this->openIncident($team, $policy, IncidentStatus::Investigating);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->last()->id, 3);

        // Acknowledgement IS the stop condition, so nothing is paged AND
        // nothing is re-queued: the chain ends here rather than ticking once
        // more against a responder who already has the incident.
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_the_repeat_chain_is_bounded(): void
    {
        Notification::fake();
        Queue::fake();
        [$team] = $this->teamWithOnCall();
        $policy = $this->repeatingPolicy($team, [5]);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep(
            $incident->id,
            $policy->steps->last()->id,
            EscalationDispatcher::MAX_REPEATS,
        );

        // The final pass still pages (the responder is owed this one), it just
        // does not arm another. Without the cap an incident nobody ever
        // acknowledges is a job re-queueing itself indefinitely.
        Notification::assertSentTimes(IncidentOpened::class, 1);
        Queue::assertNothingPushed();
    }

    public function test_a_maintenance_window_pauses_the_repeat_without_ending_it(): void
    {
        Notification::fake();
        Queue::fake();
        [$team] = $this->teamWithOnCall();
        $policy = $this->repeatingPolicy($team, [5]);
        $last = $policy->steps->last();
        $incident = $this->openIncident($team, $policy);
        $this->suppressWithMaintenance($incident);

        $this->dispatcher()->pageStep($incident->id, $last->id);

        // Withheld, not cancelled. Ending the chain inside the window would let
        // a window opened mid-outage silence the ladder permanently, which is
        // the exact failure repeat_last_step exists to prevent.
        Notification::assertNothingSent();
        Queue::assertPushed(
            DispatchEscalationStep::class,
            fn (DispatchEscalationStep $job): bool => $job->stepId === $last->id
                && $job->attempt === 1,
        );
    }

    public function test_a_zero_minute_last_rung_repeats_on_a_one_minute_floor(): void
    {
        Notification::fake();
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        [$team] = $this->teamWithOnCall();
        // A rung may legally carry 0: it fires the instant the one before it
        // does. Repeating on that interval would be a queue flood.
        $policy = $this->repeatingPolicy($team, [0]);
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $policy->steps->last()->id);

        Queue::assertPushed(
            DispatchEscalationStep::class,
            fn (DispatchEscalationStep $job): bool => $job->delay->getTimestamp()
                === now()->addMinute()->getTimestamp(),
        );
    }

    public function test_each_repeat_claims_its_own_idempotency_marker(): void
    {
        Notification::fake();
        // Without this the sync queue driver runs each scheduled repeat inline,
        // so the chain walks to MAX_REPEATS and the count measures the cap
        // rather than the marker. Faking it keeps one pass per call.
        Queue::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->repeatingPolicy($team, [5]);
        $last = $policy->steps->last();
        $incident = $this->openIncident($team, $policy);

        // Pass 0 then pass 1. Sharing one marker across passes would make every
        // repeat a silent no-op, which looks identical to the feature being off.
        $this->dispatcher()->pageStep($incident->id, $last->id, 0);
        $this->dispatcher()->pageStep($incident->id, $last->id, 1);

        Notification::assertSentToTimes($responder, IncidentOpened::class, 2);
    }

    public function test_a_repeat_still_never_double_pages_the_same_pass(): void
    {
        Notification::fake();
        Queue::fake();
        [$team, $responder] = $this->teamWithOnCall();
        $policy = $this->repeatingPolicy($team, [5]);
        $last = $policy->steps->last();
        $incident = $this->openIncident($team, $policy);

        $this->dispatcher()->pageStep($incident->id, $last->id, 2);
        $this->dispatcher()->pageStep($incident->id, $last->id, 2);

        Notification::assertSentToTimes($responder, IncidentOpened::class, 1);
    }

    // -------------------------------------------------------------------------
    // is_default
    // -------------------------------------------------------------------------

    public function test_the_marked_default_policy_outranks_creation_order(): void
    {
        Notification::fake();
        [$team] = $this->teamWithOnCall();

        $oldest = $this->makePolicy($team);
        $this->makeStep($oldest, 0, 0);

        $marked = $this->makePolicy($team);
        $marked->update(['is_default' => true]);
        $markedStep = $this->makeStep($marked, 0, 0);

        // A monitor that pins nothing: the fallback decides which ladder pages.
        $incident = $this->openIncidentWithoutPolicy($team);

        Queue::fake();
        $this->dispatcher()->escalate($incident);

        Queue::assertPushed(
            DispatchEscalationStep::class,
            fn (DispatchEscalationStep $job): bool => $job->stepId === $markedStep->id,
        );
    }

    public function test_creation_order_still_decides_when_nothing_is_marked(): void
    {
        Notification::fake();
        [$team] = $this->teamWithOnCall();

        $oldest = $this->makePolicy($team);
        $oldestStep = $this->makeStep($oldest, 0, 0);

        $newer = $this->makePolicy($team);
        $this->makeStep($newer, 0, 0);

        $incident = $this->openIncidentWithoutPolicy($team);

        Queue::fake();
        $this->dispatcher()->escalate($incident);

        // Unmarked teams must page exactly as they did before the column
        // existed, or this migration changes who gets woken on deploy.
        Queue::assertPushed(
            DispatchEscalationStep::class,
            fn (DispatchEscalationStep $job): bool => $job->stepId === $oldestStep->id,
        );
    }

    /**
     * A policy carrying `repeat_last_step`, with one rung per entry in
     * [$delays] at ascending positions.
     *
     * @param  array<int, int>  $delays  Each rung's `delay_minutes`.
     */
    /**
     * An incident whose monitor pins no policy, so the team-level fallback is
     * what decides which ladder pages.
     */
    protected function openIncidentWithoutPolicy(Team $team): Incident
    {
        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Unpinned Monitor',
            'type' => 'http',
            'url' => 'https://example.com/unpinned',
            'check_interval_sec' => 60,
        ]);

        return Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Unpinned Monitor is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now(),
        ]);
    }

    /**
     * Open a suppressing maintenance window over every monitor on [$incident],
     * which is what {@see EscalationDispatcher::isUnderMaintenance()} requires:
     * a partially covered incident is NOT suppressed.
     */
    protected function suppressWithMaintenance(Incident $incident): ScheduledMaintenance
    {
        // The suppression check reads the incident's monitor PIVOT, not
        // `primary_monitor_id`, and answers false for an incident with no pivot
        // rows. `openIncident` only sets the primary, so the link has to be made
        // here or the window covers nothing and the test silently measures the
        // unsuppressed path.
        // The pivot carries the component health frozen at open time, and both
        // columns hold a MonitorStatus value rather than a ComponentStatus one,
        // matching every real opener (PerformSslCheck, ThresholdEvaluator,
        // AiIncidentOpener).
        $incident->monitors()->syncWithoutDetaching([
            $incident->primary_monitor_id => [
                'component_status_at_start' => MonitorStatus::Down->value,
                'component_status_current' => MonitorStatus::Down->value,
            ],
        ]);

        $statusPage = StatusPage::query()->create([
            'team_id' => $incident->team_id,
            'name' => 'Public Status',
            'slug' => Str::uuid().'-status',
        ]);

        $window = ScheduledMaintenance::query()->create([
            'team_id' => $incident->team_id,
            'status_page_id' => $statusPage->id,
            'title' => 'Planned database failover',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'suppress_alerts' => true,
        ]);

        $window->monitors()->sync($incident->monitors()->pluck('monitors.id')->all());

        return $window;
    }

    /**
     * A policy carrying `repeat_last_step`, with one rung per entry in
     * [$delays] at ascending positions.
     *
     * @param  array<int, int>  $delays  Each rung's `delay_minutes`.
     */
    protected function repeatingPolicy(Team $team, array $delays): EscalationPolicy
    {
        $policy = $this->makePolicy($team);
        $policy->update(['repeat_last_step' => true]);

        foreach ($delays as $position => $delay) {
            $this->makeStep($policy, $position, $delay);
        }

        return $policy->fresh('steps');
    }

    protected function dispatcher(): EscalationDispatcher
    {
        return $this->app->make(EscalationDispatcher::class);
    }

    /**
     * Put the OneSignal driver into {@see IncidentOpened::via()}'s default set.
     *
     * The feature flag alone is not enough: `defaultChannels()` also requires a
     * provisioned `app_id`, because the channel throws on an empty one. Without
     * this the push channel is silently absent from every `via()` in the suite
     * and every assertion about it is vacuous.
     */
    protected function provisionPushChannel(): void
    {
        config([
            'magic-starter.features' => array_values(array_unique([
                ...config('magic-starter.features', []),
                Features::onesignal(),
            ])),
            'magic-starter.onesignal.app_id' => 'test-onesignal-app',
        ]);
    }

    /**
     * Switch off every channel that leaves the app except push, for
     * `incident_opened`.
     *
     * `database` goes too, and it has to: an in-app row is delivered to
     * somebody who is already looking at the app, which is the one thing an
     * escalation ladder cannot assume. Leaving it enabled would make this the
     * "push plus in-app" case rather than the push-only one.
     */
    protected function leaveOnlyPushEnabled(User $user): void
    {
        foreach (['mail', 'database'] as $channel) {
            $user->notificationSettings()->create([
                'type' => 'incident_opened',
                'channel' => $channel,
                'is_enabled' => false,
            ]);
        }
    }

    /**
     * Record what one of [$user]'s devices last told the server about its push
     * delivery state, as {@see PushDeviceController} would have written it.
     *
     * `reported_at` is the server's own clock and is what freshness reads, so
     * the staleness case sets it directly rather than moving `captured_at`,
     * which is the device's claim and is deliberately not trusted for age.
     */
    protected function reportDevice(
        User $user,
        string $reachability,
        ?string $subscriptionId = 'sub-primary',
        ?string $externalId = null,
        ?CarbonInterface $reportedAt = null,
    ): PushDevice {
        return PushDevice::query()->create([
            'user_id' => $user->getKey(),
            'external_id' => $externalId ?? 'user_'.$user->getKey(),
            'subscription_id' => $subscriptionId,
            'reachability' => $reachability,
            'captured_at' => $reportedAt ?? now(),
            'reported_at' => $reportedAt ?? now(),
        ]);
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
