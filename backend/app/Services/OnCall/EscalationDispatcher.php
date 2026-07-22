<?php

namespace App\Services\OnCall;

use App\Enums\EscalationTargetType;
use App\Jobs\DispatchEscalationStep;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\OnCallSchedule;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\Monitoring\IncidentDispatcher;
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
        // 1. A resolved incident cancels every step still pending: short-circuit
        //    before claiming the idempotency marker so nothing is consumed.
        $incident = Incident::find($incidentId);

        if ($incident === null || ! $incident->lifecycle->isActive()) {
            return;
        }

        // 2. Idempotency: one page per (incident, step). `Cache::add` is atomic,
        //    so a re-dispatch of the same pair is a no-op.
        if (! Cache::add($this->guardKey($incidentId, $stepId), true, now()->addDay())) {
            return;
        }

        // 3. Resolve the step and page its target.
        $step = EscalationStep::find($stepId);

        if ($step === null) {
            return;
        }

        $this->pageTarget($incident, $step);
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
            EscalationTargetType::OnCall => $this->pageOnCall($incident),
            EscalationTargetType::User => $this->pageUser($incident, $step),
        };
    }

    /**
     * Page the team's currently on-call responder, resolved from its schedule.
     * No schedule or an empty rotation pages nobody.
     */
    protected function pageOnCall(Incident $incident): void
    {
        $schedule = OnCallSchedule::query()
            ->where('team_id', $incident->team_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($schedule === null) {
            return;
        }

        $responder = $this->rotationResolver->resolve($schedule);

        if ($responder === null) {
            return;
        }

        Notification::send($responder, new IncidentOpened($incident));
    }

    /**
     * Page the specific user named by the step's `target_id`.
     */
    protected function pageUser(Incident $incident, EscalationStep $step): void
    {
        $user = $step->target_id !== null ? User::find($step->target_id) : null;

        if ($user === null) {
            return;
        }

        Notification::send($user, new IncidentOpened($incident));
    }

    /**
     * The idempotency cache key for a paged (incident, step) pair.
     */
    protected function guardKey(string $incidentId, string $stepId): string
    {
        return "escalation-step-paged:{$incidentId}:{$stepId}";
    }
}
