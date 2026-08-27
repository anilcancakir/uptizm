<?php

namespace Tests\Feature\Http;

use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\ThresholdDirection;
use App\Http\Controllers\Api\V1\MonitorMetricController;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Http\Requests\UpdateMonitorMetricRequest;
use App\Http\Requests\UpdateMonitorRequest;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorContentVersion;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ContentArchive;
use App\Services\Monitoring\MetricExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Locks {@see MonitorMetricController}'s CRUD/preview surface: store 201s
 * on a valid definition, a duplicate `key` per monitor and an invalid `key`
 * pattern both 422, and `preview` extracts a value plus a threshold band
 * from a caller-supplied sample without persisting anything.
 *
 * Routes are not registered yet (Step 19), so the controller is invoked
 * directly against manually-resolved request objects, matching the
 * FormRequest validation path Laravel itself runs at HTTP dispatch time.
 */
class MonitorMetricControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_metric_definition(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $controller = $this->app->make(MonitorMetricController::class);

        $request = $this->makeStoreRequest([
            'label' => 'Latency (ms)',
            'key' => 'latency_ms',
            'type' => MetricType::Numeric->value,
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
        ], $monitor, $user);

        $response = $controller->store($request, $monitor);

        $this->assertSame(201, $response->status());
        $this->assertSame('latency_ms', $response->getData(true)['data']['key']);
        $this->assertSame('json_path', $response->getData(true)['data']['source']);
        $this->assertSame('data.latency_ms', $response->getData(true)['data']['extraction_path']);
    }

    public function test_store_rejects_a_duplicate_key_per_monitor(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $controller = $this->app->make(MonitorMetricController::class);

        $controller->store($this->makeStoreRequest([
            'label' => 'Latency (ms)',
            'key' => 'latency_ms',
            'type' => MetricType::Numeric->value,
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
        ], $monitor, $user), $monitor);

        $this->expectException(ValidationException::class);

        $this->makeStoreRequest([
            'label' => 'Latency again',
            'key' => 'latency_ms',
            'type' => MetricType::Numeric->value,
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
        ], $monitor, $user);
    }

    public function test_store_rejects_an_invalid_key_pattern(): void
    {
        [$monitor, $user] = $this->makeMonitor();

        $this->expectException(ValidationException::class);

        $this->makeStoreRequest([
            'label' => 'Bad Key',
            'key' => 'Latency-MS',
            'type' => MetricType::Numeric->value,
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
        ], $monitor, $user);
    }

    public function test_preview_extracts_a_numeric_value_and_bands_it(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $controller = $this->app->make(MonitorMetricController::class);

        $request = Request::create('/monitors/'.$monitor->id.'/metrics/preview', 'POST', [
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
            'type' => MetricType::Numeric->value,
            'sample_body' => json_encode(['data' => ['latency_ms' => 950]]),
            'threshold_direction' => ThresholdDirection::HighBad->value,
            'warn_bound' => 500,
            'critical_bound' => 900,
        ]);
        $request->setUserResolver(fn () => $user);

        $response = $controller->preview($request, $monitor, $this->app->make(MetricExtractor::class));
        $payload = $response->getData(true);

        $this->assertSame('950', $payload['extracted_value']);
        $this->assertTrue($payload['type_valid']);
        $this->assertNull($payload['error']);
        $this->assertSame('critical', $payload['band']);
    }

    /**
     * The assertion the whole unit-stripping change exists for, made on the
     * shared extractor rather than only on the candidate side.
     *
     * A numeric metric over a `120ms` value used to extract the string, fail
     * `validateType()`'s `is_numeric()` gate, and record NOTHING on every
     * check, silently and forever. The strip runs ahead of that gate now, so
     * the sample records as `120`. The second half of the test is what keeps
     * the change honest: a suffix the map does not name is still left exactly
     * as it was, gate included.
     */
    public function test_a_numeric_metric_records_a_value_carrying_a_known_unit(): void
    {
        $extractor = new MetricExtractor;

        $stripped = $extractor->extract(
            MetricSource::JsonPath,
            'data.latency',
            MetricType::Numeric,
            (string) json_encode(['data' => ['latency' => '120ms']]),
        );

        $this->assertSame('120', $stripped->value);
        $this->assertTrue($stripped->typeValid);
        $this->assertNull($stripped->error);

        $unmapped = $extractor->extract(
            MetricSource::JsonPath,
            'data.inventory',
            MetricType::Numeric,
            (string) json_encode(['data' => ['inventory' => '12 widgets']]),
        );

        $this->assertSame('12 widgets', $unmapped->value);
        $this->assertFalse($unmapped->typeValid);
    }

    /**
     * The strip is scoped to `numeric`. A string metric is how an operator asks
     * for the page's own wording, so rewriting `120ms` to `120` there would
     * silently drop information the metric was created to capture.
     */
    public function test_a_string_metric_keeps_its_unit_suffix_verbatim(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::JsonPath,
            'data.latency',
            MetricType::String,
            (string) json_encode(['data' => ['latency' => '120ms']]),
        );

        $this->assertSame('120ms', $result->value);
        $this->assertTrue($result->typeValid);
    }

    /**
     * The same strip seen where the operator sees it: the form's live preview
     * shows the number that will be recorded, and bands it.
     */
    public function test_preview_strips_a_known_unit_before_banding(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $controller = $this->app->make(MonitorMetricController::class);

        $request = Request::create('/monitors/'.$monitor->id.'/metrics/preview', 'POST', [
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency',
            'type' => MetricType::Numeric->value,
            'sample_body' => json_encode(['data' => ['latency' => '950 ms']]),
            'threshold_direction' => ThresholdDirection::HighBad->value,
            'warn_bound' => 500,
            'critical_bound' => 900,
        ]);
        $request->setUserResolver(fn () => $user);

        $payload = $controller
            ->preview($request, $monitor, $this->app->make(MetricExtractor::class))
            ->getData(true);

        $this->assertSame('950', $payload['extracted_value']);
        $this->assertTrue($payload['type_valid']);
        $this->assertSame('critical', $payload['band']);
    }

    public function test_preview_falls_back_to_the_monitors_last_check_as_the_sample(): void
    {
        // The form's panel promises to verify a rule against what the monitor
        // actually returns. With no sample supplied the endpoint used to extract
        // against an EMPTY body, so every rule "failed" for the wrong reason;
        // the client papered over that by faking the whole test locally. The
        // last recorded check is the honest sample: it needs no new probe and it
        // is exactly what the pipeline itself extracted from.
        [$monitor, $user] = $this->makeMonitor();
        MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => MonitorRegion::USEast,
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => 120,
            'response_headers' => ['X-Response-Time' => '88'],
            'response_body_preview' => json_encode(['data' => ['latency_ms' => 640]]),
            'checked_at' => now(),
        ]);

        $request = Request::create('/monitors/'.$monitor->id.'/metrics/preview', 'POST', [
            'source' => MetricSource::JsonPath->value,
            // The JSONPath root the form's own placeholder prescribes.
            'extraction_path' => '$.data.latency_ms',
            'type' => MetricType::Numeric->value,
        ]);
        $request->setUserResolver(fn () => $user);

        $payload = $this->app->make(MonitorMetricController::class)
            ->preview($request, $monitor, $this->app->make(MetricExtractor::class))
            ->getData(true);

        $this->assertSame('640', $payload['extracted_value']);
        $this->assertTrue($payload['type_valid']);
        $this->assertNull($payload['error']);
        $this->assertNotNull(
            $payload['sample_checked_at'],
            'the caller must be told WHICH sample the answer came from',
        );
    }

    public function test_preview_says_so_when_the_monitor_has_never_been_checked(): void
    {
        // Nothing to verify against is its own answer, not a failed extraction:
        // the operator needs to know to run a check first.
        [$monitor, $user] = $this->makeMonitor();

        $request = Request::create('/monitors/'.$monitor->id.'/metrics/preview', 'POST', [
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
            'type' => MetricType::Numeric->value,
        ]);
        $request->setUserResolver(fn () => $user);

        $payload = $this->app->make(MonitorMetricController::class)
            ->preview($request, $monitor, $this->app->make(MetricExtractor::class))
            ->getData(true);

        $this->assertNull($payload['extracted_value']);
        $this->assertNull($payload['sample_checked_at']);
        $this->assertFalse($payload['has_sample']);
    }

    public function test_preview_still_prefers_an_explicit_sample_body(): void
    {
        // An explicitly supplied sample wins over the stored check, so a caller
        // can test a rule against a payload it has in hand.
        [$monitor, $user] = $this->makeMonitor();
        MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => MonitorRegion::USEast,
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => 120,
            'response_headers' => [],
            'response_body_preview' => json_encode(['data' => ['latency_ms' => 640]]),
            'checked_at' => now(),
        ]);

        $request = Request::create('/monitors/'.$monitor->id.'/metrics/preview', 'POST', [
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
            'type' => MetricType::Numeric->value,
            'sample_body' => json_encode(['data' => ['latency_ms' => 11]]),
        ]);
        $request->setUserResolver(fn () => $user);

        $payload = $this->app->make(MonitorMetricController::class)
            ->preview($request, $monitor, $this->app->make(MetricExtractor::class))
            ->getData(true);

        $this->assertSame('11', $payload['extracted_value']);
    }

    public function test_store_rejects_bounds_ordered_against_a_high_bad_direction(): void
    {
        // The ordering rule was enforced only on the AI discovery path, so a
        // human could save warn 90 / critical 50 on a high-is-bad metric and
        // never reach critical: band() checks critical first and 95 >= 50
        // already matched warn's own condition.
        [$monitor, $user] = $this->makeMonitor();

        try {
            $this->makeStoreRequest([
                'label' => 'Latency (ms)',
                'key' => 'latency_ms',
                'type' => MetricType::Numeric->value,
                'source' => MetricSource::JsonPath->value,
                'extraction_path' => 'data.latency_ms',
                'threshold_direction' => ThresholdDirection::HighBad->value,
                'warn_bound' => 90,
                'critical_bound' => 50,
            ], $monitor, $user);

            $this->fail('high_bad with warn 90 and critical 50 must not validate');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('critical_bound', $exception->errors());
        }
    }

    public function test_store_rejects_bounds_ordered_against_a_low_bad_direction(): void
    {
        // The inverse of the case above, and the reason the rule delegates to
        // ThresholdDirection::validateBounds() instead of comparing warn to
        // critical itself: a plain `warn < critical` check would pass this.
        [$monitor, $user] = $this->makeMonitor();

        try {
            $this->makeStoreRequest([
                'label' => 'Free memory',
                'key' => 'free_mem',
                'type' => MetricType::Numeric->value,
                'source' => MetricSource::JsonPath->value,
                'extraction_path' => 'data.free_mem',
                'threshold_direction' => ThresholdDirection::LowBad->value,
                'warn_bound' => 50,
                'critical_bound' => 90,
            ], $monitor, $user);

            $this->fail('low_bad with warn 50 and critical 90 must not validate');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('critical_bound', $exception->errors());
        }
    }

    public function test_store_accepts_bounds_ordered_with_the_direction(): void
    {
        [$monitor, $user] = $this->makeMonitor();

        $request = $this->makeStoreRequest([
            'label' => 'Latency (ms)',
            'key' => 'latency_ms',
            'type' => MetricType::Numeric->value,
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => 'data.latency_ms',
            'threshold_direction' => ThresholdDirection::HighBad->value,
            'warn_bound' => 500,
            'critical_bound' => 900,
        ], $monitor, $user);

        $this->assertSame(500.0, (float) $request->validated()['warn_bound']);
    }

    public function test_store_round_trips_the_string_band_fields(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $controller = $this->app->make(MonitorMetricController::class);

        $request = $this->makeStoreRequest([
            'label' => 'Cluster state',
            'key' => 'cluster_state',
            'type' => MetricType::String->value,
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => '$.status',
            'ok_values' => ['ok'],
            'warn_values' => ['degraded'],
            'critical_values' => ['down'],
            'unmatched_band' => MetricBand::Critical->value,
        ], $monitor, $user);

        $data = $controller->store($request, $monitor)->getData(true)['data'];

        $this->assertSame(['ok'], $data['ok_values']);
        $this->assertSame(['degraded'], $data['warn_values']);
        $this->assertSame(['down'], $data['critical_values']);
        $this->assertSame('critical', $data['unmatched_band']);
    }

    public function test_update_rejects_a_value_that_overlaps_a_stored_list(): void
    {
        // The hole the merged-state hook closes: `sometimes` means a PATCH
        // carrying one list never re-checks the two it did not carry, and the
        // comparison has to normalize both sides or `ok` and `X`/`x` pass
        // validation and then collide at evaluation.
        [$monitor, $user] = $this->makeMonitor();
        $metric = $this->makeStringMetric($monitor, ['critical_values' => ['X']]);

        try {
            $this->makeUpdateRequest(['ok_values' => ['x']], $monitor, $metric, $user);

            $this->fail('a value already stored in critical_values must not be accepted into ok_values');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'ok_values.0',
                $exception->errors(),
                'the offending element is addressed by dot notation so the client can map it back',
            );
        }
    }

    public function test_store_rejects_two_submitted_lists_that_overlap_only_after_normalization(): void
    {
        // The everyday shape: the form posts all three lists at once, so both
        // sides of the collision carry a request index and both get one.
        [$monitor, $user] = $this->makeMonitor();

        try {
            $this->makeStoreRequest([
                'label' => 'Cluster state',
                'key' => 'cluster_state',
                'type' => MetricType::String->value,
                'source' => MetricSource::JsonPath->value,
                'extraction_path' => '$.status',
                'ok_values' => ['OK'],
                'warn_values' => ['degraded', ' ok '],
            ], $monitor, $user);

            $this->fail('"OK" and " ok " normalize to the same value and must not sit in two lists');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [
                    'ok_values.0',
                    'warn_values.1',
                ],
                array_keys($exception->errors()),
            );
        }
    }

    public function test_store_rejects_a_duplicate_within_one_list(): void
    {
        // The overlap rule compares lists to each OTHER, so the within-one-list
        // duplicate is the LIST rule's job. It used to be `distinct` on the
        // element, which reported `ok_values.0`; that rule resolved its
        // comparison set from the leading explicit path, so under the bulk
        // prefix it compared across metrics and refused a monitor whose
        // subsystems all read `ok`. The check moved onto the list itself and the
        // error moved with it, which is also the better address: the defect is a
        // property of the pair, not of the first element of it.
        [$monitor, $user] = $this->makeMonitor();

        try {
            $this->makeStoreRequest([
                'label' => 'Cluster state',
                'key' => 'cluster_state',
                'type' => MetricType::String->value,
                'source' => MetricSource::JsonPath->value,
                'extraction_path' => '$.status',
                'ok_values' => ['ok', 'OK'],
            ], $monitor, $user);

            $this->fail('a case-insensitive duplicate within one list must not validate');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ok_values', $exception->errors());
        }
    }

    public function test_update_rejects_an_unmatched_band_with_no_configured_values(): void
    {
        // An unmatched band with nothing to match against would band EVERY
        // sample, which is a page per check rather than a configuration.
        [$monitor, $user] = $this->makeMonitor();
        $metric = $this->makeStringMetric($monitor);

        try {
            $this->makeUpdateRequest(['unmatched_band' => MetricBand::Critical->value], $monitor, $metric, $user);

            $this->fail('an unmatched band with three empty lists must not validate');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unmatched_band', $exception->errors());
        }
    }

    public function test_update_persists_one_list_without_disturbing_the_others(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        $metric = $this->makeStringMetric($monitor, ['ok_values' => ['ok']]);

        $resource = $this->app->make(MonitorMetricController::class)->update(
            $this->makeUpdateRequest(['warn_values' => ['degraded']], $monitor, $metric, $user),
            $monitor,
            $metric,
        );

        $this->assertSame(['degraded'], $resource->toArray(new Request)['warn_values']);
        $this->assertSame(['degraded'], $metric->fresh()->warn_values);
        $this->assertSame(['ok'], $metric->fresh()->ok_values);
    }

    public function test_update_rejects_a_duplicate_key_from_another_metric_but_allows_its_own(): void
    {
        // The unique rule moved out of the controller; prove the ignore()
        // clause survived the move, in both directions.
        [$monitor, $user] = $this->makeMonitor();
        $metric = $this->makeStringMetric($monitor);
        $other = $this->makeStringMetric($monitor, ['key' => 'other_state']);

        $request = $this->makeUpdateRequest(['key' => $metric->key], $monitor, $metric, $user);
        $this->assertSame($metric->key, $request->validated()['key']);

        $this->expectException(ValidationException::class);
        $this->makeUpdateRequest(['key' => $other->key], $monitor, $metric, $user);
    }

    public function test_a_cross_tenant_monitor_is_masked_as_404_before_any_rule_runs(): void
    {
        // A FormRequest validates BEFORE the controller method is entered, so
        // the controller's own 404-mask can no longer be the first gate on the
        // write paths. Without a mask in the request the `key` uniqueness rule
        // would probe another team's monitor and answer 422 where this API
        // answers 404 for everything it does not own. The payload is
        // deliberately invalid: a ValidationException here would prove the mask
        // runs too late.
        [$monitor] = $this->makeMonitor();
        [, $stranger] = $this->makeMonitor();

        $this->expectException(NotFoundHttpException::class);

        $this->makeStoreRequest([
            'label' => 'Bad Key',
            'key' => 'Latency-MS',
            'type' => MetricType::Numeric->value,
            'source' => MetricSource::JsonPath->value,
        ], $monitor, $stranger);
    }

    public function test_a_value_that_normalizes_to_nothing_is_rejected(): void
    {
        // `min:1` counts CHARACTERS, so a lone U+00A0 is length 1 and passes it,
        // and Laravel's TrimStrings does not strip it either (PCRE's `\s` stays
        // ASCII-only under `/u` without PCRE_UCP). It then normalizes to "" and
        // matches every extraction that also normalizes to "": a path resolving
        // to an empty string would band as whichever list holds it.
        [$monitor, $user] = $this->makeMonitor();

        try {
            $this->makeStoreRequest([
                'label' => 'Cluster state',
                'key' => 'cluster_state',
                'type' => MetricType::String->value,
                'source' => MetricSource::JsonPath->value,
                'extraction_path' => '$.status',
                'ok_values' => ["\u{00A0}"],
            ], $monitor, $user);

            $this->fail('a value that normalizes to the empty string must not be accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ok_values.0', $exception->errors());
        }
    }

    public function test_a_metric_belonging_to_another_monitor_is_masked_as_404(): void
    {
        // The route binds `metric` by id alone, so the same team's OTHER monitor's
        // metric resolves and the merged-state rules would then validate a PATCH
        // against a stored row the URL does not name: a partial payload could be
        // banded by another monitor's lists, and the `key` uniqueness rule would
        // ignore the wrong row. The payload is deliberately valid on its own, so
        // a ValidationException here would mean the mask never fired.
        [$monitor, $user] = $this->makeMonitor();
        $other = Monitor::query()->create([
            'team_id' => $monitor->team_id,
            'name' => 'Second monitor',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/other',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);
        $foreignMetric = $this->makeStringMetric($other);

        $this->expectException(NotFoundHttpException::class);

        $this->makeUpdateRequest(['label' => 'Renamed'], $monitor, $foreignMetric, $user);
    }

    public function test_a_string_metric_with_inverted_stored_bounds_still_accepts_a_label_edit(): void
    {
        // The bound-order check reads the MERGED state, so a string metric that
        // carries numeric bounds from an earlier numeric life (nothing clears
        // them on a type switch) would fail every later PATCH on a field that
        // has nothing to do with bounds. The order only means something for a
        // numeric metric, so the gate is the merged TYPE, not the presence of
        // the columns.
        [$monitor, $user] = $this->makeMonitor();
        $metric = $this->makeStringMetric($monitor, [
            'ok_values' => ['ok'],
            // high_bad with warn ABOVE critical: rejected outright on a numeric
            // metric, and irrelevant on this one.
            'threshold_direction' => ThresholdDirection::HighBad,
            'warn_bound' => 900,
            'critical_bound' => 100,
        ]);

        $request = $this->makeUpdateRequest(['label' => 'Cluster health'], $monitor, $metric, $user);

        $this->assertSame('Cluster health', $request->validated()['label']);
    }

    public function test_preview_bands_a_string_sample_against_the_configured_lists(): void
    {
        // The form's verdict must agree with what the pipeline will freeze, so
        // preview bands a string draft through the same bandString().
        [$monitor, $user] = $this->makeMonitor();
        $controller = $this->app->make(MonitorMetricController::class);

        $matched = $this->previewRequest($monitor, $user, [
            'sample_body' => json_encode(['status' => 'DEGRADED']),
            'warn_values' => ['degraded'],
            'critical_values' => ['down'],
        ]);

        $payload = $controller->preview($matched, $monitor, $this->app->make(MetricExtractor::class))
            ->getData(true);

        $this->assertSame('DEGRADED', $payload['extracted_value']);
        $this->assertSame('warn', $payload['band']);

        // A value in none of the lists takes the configured unmatched band.
        $unmatched = $this->previewRequest($monitor, $user, [
            'sample_body' => json_encode(['status' => 'on fire']),
            'ok_values' => ['ok'],
            'unmatched_band' => MetricBand::Critical->value,
        ]);

        $this->assertSame(
            'critical',
            $controller->preview($unmatched, $monitor, $this->app->make(MetricExtractor::class))
                ->getData(true)['band'],
        );
    }

    public function test_preview_does_not_promise_a_band_the_write_path_would_refuse(): void
    {
        // An unmatched band with three empty lists bands EVERY value, which is
        // why the write path rejects it. Preview used to band the draft anyway,
        // so the panel answered CRITICAL and the save then 422'd on a field the
        // operator had already filled in: a verdict promising what cannot be
        // saved. It mirrors the freeze gate instead, and stays silent.
        [$monitor, $user] = $this->makeMonitor();

        $payload = $this->app->make(MonitorMetricController::class)
            ->preview(
                $this->previewRequest($monitor, $user, [
                    'sample_body' => json_encode(['status' => 'ok']),
                    'unmatched_band' => MetricBand::Critical->value,
                ]),
                $monitor,
                $this->app->make(MetricExtractor::class),
            )
            ->getData(true);

        $this->assertSame('ok', $payload['extracted_value']);
        $this->assertNull($payload['band']);
    }

    public function test_preview_leaves_an_unconfigured_string_draft_unbanded(): void
    {
        [$monitor, $user] = $this->makeMonitor();

        $payload = $this->app->make(MonitorMetricController::class)
            ->preview(
                $this->previewRequest($monitor, $user, [
                    'sample_body' => json_encode(['status' => 'ok']),
                ]),
                $monitor,
                $this->app->make(MetricExtractor::class),
            )
            ->getData(true);

        $this->assertSame('ok', $payload['extracted_value']);
        $this->assertNull($payload['band'], 'an inert string metric bands nothing');
    }

    public function test_preview_extracts_from_the_newest_archived_body(): void
    {
        // Covers the extracted ArchivedBodyReader through its first caller: the
        // archived body is the whole of what the target served, which is what
        // makes a preview against a past-10-KiB path honest. The archive disk is
        // already faked for every test by Tests\TestCase::setUp().
        [$monitor, $user] = $this->makeMonitor();
        $body = (string) json_encode(['data' => ['latency_ms' => 4242]]);
        $hash = hash('sha256', $body);

        MonitorContentVersion::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'content_hash' => $hash,
            'content_hash_normalized' => hash('sha256', (string) preg_replace('/\s+/', ' ', $body)),
            'byte_size' => strlen($body),
            'content_type' => 'application/json',
            'truncated' => false,
            'normalizer_version' => (int) config('content-archive.normalizer_version'),
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        Storage::disk((string) config('content-archive.disk'))->put(
            $this->app->make(ContentArchive::class)->blobPath($monitor->team_id, $hash),
            (string) gzencode($body),
        );

        $request = Request::create('/monitors/'.$monitor->id.'/metrics/preview', 'POST', [
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => '$.data.latency_ms',
            'type' => MetricType::Numeric->value,
        ]);
        $request->setUserResolver(fn () => $user);

        $payload = $this->app->make(MonitorMetricController::class)
            ->preview($request, $monitor, $this->app->make(MetricExtractor::class))
            ->getData(true);

        $this->assertSame('4242', $payload['extracted_value']);
        $this->assertTrue($payload['has_sample']);
    }

    public function test_metric_field_rules_prefixes_every_key_and_gates_key_behind_the_prefix(): void
    {
        // Step 10's bulk `metrics[]` field reaches this via `metrics.*.`; the
        // route-bound path keeps composing its own `key` rule via
        // uniqueKeyRule(), so `key` must be absent with no prefix and present,
        // carrying `distinct`, only once a prefix is supplied. Asserting the
        // property rather than a key count, since Step 9 is about to add
        // another rule to this same method.
        $prefixed = StoreMonitorMetricRequest::metricFieldRules('metrics.*.');

        foreach (array_keys($prefixed) as $field) {
            $this->assertStringStartsWith('metrics.*.', $field);
            $this->assertSame(1, substr_count($field, 'metrics.*.'), "{$field} must carry the prefix exactly once");
        }

        $this->assertArrayHasKey('metrics.*.key', $prefixed);
        $this->assertContains('distinct', $prefixed['metrics.*.key']);

        $bare = StoreMonitorMetricRequest::metricFieldRules();
        $this->assertArrayNotHasKey('key', $bare);
    }

    public function test_metric_field_rules_is_callable_statically_with_no_bound_request(): void
    {
        // metricFieldRules() carries no $this reference so that a static call
        // from StoreMonitorRequest's bulk path, with no FormRequest instance
        // resolved, cannot fatal.
        $rules = StoreMonitorMetricRequest::metricFieldRules('metrics.*.');

        $this->assertNotEmpty($rules);
    }

    public function test_validate_metric_row_cross_fields_names_the_offending_row_on_every_check(): void
    {
        // The bulk loop calls this once per submitted row with THAT row's own
        // dotted prefix; an implementation that dropped $errorPrefix from
        // errors()->add() would report every collision on row 0 regardless of
        // which row actually failed. Row 0 is deliberately clean so its
        // absence from the error bag is itself part of the assertion.
        $rows = [
            [
                'key' => 'ok_one',
                'label' => 'Fine',
                'type' => MetricType::Numeric->value,
                'threshold_direction' => ThresholdDirection::HighBad->value,
                'warn_bound' => 100,
                'critical_bound' => 200,
            ],
            [
                'key' => 'bad_two',
                'label' => 'Inverted',
                'type' => MetricType::Numeric->value,
                'threshold_direction' => ThresholdDirection::HighBad->value,
                'warn_bound' => 900,
                'critical_bound' => 100,
            ],
            [
                'key' => 'bad_three',
                'label' => 'Overlap',
                'type' => MetricType::String->value,
                'ok_values' => ['ok'],
                'warn_values' => ['OK'],
            ],
        ];

        $validator = Validator::make(
            ['metrics' => $rows],
            [
                'metrics' => ['array'],
                ...StoreMonitorMetricRequest::metricFieldRules('metrics.*.'),
            ],
        );
        // Mirrors StoreMonitorRequest::withValidator(): the field rules run and
        // populate the message bag BEFORE the after() callback (here, the loop
        // below) appends its own errors on top.
        $validator->fails();

        foreach ($rows as $index => $row) {
            StoreMonitorMetricRequest::validateMetricRowCrossFields($validator, $row, "metrics.{$index}.");
        }

        $errors = $validator->errors();

        $this->assertFalse($errors->has('metrics.0.critical_bound'));
        $this->assertTrue($errors->has('metrics.1.critical_bound'));
        $this->assertTrue($errors->has('metrics.2.ok_values.0'));
        $this->assertTrue($errors->has('metrics.2.warn_values.0'));
        $this->assertFalse($errors->has('critical_bound'), 'the bare field key must not receive a bulk-row error');
    }

    public function test_validate_metric_row_cross_fields_rejects_an_unmatched_band_with_all_lists_empty(): void
    {
        // The third check's own row: an unmatched band with nothing configured
        // to match against would band every sample, and its error must land on
        // the row's own prefixed key.
        $row = [
            'key' => 'no_lists',
            'label' => 'No lists',
            'type' => MetricType::String->value,
            'unmatched_band' => MetricBand::Critical->value,
        ];

        $validator = Validator::make(
            ['metrics' => [$row]],
            [
                'metrics' => ['array'],
                ...StoreMonitorMetricRequest::metricFieldRules('metrics.*.'),
            ],
        );
        $validator->fails();

        StoreMonitorMetricRequest::validateMetricRowCrossFields($validator, $row, 'metrics.0.');

        $this->assertTrue($validator->errors()->has('metrics.0.unmatched_band'));
    }

    public function test_update_monitor_endpoint_leaves_the_metrics_cross_field_loop_dormant(): void
    {
        // UpdateMonitorRequest::rules() never declares `metrics` (only
        // StoreMonitorRequest::rules() will, in Step 10), so the per-row loop
        // StoreMonitorRequest::withValidator() runs stays unreached on a PUT
        // even carrying a metrics[] row every cross-field check would
        // otherwise fail: an inverted warn/critical pair on a numeric metric.
        // A 200 here is only possible because the loop never ran.
        [$monitor, $user] = $this->makeMonitor();
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/monitors/{$monitor->id}", [
            'metrics' => [
                [
                    'key' => 'bad',
                    'label' => 'Bad',
                    'type' => MetricType::Numeric->value,
                    'threshold_direction' => ThresholdDirection::HighBad->value,
                    'warn_bound' => 900,
                    'critical_bound' => 100,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('metrics', (new UpdateMonitorRequest)->rules());
        $this->assertSame(0, MonitorMetric::query()->where('monitor_id', $monitor->id)->count());
    }

    public function test_store_refuses_a_header_metric_on_a_credential_bearing_name(): void
    {
        // A metric is read and PERSISTED on every check, and the check job
        // hands MetricExtractor the RAW header set, so a metric pointed at
        // `set-cookie` would write an authenticated session cookie into a
        // cleartext column for as long as it exists.
        [$monitor, $user] = $this->makeMonitor();

        $this->expectException(ValidationException::class);

        $this->makeStoreRequest([
            'label' => 'Session',
            'key' => 'session',
            'type' => MetricType::String->value,
            'source' => MetricSource::Header->value,
            'extraction_path' => 'Set-Cookie',
        ], $monitor, $user);
    }

    public function test_store_still_accepts_a_header_metric_on_an_ordinary_name(): void
    {
        // The denylist is four names, not the `header` source: refusing the
        // source outright would be a different feature and would break every
        // header metric that already exists.
        [$monitor, $user] = $this->makeMonitor();

        $response = $this->app->make(MonitorMetricController::class)->store(
            $this->makeStoreRequest([
                'label' => 'Content type',
                'key' => 'content_type',
                'type' => MetricType::String->value,
                'source' => MetricSource::Header->value,
                'extraction_path' => 'content-type',
            ], $monitor, $user),
            $monitor,
        );

        $this->assertSame(201, $response->status());
    }

    public function test_store_leaves_a_denied_name_alone_under_another_source(): void
    {
        // The rule is keyed off the SIBLING `source`, so the same string under
        // `json_path` addresses a body key of that name and is nobody's
        // credential. A rule that refused the string unconditionally would pass
        // the test above and quietly forbid this.
        [$monitor, $user] = $this->makeMonitor();

        $response = $this->app->make(MonitorMetricController::class)->store(
            $this->makeStoreRequest([
                'label' => 'Echoed name',
                'key' => 'echoed_name',
                'type' => MetricType::String->value,
                'source' => MetricSource::JsonPath->value,
                'extraction_path' => 'set-cookie',
            ], $monitor, $user),
            $monitor,
        );

        $this->assertSame(201, $response->status());
    }

    public function test_the_header_denylist_fires_through_the_bulk_prefix_too(): void
    {
        // The discriminating case. On `POST /monitors` the sibling is
        // `metrics.0.source`, so a rule reading `$this->input('source')` sees
        // null, no-ops, and lets the row through on exactly the path this plan
        // opens. Row 0 is deliberately clean, so an implementation that
        // reported every row's error on the bare key fails here too.
        $rows = [
            [
                'key' => 'content_type',
                'label' => 'Content type',
                'type' => MetricType::String->value,
                'source' => MetricSource::Header->value,
                'extraction_path' => 'content-type',
            ],
            [
                'key' => 'session',
                'label' => 'Session',
                'type' => MetricType::String->value,
                'source' => MetricSource::Header->value,
                'extraction_path' => 'Set-Cookie',
            ],
        ];

        $validator = Validator::make(
            ['metrics' => $rows],
            [
                'metrics' => ['array'],
                ...StoreMonitorMetricRequest::metricFieldRules('metrics.*.'),
            ],
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('metrics.1.extraction_path'));
        $this->assertFalse($validator->errors()->has('metrics.0.extraction_path'));
        $this->assertFalse($validator->errors()->has('extraction_path'));
    }

    /**
     * A `string`-typed metric on the monitor, all three lists empty unless the
     * caller overrides them.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeStringMetric(Monitor $monitor, array $attributes = []): MonitorMetric
    {
        return $monitor->metrics()->create([
            'team_id' => $monitor->team_id,
            'label' => 'Cluster state',
            'key' => 'cluster_state',
            'type' => MetricType::String,
            'source' => MetricSource::JsonPath,
            'extraction_path' => '$.status',
            ...$attributes,
        ]);
    }

    /**
     * A `type: string` preview request over `$.status`, merged with the draft
     * band configuration under test.
     *
     * @param  array<string, mixed>  $data
     */
    protected function previewRequest(Monitor $monitor, User $user, array $data): Request
    {
        $request = Request::create('/monitors/'.$monitor->id.'/metrics/preview', 'POST', [
            'source' => MetricSource::JsonPath->value,
            'extraction_path' => '$.status',
            'type' => MetricType::String->value,
            ...$data,
        ]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * Manually resolves an {@see UpdateMonitorMetricRequest} the way the HTTP
     * kernel would, with BOTH route parameters bound: the merged-state rules
     * read the stored metric off the route, so an unbound `metric` would make
     * every partial payload look like a create.
     *
     * @param  array<string, mixed>  $data
     */
    protected function makeUpdateRequest(
        array $data,
        Monitor $monitor,
        MonitorMetric $metric,
        User $user,
    ): UpdateMonitorMetricRequest {
        $request = UpdateMonitorMetricRequest::create(
            '/monitors/'.$monitor->id.'/metrics/'.$metric->id,
            'PUT',
            $data,
        );
        $request->setUserResolver(fn () => $user);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        $route = new Route('PUT', '/monitors/{monitor}/metrics/{metric}', []);
        $route->bind($request);
        $route->setParameter('monitor', $monitor);
        $route->setParameter('metric', $metric);
        $request->setRouteResolver(fn () => $route);

        $request->validateResolved();

        return $request;
    }

    /**
     * Manually resolves a {@see StoreMonitorMetricRequest} the way the HTTP
     * kernel would: bind the route parameter, attach the authenticated
     * user, then run `validateResolved()` so validation failures surface
     * as the same `ValidationException` a real 422 response would throw.
     *
     * @param  array<string, mixed>  $data
     */
    protected function makeStoreRequest(array $data, Monitor $monitor, User $user): StoreMonitorMetricRequest
    {
        $request = StoreMonitorMetricRequest::create(
            '/monitors/'.$monitor->id.'/metrics',
            'POST',
            $data,
        );
        $request->setUserResolver(fn () => $user);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        $route = new Route('POST', '/monitors/{monitor}/metrics', []);
        $route->bind($request);
        $route->setParameter('monitor', $monitor);
        $request->setRouteResolver(fn () => $route);

        $request->validateResolved();

        return $request;
    }

    /**
     * @return array{0: Monitor, 1: User}
     */
    public function test_the_list_reads_one_row_per_metric_not_the_whole_history(): void
    {
        // What this pins is a production outage, not a preference. The list used
        // to ask for EVERY reading of every key and thin the result in PHP with
        // `->unique()`; at 176,000 readings on one monitor that exhausted the
        // 128MB worker limit, so the endpoint answered the client nothing, and
        // the metrics tab rendered empty and told the operator the monitor had
        // no metrics at all.
        [$monitor, $user] = $this->makeMonitor();
        Sanctum::actingAs($user);

        // Every reading belongs to a check: `check_id` is NOT NULL, because a
        // value with no check behind it is a number from nowhere.
        $check = MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => MonitorRegion::USEast,
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => 120,
            'checked_at' => now(),
        ]);

        foreach (['queue_depth', 'disk_free'] as $index => $key) {
            MonitorMetric::query()->create([
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'label' => ucfirst($key),
                'key' => $key,
                'type' => MetricType::Numeric,
                'source' => MetricSource::JsonPath,
                'extraction_path' => '$.'.$key,
                'display_order' => $index,
            ]);

            // A history deep enough that reading all of it is visibly the wrong
            // shape, with the NEWEST row last so an unordered read picks wrong.
            for ($i = 0; $i < 40; $i++) {
                MonitorMetricValue::query()->create([
                    'monitor_id' => $monitor->id,
                    'team_id' => $monitor->team_id,
                    'metric_key' => $key,
                    'check_id' => $check->id,
                    'numeric_value' => $i,
                    'band' => MetricBand::Ok,
                    'recorded_at' => now()->subMinutes(40 - $i),
                ]);
            }
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'monitor_metric_values')) {
                $queries[] = $query->sql;
            }
        });

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/metrics")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // The newest reading, which is the whole point of the join.
        $values = collect($response->json('data'))->pluck('latest.numeric_value', 'key');
        $this->assertEqualsWithDelta(39.0, (float) $values['queue_depth'], 0.001);
        $this->assertEqualsWithDelta(39.0, (float) $values['disk_free'], 0.001);

        // And every one of those reads was bounded. Without this the assertions
        // above pass on the old shape too: it returned the right value, it just
        // loaded eighty rows to find two, and eighty scales.
        $this->assertNotEmpty($queries, 'the value table has to be read at all');
        foreach ($queries as $sql) {
            $this->assertStringContainsString(
                'limit 1',
                strtolower($sql),
                'an unbounded read of this table is what took production down: '.$sql,
            );
        }
    }

    public function test_readings_page_newest_first_through_a_cursor(): void
    {
        // The readings table on the metric sheet walks a history that has no
        // end: one row per check, forever. It pages rather than taking a
        // windowed slice, because "what did this read last Tuesday" is a fair
        // question and a 24h chart cannot answer it.
        [$monitor, $user] = $this->makeMonitor();
        Sanctum::actingAs($user);

        $metric = MonitorMetric::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'label' => 'Queue depth',
            'key' => 'queue_depth',
            'type' => MetricType::Numeric,
            'source' => MetricSource::JsonPath,
            'extraction_path' => '$.queue_depth',
            'display_order' => 0,
        ]);

        $check = MonitorCheck::query()->create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => MonitorRegion::USEast,
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => 120,
            'checked_at' => now(),
        ]);

        // Five readings, every one of them sharing ONE timestamp. That is the
        // case the `id` tiebreaker exists for: `cursorPaginate` builds its token
        // out of the order-by columns, so a cursor on `recorded_at` alone skips
        // or repeats at this boundary with nothing in the response to say so.
        $tie = now()->subMinutes(5);
        foreach (range(1, 5) as $i) {
            MonitorMetricValue::query()->create([
                'monitor_id' => $monitor->id,
                'team_id' => $monitor->team_id,
                'metric_key' => 'queue_depth',
                'check_id' => $check->id,
                'numeric_value' => $i,
                'band' => MetricBand::Ok,
                'recorded_at' => $tie,
            ]);
        }

        $first = $this->getJson(
            "/api/v1/monitors/{$monitor->id}/metrics/{$metric->id}/readings?per_page=2",
        )->assertStatus(200)->assertJsonCount(2, 'data');

        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor, 'the table pages by cursor, so the token has to be there');

        $ids = collect($first->json('data'))->pluck('id')->all();
        $seen = $ids;

        // Walk the rest and prove nothing was skipped or served twice.
        for ($page = 0; $page < 4 && $cursor !== null; $page++) {
            $next = $this->getJson(
                "/api/v1/monitors/{$monitor->id}/metrics/{$metric->id}/readings?per_page=2&cursor={$cursor}",
            )->assertStatus(200);
            $seen = array_merge($seen, collect($next->json('data'))->pluck('id')->all());
            $cursor = $next->json('meta.next_cursor');
        }

        $this->assertCount(5, array_unique($seen), 'five tied rows must page as five distinct rows');
    }

    public function test_readings_refuse_a_metric_from_another_monitor(): void
    {
        [$monitor, $user] = $this->makeMonitor();
        [$otherMonitor] = $this->makeMonitor();
        Sanctum::actingAs($user);

        $foreign = MonitorMetric::query()->create([
            'monitor_id' => $otherMonitor->id,
            'team_id' => $otherMonitor->team_id,
            'label' => 'Not yours',
            'key' => 'not_yours',
            'type' => MetricType::Numeric,
            'source' => MetricSource::JsonPath,
            'extraction_path' => '$.x',
            'display_order' => 0,
        ]);

        $this->getJson(
            "/api/v1/monitors/{$monitor->id}/metrics/{$foreign->id}/readings",
        )->assertStatus(404);
    }

    protected function makeMonitor(): array
    {
        $user = User::query()->create([
            'name' => 'Metric Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Metric Team',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        return [$monitor, $user];
    }
}
