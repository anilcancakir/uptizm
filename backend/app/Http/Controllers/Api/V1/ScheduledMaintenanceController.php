<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduledMaintenanceRequest;
use App\Http\Requests\UpdateScheduledMaintenanceRequest;
use App\Http\Resources\ScheduledMaintenanceResource;
use App\Models\ScheduledMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped CRUD for {@see ScheduledMaintenance}, the planned windows the
 * public status page announces and the alert pipeline holds paging for.
 *
 * Scoping follows {@see IncidentController}: `index` filters on the acting
 * user's `current_team_id` and every routed window re-checks
 * {@see self::authorizeTeam()}, which masks another team's window as 404 rather
 * than 403 so its existence never leaks.
 *
 * The affected components ride the `scheduled_maintenance_monitors` pivot. The
 * request validates each submitted id against the caller's own monitors, so the
 * sync below can trust what it is given; the write is wrapped in a transaction
 * because a window whose pivot failed to land would suppress nothing and
 * announce the wrong component set.
 */
class ScheduledMaintenanceController extends Controller
{
    /**
     * Relations every maintenance payload eager-loads.
     *
     * @var array<int, string>
     */
    protected const array DETAIL_RELATIONS = [
        'monitors',
    ];

    /**
     * List the current team's maintenance windows, newest window first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $windows = ScheduledMaintenance::query()
            ->where('team_id', $request->user()->current_team_id)
            ->with(self::DETAIL_RELATIONS)
            ->orderByDesc('starts_at')
            ->paginate();

        return ScheduledMaintenanceResource::collection($windows);
    }

    /**
     * Plan a maintenance window for the current team.
     */
    public function store(StoreScheduledMaintenanceRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $monitorIds = $this->pullMonitorIds($attributes);

        $window = DB::transaction(function () use ($attributes, $monitorIds, $request): ScheduledMaintenance {
            $window = ScheduledMaintenance::create([
                ...$attributes,
                'team_id' => $request->user()->current_team_id,
            ]);

            $window->monitors()->sync($monitorIds ?? []);

            return $window;
        });

        return ScheduledMaintenanceResource::make($window->load(self::DETAIL_RELATIONS))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a window owned by the current team, with its affected components.
     */
    public function show(Request $request, ScheduledMaintenance $maintenance): ScheduledMaintenanceResource
    {
        $this->authorizeTeam($request, $maintenance);

        return ScheduledMaintenanceResource::make($maintenance->load(self::DETAIL_RELATIONS));
    }

    /**
     * Update a window owned by the current team.
     *
     * A payload that omits `monitor_ids` leaves the pivot untouched, so a
     * partial edit (a retitle, a moved window) cannot silently drop the
     * affected components.
     */
    public function update(
        UpdateScheduledMaintenanceRequest $request,
        ScheduledMaintenance $maintenance,
    ): ScheduledMaintenanceResource {
        $this->authorizeTeam($request, $maintenance);

        $attributes = $request->validated();
        $monitorIds = $this->pullMonitorIds($attributes);

        DB::transaction(function () use ($attributes, $monitorIds, $maintenance): void {
            $maintenance->update($attributes);

            if ($monitorIds !== null) {
                $maintenance->monitors()->sync($monitorIds);
            }
        });

        return ScheduledMaintenanceResource::make($maintenance->refresh()->load(self::DETAIL_RELATIONS));
    }

    /**
     * Delete a window owned by the current team. The pivot rows cascade.
     */
    public function destroy(Request $request, ScheduledMaintenance $maintenance): Response
    {
        $this->authorizeTeam($request, $maintenance);

        $maintenance->delete();

        return response()->noContent();
    }

    /**
     * Split the pivot ids out of the validated attribute bag.
     *
     * `monitor_ids` is a relation, not a column, so it must not reach
     * `create()`/`update()`. Null means "the payload said nothing about the
     * components", which the update path treats differently from an empty
     * array (detach everything).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>|null
     */
    protected function pullMonitorIds(array &$attributes): ?array
    {
        if (! array_key_exists('monitor_ids', $attributes)) {
            return null;
        }

        /** @var array<int, string> $monitorIds */
        $monitorIds = $attributes['monitor_ids'];
        unset($attributes['monitor_ids']);

        return $monitorIds;
    }

    /**
     * Guard team ownership, masking a foreign window as 404.
     *
     * A 403 would confirm the window exists; the 404 mask keeps the existence
     * of another team's planned work hidden.
     */
    protected function authorizeTeam(Request $request, ScheduledMaintenance $maintenance): void
    {
        abort_unless(
            $maintenance->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
