<?php

namespace App\Models;

use App\Jobs\AlarmDarkProbeRegions;
use App\Services\Monitoring\LocalProbeEngine;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per proxy region, recording whether the local probe engine is
 * actually producing readings there, at all.
 *
 * `monitors.last_probe_error` is tenant-facing and per-monitor; this table is
 * neither. A dead region produces the same refusal on every catalog monitor
 * that carries it, which is eight identical rows and no fleet signal, and
 * the public symptom is silence rather than an error, so nothing on a
 * monitor row would ever surface it. This is the only place an operator can
 * see "eu-west has produced nothing for an hour" as a single fact.
 *
 * {@see LocalProbeEngine} writes every field below on every attempt, after
 * the outcome (refused vs. a reading) is known. {@see AlarmDarkProbeRegions}
 * reads `consecutive_empty_intervals` against
 * `config('proxy.health.failure_threshold')` and owns `alarmed_at`.
 *
 * @property string $id
 * @property string $region
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property int $healthy_proxy_count
 * @property int $consecutive_empty_intervals
 * @property Carbon|null $alarmed_at
 */
class ProbeRegionHealth extends Model
{
    use ConditionallyUsesUuids;

    /**
     * `health` pluralizes to `healths`, not `health`; the table this model
     * backs is named for the singular per-region FACT it records, so the
     * default guess is overridden rather than renaming the migration to fit
     * Eloquent's pluralizer.
     *
     * @var string
     */
    protected $table = 'probe_region_health';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'last_success_at' => 'immutable_datetime',
        'last_failure_at' => 'immutable_datetime',
        'healthy_proxy_count' => 'integer',
        'consecutive_empty_intervals' => 'integer',
        'alarmed_at' => 'immutable_datetime',
    ];
}
