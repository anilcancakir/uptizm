<?php

namespace Tests\Unit\Models;

use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the shape of the four string-band columns added on top of
 * {@see MonitorMetric}: the three list casts must decode as PHP arrays
 * without a refresh, and {@see MonitorMetric::alertsOnString()} must be the
 * only predicate deciding whether a string metric participates in alerting.
 */
class MonitorMetricTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A freshly created metric reads `ok_values` (and its two siblings) as a
     * PHP array straight off the `'array'` cast, never as a raw JSON string,
     * and never requiring a `fresh()`/`refresh()` round trip through the
     * database to prove it.
     */
    public function test_string_band_lists_read_as_arrays_without_a_refresh(): void
    {
        $metric = $this->makeMetric([
            'ok_values' => [
                'ok',
            ],
            'warn_values' => [],
            'critical_values' => [],
        ]);

        $this->assertIsArray($metric->ok_values);
        $this->assertSame([
            'ok',
        ], $metric->ok_values);
        $this->assertIsArray($metric->warn_values);
        $this->assertSame([], $metric->warn_values);
        $this->assertIsArray($metric->critical_values);
        $this->assertSame([], $metric->critical_values);
    }

    /**
     * The default for all three list columns is an empty array, matching the
     * `default('[]')` declared on the migration, without requiring the caller
     * to pass them explicitly.
     */
    public function test_string_band_lists_default_to_empty_arrays(): void
    {
        $metric = $this->makeMetric();

        $this->assertSame([], $metric->ok_values);
        $this->assertSame([], $metric->warn_values);
        $this->assertSame([], $metric->critical_values);
        $this->assertNull($metric->unmatched_band);
    }

    /**
     * `unmatched_band` casts to the {@see MetricBand} enum, not a bare
     * string, matching every other enum-backed column on this model.
     */
    public function test_unmatched_band_casts_to_the_metric_band_enum(): void
    {
        $metric = $this->makeMetric([
            'unmatched_band' => MetricBand::Critical->value,
        ]);

        $this->assertSame(MetricBand::Critical, $metric->unmatched_band);
    }

    /**
     * A string metric with all three lists empty is inert: it collects
     * samples but alerts nothing, mirroring what `threshold_direction ===
     * null` means for a numeric metric.
     */
    public function test_alerts_on_string_is_false_when_all_three_lists_are_empty(): void
    {
        $metric = $this->makeMetric([
            'ok_values' => [],
            'warn_values' => [],
            'critical_values' => [],
        ]);

        $this->assertFalse($metric->alertsOnString());
    }

    /**
     * A single non-empty list is enough to arm the predicate, whichever of
     * the three lists carries the value.
     */
    public function test_alerts_on_string_is_true_as_soon_as_one_list_has_a_value(): void
    {
        $metric = $this->makeMetric([
            'ok_values' => [],
            'warn_values' => [
                'degraded',
            ],
            'critical_values' => [],
        ]);

        $this->assertTrue($metric->alertsOnString());
    }

    /**
     * A numeric metric never alerts on string bands, even if it somehow
     * carries populated lists: {@see MonitorMetric::alertsOnString()} gates
     * on `type` first.
     */
    public function test_alerts_on_string_is_false_for_a_non_string_metric_type(): void
    {
        $metric = $this->makeMetric([
            'type' => MetricType::Numeric,
            'ok_values' => [
                'ok',
            ],
        ]);

        $this->assertFalse($metric->alertsOnString());
    }

    /**
     * Creates a string-typed {@see MonitorMetric} attached to a freshly
     * created monitor, overriding any attribute via `$overrides`.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMetric(array $overrides = []): MonitorMetric
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

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);

        return $monitor->metrics()->create(array_merge([
            'team_id' => $team->id,
            'label' => 'Deployment status',
            'key' => 'deployment_status',
            'type' => MetricType::String,
            'source' => MetricSource::JsonPath,
            'extraction_path' => 'status',
        ], $overrides));
    }
}
