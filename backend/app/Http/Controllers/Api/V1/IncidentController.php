<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Read-only, team-scoped incident API: list and show. Incidents are opened
 * by {@see \App\Services\Monitoring\ThresholdEvaluator}, never by this
 * controller, so there is deliberately no store/update action here.
 */
class IncidentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Incident::query()
            ->where('team_id', $request->user()->current_team_id)
            ->with(['monitors']);

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

    protected function authorizeTeam(Request $request, Incident $incident): void
    {
        abort_unless(
            $incident->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
