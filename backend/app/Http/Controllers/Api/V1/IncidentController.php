<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Concerns\PagesCollections;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignIncidentRequest;
use App\Http\Requests\IncidentNoteRequest;
use App\Http\Requests\PostIncidentUpdateRequest;
use App\Http\Requests\SaveIncidentPostmortemRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Monitoring\IncidentWriteService;
use App\Support\SearchText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped incident API: the read-only list/show surface plus the
 * operator incident-write actions (create, resolve, acknowledge, reopen,
 * post-update, assign, postmortem), each a thin delegation onto
 * {@see IncidentWriteService}. The
 * automated path still opens/resolves incidents via {@see
 * \App\Services\Monitoring\ThresholdEvaluator}; this controller only exposes
 * the human counterpart.
 */
class IncidentController extends Controller
{
    use PagesCollections;

    /**
     * Relations every single-incident payload eager-loads. `assignee` is in the
     * set because {@see IncidentResource} omits the key entirely when the
     * relation is unloaded, and the client reads the assignment from the
     * incident (never from local state), so an unloaded relation would read as
     * "unassigned".
     *
     * @var array<int, string>
     */
    protected const array DETAIL_RELATIONS = [
        'monitors',
        'updates',
        'assignee',
    ];

    public function __construct(
        protected IncidentWriteService $incidentWriteService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Incident::query()
            ->where('team_id', $request->user()->current_team_id)
            ->with(self::DETAIL_RELATIONS);

        // Filter by the affected monitor, matching either the denormalized
        // primary hint or the full affected-component pivot.
        $monitorId = $request->query('monitor_id');
        if ($monitorId !== null) {
            $query->where(function ($q) use ($monitorId): void {
                $q->where('primary_monitor_id', $monitorId)
                    ->orWhereHas('monitors', fn ($m) => $m->where('monitors.id', $monitorId));
            });
        }

        $lifecycle = $request->query('lifecycle');
        if ($lifecycle !== null) {
            $query->where('lifecycle', $lifecycle);
        }

        // `?open=1` is the list screen's Active tab: every stage except the
        // terminal one. It is a separate parameter from `lifecycle` rather than
        // a magic value for it, because the tab is a SET of stages and reusing
        // the single-stage parameter for it would make `lifecycle=active` mean
        // something the enum does not contain.
        if ($request->boolean('open')) {
            $query->where('lifecycle', '!=', IncidentStatus::Resolved->value);
        }

        // `?ai_owned=1` is the list screen's AI tab. Present-or-absent rather
        // than a boolean read on both sides: `boolean()` answers false for a
        // missing parameter, so a `where('ai_owned', false)` here would turn
        // every unfiltered request into "show me the ones AI does not own".
        if ($request->has('ai_owned')) {
            $query->where('ai_owned', $request->boolean('ai_owned'));
        }

        // Free-text search over the incident title. The client filtered this in
        // Dart over a roster it believed was complete; with one page in hand it
        // would search whatever 25 rows it happened to hold.
        $search = $request->query('q');

        if (is_string($search) && trim($search) !== '') {
            // Searched against `title_search`, NOT `title`. The stored title is
            // the English sentence; the operator is reading `title_key` +
            // `title_params` rendered in their own language, so matching the
            // column would make every automatically opened incident
            // unmatchable by the words on their screen. `title_search` carries
            // both renders plus the monitor's name.
            //
            // Both sides are folded by the same PHP function, which is what
            // makes this behave identically on the two engines the suite runs
            // against: SQLite's LIKE is case-insensitive for ASCII and
            // PostgreSQL's is case-sensitive, so lowering in SQL meant picking
            // whose LOWER you got, and neither one implements the Turkish rule
            // that makes `İstanbul` findable by `istanbul`.
            //
            // The ESCAPE clause survives that change and is still not optional:
            // PostgreSQL treats `\` as the default LIKE escape, SQLite has NO
            // default one, so without it the backslashes `escapeLike` adds
            // would be matched literally and a search for `%` would find
            // nothing rather than everything.
            $query->whereRaw(
                "incidents.title_search LIKE ? ESCAPE '\\'",
                ['%'.$this->escapeLike(SearchText::fold(trim($search))).'%'],
            );
        }

        $incidents = $this->cursorOrder($query, 'started_at')
            ->cursorPaginate($this->perPage($request, 25, 100));

        return IncidentResource::collection($incidents)
            ->additional([
                'meta' => $this->headlineCounts($request->user()->current_team_id),
            ]);
    }

