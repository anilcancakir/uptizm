<?php

use App\Http\Controllers\Api\V1\AiSuggestionController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DigestController;
use App\Http\Controllers\Api\V1\EscalationPolicyController;
use App\Http\Controllers\Api\V1\IncidentAnalysisController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\IncidentDraftController;
use App\Http\Controllers\Api\V1\MonitorCheckController;
use App\Http\Controllers\Api\V1\MonitorContentController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Controllers\Api\V1\MonitorMetricController;
use App\Http\Controllers\Api\V1\NotificationChannelController;
use App\Http\Controllers\Api\V1\OnCallController;
use App\Http\Controllers\Api\V1\ScheduledMaintenanceController;
use App\Http\Controllers\Api\V1\StatusPageController;
use App\Http\Controllers\Api\V1\StatusPagePreviewImageController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Route parameter patterns: enforce the uuid shape for every parameter that
| addresses a `uuid` primary key.
|--------------------------------------------------------------------------
|
| Without this, an implicit-bound route parameter (or a plain string handed
| to a manual `findOrFail()`) that fails to parse as a uuid still reaches the
| database as a bare string, and PostgreSQL raises
| `22P02: invalid input syntax for type uuid` for the resulting query, which
| surfaces as an uncaught 500 instead of the 404 a malformed identifier
| should answer. `Route::pattern()` rejects the segment before the route
| matches at all, so a malformed value never reaches `SubstituteBindings` or
| a manual lookup.
|
| Registered here, before every route in this file, because
| `Router::addWhereClausesToRoute()` snapshots the global pattern list at the
| MOMENT a route is created (`Router.php:707-714`); a pattern registered
| after a route would not apply to it. That includes the signed
| preview-image route below, which sits outside the `auth:sanctum` group but
| still implicit-binds `{statusPage:id}`.
|
| `Route::pattern()` is process-global (`Router::$patterns`), so it also
| reaches any later-loaded route file that happens to reuse one of these
| parameter names. None of `routes/web.php`, `routes/marketing.php`, or
| `routes/status.php` do today (they address `{slug}`, `{token}`,
| `{locale}`, resolved by an explicit `->where()` lookup rather than
| implicit binding), so there is no live collision, but a new route
| elsewhere reusing one of these names inherits the constraint too.
|
| Deliberately NOT constrained here:
|   - `{contentHash}`: a sha256 hex digest, not a uuid; constrained inline
|     at its own route (`where('contentHash', '[0-9a-f]{64}')` below).
|   - `{run}`: an analyze run id. It IS minted as a uuid
|     (`MonitorController::analyze()`, `Str::uuid()`), but it never reaches a
|     `uuid` column: `AnalyzeRunStore` reads it back with `Cache::get()`
|     against Redis, so a malformed value is already a clean cache miss and
|     `analyzeRun()`'s `abort_if($stored === null, ...)` already 404s it.
|   - Every parameter in `routes/status.php` (`{slug}`, `{token}`): resolved
|     by an explicit `->where('slug', $slug)->first()` lookup rather than
|     implicit binding, so a malformed value already 404s on a miss with no
|     type coercion involved.
*/
// The same expression Route::whereUuid() applies per-parameter; used here as
// a global pattern instead since every name below needs the identical shape.
$uuidPattern = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

Route::patterns([
    'monitor' => $uuidPattern,
    'check' => $uuidPattern,
    'metric' => $uuidPattern,
    'incident' => $uuidPattern,
    'suggestion' => $uuidPattern,
    'statusPage' => $uuidPattern,
    'subscriber' => $uuidPattern,
    'maintenance' => $uuidPattern,
    'schedule' => $uuidPattern,
    'rotation' => $uuidPattern,
    'override' => $uuidPattern,
    'policy' => $uuidPattern,
    'step' => $uuidPattern,
    'channel' => $uuidPattern,
]);

/*
|--------------------------------------------------------------------------
| API v1: Public routes (no auth).
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
        'notes' => "service stable, active: {$active}, build #{$build}",
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
 * The rendered preview PNG of a status page.
 *
 * Deliberately OUTSIDE `auth:sanctum`, and deliberately under `api/`. A Flutter
 * `Image.network()` fetches these bytes itself and attaches no bearer token, so
 * an authenticated route is simply not loadable; and only `api/*` gets CORS
 * headers (`config/cors.php`), which Flutter web needs to read image bytes at
 * all. The signature is therefore the whole authorisation, and it is bound to
 * this exact URL including the page id. See
 * {@see StatusPagePreviewImageController} for what a copied URL grants.
 *
 * The throttle is not decorative: `api/v1` never calls `throttleApi()`, so
 * without it this unauthenticated route would be unbounded.
 */
