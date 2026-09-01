<?php

namespace App\Services\OnCall;

use App\Enums\EscalationTargetType;
use App\Enums\IncidentStatus;
use App\Jobs\DispatchEscalationStep;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\OnCallSchedule;
use App\Models\PushDevice;
use App\Models\ScheduledMaintenance;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\Monitoring\IncidentDispatcher;
use App\Support\Logging\EvidenceLog;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Walks an opened incident's escalation ladder: it resolves the governing
 * policy (the monitor's pinned policy, else the team's default), queues one
 * delayed {@see DispatchEscalationStep} per step at its cumulative delay, and
 * pages each step's target when the job fires.
 *
 * Runs OFF the incident-open lock: it is invoked by {@see IncidentDispatcher}
 * as an additional side effect of an open, never inside the critical section.
 * Paging is idempotent per (incident, step) and a resolved incident cancels
 * every step that has not yet fired.
 */
class EscalationDispatcher
{
    /**
     * How many extra passes a repeating policy's last rung may take.
     *
     * This is a runaway guard, NOT the feature's stop condition. The real stop
     * is acknowledgement: {@see pageStep} refuses anything that has left
     * `Detected`, so a repeating chain ends the moment somebody picks the
     * incident up. The cap exists for the case where nobody ever does, which on
     * an unattended team would otherwise be a job re-queueing itself for as long
     * as the incident stays open. At the 5-minute rung a team is likely to put
     * last, twelve passes is an hour of continued paging before the ladder gives
     * up; at 30 minutes it is six hours.
     */
    public const int MAX_REPEATS = 12;

    /**
     * The driver name of the push channel, the one outward channel whose
     * deliverability cannot be answered from the channel list alone.
     */
    protected const string PUSH_CHANNEL = 'onesignal';

    public function __construct(
        protected RotationResolver $rotationResolver,
    ) {}

    /**
     * Schedule the escalation ladder for a freshly opened incident.
     *
     * @param  Incident  $incident  The incident that just opened.
     */
    public function escalate(Incident $incident): void
    {
        // 1. Resolve the policy that governs this incident's paging chain.
        $policy = $this->resolvePolicy($incident);

        if ($policy === null) {
            return;
        }

        // 2. Queue one delayed job per step. Each `delay_minutes` stacks on the
        //    prior steps, so a step fires at its cumulative offset from open.
        $cumulativeMinutes = 0;

        foreach ($policy->steps as $step) {
            $cumulativeMinutes += $step->delay_minutes;

            DispatchEscalationStep::dispatch($incident->getKey(), $step->getKey())
                ->delay(now()->addMinutes($cumulativeMinutes));
        }
    }

    /**
     * Page a single escalation step, unless the incident has since resolved or
     * this (incident, step) pair was already paged.
     *
     * @param  string  $incidentId  The opened incident this step pages for.
     * @param  string  $stepId  The escalation step to fire.
     */
    public function pageStep(string $incidentId, string $stepId, int $attempt = 0): void
    {
        // 1. Page only while NOBODY has engaged, and short-circuit before
        //    claiming the idempotency marker so nothing is consumed.
        //
        //    `Detected` is the untouched state. An acknowledgement moves the
        //    incident to `Investigating` (see IncidentWriteService::acknowledge,
        //    which is what acknowledging IS: there is no separate acknowledged_at
        //    column), an operator's status update moves it further, and `Resolved`
        //    ends it. Every one of those means a human has this incident, and
        //    paging the next rung at that point is the exact thing acknowledging
        //    exists to prevent.
        //
        //    This used to check `isActive()`, which is only false for `Resolved`.
        //    So the ladder kept firing at the operator who had already taken the
        //    incident, and the only way to silence a page was to declare an outage
        //    over that nobody had fixed.
        //
        //    One consequence worth naming: `reopen()` sets `Investigating` (a
        //    contract pinned by IncidentWriteControllerTest) and calls
        //    dispatchOpened, so a reopened incident still ANNOUNCES on the team's
        //    channels but no longer walks the on-call ladder. That follows from the
        //    same rule (the operator who reopened it is engaged), and it is the
        //    conservative half of the choice: the alternative, re-arming the ladder
        //    because the rotation may have changed since, is a product decision
        //    rather than a bug fix.
        $incident = Incident::find($incidentId);

        if ($incident === null || $incident->lifecycle !== IncidentStatus::Detected) {
            return;
        }

        // 2. An open maintenance window withholds this step. IncidentDispatcher
        //    already checked when the ladder was QUEUED, but `escalate()` only
        //    enqueues delayed jobs, so the queue-time answer cannot speak for a
        //    step that fires minutes later: a window scheduled after the incident
        //    opened (the most natural operator sequence there is) used to page the
        //    on-call straight through planned work. Suppression deliberately
        //    leaves the incident open and active, so the lifecycle check above
        //    cannot stand in for this one. Checked BEFORE the idempotency claim,
        //    matching the resolved-incident short-circuit: a withheld step
        //    consumes no marker, so a retry after the window closes still pages.
        if ($this->isUnderMaintenance($incident)) {
            // A repeating chain has to SURVIVE the window rather than end in it.
            // Returning outright here would mean a maintenance window opened
            // mid-outage silently cancels the repeat forever, so the on-call
            // stops being paged for an incident nobody acknowledged, which is
            // the failure `repeat_last_step` exists to prevent. Scheduling the
            // next pass without paging this one keeps the chain alive and
            // withholds only the page, which is what suppression means.
            $this->scheduleRepeat($incidentId, $stepId, $attempt);

            return;
        }

        // 3. Idempotency: one page per (incident, step, attempt). `Cache::add`
        //    is atomic, so a re-dispatch of the same triple is a no-op. Attempt
        //    0 produces the key this has always used, so jobs queued before the
        //    repeat flag existed keep their marker across a deploy.
        if (! Cache::add($this->guardKey($incidentId, $stepId, $attempt), true, now()->addDay())) {
            return;
        }

        // 4. Resolve the step and page its target.
        $step = EscalationStep::find($stepId);

        if ($step === null) {
            return;
        }

        $this->pageTarget($incident, $step);

        // 5. Arm the next pass, when this is a repeating policy's last rung.
        $this->scheduleRepeat($incidentId, $stepId, $attempt);
    }