    /**
     * The four counts the list header states, ACROSS THE TEAM.
     *
     * Deliberately unaffected by the filters above, and that is the whole point
     * of computing them here rather than in the client. They answer "how many
     * are open", not "how many of what you are looking at are open": the screen
     * used to derive all four from the rows it happened to hold, so a team with
     * sixty incidents reported on its first twenty-five, and selecting the
     * Resolved tab made it report "0 active" while three were burning.
     *
     * One query rather than four, and `SUM(CASE ...)` rather than the `FILTER`
     * clause: this suite runs against SQLite and PostgreSQL, and only one of
     * them has had `FILTER` for its whole supported range.
     *
     * @return array<string, int>
     */
    protected function headlineCounts(?string $teamId): array
    {
        $resolved = IncidentStatus::Resolved->value;

        $row = Incident::query()
            ->where('team_id', $teamId)
            ->selectRaw('SUM(CASE WHEN lifecycle != ? THEN 1 ELSE 0 END) AS open_total', [$resolved])
            ->selectRaw(
                'SUM(CASE WHEN lifecycle != ? AND severity = ? THEN 1 ELSE 0 END) AS critical_total',
                [$resolved, IncidentSeverity::Critical->value],
            )
            ->selectRaw('SUM(CASE WHEN ai_owned = ? THEN 1 ELSE 0 END) AS ai_total', [true])
            ->selectRaw('SUM(CASE WHEN lifecycle = ? THEN 1 ELSE 0 END) AS resolved_total', [$resolved])
            ->first();

        // `SUM` over no rows is NULL, not 0: a team with no incidents at all
        // would otherwise ship four nulls and the client would render them as
        // absent rather than as none.
        return [
            'open_total' => (int) ($row->open_total ?? 0),
            'critical_total' => (int) ($row->critical_total ?? 0),
            'ai_total' => (int) ($row->ai_total ?? 0),
            'resolved_total' => (int) ($row->resolved_total ?? 0),
        ];
    }

    /**
     * Escape the wildcards in a user-supplied `LIKE` term.
     *
     * Without this a search for `%` matches every row and one for `_` matches
     * any single character, so the operator's own text silently stops being a
     * literal.
     */
    protected function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    public function show(Request $request, Incident $incident): IncidentResource
    {
        $this->authorizeTeam($request, $incident);

        $incident->load([
            'monitors',
            'assignee',
            'updates' => fn ($q) => $q->orderBy('display_at'),
        ]);

        return IncidentResource::make($incident);
    }

    /**
     * Open a manual incident for a monitor owned by the current team.
     */
    public function store(StoreIncidentRequest $request): JsonResponse
    {
        $monitor = Monitor::query()->findOrFail($request->validated('monitor_id'));

        $incident = $this->incidentWriteService->createManual(
            monitor: $monitor,
            severity: IncidentSeverity::from($request->validated('severity')),
            title: $request->validated('title'),
            author: $request->user()->name,
            message: $request->validated('message'),
            // Absent means yes: the form has shown this switch on by default
            // since the screen was written.
            notify: $request->boolean('notify', true),
            // `filled` rather than `has`: an explicit null means no override.
            impact: $request->filled('impact')
                ? IncidentImpact::from((string) $request->validated('impact'))
                : null,
        );

        // 201 only when something was actually created. `createManual()` dedupes
        // by design: a monitor that already has an active incident is not opened
        // a second time, and the existing one comes back untouched. Answering
        // 201 for that told every caller a row had been made, and the app
        // believed it: the operator filled the form, pressed Open incident,
        // landed on the list, and found nothing new, because there was nothing
        // new. 200 is the honest code for "here it is, it already existed".
        return IncidentResource::make($incident->load(self::DETAIL_RELATIONS))
            ->response()
            ->setStatusCode(
                $incident->wasRecentlyCreated
                    ? HttpResponse::HTTP_CREATED
                    : HttpResponse::HTTP_OK,
            );
    }

