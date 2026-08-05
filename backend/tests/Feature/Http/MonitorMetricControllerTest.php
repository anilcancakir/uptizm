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
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorContentVersion;
use App\Models\MonitorMetric;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ContentArchive;
use App\Services\Monitoring\MetricExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        // duplicate is the field rule's job.
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
            $this->assertArrayHasKey('ok_values.0', $exception->errors());
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
