<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Http\Resources\MonitorMetricResource;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorContentVersion;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Models\Team;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\CheckAggregateService;
use App\Services\Monitoring\ContentArchive;
use App\Services\Monitoring\MetricExtractor;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Nested CRUD for a monitor's custom metric definitions, plus a windowed
 * reader over `monitor_metric_values`, a non-persisting extraction preview used
 * by the metric form sheet, and AI-assisted discovery of metrics worth adding.
 *
 * The preview and the discovery endpoint both read their sample from the
 * monitor's newest ARCHIVED page content rather than from the 10 KiB
 * `response_body_preview` column, and they resolve that blob through
 * {@see ContentArchive::blobPath()}. Both details matter: on a real page the
 * first useful candidate can sit past byte 98,000, so a preview reading the
 * truncated column would answer "extracts nothing" for a rule that extracts
 * perfectly well at runtime; and the blob path is derived in exactly one place
 * because it is also the argument to a delete against a remote holding this
 * system's only database backups.
 */
class MonitorMetricController extends Controller
{
    /**
     * The AI capability level a team needs for metric discovery.
     */
    protected const string DISCOVERY_AI_LEVEL = 'analysis';

    public function __construct(
        protected ContentArchive $archive,
    ) {}

    public function index(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $this->authorizeMonitor($request, $monitor);

        $metrics = $monitor->metrics()
            ->orderBy('group_name')
            ->orderBy('display_order')
            ->orderBy('label')
            ->get();

        // Attach the latest persisted value per metric key so the list
        // cards can render current readings without a second round-trip.
        // monitor_metric_values has no metric_id FK, so we match on
        // (monitor_id, metric_key) pairs via a single newest-first query.
        $keys = $metrics->pluck('key')->filter()->values()->all();
        $latestByKey = collect();
        if ($keys !== []) {
            $latestByKey = MonitorMetricValue::query()
                ->where('monitor_id', $monitor->id)
                ->whereIn('metric_key', $keys)
                ->orderByDesc('recorded_at')
                ->get()
                ->unique('metric_key')
                ->keyBy('metric_key');
        }

        foreach ($metrics as $metric) {
            $metric->setRelation('latestValue', $latestByKey->get($metric->key));
        }

        return MonitorMetricResource::collection($metrics);
    }

