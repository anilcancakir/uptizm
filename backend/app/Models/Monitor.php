<?php

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\HttpMethod;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use DateTimeInterface;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A probe that periodically executes a check against a user-supplied URL
 * or TCP host, on a `check_interval_sec` cadence.
 *
 * Check execution is scheduled off `next_check_at`; incidents open when
 * `consecutive_fails` crosses `incident_threshold` (see
 * {@see self::DEFAULT_INCIDENT_THRESHOLD} for the default).
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to {@see Monitor} (parent, when nested under a group header)
 * - has many {@see Monitor} (children, when acting as a group header)
 * - has many {@see MonitorCheck} (probe execution history)
 * - has many {@see MonitorMetric} (user-defined extraction rules)
 * - has many {@see Incident} (primary-monitor hint)
 */
class Monitor extends Model
{
    use ConditionallyUsesUuids;
    use SoftDeletes;

    /**
     * Number of consecutive failures required to open an incident when
     * the monitor has no explicit `incident_threshold`. Picked so a
     * single transient flake does not page anyone, but a sustained
     * outage still opens an incident on the next tick.
     */
    public const int DEFAULT_INCIDENT_THRESHOLD = 2;

    /**
     * Minimum number of seconds between two manual checks on the same
     * monitor. Enforced by an atomic conditional UPDATE on
     * `last_manual_check_at` in the manual-check endpoint
     * (`POST api/v1/monitors/{id}/test`), not a route throttle: a route
     * limiter cannot express "per monitor" cleanly.
     *
     * The endpoint is named by its route rather than by its controller class
     * on purpose. A `{@see}` on the controller reads better but costs a real
     * `use App\Http\Controllers\...` in a domain model, because Pint's
     * `fully_qualified_strict_types` fixer rewrites an inline FQCN back to a
     * short name and restores the import. The route is the stabler address
     * anyway.
     */
    public const int MANUAL_CHECK_COOLDOWN_SECONDS = 60;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Mirror the schema defaults in memory.
     *
     * These columns are NOT NULL with database defaults, which Eloquent does not
     * know about, so a freshly created monitor read them back as null until it was
     * refreshed. That is not cosmetic here: `RelayClient::buildSpec()` sends
     * `method` and `timeout_sec` to the edge worker, and a null timeout became
     * `AbortSignal.timeout(0)`, so the probe aborted after 0ms and reported the
     * target as down. Found by dispatching a probe for a monitor created in the
     * same request.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'method' => 'get',
        'timeout_sec' => 30,
        'expected_status_code' => 200,
        'request_headers' => '{}',
        'regions' => '[]',
        'tags' => '[]',
        'ai_mode' => 'off',
        'status' => 'active',
        'consecutive_fails' => 0,
        'incident_threshold' => self::DEFAULT_INCIDENT_THRESHOLD,
        'show_on_status_page' => false,
        'only_show_if_degraded' => false,
        'is_group' => false,
        'alert_on_down' => true,
        'alert_on_recover' => true,
        'ssl_tracking' => false,
        'ssl_alert_threshold_days' => 14,
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => MonitorType::class,
        'method' => HttpMethod::class,
        'request_headers' => 'array',
        'expected_status_code' => 'integer',
        'check_interval_sec' => 'integer',
        'timeout_sec' => 'integer',
        'regions' => 'array',
        // Credentials are encrypted at rest (column is `text`); the cast reads
        // back the decrypted array transparently for RelayClient + the resource.
        'auth_config' => 'encrypted:array',
        'assertion_rules' => 'array',
        'tags' => 'array',
        'ai_mode' => AiMode::class,
        'alert_on_down' => 'boolean',
        'alert_on_recover' => 'boolean',
        'ssl_tracking' => 'boolean',
        'ssl_expires_at' => 'datetime',
        'ssl_last_checked_at' => 'datetime',
        // Set only when the edge refused to run a probe, and cleared by the next
        // probe that reached the target. See CheckPersistenceService.
        'last_probe_error_at' => 'datetime',
        // Cooldown marker for the manual `test()` endpoint; see
        // self::MANUAL_CHECK_COOLDOWN_SECONDS.
        'last_manual_check_at' => 'datetime',
        'ssl_alert_threshold_days' => 'integer',
        // `status` is the administrative state (active/paused), a plain string;
        // MonitorStatus (up/down/degraded/paused) is the health cast for `last_status`.
        'last_status' => MonitorStatus::class,
        'last_checked_at' => 'immutable_datetime',
        'last_response_ms' => 'integer',
        'next_check_at' => 'immutable_datetime',
        'consecutive_fails' => 'integer',
        'incident_threshold' => 'integer',
        'slo_target' => 'float',
        'show_on_status_page' => 'boolean',
        'only_show_if_degraded' => 'boolean',
        'is_group' => 'boolean',
    ];

    /**
     * Owning team (tenant boundary).
     *
     * @return BelongsTo<Team, self>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Parent monitor when this row nests under a group header. Null for
     * top-level monitors; see {@see self::children()} for the reverse.
     *
     * @return BelongsTo<Monitor, self>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'parent_id');
    }

    /**
     * Child monitors nested under this row when it acts as a group
     * header. Empty for leaf monitors.
     *
     * @return HasMany<Monitor>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Monitor::class, 'parent_id');
    }

    /**
     * Probe execution history for this monitor.
     *
     * @return HasMany<MonitorCheck>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * User-defined metrics extracted from each check response.
     *
     * @return HasMany<MonitorMetric>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(MonitorMetric::class);
    }

    /**
     * Incidents that named this monitor as the primary trigger.
     *
     * @return HasMany<Incident>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'primary_monitor_id');
    }

    /**
     * Scope to active monitors whose `next_check_at` is at or before `$at`
     * (defaults to now). Used by the scheduler to fan out checks.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('next_check_at', '<=', $at ?? now());
    }
}
