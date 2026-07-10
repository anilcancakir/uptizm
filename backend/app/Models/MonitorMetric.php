<?php

namespace App\Models;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
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
     * @var array<int, string>
     */
    protected $guarded = [];

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
}