    /**
     * Resolve an active incident, independent of the monitor's live health.
     */
    public function resolve(IncidentNoteRequest $request, Incident $incident): IncidentResource
    {
        $this->authorizeTeam($request, $incident);

        $incident = $this->incidentWriteService->resolve(
            $incident,
            author: $request->user()->name,
            message: $request->validated('message'),
        );

        return IncidentResource::make($incident->load(self::DETAIL_RELATIONS));
    }

    /**
     * Acknowledge a freshly-detected incident, moving it to investigating.
     */
    public function acknowledge(IncidentNoteRequest $request, Incident $incident): IncidentResource
    {
        $this->authorizeTeam($request, $incident);

        $incident = $this->incidentWriteService->acknowledge(
            $incident,
            author: $request->user()->name,
            message: $request->validated('message'),
        );

        return IncidentResource::make($incident->load(self::DETAIL_RELATIONS));
    }

    /**
     * Reopen a resolved incident, returning it to the active investigating state.
     */
    public function reopen(IncidentNoteRequest $request, Incident $incident): IncidentResource
    {
        $this->authorizeTeam($request, $incident);

        $incident = $this->incidentWriteService->reopen(
            $incident,
            author: $request->user()->name,
            message: $request->validated('message'),
        );

        return IncidentResource::make($incident->load(self::DETAIL_RELATIONS));
    }

    /**
     * Hand the incident to a team member, or clear the assignment when
     * `assignee_id` is null. The request rule guarantees the id belongs to the
     * team's roster, so a non-member is rejected as a 422 field error.
     */
    public function assign(AssignIncidentRequest $request, Incident $incident): IncidentResource
    {
        $this->authorizeTeam($request, $incident);

        $assigneeId = $request->validated('assignee_id');

        $incident = $this->incidentWriteService->assign(
            $incident,
            assignee: $assigneeId === null ? null : User::query()->findOrFail($assigneeId),
            author: $request->user()->name,
        );

        return IncidentResource::make($incident->load(self::DETAIL_RELATIONS));
    }

    /**
     * Store the incident's postmortem body and, when `publish` is set, stamp it
     * as published so the public status page renders it.
     */
    public function postmortem(SaveIncidentPostmortemRequest $request, Incident $incident): IncidentResource
    {
        $this->authorizeTeam($request, $incident);

        $incident = $this->incidentWriteService->savePostmortem(
            $incident,
            body: $request->validated('body'),
            publish: (bool) $request->validated('publish', false),
            author: $request->user()->name,
        );

        return IncidentResource::make($incident->load(self::DETAIL_RELATIONS));
    }

    /**
     * Append an operator update to the incident's unified timeline without
     * necessarily changing its lifecycle.
     */
    public function postUpdate(PostIncidentUpdateRequest $request, Incident $incident): JsonResponse
    {
        $this->authorizeTeam($request, $incident);

        $status = $request->validated('status');

        $update = $this->incidentWriteService->postUpdate(
            $incident,
            message: $request->validated('message'),
            author: $request->user()->name,
            isPublic: (bool) $request->validated('is_public', true),
            status: $status !== null ? IncidentStatus::from($status) : null,
        );

        return IncidentUpdateResource::make($update)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    protected function authorizeTeam(Request $request, Incident $incident): void
    {
        abort_unless(
            $incident->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
