<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single probe execution recorded against a monitor for a given region.
 *
 * The DB-level primary key is `(id, checked_at)` (a TimescaleDB hypertable
 * requirement, see the migration); the `id` column is always an ordered UUID
 * regardless of the `magic-starter.use_uuids` toggle, so it stays unique in
 * practice and Eloquent keeps treating it as the canonical, non-incrementing
 * key.
 *
 * Relationships:
 * - belongs to {@see Monitor}
 * - belongs to {@see Team} (denormalized for direct team-scoped queries)
 * - has many {@see MonitorMetricValue} via `check_id`
 */
class MonitorCheck extends Model
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
     * Disable `updated_at` management: a recorded check is an immutable
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
        'checked_at' => 'immutable_datetime',
        'status' => MonitorStatus::class,
        'status_code' => 'integer',
        'response_ms' => 'integer',
        'timing_dns_ms' => 'integer',
        'timing_connect_ms' => 'integer',
        'timing_tls_ms' => 'integer',
        'timing_ttfb_ms' => 'integer',
        'timing_download_ms' => 'integer',
        'response_headers' => 'array',
        // A THREE-state pair, not a boolean and a list. Both columns are NULL when
        // the monitor configured no assertions, and NULL means NOT EVALUATED: the
        // edge decides the verdict and sends one nullable report, so a verdict
        // never arrives without its outcomes and vice versa.
        //
        // The `boolean` cast leaves NULL as NULL (Eloquent returns a null
        // attribute before any cast runs), and that distinction is the whole point
        // of the column being nullable: `false` says the target failed an
        // assertion, NULL says nobody asked it anything. PHP's `false == null` is
        // true, so a reader comparing loosely would publish an assertion failure
        // for a monitor that asserts nothing. Compare with `===`, or ask
        // `is_null()` first.
        'assertions_passed' => 'boolean',
        'assertion_results' => 'array',
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
     * Metric values extracted from this check's response payload.
     *
     * @return HasMany<MonitorMetricValue>
     */
    public function metricValues(): HasMany
    {
        return $this->hasMany(MonitorMetricValue::class, 'check_id');
    }
}