    public function store(StoreMonitorMetricRequest $request, Monitor $monitor): JsonResponse
    {
        $this->authorizeMonitor($request, $monitor);

        $metric = MonitorMetric::query()->create([
            ...$request->validated(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
        ]);

        return MonitorMetricResource::make($metric)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(Request $request, Monitor $monitor, MonitorMetric $metric): MonitorMetricResource
    {
        $this->authorizeMetric($request, $monitor, $metric);

        $validated = $request->validate([
            'group_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:80',
            ],
            'label' => [
                'sometimes',
                'string',
                'max:120',
            ],
            'key' => [
                'sometimes',
                'string',
                'max:40',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('monitor_metrics', 'key')
                    ->where('monitor_id', $monitor->id)
                    ->ignore($metric->id),
            ],
            'type' => [
                'sometimes',
                Rule::enum(MetricType::class),
            ],
            'source' => [
                'sometimes',
                'nullable',
                Rule::enum(MetricSource::class),
            ],
            'extraction_path' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'unit' => [
                'sometimes',
                'nullable',
                Rule::enum(MetricUnit::class),
            ],
            'threshold_direction' => [
                'sometimes',
                'nullable',
                Rule::enum(ThresholdDirection::class),
            ],
            'warn_bound' => [
                'sometimes',
                'nullable',
                'numeric',
            ],
            'critical_bound' => [
                'sometimes',
                'nullable',
                'numeric',
            ],
            'display_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],
        ]);

        $metric->fill($validated)->save();

        return MonitorMetricResource::make($metric);
    }

    public function destroy(Request $request, Monitor $monitor, MonitorMetric $metric): Response
    {
        $this->authorizeMetric($request, $monitor, $metric);

        $metric->delete();

        return response()->noContent();
    }

    /**
     * Bulk-update `display_order` for a set of this monitor's metrics.
     *
     * The reorder sheet sends the full set of ids in the group's new
     * order. Validating that every incoming id belongs to the routed
     * monitor blocks cross-monitor payloads (returning 404 to stay
     * consistent with the rest of this nested controller).
     */
    public function reorder(Request $request, Monitor $monitor): Response
    {
        $this->authorizeMonitor($request, $monitor);

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

        // 1. Reject payloads that reference metrics from another monitor;
        //    route binding only validated the top-level monitor id, not
        //    each metric id. Returning 404 mirrors authorizeMetric().
        $incomingIds = array_map(static fn (array $row): string => (string) $row['id'], $order);
        $ownedIds = $monitor->metrics()->pluck('id')->map(static fn ($v) => (string) $v)->all();
        foreach ($incomingIds as $id) {
            abort_unless(in_array($id, $ownedIds, true), HttpResponse::HTTP_NOT_FOUND);
        }

        // 2. Apply the new ordering in a transaction so partial writes
        //    don't leave the UI seeing a mix of old and new positions on
        //    the next index call.
        DB::transaction(function () use ($order, $monitor): void {
            foreach ($order as $row) {
                $monitor->metrics()
                    ->whereKey((string) $row['id'])
                    ->update(['display_order' => (int) $row['display_order']]);
            }
        });

        return response()->noContent();
    }

    /**
     * Apply a draft extraction rule against a real sample response and return
     * the extracted value plus the threshold band it would land in.
     *
     * The sample is the caller's `sample_body`/`sample_headers`/
     * `sample_status_code` when supplied, otherwise the monitor's newest
     * ARCHIVED page content, and failing that the most recent
     * {@see MonitorCheck}'s truncated body preview. That order is what makes the
     * answer trustworthy: the archived body is the whole of what the target
     * served, which is exactly what runtime extraction now runs against, while
     * the preview column stops at 10 KiB and would report a perfectly good rule
     * as extracting nothing. A monitor with neither answers `has_sample: false`
     * rather than reporting a failed extraction, because there is nothing to test
     * against.
     *
     * Nothing is persisted; this powers the metric form's "Test extraction"
     * card while the user is still composing the rule.
     */
    public function preview(Request $request, Monitor $monitor, MetricExtractor $extractor): JsonResponse
    {
        $this->authorizeMonitor($request, $monitor);

        $validated = $request->validate([
            'source' => [
                'required',
                Rule::enum(MetricSource::class),
            ],
            'extraction_path' => [
                'required_unless:source,'.MetricSource::HttpStatus->value,
                'nullable',
                'string',
                'max:500',
            ],
            'type' => [
                'required',
                Rule::enum(MetricType::class),
            ],
            'sample_body' => [
                'nullable',
                'string',
            ],
            'sample_headers' => [
                'nullable',
                'array',
            ],
            'sample_status_code' => [
                'nullable',
                'integer',
            ],
            'threshold_direction' => [
                'nullable',
                Rule::enum(ThresholdDirection::class),
            ],
            'warn_bound' => [
                'nullable',
                'numeric',
            ],
            'critical_bound' => [
                'nullable',
                'numeric',
            ],
        ]);

        $source = MetricSource::from($validated['source']);
        $type = MetricType::from($validated['type']);

        // Fall back to what the monitor actually returned. Without this the
        // endpoint extracted against an EMPTY body whenever the caller supplied
        // none, so every rule "failed" for a reason that had nothing to do with
        // the rule. The last check supplies the headers, the status code and the
        // "which sample was this" answer; the archived version supplies the FULL
        // body, which is what the runtime extraction path sees.
        $sampleCheck = null;
        $archivedBody = null;
        if (! $request->has('sample_body')) {
            $sampleCheck = MonitorCheck::query()
                ->where('monitor_id', $monitor->id)
                ->orderByDesc('checked_at')
                ->first();

            // Only the body-consuming sources pay for the archived read. A cold
            // read off the archive mount costs about a second against a remote
            // capped near two file operations a second, and `http_status` and
            // `header` rules never look at a body, so for them the read buys
            // nothing and `$sampleCheck` already answers "is there a sample".
            // Listed inline rather than as a method on `MetricSource`: one call
            // site does not earn a new member on a shared domain enum.
            $consumesBody = in_array($source, [
                MetricSource::JsonPath,
                MetricSource::Regex,
                MetricSource::Xpath,
            ], true);

            if ($consumesBody) {
                $archivedBody = $this->newestArchivedBody($monitor);
            }
        }

        // An http_status rule needs no body, so it can still be verified from a
        // check's status code alone.
        $hasSample = $request->has('sample_body') || $sampleCheck !== null || $archivedBody !== null;

        if (! $hasSample) {
            return response()->json([
                'extracted_value' => null,
                'type_valid' => false,
                'error' => 'This monitor has no checks yet, so there is nothing to test against. Run a check first.',
                'band' => null,
                'has_sample' => false,
                'sample_checked_at' => null,
                'sample_status_code' => null,
            ]);
        }

        $result = $extractor->extract(
            source: $source,
            extractionPath: (string) ($validated['extraction_path'] ?? ''),
            type: $type,
            body: (string) ($validated['sample_body'] ?? $archivedBody ?? $sampleCheck?->response_body_preview ?? ''),
            headers: $validated['sample_headers'] ?? $sampleCheck?->response_headers ?? [],
            statusCode: $validated['sample_status_code'] ?? $sampleCheck?->status_code,
        );

        // Band the extracted value only when the caller supplied a
        // direction to band against; a rule with no thresholds yet has
        // nothing to band.
        $band = null;
        if ($result->typeValid && $type === MetricType::Numeric
            && $result->value !== null && isset($validated['threshold_direction'])) {
            $band = ThresholdEvaluator::band(
                direction: ThresholdDirection::from($validated['threshold_direction']),
                value: (float) $result->value,
                warnBound: isset($validated['warn_bound']) ? (float) $validated['warn_bound'] : null,
                criticalBound: isset($validated['critical_bound']) ? (float) $validated['critical_bound'] : null,
            )->value;
        }

        return response()->json([
            'extracted_value' => $result->value,
            'type_valid' => $result->typeValid,
            'error' => $result->error,
            'band' => $band,
            // Which sample produced this answer, so the UI can name it instead of
            // implying it just fetched the endpoint live.
            'has_sample' => true,
            'sample_checked_at' => $sampleCheck?->checked_at?->toIso8601String(),
            'sample_status_code' => $validated['sample_status_code'] ?? $sampleCheck?->status_code,
        ]);
    }

    /**
     * Propose metrics worth adding, discovered from the monitor's newest
     * archived page content.
     *
     * Gated on the team's AI LEVEL rather than on the create wizard's metered
     * analyze allowance: this call is re-runnable by design (the page changes,
     * the operator changes their mind), so spending a one-off setup try on it
     * would be wrong.
     *
     * Nothing is created. The response is a list of suggestions the operator
     * accepts by submitting the existing metric form, and it is an empty array
     * whenever discovery could not run (nothing archived, the daily AI budget
     * exhausted, or output the gateway would not trust) so the client renders no
     * hole and never sees a fabricated metric.
     */
    public function discover(Request $request, Monitor $monitor, MetricDiscoveryService $discovery): JsonResponse
    {
        $this->authorizeMonitor($request, $monitor);

        $team = Team::find($request->user()->current_team_id);
        if ($team !== null) {
            (new PlanGate)->assertAiLevel($team, self::DISCOVERY_AI_LEVEL, 'AI metric discovery');
        }

        return response()->json([
            'data' => [
                'suggested_metrics' => $discovery->discover(
                    $monitor,
                    $this->newestArchivedBody($monitor),
                    (string) $monitor->team_id,
                ),
            ],
        ]);
    }

    /**
     * The decompressed body of the monitor's NEWEST archived content version, or
     * null when there is none to read.
     *
     * Only the newest, deliberately: the archive lives on a cold FUSE mount of a
     * Drive remote where a single read costs about a second and the remote caps
     * at roughly two files per second, so a history-wide scan would turn one
     * request into a stall.
     *
     * Every failure mode answers null rather than throwing, because both callers
     * are read-only conveniences: a version whose blob retention already pruned,
     * a corrupt stored hash the path helper refuses, and bytes that will not
     * decompress all mean "no sample available", never a 500 on a form panel.
     */
    protected function newestArchivedBody(Monitor $monitor): ?string
    {
        $version = MonitorContentVersion::query()
            ->where('monitor_id', $monitor->getKey())
            ->orderByDesc('last_seen_at')
            ->first();

        if ($version === null) {
            return null;
        }

        try {
            // The single permitted derivation of a blob location; no caller
            // rebuilds `{team}/{fanout}/{hash}.gz` for itself.
            $path = $this->archive->blobPath($version->team_id, (string) $version->content_hash);
        } catch (InvalidArgumentException $exception) {
            // The corrupt-row case the retention sweep also skips. Logged rather
            // than swallowed: the row needs a human, the request does not.
            Log::warning('Skipped an archived content version with a malformed hash.', [
                'monitor_id' => (string) $monitor->getKey(),
                'version_id' => (string) $version->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        // One `get()` rather than `exists()`-then-`get()`: two round trips to a
        // FUSE mount can also lose the race to the retention sweep between them.
        $blob = Storage::disk((string) config('content-archive.disk'))->get($path);
        if ($blob === null || $blob === '') {
            return null;
        }

        $body = gzdecode($blob);

        return $body === false ? null : $body;
    }

    /**
     * Batch companion to {@see self::series()}. Returns the most recent
     * samples for every metric owned by the monitor in one response so
     * the metrics tab can render N sparklines from a single request
     * instead of firing one XHR per card.
     */
    public function batchSeries(Request $request, Monitor $monitor): JsonResponse
    {
        $this->authorizeMonitor($request, $monitor);

        $range = (string) $request->query('range', '24h');
        $modifier = CheckAggregateService::RANGE_WINDOWS[$range] ?? CheckAggregateService::RANGE_WINDOWS['24h'];
        $since = now()->modify($modifier);

        $limit = (int) $request->query('limit', 60);
        $limit = max(1, min($limit, 200));

        $keys = $monitor->metrics()->pluck('key')->filter()->values()->all();
        if ($keys === []) {
            return response()->json([
                'data' => (object) [],
                'meta' => [
                    'range' => $range,
                    'limit' => $limit,
                ],
            ]);
        }

        $byKey = [];
        foreach ($keys as $key) {
            $samples = MonitorMetricValue::query()
                ->where('monitor_id', $monitor->id)
                ->where('metric_key', $key)
                ->where('recorded_at', '>=', $since)
                ->orderByDesc('recorded_at')
                ->limit($limit)
                ->get();

            $byKey[$key] = $samples
                ->reverse()
                ->values()
                ->map(fn (MonitorMetricValue $v) => $this->serializeValue($v))
                ->all();
        }

        return response()->json([
            'data' => $byKey,
            'meta' => [
                'range' => $range,
                'limit' => $limit,
            ],
        ]);
    }

    public function series(Request $request, Monitor $monitor, MonitorMetric $metric): JsonResponse
    {
        $this->authorizeMetric($request, $monitor, $metric);

        $range = (string) $request->query('range', '24h');
        $modifier = CheckAggregateService::RANGE_WINDOWS[$range] ?? CheckAggregateService::RANGE_WINDOWS['24h'];
        $since = now()->modify($modifier);

        $values = MonitorMetricValue::query()
            ->where('monitor_id', $monitor->id)
            ->where('metric_key', $metric->key)
            ->where('recorded_at', '>=', $since)
            ->orderByDesc('recorded_at')
            ->limit(2000)
            ->get();

        return response()->json([
            'data' => $values->reverse()->values()->map(fn (MonitorMetricValue $v) => $this->serializeValue($v))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeValue(MonitorMetricValue $value): array
    {
        return [
            'recorded_at' => $value->recorded_at?->toIso8601String(),
            'numeric_value' => $value->numeric_value !== null ? (float) $value->numeric_value : null,
            'string_value' => $value->string_value,
            'status_value' => $value->status_value?->value,
            'band' => $value->band?->value,
        ];
    }

    protected function authorizeMonitor(Request $request, Monitor $monitor): void
    {
        abort_unless(
            $monitor->team_id === $request->user()->current_team_id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    protected function authorizeMetric(Request $request, Monitor $monitor, MonitorMetric $metric): void
    {
        $this->authorizeMonitor($request, $monitor);

        abort_unless(
            $metric->monitor_id === $monitor->id,
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
