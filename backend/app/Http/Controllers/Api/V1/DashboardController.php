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
use App\Models\MonitorCheck;
use App\Services\Ai\LaravelAiTriageGateway;
use App\Services\StatusPages\ComponentDailyUptimeService;
use Carbon\CarbonInterface;
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
     * Monitor counts by last-seen status, average last response time, the
     * count of incidents still open, and the rolling 24h fleet uptime (with
     * its change against the prior 24h), for the caller's current team.
     */
    public function stats(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $statusCounts = Monitor::query()
            ->where('team_id', $teamId)
            ->selectRaw('last_status, count(*) as total')
            ->groupBy('last_status')
            ->pluck('total', 'last_status');

        // A monitor awaiting its first check has a null last_status, so it lands
        // in NONE of the four status buckets. Count it explicitly and report a
        // real total: a client deriving "how many monitors do I have" from the
        // bucket sum read a brand-new team as having zero and offered to add
        // their first endpoint after they had already added several.
        $monitorCounts = Monitor::query()
            ->where('team_id', $teamId)
            ->selectRaw('count(*) as total, count(last_status) as with_status')
            ->first();

        $monitorsTotal = (int) ($monitorCounts->total ?? 0);
        $monitorsPending = $monitorsTotal - (int) ($monitorCounts->with_status ?? 0);

        $avgResponseMs = Monitor::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_response_ms')
            ->avg('last_response_ms');

        $openIncidents = Incident::query()
            ->where('team_id', $teamId)
            ->whereIn('lifecycle', $this->activeLifecycleValues())
            ->count();

        // Live rolling-24h fleet uptime from the raw check stream (the daily
        // rollup lags a day), plus the prior 24h to derive a change. Both are
        // null when the window has no checks, so the client renders "no data"
        // rather than a fabricated 100%.
        $now = now();
        $uptime24h = $this->uptimePercentForWindow($teamId, $now->copy()->subHours(24), $now);
        $uptimePrev24h = $this->uptimePercentForWindow($teamId, $now->copy()->subHours(48), $now->copy()->subHours(24));
        $uptimeDelta = ($uptime24h !== null && $uptimePrev24h !== null)
            ? round($uptime24h - $uptimePrev24h, 2)
            : null;

        return response()->json([
            'data' => [
                'monitors_up' => (int) ($statusCounts[MonitorStatus::Up->value] ?? 0),
                'monitors_down' => (int) ($statusCounts[MonitorStatus::Down->value] ?? 0),
                'monitors_degraded' => (int) ($statusCounts[MonitorStatus::Degraded->value] ?? 0),
                'monitors_paused' => (int) ($statusCounts[MonitorStatus::Paused->value] ?? 0),
                'monitors_pending' => $monitorsPending,
                'monitors_total' => $monitorsTotal,
                'avg_response_ms' => $avgResponseMs !== null ? (int) round($avgResponseMs) : null,
                'open_incidents' => $openIncidents,
                'uptime_24h' => $uptime24h,
                'uptime_24h_delta' => $uptimeDelta,
            ],
        ]);
    }

    /**
     * Fleet uptime percentage over [$from, $to] for a team, computed from the
     * raw check stream as up / (up + down + degraded). Paused checks are
     * excluded (an administrative pause is not a availability failure),
     * matching {@see ComponentDailyUptimeService}.
     *
     * Returns null when the window holds no counted checks, so the caller can
     * distinguish "no data" from a real 100%.
     */
    protected function uptimePercentForWindow($teamId, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $counts = MonitorCheck::query()
            ->where('team_id', $teamId)
            ->whereBetween('checked_at', [$from, $to])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $up = (int) ($counts[MonitorStatus::Up->value] ?? 0);
        $down = (int) ($counts[MonitorStatus::Down->value] ?? 0);
        $degraded = (int) ($counts[MonitorStatus::Degraded->value] ?? 0);
        $total = $up + $down + $degraded;

        if ($total === 0) {
            return null;
        }

        return round(($up / $total) * 100, 2);
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
                // Tri-state on the wire as it is in the column: true confirmed,
                // false the model saw no real deviation, null no model ran. The
                // client marks only the false, so the two silent states must
                // stay distinguishable rather than collapsing to a boolean.
                'confirmed' => $suggestion->confirmed,
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
