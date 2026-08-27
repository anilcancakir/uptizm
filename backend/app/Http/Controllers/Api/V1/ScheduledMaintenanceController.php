<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PagesCollections;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduledMaintenanceRequest;
use App\Http\Requests\UpdateScheduledMaintenanceRequest;
use App\Http\Resources\ScheduledMaintenanceResource;
use App\Jobs\AnnounceScheduledMaintenance;
use App\Jobs\TranslateStatusPageText;
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
    use PagesCollections;

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
        $windows = $this->cursorOrder(
            ScheduledMaintenance::query()
                ->where('team_id', $request->user()->current_team_id)
                ->with(self::DETAIL_RELATIONS),
            'starts_at',
        )->cursorPaginate($this->perPage($request));

        return ScheduledMaintenanceResource::collection($windows);
    }

    /**
     * Plan a maintenance window for the current team, and announce it to the
     * page's confirmed subscribers.
     *
     * The announcement is dispatched from HERE and from nowhere else: an edit
     * must not re-mail a window the subscribers already heard about, and a delete
     * must not mail at all. It is dispatched AFTER the transaction commits, so a
     * worker (or, in the test suite, the sync connection) can never pick the job
     * up while the window and its pivot are still invisible to other
     * connections. Whether the mail actually goes out is the job's own decision:
     * it claims `announced_at` atomically first, which is what makes a retried or
     * re-dispatched job a no-op.
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

        AnnounceScheduledMaintenance::dispatch($window);

        $this->queueTranslations($window);

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

        $this->queueTranslations($maintenance->refresh());

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
     * Queue a translation of the window's title and description into every
     * supported language other than the one it was authored in.
     *
     * Called from `store` and `update` both, unlike the announcement above,
     * because a retitled window has to reach the non-default languages the same
     * way it reaches the default one; the announcement is once-only because a
     * subscriber must not be re-mailed, which is a different question.
     *
     * The source language is the deployment default. A maintenance window
     * carries no locale of its own, and the only language column on this surface
     * is `status_pages.locale`, whose null means exactly this default; the read
     * model treats the page's language as the authored one, so anchoring here
     * keeps the two ends agreeing. The residual case is a page whose canonical
     * language is NOT the deployment default: its own language is translated
     * (the row is written) while the deployment language is not, which the page
     * shows as `pending` rather than as anything wrong.
     */
    protected function queueTranslations(ScheduledMaintenance $maintenance): void
    {
        $sourceLocale = (string) config('app.default_locale');

        TranslateStatusPageText::fanOut($maintenance, 'title', $sourceLocale);
        TranslateStatusPageText::fanOut($maintenance, 'description', $sourceLocale);
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
