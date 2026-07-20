<?php

namespace Tests\Feature\Http;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorType;
use App\Enums\ThresholdDirection;
use App\Http\Controllers\Api\V1\MonitorMetricController;
use App\Http\Requests\StoreMonitorMetricRequest;
use App\Models\Monitor;
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
