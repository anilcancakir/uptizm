<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOnCallOverrideRequest;
use App\Http\Requests\StoreOnCallRotationRequest;
use App\Http\Requests\StoreOnCallScheduleRequest;
use App\Http\Requests\UpdateOnCallScheduleRequest;
use App\Http\Resources\OnCallScheduleResource;
use App\Models\OnCallOverride;
use App\Models\OnCallRotation;
use App\Models\OnCallSchedule;
use App\Services\OnCall\RotationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped CRUD for {@see OnCallSchedule} plus its rotation ring and
 * temporary overrides, and the "who is on call right now" read.
 *
 * Mirrors {@see MonitorController}'s team-scope + 404-mask pattern (cross-team
 * access is masked as 404, never 403, so the existence of another team's
 * schedules never leaks). Rotation/override management operates on a
 * schedule already owned by the current team, so every nested action
 * re-checks {@see self::authorizeTeam()} before touching a child row, and
 * the child row's own `schedule_id` is checked against the routed schedule
 * so one schedule's rotations/overrides can never be edited through another
 * schedule's URL.
 */
class OnCallController extends Controller
{
    public function __construct(
        protected RotationResolver $rotationResolver,
    ) {}

    /**
     * List the current team's on-call schedules, newest first, paginated.
     *
     * The rotation ring and the overrides are eager-loaded so one call returns
     * a complete schedule list: the on-call screen renders who is in the ring
     * and which overrides are live straight from this payload, and a client
     * that had to follow up with a `show()` per row would either fan out N+1
     * requests or fall back to inventing a ring it never received.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $schedules = OnCallSchedule::query()
            ->where('team_id', $request->user()->current_team_id)
            ->with(['rotations.user', 'overrides.user'])
            ->orderByDesc('created_at')
            ->paginate();

        return OnCallScheduleResource::collection($schedules);
    }

    /**
     * Create an on-call schedule for the current team.
     */
    public function store(StoreOnCallScheduleRequest $request): JsonResponse
    {
        $schedule = OnCallSchedule::create([
            ...$request->validated(),
            'team_id' => $request->user()->current_team_id,
        ]);

        return OnCallScheduleResource::make($schedule)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a schedule owned by the current team, with its rotation ring and
     * overrides.
     */
    public function show(Request $request, OnCallSchedule $schedule): OnCallScheduleResource
    {
        $this->authorizeTeam($request, $schedule);

        return OnCallScheduleResource::make($schedule->load(['rotations.user', 'overrides.user']));
    }

    /**
     * Update a schedule owned by the current team.
     */
    public function update(UpdateOnCallScheduleRequest $request, OnCallSchedule $schedule): OnCallScheduleResource
    {
        $this->authorizeTeam($request, $schedule);

        $schedule->update($request->validated());

        return OnCallScheduleResource::make($schedule->refresh());
    }

    /**
     * Delete a schedule owned by the current team.
     */
    public function destroy(Request $request, OnCallSchedule $schedule): Response
    {
        $this->authorizeTeam($request, $schedule);

        $schedule->delete();

        return response()->noContent();
    }

    /**
     * Add a responder slot to the schedule's rotation ring.
     */
    public function addRotation(StoreOnCallRotationRequest $request, OnCallSchedule $schedule): JsonResponse
    {
        $this->authorizeTeam($request, $schedule);

        OnCallRotation::create([
            ...$request->validated(),
            'schedule_id' => $schedule->id,
        ]);

        return OnCallScheduleResource::make($schedule->refresh()->load(['rotations.user', 'overrides.user']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Remove a responder slot from the schedule's rotation ring.
     */
    public function removeRotation(Request $request, OnCallSchedule $schedule, OnCallRotation $rotation): Response
    {
        $this->authorizeTeam($request, $schedule);
        $this->authorizeRotationOwnership($schedule, $rotation);

        $rotation->delete();

        return response()->noContent();
    }

    /**
     * Bulk-update `position` for the schedule's rotation ring.
     *
     * Mirrors {@see StatusPageController::reorderMonitors()}: every incoming
     * id is validated against the schedule's own rotation slots before any
     * write, returning 404 for a foreign id to stay consistent with the
     * rest of this team-scoped controller.
     */
    public function reorderRotations(Request $request, OnCallSchedule $schedule): Response
    {
        $this->authorizeTeam($request, $schedule);

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
        $ownedIds = $schedule->rotations()->pluck('id')->map(static fn ($v) => (string) $v)->all();
        foreach ($incomingIds as $id) {
            abort_unless(in_array($id, $ownedIds, true), HttpResponse::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($order, $schedule): void {
            foreach ($order as $row) {
                $schedule->rotations()
                    ->whereKey((string) $row['id'])
                    ->update(['position' => (int) $row['position']]);
            }
        });

        return response()->noContent();
    }

    /**
     * Add a temporary responder override to the schedule.
     */
    public function addOverride(StoreOnCallOverrideRequest $request, OnCallSchedule $schedule): JsonResponse
    {
        $this->authorizeTeam($request, $schedule);

        OnCallOverride::create([
            ...$request->validated(),
            'schedule_id' => $schedule->id,
        ]);

        return OnCallScheduleResource::make($schedule->refresh()->load(['rotations.user', 'overrides.user']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Remove a temporary responder override from the schedule.
     */
    public function removeOverride(Request $request, OnCallSchedule $schedule, OnCallOverride $override): Response
    {
        $this->authorizeTeam($request, $schedule);
        $this->authorizeOverrideOwnership($schedule, $override);

        $override->delete();

        return response()->noContent();
    }

    /**
     * Resolve who is on call right now.
     *
     * A `schedule_id` query param resolves a single schedule (404-masked
     * when it belongs to another team); omitting it resolves every schedule
     * owned by the current team.
     */
    public function current(Request $request): JsonResponse
    {
        $scheduleId = $request->query('schedule_id');

        if ($scheduleId !== null) {
            $schedule = OnCallSchedule::query()->findOrFail($scheduleId);
            $this->authorizeTeam($request, $schedule);

            return response()->json([
                'data' => $this->resolvedResponder($schedule),
            ]);
        }

        $schedules = OnCallSchedule::query()
            ->where('team_id', $request->user()->current_team_id)
            ->get();

        return response()->json([
            'data' => $schedules
                ->map(fn (OnCallSchedule $schedule): array => $this->resolvedResponder($schedule))
                ->all(),
        ]);
    }

    /**
     * Guard team ownership, masking a foreign schedule as 404.
     *
     * A 403 would confirm the schedule exists; the 404 mask keeps the
     * existence of another team's schedules hidden.
     */
    protected function authorizeTeam(Request $request, OnCallSchedule $schedule): void
    {
        abort_if(
            $schedule->team_id !== $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * Guard that a rotation slot belongs to the routed schedule, masking a
     * slot from another schedule as 404.
     */
    protected function authorizeRotationOwnership(OnCallSchedule $schedule, OnCallRotation $rotation): void
    {
        abort_if($rotation->schedule_id !== $schedule->id, HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Guard that an override belongs to the routed schedule, masking an
     * override from another schedule as 404.
     */
    protected function authorizeOverrideOwnership(OnCallSchedule $schedule, OnCallOverride $override): void
    {
        abort_if($override->schedule_id !== $schedule->id, HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * The resolved responder for a schedule, shaped for the wire.
     *
     * @return array<string, mixed>
     */
    protected function resolvedResponder(OnCallSchedule $schedule): array
    {
        $user = $this->rotationResolver->resolve($schedule);

        return [
            'schedule_id' => $schedule->id,
            'user' => $user === null ? null : [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
    }
}
