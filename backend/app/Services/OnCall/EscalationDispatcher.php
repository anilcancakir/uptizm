<?php

namespace App\Services\OnCall;

use App\Enums\EscalationTargetType;
use App\Enums\IncidentStatus;
use App\Jobs\DispatchEscalationStep;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\OnCallSchedule;
use App\Models\ScheduledMaintenance;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\Monitoring\IncidentDispatcher;
use App\Support\Logging\EvidenceLog;
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
    public function pageStep(string $incidentId, string $stepId): void
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
            return;
        }

        // 3. Idempotency: one page per (incident, step). `Cache::add` is atomic,
        //    so a re-dispatch of the same pair is a no-op.
        if (! Cache::add($this->guardKey($incidentId, $stepId), true, now()->addDay())) {
            return;
        }

        // 4. Resolve the step and page its target.
        $step = EscalationStep::find($stepId);

        if ($step === null) {
            return;
        }

        $this->pageTarget($incident, $step);
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
     * monitor's pinned policy wins, otherwise the team's default (the
     * earliest-created policy) is used. Returns null when neither exists.
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

        // 2. Otherwise fall back to the team's default (earliest-created) policy.
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

        Notification::send($responder, new IncidentOpened($incident));
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

        Notification::send($user, new IncidentOpened($incident));
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
    protected function guardKey(string $incidentId, string $stepId): string
    {
        return "escalation-step-paged:{$incidentId}:{$stepId}";
    }
}