    /**
     * Queue the next pass over a repeating policy's last rung.
     *
     * A no-op unless [$stepId] is the LAST step of a policy that carries
     * `repeat_last_step`. There is no lifecycle check here: the next job runs
     * {@see pageStep}, whose first gate already refuses anything that has left
     * `Detected`, so an acknowledgement stops the chain at its next tick rather
     * than needing to reach into the queue and cancel it.
     *
     * @param  string  $incidentId  The incident being paged for.
     * @param  string  $stepId  The step that just fired (or was withheld).
     * @param  int  $attempt  The pass that just ran; the next one is this + 1.
     */
    protected function scheduleRepeat(string $incidentId, string $stepId, int $attempt): void
    {
        if ($attempt >= self::MAX_REPEATS) {
            return;
        }

        $step = EscalationStep::with('policy.steps')->find($stepId);

        if ($step === null || $step->policy === null || ! $step->policy->repeat_last_step) {
            return;
        }

        // Only the final rung repeats. `steps` is ordered by position, so the
        // last element is the end of the ladder.
        if ($step->policy->steps->last()?->getKey() !== $step->getKey()) {
            return;
        }

        // The rung's own delay sets the repeat interval, but a rung may legally
        // carry 0 (it fires the instant the one before it does). Repeating on a
        // 0-minute interval would be a job re-queueing itself with no delay,
        // which is a queue flood rather than a paging policy, so the interval
        // floors at a minute.
        $minutes = max(1, $step->delay_minutes);

        DispatchEscalationStep::dispatch($incidentId, $stepId, $attempt + 1)
            ->delay(now()->addMinutes($minutes));
    }

    /**
     * Whether EVERY monitor attached to this incident is inside an open
     * suppressing maintenance window, evaluated against the clock now.
     *
     * "Every", not "the primary one", and not "any". An incident with a
     * monitor under planned work AND a monitor that is genuinely down is a
     * real outage, and IncidentDispatcher already put the reasoning on record:
     * silencing an outage nobody planned for is a far more expensive failure
     * than a page an operator expected. Today every incident-opening path
     * attaches exactly one monitor, so the three rules behave identically;
     * this is the one that stays correct when correlated incidents start
     * attaching several.
     *
     * An incident with no attached monitor is never suppressed: there is
     * nothing to prove planned work against.
     */
    protected function isUnderMaintenance(Incident $incident): bool
    {
        /** @var list<string> $monitorIds */
        $monitorIds = $incident->monitors()->pluck('monitors.id')->all();

        if ($monitorIds === []) {
            return false;
        }

        $suppressed = ScheduledMaintenance::suppressedMonitorIds($incident->team_id, $monitorIds);

        if (count($suppressed) < count($monitorIds)) {
            return false;
        }

        $this->logSuppression($incident, $monitorIds);

        return true;
    }

