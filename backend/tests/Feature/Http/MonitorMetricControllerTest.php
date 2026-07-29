<?php

namespace Tests\Feature\Http;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\ThresholdDirection;
use App\Http\Controllers\Api\V1\MonitorMetricController;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\MetricExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
