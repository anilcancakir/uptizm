<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StatusPage\SubscribeController;
use App\Http\Requests\StoreStatusPageRequest;
use App\Http\Requests\UpdateStatusPageRequest;
use App\Http\Resources\StatusPageResource;
use App\Http\Resources\StatusPageSubscriberResource;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Services\StatusPages\StatusPageCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped CRUD + monitor-membership management for {@see StatusPage}.
 *
 * Mirrors {@see MonitorController}'s team-scope + 404-mask pattern (cross-team
 * access is masked as 404, never 403, so the existence of another team's page
 * never leaks). Every write that changes what the public page renders
 * (create/update/delete/attach/detach/reorder) busts the read-through cache
 * via {@see StatusPageCache::invalidateForMonitors()} so the public page never
 * serves a stale component list.
 */
class StatusPageController extends Controller
{
    public function __construct(
        protected StatusPageCache $statusPageCache,
    ) {}

    /**
     * List the current team's status pages, newest first, paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $pages = StatusPage::query()
            ->where('team_id', $request->user()->current_team_id)
            ->orderByDesc('created_at')
            ->paginate();

        return StatusPageResource::collection($pages);
    }

    /**
     * Create a status page for the current team.
     */
    public function store(StoreStatusPageRequest $request): JsonResponse
    {
        $page = StatusPage::create([
            ...$request->validated(),
            'team_id' => $request->user()->current_team_id,
        ]);

        return StatusPageResource::make($page)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a single status page owned by the current team, with its
     * component list.
     */
    public function show(Request $request, StatusPage $statusPage): StatusPageResource
    {
        $this->authorizeTeam($request, $statusPage);

        return StatusPageResource::make($statusPage->load('monitors'));
    }

    /**
     * Update a status page owned by the current team.
     */
    public function update(UpdateStatusPageRequest $request, StatusPage $statusPage): StatusPageResource
    {
        $this->authorizeTeam($request, $statusPage);

        $statusPage->update($request->validated());

        $this->statusPageCache->invalidateForMonitors($this->monitorIds($statusPage));

        return StatusPageResource::make($statusPage->refresh()->load('monitors'));
    }

    /**
     * Delete a status page owned by the current team.
     */
    public function destroy(Request $request, StatusPage $statusPage): Response
    {
        $this->authorizeTeam($request, $statusPage);

        // 1. Bust the cache BEFORE deleting: invalidateForMonitors() resolves
        //    the containing page's slug via a status_page_monitors join, and
        //    that pivot cascades away the instant the page is deleted, so
        //    invalidating afterward would find nothing to forget.
        $this->statusPageCache->invalidateForMonitors($this->monitorIds($statusPage));

        $statusPage->delete();

        return response()->noContent();
    }

    /**
     * Attach a monitor (owned by the same team) to the page's component
     * list, or update its pivot fields when already attached.
     */
    public function attachMonitor(Request $request, StatusPage $statusPage): StatusPageResource
    {
        $this->authorizeTeam($request, $statusPage);

        $validated = $request->validate([
            'monitor_id' => [
                'required',
                'string',
                Rule::exists('monitors', 'id')->where('team_id', $statusPage->team_id),
            ],
            'display_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],
            'custom_label' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $statusPage->monitors()->syncWithoutDetaching([
            $validated['monitor_id'] => [
                'display_order' => $validated['display_order'] ?? 0,
                'custom_label' => $validated['custom_label'] ?? null,
            ],
        ]);

        $this->statusPageCache->invalidateForMonitors([$validated['monitor_id']]);

        return StatusPageResource::make($statusPage->load('monitors'));
    }

    /**
     * Remove a monitor from the page's component list.
     */
    public function detachMonitor(Request $request, StatusPage $statusPage, Monitor $monitor): Response
    {
        $this->authorizeTeam($request, $statusPage);

        $statusPage->monitors()->detach($monitor->id);

        $this->statusPageCache->invalidateForMonitors([$monitor->id]);

        return response()->noContent();
    }

    /**
     * Bulk-update `display_order` for the page's attached monitors.
     *
     * Mirrors {@see MonitorMetricController::reorder()}: the reorder sheet
     * sends the full set of ids in the group's new order, and every incoming
     * id is validated against the page's own attached monitors before any
     * write, returning 404 (not 422) for a foreign id to stay consistent with
     * the rest of this team-scoped controller.
     */
    public function reorderMonitors(Request $request, StatusPage $statusPage): Response
    {
        $this->authorizeTeam($request, $statusPage);

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
            'order.*.display_order' => [
                'required',
                'integer',
                'min:0',
            ],
        ]);

        /** @var array<int, array{id: string, display_order: int}> $order */
        $order = $validated['order'];

        $incomingIds = array_map(static fn (array $row): string => (string) $row['id'], $order);
        $ownedIds = $statusPage->monitors()->pluck('monitors.id')->map(static fn ($v) => (string) $v)->all();
        foreach ($incomingIds as $id) {
            abort_unless(in_array($id, $ownedIds, true), HttpResponse::HTTP_NOT_FOUND);
        }

        DB::transaction(function () use ($order, $statusPage): void {
            foreach ($order as $row) {
                $statusPage->monitors()->updateExistingPivot((string) $row['id'], [
                    'display_order' => (int) $row['display_order'],
                ]);
            }
        });

        $this->statusPageCache->invalidateForMonitors($incomingIds);

        return response()->noContent();
    }

    /**
     * List the subscribers of a status page owned by the current team,
     * newest opt-in first.
     */
    public function listSubscribers(Request $request, StatusPage $statusPage): AnonymousResourceCollection
    {
        $this->authorizeTeam($request, $statusPage);

        $subscribers = $statusPage->subscribers()
            ->orderByDesc('subscribed_at')
            ->get();

        return StatusPageSubscriberResource::collection($subscribers);
    }

    /**
     * Direct-add a subscriber to a status page owned by the current team.
     *
     * Unlike the public {@see SubscribeController}
     * double opt-in, an admin add is trusted: the subscriber is created already
     * confirmed (`confirmed_at = now()`, no `confirmed_token`) and NO
     * confirmation mail is sent. The email rule mirrors the public store; the
     * dedupe on the `(status_page_id, email)` unique constraint returns the
     * existing row rather than leaking a constraint-violation 500.
     */
    public function addSubscriber(Request $request, StatusPage $statusPage): JsonResponse
    {
        $this->authorizeTeam($request, $statusPage);

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:254',
            ],
        ]);
        $email = $validated['email'];

        // 1. Dedupe: an admin re-adding an address already on the page returns
        //    that row (200) instead of hitting the unique constraint.
        $existing = $statusPage->subscribers()->where('email', $email)->first();

        if ($existing !== null) {
            return StatusPageSubscriberResource::make($existing)->response();
        }

        // 2. Trusted direct-add: create an already-confirmed subscriber with an
        //    unsubscribe token but no confirm token and no double opt-in mail.
        $subscriber = $statusPage->subscribers()->create([
            'email' => $email,
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now(),
            'confirmed_at' => now(),
        ]);

        return StatusPageSubscriberResource::make($subscriber)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Remove a subscriber from a status page owned by the current team.
     *
     * The subscriber must belong to this page; a subscriber id from a sibling
     * page 404s (the same mask {@see self::authorizeTeam()} applies to a
     * foreign team) so the route can never delete across pages.
     */
    public function removeSubscriber(
        Request $request,
        StatusPage $statusPage,
        StatusPageSubscriber $subscriber,
    ): Response {
        $this->authorizeTeam($request, $statusPage);

        abort_if($subscriber->status_page_id !== $statusPage->id, HttpResponse::HTTP_NOT_FOUND);

        $subscriber->delete();

        return response()->noContent();
    }

    /**
     * Guard team ownership, masking a foreign status page as 404.
     *
     * A 403 would confirm the page exists; the 404 mask keeps the existence
     * of another team's status pages hidden.
     */
    protected function authorizeTeam(Request $request, StatusPage $statusPage): void
    {
        abort_if(
            $statusPage->team_id !== $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * The ids of every monitor currently attached to the page.
     *
     * @return array<int, string>
     */
    protected function monitorIds(StatusPage $statusPage): array
    {
        return $statusPage->monitors()->pluck('monitors.id')->map(static fn ($v) => (string) $v)->all();
    }
}