Route::get('status-pages/{statusPage:id}/preview-image', StatusPagePreviewImageController::class)
    ->middleware([
        'signed',
        'throttle:status-page-preview-image',
    ])
    ->name(StatusPagePreviewImageController::ROUTE_NAME);

/*
|--------------------------------------------------------------------------
| API v1: Authenticated routes.
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    // Throttled by name because one accepted request runs a live relay probe of
    // an operator-supplied URL and queues a job that spends up to two provider
    // calls, and nothing else bounds its RATE: `api/v1` never calls
    // throttleApi(), and the per-team AI budget is a daily cost cap that
    // degrades rather than refusing. The buckets are registered in
    // `bootstrap/app.php`. A second CONCURRENT analyze for one team is a
    // different control and answers 409, from the per-team in-flight lock the
    // controller takes.
    Route::post('monitors/analyze', [MonitorController::class, 'analyze'])
        ->middleware('throttle:'.MonitorController::ANALYZE_LIMITER)
        ->name('api.v1.monitors.analyze');
    // The accepted run's state, for the client's poll. Registered next to the
    // POST rather than beside the other `monitors/{monitor}/...` reads, because
    // its first segment is the LITERAL `analyze` and this file's convention is
    // that a literal is declared ahead of the wildcard it could be swallowed by
    // (see the `incidents/digest` and `content/candidates` notes below).
    //
    // Deliberately NOT carrying `MonitorController::ANALYZE_LIMITER`. That
    // bucket is ten a minute, sized for a human pressing a button; the client
    // polls this every 2500ms as the source of truth for a run that takes up to
    // 150 seconds, which is twenty-four reads a minute for one analyze. The
    // accept cost is the reason the two differ: this is a single cache read
    // against a run the caller's own team owns, with no probe, no provider call
    // and no write.
    Route::get('monitors/analyze/{run}', [MonitorController::class, 'analyzeRun'])
        ->name('api.v1.monitors.analyze.run');
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
    // The archived page-content versions of a monitor, plus the download of one
    // version's original bytes. The address is a CONTENT HASH, not a row id.
    //
    // The constraint below is not decorative. Almost every malformed address is
    // already turned away by the version lookup matching no row, but a stored row
    // carrying a hash that is not 64 lowercase hex (the corrupt row the retention
    // sweep skips) DOES match its own malformed value, and
    // `ContentArchive::blobPath()` throws on it. Without this the endpoint answers
    // 500 for that row instead of 404.
    Route::get('monitors/{monitor}/content', [MonitorContentController::class, 'index'])
        ->name('api.v1.monitors.content.index');
    // The extraction candidates of the newest archived body, for the metric
    // form's candidate browser. Digest rows only, never the archived bytes; the
    // reasoning is in the action's own docblock.
    //
    // Declared BEFORE the `{contentHash}` route below. Today the hash constraint
    // alone would already keep a literal `candidates` segment from binding as a
    // version address, but that makes an endpoint's existence depend on a
    // constraint written for an unrelated reason: loosening it later would turn
    // this route into a 404 with nothing pointing at the cause. Order states the
    // intent instead.
    Route::get('monitors/{monitor}/content/candidates', [MonitorContentController::class, 'candidates'])
        ->middleware('throttle:'.MonitorContentController::SAMPLE_READ_LIMITER)
        ->name('api.v1.monitors.content.candidates');
    Route::get('monitors/{monitor}/content/{contentHash}', [MonitorContentController::class, 'show'])
        ->where('contentHash', '[0-9a-f]{64}')
        ->name('api.v1.monitors.content.show');

    Route::get('monitors/{monitor}/uptime', [MonitorCheckController::class, 'uptime'])
        ->name('api.v1.monitors.uptime');
    Route::get('monitors/{monitor}/response-times', [MonitorCheckController::class, 'responseTimes'])
        ->name('api.v1.monitors.response-times');

    Route::get('monitors/{monitor}/metrics', [MonitorMetricController::class, 'index'])
        ->name('api.v1.monitors.metrics.index');
    Route::post('monitors/{monitor}/metrics', [MonitorMetricController::class, 'store'])
        ->name('api.v1.monitors.metrics.store');
    // Throttled by the SAME limiter as the candidate browser above, because it
    // pays the same cold archive read: the sample it extracts against is the
    // monitor's newest archived blob, read through `ArchivedBodyReader`.
    Route::post('monitors/{monitor}/metrics/preview', [MonitorMetricController::class, 'preview'])
        ->middleware('throttle:'.MonitorContentController::SAMPLE_READ_LIMITER)
        ->name('api.v1.monitors.metrics.preview');
    // AI-proposed metrics for an already-running monitor, read from its newest
    // archived page content. Gated on the team's AI level, not on the create
    // wizard's metered analyze allowance, because it is re-runnable.
    Route::post('monitors/{monitor}/metrics/discover', [MonitorMetricController::class, 'discover'])
        ->name('api.v1.monitors.metrics.discover');
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
    Route::post('incidents', [IncidentController::class, 'store'])
        ->name('api.v1.incidents.store');
    // Registered BEFORE the `{incident}` wildcard below: a literal segment
    // must win the match, or `digest` would be swallowed as an incident id.
    Route::get('incidents/digest', [DigestController::class, 'index'])
        ->name('api.v1.incidents.digest');
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])
        ->name('api.v1.incidents.show');
    Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve'])
        ->name('api.v1.incidents.resolve');
    Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])
        ->name('api.v1.incidents.acknowledge');
    Route::post('incidents/{incident}/reopen', [IncidentController::class, 'reopen'])
        ->name('api.v1.incidents.reopen');
    Route::post('incidents/{incident}/assign', [IncidentController::class, 'assign'])
        ->name('api.v1.incidents.assign');
    Route::post('incidents/{incident}/postmortem', [IncidentController::class, 'postmortem'])
        ->name('api.v1.incidents.postmortem');
    Route::post('incidents/{incident}/updates', [IncidentController::class, 'postUpdate'])
        ->name('api.v1.incidents.updates.store');
    Route::get('incidents/{incident}/analysis', [IncidentAnalysisController::class, 'show'])
        ->name('api.v1.incidents.analysis');
    Route::post('incidents/{incident}/analysis/feedback', [IncidentAnalysisController::class, 'feedback'])
        ->name('api.v1.incidents.analysis.feedback');

    // Drafting spends an AI budget unit per call, which is why both are POST
    // although neither stores anything: a GET is the verb a browser, a
    // prefetcher or a retry repeats on its own.
    Route::post('incidents/{incident}/draft-update', [IncidentDraftController::class, 'update'])
        ->name('api.v1.incidents.draft.update');
    Route::post('incidents/{incident}/draft-postmortem', [IncidentDraftController::class, 'postmortem'])
        ->name('api.v1.incidents.draft.postmortem');

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

    Route::post('assistant', [AssistantController::class, 'answer'])
        ->name('api.v1.assistant.answer');

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

    // The operator's explicit "refresh the preview" action. Throttled by name
    // because one accepted request spawns a headless browser, and because
    // nothing else bounds it: `api/v1` is unthrottled, and the render job's
    // per-page lock releases the moment processing starts, so it caps queue
    // depth rather than request rate.
    Route::post('status-pages/{statusPage:id}/preview', [StatusPageController::class, 'renderPreview'])
        ->middleware('throttle:status-page-preview-render')
        ->name('api.v1.status-pages.preview');

    Route::get('status-pages/{statusPage:id}/subscribers', [StatusPageController::class, 'listSubscribers'])
        ->name('api.v1.status-pages.subscribers.index');
    // Throttled by name because one accepted request queues a confirmation mail
    // to an address the operator typed, and `api/v1` is otherwise unthrottled.
    // The plan's per-page cap bounds how many subscribers a page may hold; this
    // bounds the rate at which mail leaves for them.
    Route::post('status-pages/{statusPage:id}/subscribers', [StatusPageController::class, 'addSubscriber'])
        ->middleware('throttle:status-page-subscriber-add')
        ->name('api.v1.status-pages.subscribers.store');
    Route::delete('status-pages/{statusPage:id}/subscribers/{subscriber}', [StatusPageController::class, 'removeSubscriber'])
        ->name('api.v1.status-pages.subscribers.destroy');

    // Planned maintenance windows, announced on a status page. The parameter is
    // renamed off the resource's own `scheduled_maintenance` default so the
    // controller can type-hint a camelCase `$maintenance` and stay readable.
    Route::apiResource('scheduled-maintenances', ScheduledMaintenanceController::class)
        ->parameters(['scheduled-maintenances' => 'maintenance']);

    Route::get('on-call/current', [OnCallController::class, 'current'])
        ->name('api.v1.on-call.current');

    Route::get('on-call/schedules', [OnCallController::class, 'index'])
        ->name('api.v1.on-call.schedules.index');
    Route::post('on-call/schedules', [OnCallController::class, 'store'])
        ->name('api.v1.on-call.schedules.store');
    Route::get('on-call/schedules/{schedule}', [OnCallController::class, 'show'])
        ->name('api.v1.on-call.schedules.show');
    Route::put('on-call/schedules/{schedule}', [OnCallController::class, 'update'])
        ->name('api.v1.on-call.schedules.update');
    Route::delete('on-call/schedules/{schedule}', [OnCallController::class, 'destroy'])
        ->name('api.v1.on-call.schedules.destroy');

    Route::post('on-call/schedules/{schedule}/rotations', [OnCallController::class, 'addRotation'])
        ->name('api.v1.on-call.schedules.rotations.store');
    Route::match(['patch', 'put'], 'on-call/schedules/{schedule}/rotations/reorder', [
        OnCallController::class,
        'reorderRotations',
    ])->name('api.v1.on-call.schedules.rotations.reorder');
    Route::delete('on-call/schedules/{schedule}/rotations/{rotation}', [OnCallController::class, 'removeRotation'])
        ->name('api.v1.on-call.schedules.rotations.destroy');

    Route::post('on-call/schedules/{schedule}/overrides', [OnCallController::class, 'addOverride'])
        ->name('api.v1.on-call.schedules.overrides.store');
    Route::delete('on-call/schedules/{schedule}/overrides/{override}', [OnCallController::class, 'removeOverride'])
        ->name('api.v1.on-call.schedules.overrides.destroy');

    Route::get('escalation-policies', [EscalationPolicyController::class, 'index'])
        ->name('api.v1.escalation-policies.index');
    Route::post('escalation-policies', [EscalationPolicyController::class, 'store'])
        ->name('api.v1.escalation-policies.store');
    Route::get('escalation-policies/{policy}', [EscalationPolicyController::class, 'show'])
        ->name('api.v1.escalation-policies.show');
    Route::put('escalation-policies/{policy}', [EscalationPolicyController::class, 'update'])
        ->name('api.v1.escalation-policies.update');
    Route::delete('escalation-policies/{policy}', [EscalationPolicyController::class, 'destroy'])
        ->name('api.v1.escalation-policies.destroy');

    Route::post('escalation-policies/{policy}/steps', [EscalationPolicyController::class, 'addStep'])
        ->name('api.v1.escalation-policies.steps.store');
    Route::match(['patch', 'put'], 'escalation-policies/{policy}/steps/reorder', [
        EscalationPolicyController::class,
        'reorderSteps',
    ])->name('api.v1.escalation-policies.steps.reorder');
    Route::delete('escalation-policies/{policy}/steps/{step}', [EscalationPolicyController::class, 'removeStep'])
        ->name('api.v1.escalation-policies.steps.destroy');

    Route::get('notification-channels', [NotificationChannelController::class, 'index'])
        ->name('api.v1.notification-channels.index');
    Route::post('notification-channels', [NotificationChannelController::class, 'store'])
        ->name('api.v1.notification-channels.store');
    Route::get('notification-channels/{channel}', [NotificationChannelController::class, 'show'])
        ->name('api.v1.notification-channels.show');
    Route::put('notification-channels/{channel}', [NotificationChannelController::class, 'update'])
        ->name('api.v1.notification-channels.update');
    Route::delete('notification-channels/{channel}', [NotificationChannelController::class, 'destroy'])
        ->name('api.v1.notification-channels.destroy');
    Route::post('notification-channels/{channel}/test', [NotificationChannelController::class, 'test'])
        ->name('api.v1.notification-channels.test');

    Route::get('billing', [BillingController::class, 'show'])
        ->name('api.v1.billing.show');
    Route::get('billing/plans', [BillingController::class, 'plans'])
        ->name('api.v1.billing.plans');
    Route::get('billing/usage', [BillingController::class, 'usage'])
        ->name('api.v1.billing.usage');
    Route::get('billing/invoices', [BillingController::class, 'invoices'])
        ->name('api.v1.billing.invoices');
    Route::get('billing/payment-method', [BillingController::class, 'paymentMethod'])
        ->name('api.v1.billing.payment-method');
    Route::post('billing/checkout', [BillingController::class, 'checkout'])
        ->name('api.v1.billing.checkout');
    Route::post('billing/swap', [BillingController::class, 'swap'])
        ->name('api.v1.billing.swap');
    Route::post('billing/cancel', [BillingController::class, 'cancel'])
        ->name('api.v1.billing.cancel');
    Route::get('billing/portal', [BillingController::class, 'portal'])
        ->name('api.v1.billing.portal');
});
