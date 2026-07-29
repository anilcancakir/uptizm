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
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Services\Monitoring\CheckAggregateService;
use App\Services\Monitoring\MetricExtractor;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Nested CRUD for a monitor's custom metric definitions, plus a windowed
 * reader over `monitor_metric_values` and a non-persisting extraction
 * preview used by the metric form sheet.
 */
class MonitorMetricController extends Controller
{
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
     * `sample_status_code` when supplied, otherwise the monitor's most recent
     * {@see MonitorCheck}. That fallback is what makes the answer trustworthy:
     * it is the same payload the extraction pipeline itself ran on, so a rule
     * that previews cleanly here will extract on the next check. A monitor with
     * no checks yet answers `has_sample: false` rather than reporting a failed
     * extraction, because there is nothing to test against.
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

        // Fall back to the monitor's most recent check as the sample. Without
        // this the endpoint extracted against an EMPTY body whenever the caller
        // supplied none, so every rule "failed" for a reason that had nothing to
        // do with the rule. The last check is the honest sample to verify
        // against: it costs no new probe and it is the very payload the
        // extraction pipeline itself ran on.
        $sampleCheck = null;
        if (! $request->has('sample_body')) {
            $sampleCheck = MonitorCheck::query()
                ->where('monitor_id', $monitor->id)
                ->orderByDesc('checked_at')
                ->first();
        }

        // An http_status rule needs no body, so it can still be verified from a
        // check's status code alone.
        $hasSample = $request->has('sample_body') || $sampleCheck !== null;

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
            body: (string) ($validated['sample_body'] ?? $sampleCheck?->response_body_preview ?? ''),
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
