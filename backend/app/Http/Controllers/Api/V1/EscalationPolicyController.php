<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEscalationPolicyRequest;
use App\Http\Requests\StoreEscalationStepRequest;
use App\Http\Requests\UpdateEscalationPolicyRequest;
use App\Http\Resources\EscalationPolicyResource;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped CRUD for {@see EscalationPolicy} plus its ordered step chain.
 *
 * Mirrors {@see MonitorController}'s team-scope + 404-mask pattern (cross-team
 * access is masked as 404, never 403, so the existence of another team's
 * policies never leaks). Step management operates on a policy already owned
 * by the current team, so every nested action re-checks
 * {@see self::authorizeTeam()} before touching a child row, and the child
 * row's own `escalation_policy_id` is checked against the routed policy so
 * one policy's steps can never be edited through another policy's URL.
 */
class EscalationPolicyController extends Controller
{
    /**
     * List the current team's escalation policies, newest first, paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $policies = EscalationPolicy::query()
            ->where('team_id', $request->user()->current_team_id)
            ->orderByDesc('created_at')
            ->paginate();

        return EscalationPolicyResource::collection($policies);
    }

    /**
     * Create an escalation policy for the current team.
     */
    public function store(StoreEscalationPolicyRequest $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $policy = DB::transaction(function () use ($request, $teamId): EscalationPolicy {
            $created = EscalationPolicy::create([
                ...$request->validated(),
                'team_id' => $teamId,
            ]);

            $this->demoteOtherDefaults($created);

            // Refreshed so the 201 carries the flags as booleans. `create()`
            // returns a model holding only what was passed, so a request that
            // omits either flag would serialise it as null rather than as the
            // false the column actually stores, and the client would decode a
            // missing bool instead of an off switch.
            return $created->refresh();
        });

        return EscalationPolicyResource::make($policy)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a policy owned by the current team, with its ordered step chain.
     */
    public function show(Request $request, EscalationPolicy $policy): EscalationPolicyResource
    {
        $this->authorizeTeam($request, $policy);

        return EscalationPolicyResource::make($policy->load('steps'));
    }

    /**
     * Update a policy owned by the current team.
     */
    public function update(UpdateEscalationPolicyRequest $request, EscalationPolicy $policy): EscalationPolicyResource
    {
        $this->authorizeTeam($request, $policy);

        DB::transaction(function () use ($request, $policy): void {
            $policy->update($request->validated());

            $this->demoteOtherDefaults($policy);
        });

        return EscalationPolicyResource::make($policy->refresh()->load('steps'));
    }

    /**
     * Leave [$policy] as the only default policy on its team.
     *
     * A no-op unless [$policy] is itself marked. There is no partial unique
     * index behind `is_default` (the suite runs on both SQLite and PostgreSQL
     * and Laravel has no portable helper for one), so this is what keeps the
     * invariant, and it has to run inside the caller's transaction to hold.
     *
     * Marking a second policy MOVES the default rather than rejecting the
     * write. The alternative, a 422 telling the operator to go and unmark the
     * other one first, makes them do a two-step edit to express one intent, and
     * the intent is never ambiguous: a team has one fallback ladder.
     */
    protected function demoteOtherDefaults(EscalationPolicy $policy): void
    {
        if (! $policy->is_default) {
            return;
        }

        EscalationPolicy::query()
            ->where('team_id', $policy->team_id)
            ->whereKeyNot($policy->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Delete a policy owned by the current team.
     */
    public function destroy(Request $request, EscalationPolicy $policy): Response
    {
        $this->authorizeTeam($request, $policy);

        $policy->delete();

        return response()->noContent();
    }

    /**
     * Add a step to the policy's paging chain.
     */
    public function addStep(StoreEscalationStepRequest $request, EscalationPolicy $policy): JsonResponse
    {
        $this->authorizeTeam($request, $policy);

        EscalationStep::create([
            ...$request->validated(),
            'escalation_policy_id' => $policy->id,
        ]);

        return EscalationPolicyResource::make($policy->refresh()->load('steps'))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Remove a step from the policy's paging chain.
     */
    public function removeStep(Request $request, EscalationPolicy $policy, EscalationStep $step): Response
    {
        $this->authorizeTeam($request, $policy);
        $this->authorizeStepOwnership($policy, $step);

        $step->delete();

        return response()->noContent();
    }

    /**
     * Bulk-update `position` for the policy's step chain.
     *
     * Mirrors {@see OnCallController::reorderRotations()}: every incoming id
     * is validated against the policy's own steps before any write, returning
     * 404 for a foreign id to stay consistent with the rest of this
     * team-scoped controller.
     */
    public function reorderSteps(Request $request, EscalationPolicy $policy): Response
    {
        $this->authorizeTeam($request, $policy);

        $validated = $request->validate([
            'order' => [
                'required',
                'array',
                'min:1',
            ],
            'order.*.id' => [
                'required',
                'string',
            ],
            'order.*.position' => [
                'required',
                'integer',
                'min:0',
            ],
        ]);

        /** @var array<int, array{id: string, position: int}> $order */
        $order = $validated['order'];

        $incomingIds = array_map(static fn (array $row): string => (string) $row['id'], $order);
        $ownedIds = $policy->steps()->pluck('id')->map(static fn ($v) => (string) $v)->all();
        foreach ($incomingIds as $id) {
            abort_unless(in_array($id, $ownedIds, true), HttpResponse::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($order, $policy): void {
            foreach ($order as $row) {
                $policy->steps()
                    ->whereKey((string) $row['id'])
                    ->update(['position' => (int) $row['position']]);
            }
        });

        return response()->noContent();
    }

    /**
     * Guard team ownership, masking a foreign policy as 404.
     *
     * A 403 would confirm the policy exists; the 404 mask keeps the
     * existence of another team's policies hidden.
     */
    protected function authorizeTeam(Request $request, EscalationPolicy $policy): void
    {
        abort_if(
            $policy->team_id !== $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * Guard that a step belongs to the routed policy, masking a step from
     * another policy as 404.
     */
    protected function authorizeStepOwnership(EscalationPolicy $policy, EscalationStep $step): void
    {
        abort_if($step->escalation_policy_id !== $policy->id, HttpResponse::HTTP_NOT_FOUND);
    }
}
