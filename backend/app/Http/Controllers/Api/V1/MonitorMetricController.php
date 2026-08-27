<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GatedFeature;
use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\ThresholdDirection;
use App\Http\Controllers\Concerns\PagesCollections;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Http\Requests\UpdateMonitorMetricRequest;
use App\Http\Resources\MonitorMetricResource;
use App\Http\Resources\MonitorMetricValueResource;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Models\Team;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Billing\PlanGate;
use App\Services\Monitoring\ArchivedBodyReader;
use App\Services\Monitoring\CheckAggregateService;
use App\Services\Monitoring\ContentArchive;
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
 * reader over `monitor_metric_values`, a non-persisting extraction preview used
 * by the metric form sheet, and AI-assisted discovery of metrics worth adding.
 *
 * The preview and the discovery endpoint both read their sample from the
 * monitor's newest ARCHIVED page content rather than from the 10 KiB
 * `response_body_preview` column, through {@see ArchivedBodyReader} and
 * therefore through {@see ContentArchive::blobPath()}. Both details matter: on a
 * real page the first useful candidate can sit past byte 98,000, so a preview
 * reading the truncated column would answer "extracts nothing" for a rule that
 * extracts perfectly well at runtime; and the blob path is derived in exactly
 * one place because it is also the argument to a delete against a remote
 * holding this system's only database backups.
 */
class MonitorMetricController extends Controller
{
    use PagesCollections;

    /**
     * The AI capability level a team needs for metric discovery.
     */
    protected const string DISCOVERY_AI_LEVEL = 'analysis';

    public function __construct(
        protected ArchivedBodyReader $bodyReader,
    ) {}

