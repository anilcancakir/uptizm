<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StatusPage\SubscribeController;
use App\Http\Requests\StoreStatusPageRequest;
use App\Http\Requests\UpdateStatusPageRequest;
use App\Http\Resources\StatusPageResource;
use App\Http\Resources\StatusPageSubscriberResource;
use App\Jobs\RenderStatusPagePreview;
use App\Jobs\TranslateStatusPageText;
use App\Mail\StatusPageSubscribeConfirmation;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\StatusPages\StatusPageCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 *
 * Every write that changes what the public page LOOKS LIKE additionally queues a
 * fresh headless render through {@see self::queuePreviewRender()}, so the
 * editor's artefact tracks the page instead of waiting for someone to press
 * refresh. `store` is deliberately not one of them: see that method.
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
        // Components are eager-loaded here, not just on `show`: the client's list
        // renders each page's component count and overall status badge, and
        // without the relation it had nothing to derive them from and reported
        // "0 components / Operational" for a page whose monitors were down.
        $pages = StatusPage::query()
            ->where('team_id', $request->user()->current_team_id)
            ->with('monitors')
            ->orderByDesc('created_at')
            ->paginate();

        return StatusPageResource::collection($pages);
    }

    /**
     * Create a status page for the current team.
     *
     * Queues NO preview render, unlike every other write here. A page is created
     * with no components at all, so the artefact would be an empty page stored
     * under a customer-view label, and the operator's next action is attaching
     * the first monitor, which renders anyway.
     */
    public function store(StoreStatusPageRequest $request): JsonResponse
    {
        $page = StatusPage::create([
            ...$request->validated(),
            'team_id' => $request->user()->current_team_id,
        ]);

        $this->queueDescriptionTranslations($page);

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

        // Forget this page's own key as well as the ones its monitors appear on.
        // `invalidateForMonitors()` resolves slugs THROUGH the pivot and returns
        // early on an empty set, so a page with no components attached would keep
        // serving its cached read model for up to 60 more seconds. That was
        // harmless while every cached field came from a monitor; it stopped being
        // harmless when `locale` became writable here, because the cached array
        // holds two strings rendered in the OLD language (the banner label and
        // every incident title), so a locale change would show old-language copy
        // under new-language chrome.
        //
        // `forgetPage()` drops EVERY language of the page, which is what this
        // write needs twice over: the edited fields reach all of them, and the
        // one being edited may be the language itself.
        $this->statusPageCache->forgetPage($statusPage->slug);

        $this->statusPageCache->invalidateForMonitors($this->monitorIds($statusPage));

        $this->queuePreviewRender($statusPage);

        $this->queueDescriptionTranslations($statusPage->refresh());

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
     * Queue a headless re-render of the page's public view.
     *
     * The operator's explicit refresh action. Returns 202 with no body on
     * purpose: the render happens in a worker, so the row still holds the
     * PREVIOUS state at this point and returning the resource here would state a
     * status the render is about to contradict. The client polls
     * {@see self::show()} for the outcome.
     *
     * The dispatch itself, and exactly what `afterCommit()` does and does not
     * promise on this path, live in {@see self::queuePreviewRender()}.
     */
    public function renderPreview(Request $request, StatusPage $statusPage): Response
    {
        $this->authorizeTeam($request, $statusPage);

        $this->queuePreviewRender($statusPage);

        return response()->noContent(HttpResponse::HTTP_ACCEPTED);
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

        $this->queuePreviewRender($statusPage);

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

        $this->queuePreviewRender($statusPage);

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

        // Dispatched outside the transaction above, so the render reads committed
        // pivot rows rather than a view a rollback could still discard.
        $this->queuePreviewRender($statusPage);

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
     * Add a subscriber to a status page owned by the current team, through the
     * same double opt-in the public {@see SubscribeController} enforces.
     *
     * There is no trusted add. An operator typing an address proves nothing
     * about whether its owner asked for mail, and this endpoint is reachable by
     * any member of any team, so a free account could otherwise turn its
     * subscriber allowance into that many unsolicited messages from the
     * product's own authenticated sending domain. The row is created UNCONFIRMED
     * with a `confirmed_token`, the same confirmation mail the public path sends
     * is QUEUED to the entered address only, and neither `confirmed_at` nor
     * `opt_in_confirmed_at` is written here: only clicking the link sets those,
     * and the announcement path selects on the latter.
     *
     * Two behaviours predate the consent change and are preserved. The email
     * rule mirrors the public store, and the dedupe on the
     * `(status_page_id, email)` unique constraint returns the existing row
     * rather than leaking a constraint-violation 500 (and sends no second mail,
     * so a repeat add is not a way to re-mail an address). The plan's per-page
     * subscriber cap is enforced before any row or mail exists.
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

        // Enforce the plan's per-page subscriber cap for a genuinely new
        // address (an existing subscriber above is never blocked).
        $team = Team::find($statusPage->team_id);
        $limit = $team !== null ? (new PlanGate)->subscriberLimit($team) : null;
        if ($limit !== null && $statusPage->subscribers()->count() >= $limit) {
            throw ValidationException::withMessages([
                'email' => "This status page has reached its {$limit}-subscriber limit. Upgrade the team's plan to add more.",
            ]);
        }

        // 2. Mint an UNCONFIRMED subscriber, mirroring the public flow's shape
        //    exactly: a confirm token, an unsubscribe token, and no consent
        //    timestamp of either kind until the recipient clicks.
        $subscriber = $statusPage->subscribers()->create([
            'email' => $email,
            'confirmed_token' => Str::random(48),
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now(),
        ]);

        // 3. Queued, not sent inline like the public path: this request is
        //    answered by an Octane worker, and a third-party transport call on
        //    the request path blocks it for the whole handshake.
        Mail::to($email)->queue(new StatusPageSubscribeConfirmation($statusPage, $subscriber));

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
     * Queue a headless re-render of the page's public artefact.
     *
     * Called by the explicit refresh endpoint and by every write that changes
     * what the public page renders. Two properties belong to the CALL SITE and
     * cannot be enforced from in here:
     *
     * ORDER. The call must sit AFTER the caller's
     * {@see StatusPageCache::invalidateForMonitors()}. On a synchronous queue
     * (which is what the test suite runs) the render happens inside this call, so
     * a browser navigating while the page is still served from its 60-second
     * read-through cache would store the PRE-write page under a post-write
     * timestamp: the exact drift this artefact exists to remove.
     *
     * COMMIT. `afterCommit()` promises less here than it looks like it does. None
     * of the current callers holds an open transaction at this point
     * (`reorderMonitors` closes its own first), so the dispatch is immediate
     * today and the flag changes nothing. What it buys is the next caller:
     * wrapping any of these actions in a transaction cannot start feeding the
     * renderer a view a rollback is about to discard, with nobody having to
     * remember this.
     *
     * Dispatching on every write is only affordable because
     * {@see RenderStatusPagePreview} is unique per page until processing starts: a
     * save followed by three attaches queues one render, while an edit made
     * DURING a render queues a follow-up instead of being dropped.
     */
    protected function queuePreviewRender(StatusPage $statusPage): void
    {
        RenderStatusPagePreview::dispatch($statusPage)->afterCommit();
    }

    /**
     * Queue a translation of the page's description into every supported
     * language other than the page's own.
     *
     * This is the sixth translated field and the one that does not look like
     * incident work, so it is easy to leave unwired; the cost of leaving it is
     * silent, because Step 6's read model would resolve a field nothing ever
     * enqueues and every non-default language would render `pending` forever.
     *
     * The source language is the page's own `locale`, falling back to the
     * deployment default exactly as a null column already means everywhere else
     * on this surface. An edit that CHANGES `locale` therefore re-fans from the
     * new source, which is correct: the stored description is now declared to be
     * in that language.
     *
     * {@see TranslateStatusPageText::fanOut()} is a no-op on a page with no
     * description, so an unconditional call here needs no guard of its own.
     */
    protected function queueDescriptionTranslations(StatusPage $statusPage): void
    {
        TranslateStatusPageText::fanOut(
            $statusPage,
            'description',
            $statusPage->locale ?? (string) config('app.default_locale'),
        );
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
