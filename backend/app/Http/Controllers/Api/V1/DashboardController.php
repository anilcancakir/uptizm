<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\MonitorResource;
use App\Models\AiSuggestion;
use App\Models\Incident;
use App\Models\Monitor;
use App\Services\Ai\LaravelAiTriageGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Read-only aggregate endpoints that power the Flutter dashboard view. Each
 * method is a single team-scoped query, intentionally thin so the Flutter
 * polling layer can call them in parallel without pulling a heavier
 * controller in as a dependency. `aiInbox` shapes the team's pending AI
 * suggestions into the same incident-summary contract the dashboard decodes.
 */
class DashboardController extends Controller
{
    /**
     * Monitor counts by last-seen status, average last response time, and
     * the count of incidents still open, for the caller's current team.
     */
    public function stats(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $statusCounts = Monitor::query()
            ->where('team_id', $teamId)
            ->selectRaw('last_status, count(*) as total')
            ->groupBy('last_status')
            ->pluck('total', 'last_status');

        $avgResponseMs = Monitor::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_response_ms')
            ->avg('last_response_ms');

        $openIncidents = Incident::query()
            ->where('team_id', $teamId)
            ->whereIn('lifecycle', $this->activeLifecycleValues())
            ->count();

        return response()->json([
            'data' => [
                'monitors_up' => (int) ($statusCounts[MonitorStatus::Up->value] ?? 0),
                'monitors_down' => (int) ($statusCounts[MonitorStatus::Down->value] ?? 0),
                'monitors_degraded' => (int) ($statusCounts[MonitorStatus::Degraded->value] ?? 0),
                'monitors_paused' => (int) ($statusCounts[MonitorStatus::Paused->value] ?? 0),
                'avg_response_ms' => $avgResponseMs !== null ? (int) round($avgResponseMs) : null,
                'open_incidents' => $openIncidents,
            ],
        ]);
    }

    /**
     * Unresolved incidents for the caller's current team, newest first.
     * Reuses {@see IncidentResource} for the wire shape (Steps 16/18) so
     * enum unwrapping and date formatting stay in one place.
     */
    public function activeIncidents(Request $request): AnonymousResourceCollection
    {
        $incidents = Incident::query()
            ->where('team_id', $request->user()->current_team_id)
            ->whereIn('lifecycle', $this->activeLifecycleValues())
            ->with(['monitors', 'updates'])
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();

        return IncidentResource::collection($incidents);
    }

    /**
     * The team's monitors and their last-known health status, used to
     * render the dashboard's monitor list without a per-monitor request.
     * Reuses {@see MonitorResource} (Step 16) for the wire shape.
     */
    public function monitorsSnapshot(Request $request): AnonymousResourceCollection
    {
        $monitors = Monitor::query()
            ->where('team_id', $request->user()->current_team_id)
            ->orderBy('name')
            ->get();

        return MonitorResource::collection($monitors);
    }

    /**
     * AI suggestion inbox: the team's pending, non-expired suggestions, each
     * shaped into the Flutter {@see Incident} summary contract so
     * the dashboard can render them through its existing incident decoder.
     */
    public function aiInbox(Request $request): JsonResponse
    {
        $suggestions = AiSuggestion::query()
            ->forTeam($request->user()->current_team_id)
            ->pending()
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->with('monitor')
            ->latest()
            ->get();

        return response()->json([
            'data' => $suggestions
                ->map(fn (AiSuggestion $suggestion): array => $this->shapeSuggestion($suggestion))
                ->all(),
        ]);
    }

    /**
     * Shape one suggestion into the incident-summary map the Flutter
     * `IncidentSummary.fromMap` decodes.
     *
     * The monitor name resolves from the `monitors[]` list keyed by
     * `primary_monitor_id` (not a top-level field), matching the incident
     * wire shape. The `ai` sub-object carries the confidence + narration.
     *
     * Defence in depth on the untrusted narration: the `recommendation` is
     * already allowlist-cleaned upstream (see
     * {@see LaravelAiTriageGateway::sanitizeRecommendation()}),
     * so it is emitted as plain text, and the raw `evidence` jsonb is
     * deliberately never serialized into the client payload.
     *
     * @return array<string, mixed>
     */
    protected function shapeSuggestion(AiSuggestion $suggestion): array
    {
        $monitor = $suggestion->monitor;
        $componentStatus = $monitor->last_status?->value ?? MonitorStatus::Paused->value;

        return [
            'id' => $suggestion->id,
            'started_at' => $suggestion->created_at?->toIso8601String(),
            'ai_owned' => true,
            'signal_source' => SignalSource::AiAnomaly->value,
            'primary_monitor_id' => $monitor->id,
            'monitors' => [
                [
                    'monitor_id' => $monitor->id,
                    'name' => $monitor->name,
                    'component_status_at_start' => $componentStatus,
                    'component_status_current' => $componentStatus,
                ],
            ],
            'ai' => [
                'confidence' => $suggestion->confidence->value,
                'tldr' => $suggestion->recommendation,
                'trigger' => 'anomaly',
            ],
        ];
    }

    /**
     * Lifecycle string values still considered open, per
     * {@see IncidentStatus::isActive()}.
     *
     * @return array<int, string>
     */
    protected function activeLifecycleValues(): array
    {
        return Collection::make(IncidentStatus::cases())
            ->filter(fn (IncidentStatus $status): bool => $status->isActive())
            ->map(fn (IncidentStatus $status): string => $status->value)
            ->values()
            ->all();
    }
}