    public function index(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $this->authorizeMonitor($request, $monitor);

        $metrics = $monitor->metrics()
            ->orderBy('group_name')
            ->orderBy('display_order')
            ->orderBy('label')
            ->get();

        // Attach the latest persisted value per metric key so the list cards can
        // render current readings without a second round-trip.
        //
        // ONE QUERY PER KEY, each bounded to a single row. The shape this
        // replaces asked for every reading of every key at once and thinned the
        // result in PHP with `->unique()`, which is a table scan wearing a
        // collection method: this endpoint took down production when a monitor
        // reached 176,000 readings, exhausting the 128MB worker limit and
        // answering the client nothing at all, so the metrics tab rendered
        // empty and said the monitor had none.
        //
        // The per-key query is a pure index seek on
        // (monitor_id, metric_key, recorded_at), so its cost is the NUMBER OF
        // DEFINITIONS rather than the length of their history, and definitions
        // are created by hand. `monitor_metric_values` carries no metric_id FK,
        // which is why the match is on the key rather than a relation.
        //
        // The `id` tiebreaker matters because readings tie on `recorded_at`
        // across regions: without it "the latest" is whichever row the engine
        // happened to return, and the card's value could flip between reads
        // with nothing having changed.
        $keys = $metrics->pluck('key')->filter()->unique()->values()->all();
        $latestByKey = collect();

        foreach ($keys as $key) {
            $latest = MonitorMetricValue::query()
                ->where('monitor_id', $monitor->id)
                ->where('metric_key', $key)
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->first();

            if ($latest !== null) {
                $latestByKey->put($key, $latest);
            }
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

    /**
     * Partial edit of one metric definition.
     *
     * The rules live in {@see UpdateMonitorMetricRequest} (which consumes
     * {@see StoreMonitorMetricRequest}'s one definition) rather than inline
     * here. That is not tidying: the inline copy was why warn/critical ordering
     * and the string-band overlap had nowhere to be checked, since both rules
     * span fields and one of them has to read the STORED row for the keys a
     * partial payload omits.
     */
    public function update(
        UpdateMonitorMetricRequest $request,
        Monitor $monitor,
        MonitorMetric $metric,
    ): MonitorMetricResource {
        $this->authorizeMetric($request, $monitor, $metric);

        $metric->fill($request->validated())->save();

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
            // The string-band fields come from the request class rather than
            // being spelled again here, so the draft this endpoint bands is
            // validated exactly as the definition that gets saved. Without them
            // `$validated` could not carry a list and the string verdict below
            // would be unreachable.
            ...StoreMonitorMetricRequest::stringBandRules(),
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
                $archivedBody = $this->bodyReader->newestArchivedBody($monitor);
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

        // Band the extracted value through the SAME functions the pipeline
        // freezes a band with, so the form's verdict cannot disagree with what
        // the next check will record. A numeric draft needs a direction to band
        // against; a string draft needs at least one configured list or an
        // unmatched band, which is precisely what bandString() answering null
        // already encodes, so an unconfigured (inert) string metric bands
        // nothing here either.
        $band = null;
        if ($result->typeValid && $result->value !== null) {
            if ($type === MetricType::Numeric && isset($validated['threshold_direction'])) {
                $band = ThresholdEvaluator::band(
                    direction: ThresholdDirection::from($validated['threshold_direction']),
                    value: (float) $result->value,
                    warnBound: isset($validated['warn_bound']) ? (float) $validated['warn_bound'] : null,
                    criticalBound: isset($validated['critical_bound']) ? (float) $validated['critical_bound'] : null,
                    // Null when the draft carries no bound at all, which is the
                    // same "bands nothing" the string branch below already
                    // encodes. The preview then shows no band rather than a
                    // green one the draft has not earned.
                )?->value;
            } elseif ($type === MetricType::String) {
                $okValues = $this->draftValues($validated, 'ok_values');
                $warnValues = $this->draftValues($validated, 'warn_values');
                $criticalValues = $this->draftValues($validated, 'critical_values');

                // The draft equivalent of {@see MonitorMetric::alertsOnString()},
                // which gates both the freeze and the paging decision. Without it
                // a draft with three empty lists and an unmatched band would be
                // banded here, so the panel answered CRITICAL for a configuration
                // the write path rejects: a verdict promising what cannot be
                // saved. A preview may under-promise; it may not over-promise.
                $configured = $okValues !== [] || $warnValues !== [] || $criticalValues !== [];

                $band = $configured
                    ? ThresholdEvaluator::bandString(
                        value: (string) $result->value,
                        okValues: $okValues,
                        warnValues: $warnValues,
                        criticalValues: $criticalValues,
                        unmatchedBand: isset($validated['unmatched_band'])
                            ? MetricBand::from((string) $validated['unmatched_band'])
                            : null,
                    )?->value
                    : null;
            }
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
            (new PlanGate)->assertAiLevel($team, self::DISCOVERY_AI_LEVEL, GatedFeature::AiMetricDiscovery);
        }

        return response()->json([
            'data' => [
                'suggested_metrics' => $discovery->discover(
                    $monitor,
                    $this->bodyReader->newestArchivedBody($monitor),
                    (string) $monitor->team_id,
                    // No headers, for the reason documented on `discover()`: this
                    // path mines an archived body and cannot pair it with headers
                    // from the same response.
                    [],
                    $request->user()->locale,
                ),
            ],
        ]);
    }

    /**
     * One validated draft value list as a plain list of strings, so the preview
     * hands {@see ThresholdEvaluator::bandString()} the same shape the model's
     * `array` cast would.
     *
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    protected function draftValues(array $validated, string $field): array
    {
        $values = $validated[$field] ?? [];

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $values));
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

    /**
     * One metric's readings, newest first, PAGED.
     *
     * Separate from {@see self::series()} because the two answer different
     * questions and want different shapes. The series feeds a chart: it is
     * bounded by a time window, capped, and returned oldest-first so a line can
     * be drawn through it. This feeds a table an operator scrolls: no window at
     * all, because "what did this metric read last Tuesday" is a fair question,
     * and a cursor because the history has no end.
     *
     * Cursor rather than page numbers, and ordered on `(recorded_at, id)`: this
     * table takes a row every check, so readings tie on the timestamp across
     * regions, and a cursor built on a non-unique column alone skips or repeats
     * at the boundary with nothing in the response to say so.
     */
    public function readings(Request $request, Monitor $monitor, MonitorMetric $metric): AnonymousResourceCollection
    {
        $this->authorizeMetric($request, $monitor, $metric);

        $readings = MonitorMetricValue::query()
            ->where('monitor_id', $monitor->id)
            ->where('metric_key', $metric->key)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->cursorPaginate($this->perPage($request, 25, 200));

        return MonitorMetricValueResource::collection($readings);
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
