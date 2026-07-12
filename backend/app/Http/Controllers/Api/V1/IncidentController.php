<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentNoteRequest;
use App\Http\Requests\PostIncidentUpdateRequest;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Services\Monitoring\IncidentWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Team-scoped incident API: the read-only list/show surface plus the
 * operator incident-write actions (create, resolve, acknowledge, reopen,
 * post-update), each a thin delegation onto {@see IncidentWriteService}. The
 * automated path still opens/resolves incidents via {@see
 * \App\Services\Monitoring\ThresholdEvaluator}; this controller only exposes
 * the human counterpart.
 */
class IncidentController extends Controller
{
    public function __construct(
        protected IncidentWriteService $incidentWriteService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Incident::query()
            ->where('team_id', $request->user()->current_team_id)
            ->with(['monitors', 'updates']);

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

        $perPage = (int) $request->query('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $incidents = $query
            ->orderByDesc('started_at')
            ->paginate($perPage);

        return IncidentResource::collection($incidents);
    }

    public function show(Request $request, Incident $incident): IncidentResource
    {
        $this->authorizeTeam($request, $incident);

        $incident->load([
            'monitors',
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
        );

        return IncidentResource::make($incident->load(['monitors', 'updates']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
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

        return IncidentResource::make($incident->load(['monitors', 'updates']));
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

        return IncidentResource::make($incident->load(['monitors', 'updates']));
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

        return IncidentResource::make($incident->load(['monitors', 'updates']));
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
