<?php

namespace App\Models;

use App\Enums\MetricBand;
use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
use App\Services\Monitoring\IncidentWriteService;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-defined metric extracted from every check response of a monitor.
 *
 * Each metric owns its own extraction strategy (json_path, regex, xpath,
 * header, http_status) and, for numeric metrics, its own threshold bounds.
 * The `key` column is unique per monitor so metric values stay joinable
 * over time.
 *
 * Relationships:
 * - belongs to {@see Monitor}
 * - belongs to {@see Team} (denormalized for direct team-scoped queries)
 */
class MonitorMetric extends Model
{
    use ConditionallyUsesUuids;

    /**
     * Close the incident this metric was raised on when the metric goes.
     *
     * The metric lane's auto-resolve reads the trailing run of frozen bands for a
     * metric KEY, and a deleted metric produces no further samples, so without
     * this the incident could never clear. See
     * {@see IncidentWriteService::closeOrphanedByMetric()} for why closing is the
     * honest answer and why it is silent.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $metric): void {
            app(IncidentWriteService::class)->closeOrphanedByMetric($metric);
        });
    }

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Mirror the schema defaults in memory.
     *
     * `ok_values` / `warn_values` / `critical_values` are NOT NULL with a
     * database default of `'[]'`, which Eloquent does not know about, so a
     * freshly created metric would read them back as null until refreshed
     * (see the identical note on {@see Monitor::$attributes}). That matters
     * here because {@see self::alertsOnString()} compares these lists
     * against `[]` immediately after `create()`, in the same request that
     * built the metric.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ok_values' => '[]',
        'warn_values' => '[]',
        'critical_values' => '[]',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => MetricType::class,
        'source' => MetricSource::class,
        'unit' => MetricUnit::class,
        'threshold_direction' => ThresholdDirection::class,
        'warn_bound' => 'float',
        'critical_bound' => 'float',
        'display_order' => 'integer',
        'ok_values' => 'array',
        'warn_values' => 'array',
        'critical_values' => 'array',
        'unmatched_band' => MetricBand::class,
    ];

    /**
     * Owning monitor.
     *
     * @return BelongsTo<Monitor, self>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * Denormalized tenant link for direct team-scoped queries.
     *
     * @return BelongsTo<Team, self>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Whether this metric participates in string-band alerting at all.
     *
     * This is the ONLY predicate any other code may use to decide whether a
     * string metric alerts: `type` must be {@see MetricType::String} AND at
     * least one of `ok_values` / `warn_values` / `critical_values` must be
     * non-empty. It is deliberately NOT `threshold_direction !== null`,
     * because every string metric already carries
     * `threshold_direction = 'high_bad'` regardless of configuration: the
     * client's write path (`monitor_metrics_controller.dart:513`) sends
     * `threshold_direction` unconditionally for every metric type, so that
     * column cannot distinguish a configured string metric from an
     * unconfigured one. An all-empty string metric must stay inert (it
     * collects samples but alerts nothing), mirroring what
     * `threshold_direction === null` means for a numeric metric.
     */
    public function alertsOnString(): bool
    {
        if ($this->type !== MetricType::String) {
            return false;
        }

        return $this->ok_values !== []
            || $this->warn_values !== []
            || $this->critical_values !== [];
    }
}
