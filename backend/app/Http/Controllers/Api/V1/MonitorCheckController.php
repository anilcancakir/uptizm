<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MonitorCheckResource;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\CheckAggregateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Read-only access to a monitor's check history plus the two aggregate
 * endpoints (uptime, response-times) that power the charts on the monitor
 * show screen. Aggregations delegate to {@see CheckAggregateService}; the two
 * this controller uses are `uptimeSummary()` and `responseTimeSamples()` (no
 * broader per-monitor `summary()` with previous-window deltas, unlike the v2
 * reference), so `uptime()` is the only summary-style action. The service also
 * exposes `reliabilitySummary()`, which the monitor show action reads for the
 * error-budget cards; it is deliberately not surfaced here.
 */
class MonitorCheckController extends Controller
{
    public function __construct(
        protected CheckAggregateService $aggregates,
    ) {}

    /**
     * Recent checks for a monitor, newest first, team-scoped.
     */
    public function index(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $this->authorizeMonitor($request, $monitor);

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        $checks = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->orderByDesc('checked_at')
            ->paginate($perPage);

        return MonitorCheckResource::collection($checks);
    }

    /**
     * A single check row, team-scoped through its parent monitor.
     */
    public function show(Request $request, Monitor $monitor, MonitorCheck $check): MonitorCheckResource
    {
        $this->authorizeMonitor($request, $monitor);

        abort_unless(
            $check->monitor_id === $monitor->id,
            HttpResponse::HTTP_NOT_FOUND,
        );

        return MonitorCheckResource::make($check);
    }

    /**
     * Windowed uptime snapshot (range/total/up/down/degraded/uptime_ratio).
     */
    public function uptime(Request $request, Monitor $monitor): JsonResponse
    {
        $this->authorizeMonitor($request, $monitor);

        $summary = $this->aggregates->uptimeSummary($monitor, $this->resolveRange($request));

        return response()->json([
            'data' => (array) $summary,
        ]);
    }

    /**
     * Time-bucketed response-time dots for the chart, reusing
     * {@see MonitorCheckResource} since a bucketed row is a synthetic
     * {@see MonitorCheck} with the same shape.
     */
    public function responseTimes(Request $request, Monitor $monitor): JsonResponse
    {
        $this->authorizeMonitor($request, $monitor);

        // No resource collection here on purpose: an aggregated dot is not a check
        // row, and wrapping ~1,400 of them in synthetic models cost more than the
        // query did. The sampler already emits the exact wire shape, which
        // MonitorResponseTimesControllerTest pins key for key.
        return response()->json([
            'data' => $this->aggregates->responseTimeSamples(
                $monitor,
                $this->resolveRange($request),
            ),
        ]);
    }

    /**
     * Aborts with 404 when the monitor does not belong to the caller's
     * current team, so a stray id never leaks cross-tenant data.
     */
    protected function authorizeMonitor(Request $request, Monitor $monitor): void
    {
        abort_unless(
            $monitor->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * Coerces the `range` query param to one of the supported windows,
     * defaulting to `24h` for anything unrecognized.
     */
    protected function resolveRange(Request $request): string
    {
        $range = (string) $request->query('range', '24h');

        return array_key_exists($range, CheckAggregateService::RANGE_WINDOWS) ? $range : '24h';
    }
}
