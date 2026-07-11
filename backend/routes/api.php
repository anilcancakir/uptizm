<?php

use App\Http\Controllers\Api\V1\AiSuggestionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\MonitorCheckController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Controllers\Api\V1\MonitorMetricController;
use App\Http\Controllers\Api\V1\StatusPageController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API v1 — Public routes (no auth).
|--------------------------------------------------------------------------
*/

/*
 * Local fixtures endpoint for exercising the metric extraction preview.
 * Returns a randomised payload covering every metric source + type combo:
 *   - JSONPath targets: status, nested numeric, nested string, array length.
 *   - Regex targets: embedded "active: N" and "build #X" strings in `notes`.
 *   - XPath target: `xml` string carrying a mini document.
 *   - Header targets: X-Response-Time (numeric), X-Cache (string).
 *   - HTTP status target: 200 most of the time, 500 / 503 occasionally.
 */
Route::get('public/fixtures/random', function (): JsonResponse {
    $statusPool = ['up', 'warn', 'down'];
    $cachePool = ['HIT', 'MISS', 'STALE'];
    $regionPool = ['eu-central', 'us-east', 'ap'];
    $httpStatusPool = [200, 200, 200, 200, 500, 503];

    $httpStatus = $httpStatusPool[array_rand($httpStatusPool)];
    $active = random_int(0, 500);
    $build = random_int(1000, 9999);
    $latency = random_int(20, 1200);
    $cache = $cachePool[array_rand($cachePool)];
    $region = $regionPool[array_rand($regionPool)];

    $payload = [
        'status' => $statusPool[array_rand($statusPool)],
        'status_code' => $httpStatus,
        'healthy' => (bool) random_int(0, 1),
        'region' => $region,
        'timestamp' => now()->toIso8601String(),
        'data' => [
            'database' => [
                'size_mb' => round(random_int(10_000, 500_000) / 100, 2),
                'connections' => random_int(0, 200),
            ],
            'cache' => [
                'hit_rate' => round(random_int(0, 100) / 100, 2),
                'entries' => random_int(0, 50_000),
            ],
            'latency_ms' => $latency,
            'active_users' => $active,
            'message' => "active: {$active}, build #{$build}, region {$region}",
            'items' => array_fill(0, random_int(0, 7), [
                'id' => random_int(1, 9999),
                'value' => random_int(1, 100),
            ]),
        ],
        'notes' => "service stable — active: {$active} — build #{$build}",
        'xml' => "<response><status>{$statusPool[array_rand($statusPool)]}</status>"
            ."<latency>{$latency}</latency></response>",
    ];

    return response()
        ->json($payload, $httpStatus)
        ->header('X-Response-Time', (string) $latency)
        ->header('X-Cache', $cache)
        ->header('X-Request-Id', (string) Str::uuid());
})->name('api.v1.public.fixtures.random');

/*
|--------------------------------------------------------------------------
| API v1 — Authenticated routes.
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('monitors', MonitorController::class);
    Route::post('monitors/{monitor}/pause', [MonitorController::class, 'pause'])
        ->name('api.v1.monitors.pause');
    Route::post('monitors/{monitor}/resume', [MonitorController::class, 'resume'])
        ->name('api.v1.monitors.resume');
    Route::post('monitors/{monitor}/test', [MonitorController::class, 'test'])
        ->name('api.v1.monitors.test');

    Route::get('monitors/{monitor}/checks', [MonitorCheckController::class, 'index'])
        ->name('api.v1.monitors.checks.index');
    Route::get('monitors/{monitor}/checks/{check}', [MonitorCheckController::class, 'show'])
        ->name('api.v1.monitors.checks.show');
    Route::get('monitors/{monitor}/uptime', [MonitorCheckController::class, 'uptime'])
        ->name('api.v1.monitors.uptime');
    Route::get('monitors/{monitor}/response-times', [MonitorCheckController::class, 'responseTimes'])
        ->name('api.v1.monitors.response-times');

    Route::get('monitors/{monitor}/metrics', [MonitorMetricController::class, 'index'])
        ->name('api.v1.monitors.metrics.index');
    Route::post('monitors/{monitor}/metrics', [MonitorMetricController::class, 'store'])
        ->name('api.v1.monitors.metrics.store');
    Route::post('monitors/{monitor}/metrics/preview', [MonitorMetricController::class, 'preview'])
        ->name('api.v1.monitors.metrics.preview');
    Route::match(['patch', 'put'], 'monitors/{monitor}/metrics/reorder', [
        MonitorMetricController::class,
        'reorder',
    ])->name('api.v1.monitors.metrics.reorder');
    Route::put('monitors/{monitor}/metrics/{metric}', [MonitorMetricController::class, 'update'])
        ->name('api.v1.monitors.metrics.update');
    Route::delete('monitors/{monitor}/metrics/{metric}', [MonitorMetricController::class, 'destroy'])
        ->name('api.v1.monitors.metrics.destroy');
    Route::get('monitors/{monitor}/metrics/series', [MonitorMetricController::class, 'batchSeries'])
        ->name('api.v1.monitors.metrics.batch_series');
    Route::get('monitors/{monitor}/metrics/{metric}/series', [MonitorMetricController::class, 'series'])
        ->name('api.v1.monitors.metrics.series');

    Route::get('incidents', [IncidentController::class, 'index'])
        ->name('api.v1.incidents.index');
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])
        ->name('api.v1.incidents.show');

    Route::get('dashboard/stats', [DashboardController::class, 'stats'])
        ->name('api.v1.dashboard.stats');
    Route::get('dashboard/active-incidents', [DashboardController::class, 'activeIncidents'])
        ->name('api.v1.dashboard.active-incidents');
    Route::get('dashboard/monitors-snapshot', [DashboardController::class, 'monitorsSnapshot'])
        ->name('api.v1.dashboard.monitors-snapshot');
    Route::get('dashboard/ai-inbox', [DashboardController::class, 'aiInbox'])
        ->name('api.v1.dashboard.ai-inbox');

    Route::post('ai-suggestions/{suggestion}/accept', [AiSuggestionController::class, 'accept'])
        ->name('api.v1.ai-suggestions.accept');
    Route::post('ai-suggestions/{suggestion}/dismiss', [AiSuggestionController::class, 'dismiss'])
        ->name('api.v1.ai-suggestions.dismiss');

    // `StatusPage` binds its route key to `slug` for the public `/s/{slug}`
    // surface, so the team-scoped admin routes below bind `{statusPage}` back
    // to `id` explicitly.
    Route::apiResource('status-pages', StatusPageController::class)
        ->parameters(['status-pages' => 'statusPage:id']);
    Route::match(['patch', 'put'], 'status-pages/{statusPage:id}/monitors/reorder', [
        StatusPageController::class,
        'reorderMonitors',
    ])->name('api.v1.status-pages.monitors.reorder');
    Route::post('status-pages/{statusPage:id}/monitors', [StatusPageController::class, 'attachMonitor'])
        ->name('api.v1.status-pages.monitors.attach');
    Route::delete('status-pages/{statusPage:id}/monitors/{monitor}', [StatusPageController::class, 'detachMonitor'])
        ->name('api.v1.status-pages.monitors.detach');
});
