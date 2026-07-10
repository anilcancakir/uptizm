<?php

namespace App\Models;

use App\Enums\MetricBand;
use App\Enums\MonitorStatus;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extracted metric value captured alongside a monitor check.
 *
 * Three metric shapes (numeric, status, string) share the table via
 * nullable columns; consumers should inspect the paired {@see MonitorMetric}
 * row to know which column carries the value. The `band` column freezes the
 * banding decision at insert time so later threshold edits do not rewrite
 * history.
 *
 * The DB-level primary key is `(id, recorded_at)` for the same hypertable
 * reason documented on {@see MonitorCheck}; `id` is always an ordered UUID
 * regardless of the `magic-starter.use_uuids` toggle.
 *
 * Relationships:
 * - belongs to {@see Monitor}
 * - belongs to {@see Team} (denormalized for direct team-scoped queries)
 */
class MonitorMetricValue extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Disable `updated_at` management: a recorded sample is an immutable
     * log entry, never revised after insert.
     */
    public const UPDATED_AT = null;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'recorded_at' => 'immutable_datetime',
        'numeric_value' => 'float',
        'status_value' => MonitorStatus::class,
        'band' => MetricBand::class,
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
