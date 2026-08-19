<?php

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\HttpMethod;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Services\Monitoring\IncidentWriteService;
use DateTimeInterface;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * - belongs to many {@see Service} via `service_monitor` (catalog services
 *   this monitor provides the own-measurement for; empty for every ordinary
 *   customer monitor)
 */
class Monitor extends Model
{
    use ConditionallyUsesUuids;
    use SoftDeletes;

    /**
     * Close the incidents this monitor leaves with nothing to report on.
     *
     * On `deleted` and not `deleting`, which is the whole reason this is a hook
     * and not two lines in the controller: the "does this incident still have a
     * live monitor" question is asked through the `monitors()` relation, that
     * relation applies this model's own soft-delete scope, and it therefore only
     * answers correctly once the row being deleted is already excluded.
     *
     * A hook rather than a call in the `DELETE api/v1/monitors/{id}` controller,
     * and named by its route for the reason {@see self::MANUAL_CHECK_COOLDOWN_SECONDS}
     * already records: a `{@see}` on a controller costs a real
     * `use App\Http\Controllers\...` in a domain model, because Pint's
     * `fully_qualified_strict_types` fixer rewrites an inline FQCN back to a
     * short name and restores the import. Measured again here by deleting the
     * import and running Pint, which put it straight back.
     *
     * The hook exists because the controller is not the only way a monitor dies: a console
     * command, a future bulk action or a cascade all reach `delete()` directly,
     * and an orphaned incident is silent when it happens and expensive when it
     * is found (it cannot close by any route, and it keeps paging). The work
     * itself is a service call rather than logic here; see
     * {@see IncidentWriteService::closeOrphanedBy()} for why closing is the
     * chosen answer and why it is silent.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $monitor): void {
            app(IncidentWriteService::class)->closeOrphanedBy($monitor);
        });
    }

    /**
     * Administrative `status` value for a monitor the customer has stopped.
     *
     * The column is a plain string rather than a backed enum (see the cast
     * block below), so these two constants are the only spelling of it.
     */
    public const string STATUS_PAUSED = 'paused';

    /**
     * Administrative `status` value for a monitor under active measurement.
     */
    public const string STATUS_ACTIVE = 'active';

    /**
     * Number of consecutive failures required to open an incident when
     * the monitor has no explicit `incident_threshold`. Picked so a
     * single transient flake does not page anyone, but a sustained
     * outage still opens an incident on the next tick.
     */
    public const int DEFAULT_INCIDENT_THRESHOLD = 2;

    /**
     * Cadence in seconds assumed when a monitor's own `check_interval_sec` is
     * not available, which is narrower than it sounds: the column is `required`
     * on both write requests and NOT NULL in the schema, so this is reached only
     * through a model whose attribute was never loaded, as any `select()` that
     * omits the column produces.
     *
     * Three callers multiply it into a window rather than using it as a cadence,
     * so the value decides how much history a fallback looks at: the evidence
     * lookback in `IncidentAnalysisService` and `IncidentDraftService`, and the
     * reopen window in `ThresholdEvaluator`. They are named in backticks rather
     * than through `{@see}` for the reason spelled out on
     * `MANUAL_CHECK_COOLDOWN_SECONDS` below: Pint's `fully_qualified_strict_types`
     * fixer turns an FQCN in a docblock into a real import, and a domain model
     * importing three services to describe a constant is a worse trade than a
     * reader running one grep. Sixty seconds is the
     * interval most monitors actually run at, and it sits inside the platform's
     * own accepted range (30 to 86400), so a window built on it can never be
     * one the product would have rejected at the door.
     */
    public const int DEFAULT_CHECK_INTERVAL_SEC = 60;

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
        'ai_auto_updates' => false,
        'status' => 'active',
        'consecutive_fails' => 0,
        'incident_threshold' => self::DEFAULT_INCIDENT_THRESHOLD,
        'show_on_status_page' => false,
        'only_show_if_degraded' => false,
        'follow_redirects' => false,
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
        'ai_auto_updates' => 'boolean',
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
        // Opt-in, and honoured only for a customer's own monitor: the catalog
        // probe never follows a redirect whatever this says, because
        // `resources/legal/bot.en.md` promises one URL. The promise is kept in
        // `RelayClient::buildSpec()`.
        'follow_redirects' => 'boolean',
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
     * Catalog services this monitor provides the own-measurement for: the
     * inverse of {@see Service::monitors()}. `withPivot()` is declared on
     * both sides of the relation because Filament's `pivotData()` and
     * `AttachAction` (a later step's Monitor resource) need it on both ends,
     * not just the owning side.
     *
     * @return BelongsToMany<Service>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_monitor')
            ->withPivot('label');
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
            ->active()
            ->where('next_check_at', '<=', $at ?? now());
    }

    /**
     * Scope to monitors under active measurement, whatever their last reading.
     *
     * Every job that probes a monitor belongs behind this scope. `scopeDue()`
     * had the gate inline and the daily SSL fan-out had none, so
     * a paused monitor stopped receiving uptime checks and kept receiving
     * certificate checks, which could open an incident for an endpoint the
     * customer had explicitly stopped watching.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to monitors whose effective status is paused: the SQL mirror of
     * {@see effectiveStatus()}.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePaused(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', self::STATUS_PAUSED)
                ->orWhere('last_status', MonitorStatus::Paused->value);
        });
    }

    /**
     * The complement of {@see scopePaused()}: everything a reader may be shown
     * health for.
     *
     * A never-checked monitor passes: a null `last_status` means "no reading
     * yet", not "paused", and the page shows it as pending.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotPaused(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', self::STATUS_PAUSED)
            ->where(function (Builder $query): void {
                $query->whereNull('last_status')
                    ->orWhere('last_status', '!=', MonitorStatus::Paused->value);
            });
    }

    /**
     * Whether the customer has stopped this monitor.
     */
    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED
            || $this->last_status === MonitorStatus::Paused;
    }

    /**
     * The health a reader may be shown, administrative state first.
     *
     * `last_status` is the last READING; `status` is whether readings are still
     * being taken at all. Pausing writes only the administrative column and
     * deliberately leaves the final reading in place, so a caller that trusts
     * `last_status` alone republishes a frozen reading as live health. That is
     * how a paused monitor came to publish "Major System Outage" on a
     * customer's public status page for an endpoint nothing was probing.
     *
     * Null means the monitor has never been checked, which callers render as
     * pending rather than as any health verdict.
     */
    public function effectiveStatus(): ?MonitorStatus
    {
        if ($this->isPaused()) {
            return MonitorStatus::Paused;
        }

        return $this->last_status;
    }
}