    /**
     * Record a withheld escalation step, mirroring the shape
     * {@see IncidentDispatcher::logSuppression()} records so "why did nobody get
     * paged" is answered for both paging paths by one grep.
     *
     * On {@see EvidenceLog::CHANNEL} for the reason that method's docblock gives,
     * and this is the path where it matters most: a step fires minutes to hours
     * after the incident opened, so by the time anyone asks, the reasoning is only
     * reconstructible from a line that was actually kept.
     *
     * @param  list<string>  $monitorIds  The attached monitors, all suppressed.
     */
    protected function logSuppression(Incident $incident, array $monitorIds): void
    {
        EvidenceLog::record('Escalation step suppressed by an open maintenance window.', [
            'incident_id' => $incident->getKey(),
            'monitor_ids' => $monitorIds,
        ]);
    }

    /**
     * Resolve the escalation policy governing this incident: the primary
     * monitor's pinned policy wins, then the team's marked default, then the
     * earliest-created policy. Returns null when none exists.
     */
    protected function resolvePolicy(Incident $incident): ?EscalationPolicy
    {
        // 1. A monitor may pin a specific policy via `escalation_policy_id`.
        $policyId = $incident->primaryMonitor?->escalation_policy_id;

        if ($policyId !== null) {
            $pinned = EscalationPolicy::find($policyId);

            if ($pinned !== null) {
                return $pinned;
            }
        }

        // 2. The team's marked default, when it has one.
        $default = EscalationPolicy::query()
            ->where('team_id', $incident->team_id)
            ->where('is_default', true)
            ->first();

        if ($default !== null) {
            return $default;
        }

        // 3. Otherwise the earliest-created policy, which is what this fallback
        //    has always been. Keeping it is what makes `is_default` additive:
        //    a team that never marks a policy pages exactly as it did before.
        return EscalationPolicy::query()
            ->where('team_id', $incident->team_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Page the resolved target for a step.
     */
    protected function pageTarget(Incident $incident, EscalationStep $step): void
    {
        match ($step->target_type) {
            EscalationTargetType::OnCall => $this->pageOnCall($incident, $step),
            EscalationTargetType::User => $this->pageUser($incident, $step),
        };
    }

    /**
     * Page the team's currently on-call responder, resolved from its schedule.
     *
     * No schedule and an empty rotation both page nobody, and both now say so.
     * They used to return silently, AFTER the idempotency marker was claimed, so
     * a team with a policy and no rota climbed its whole ladder reaching no one
     * and the only evidence was silence. That is the worst shape this product
     * has: the monitor is down, the incident is open, the policy is configured,
     * and nobody hears about it. The two states are named separately because the
     * fix differs (create a schedule, or put somebody in the ring).
     */
    protected function pageOnCall(Incident $incident, EscalationStep $step): void
    {
        $schedule = OnCallSchedule::query()
            ->where('team_id', $incident->team_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($schedule === null) {
            $this->logUnreachable($incident, $step, 'the team has no on-call schedule');

            return;
        }

        $responder = $this->rotationResolver->resolve($schedule);

        if ($responder === null) {
            $this->logUnreachable(
                $incident,
                $step,
                'the on-call rotation is empty and no override covers now',
                ['on_call_schedule_id' => $schedule->getKey()],
            );

            return;
        }

        $this->pageResponder($incident, $step, $responder, [
            'on_call_schedule_id' => $schedule->getKey(),
        ]);
    }

    /**
     * Page the specific user named by the step's `target_id`.
     *
     * A step pinned to a user who has since been removed from the team, or
     * deleted outright, is the third way a rung reaches nobody. `target_id` is
     * nullable in the schema and only required for this target type, so a step
     * with no id at all lands here too.
     */
    protected function pageUser(Incident $incident, EscalationStep $step): void
    {
        $user = $step->target_id !== null ? User::find($step->target_id) : null;

        if ($user === null) {
            $this->logUnreachable(
                $incident,
                $step,
                'the user this step pages no longer exists',
                ['target_user_id' => $step->target_id],
            );

            return;
        }

        $this->pageResponder($incident, $step, $user, ['target_user_id' => $step->target_id]);
    }

    /**
     * Page a resolved human, and record it when nothing in the send could
     * actually leave the app.
     *
     * ## The rule, and why it is narrower than "no push"
     *
     * The tempting rule is "a responder with no push is unreachable, skip to
     * the next rung", and it would page the wrong person minutes early: mail is
     * in the default channel set, needs no device, and a responder who has it
     * on WAS reached even though their phone stayed dark.
     *
     * So the question is asked of the channel set rather than of the phone.
     * {@see IncidentOpened::via()} already resolves that set for this exact
     * notifiable, honouring their per-type preference matrix, and it is the
     * only place that resolution exists. A rung reached nobody when, after that
     * filtering, nothing outward survives: no mail, no sms, and either no push
     * or a push whose every known device says it cannot receive one.
     *
     * `database` and `broadcast` are deliberately not outward. They are the
     * in-app bell, delivered to somebody who is already looking at the app,
     * which is the one thing an escalation ladder exists because it cannot
     * assume.
     *
     * ## It records, it does not withhold
     *
     * The send happens either way. Whatever survived the responder's
     * preferences is still owed to them (the in-app row most of all, since it
     * is what they find when they do open the app), and this feature reports
     * that a page did not land rather than deciding not to send one. What the
     * evidence line buys is the operator afterwards, and the rung after this
     * one getting its turn: the ladder is queued in full at open time, so the
     * next responder is already on their way and the record is what explains
     * why they were needed.
     *
     * @param  array<string, string|null>  $context  Extra ids naming how this
     *                                               responder was resolved.
     */
    protected function pageResponder(
        Incident $incident,
        EscalationStep $step,
        User $responder,
        array $context = [],
    ): void {
        $notification = new IncidentOpened($incident);
        $channels = $notification->via($responder);

        if (! $this->reaches($responder, $channels)) {
            $this->logUnreachable(
                $incident,
                $step,
                'the responder has no channel that leaves the app',
                [
                    ...$context,
                    'responder_id' => (string) $responder->getKey(),
                    'channels' => implode(',', $channels),
                ],
            );
        }

        Notification::send($responder, $notification);
    }

    /**
     * Whether [$channels] carries anything that actually reaches [$responder]
     * away from the app.
     *
     * Push is the one channel whose answer is not in the channel list: it is
     * enabled here and unreachable at the device, and only the device can say
     * so. {@see PushDevice::canReachByPush()} carries the four conditions a
     * stored report has to satisfy, the staleness horizon among them.
     *
     * @param  array<int, string>  $channels  The result of {@see IncidentOpened::via()}.
     */
    protected function reaches(User $responder, array $channels): bool
    {
        $outward = array_intersect($channels, $this->outwardChannels());

        if ($outward === []) {
            return false;
        }

        // Anything outward that is NOT push settles it without asking the
        // device: mail and sms leave this server under their own steam.
        if (array_diff($outward, [self::PUSH_CHANNEL]) !== []) {
            return true;
        }

        return PushDevice::canReachByPush($responder);
    }

    /**
     * The driver channels that reach a person away from the app.
     *
     * Resolved rather than listed for sms, because the logical `sms`
     * preference maps to a driver name the starter package registers
     * (`onesignal-sms`); hardcoding it here would leave this check silently
     * treating a texted responder as unreachable the day that mapping changes.
     *
     * @return array<int, string>
     */
    protected function outwardChannels(): array
    {
        return [
            'mail',
            self::PUSH_CHANNEL,
            NotificationPreferenceRegistry::resolveDriverChannel('sms'),
        ];
    }

    /**
     * Record that a rung of the ladder fired and reached nobody.
     *
     * On {@see EvidenceLog::CHANNEL} for the reason the two suppression lines
     * are: "why did nobody get paged" is asked after the fact, and the default
     * channel runs at `warning` in production so an info line there would never
     * have been written. Unlike a suppression this is not the system doing what
     * it was told, but it is still a configuration gap rather than a fault, and
     * an operator reading it needs the same trail.
     *
     * @param  array<string, string|null>  $context  Extra ids that name the gap.
     */
    protected function logUnreachable(
        Incident $incident,
        EscalationStep $step,
        string $reason,
        array $context = [],
    ): void {
        EvidenceLog::record('Escalation step reached nobody: '.$reason.'.', [
            'incident_id' => $incident->getKey(),
            'escalation_step_id' => $step->getKey(),
            'escalation_policy_id' => $step->escalation_policy_id,
            'step_position' => $step->position,
            'target_type' => $step->target_type->value,
            ...$context,
        ]);
    }

    /**
     * The idempotency cache key for a paged (incident, step) pair.
     */
    protected function guardKey(string $incidentId, string $stepId, int $attempt = 0): string
    {
        // Attempt 0 keeps the key this has always produced, so a job queued
        // before repeats existed still finds (and still claims) its own marker
        // after a deploy. Only a repeat adds the suffix, and each repeat needs
        // its own marker or the second pass would read the first one's claim and
        // silently decline to page.
        $key = "escalation-step-paged:{$incidentId}:{$stepId}";

        return $attempt === 0 ? $key : "{$key}:{$attempt}";
    }
}
