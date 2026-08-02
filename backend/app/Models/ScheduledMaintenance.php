<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A planned, publicly announced window during which one or more monitors
 * are expected to be unhealthy on purpose.
 *
 * A window carries no lifecycle enum: whether it is upcoming, active, or
 * past is derived from `starts_at`/`ends_at` against the current time by its
 * callers, never stored. `suppress_alerts` tells the alert pipeline (Step 9)
 * to hold paging while the window is active; `announced_at` is the
 * announce-once guard the subscriber mail job (Step 8) sets the first time
 * it actually sends.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to {@see StatusPage} (where the window is announced)
 * - belongs to many {@see Monitor} via `scheduled_maintenance_monitors` (affected components)
 */
class ScheduledMaintenance extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'status_page_id',
        'title',
        'description',
        'suppress_alerts',
        'starts_at',
        'ends_at',
        'announced_at',
    ];

    /**
     * Mirror the schema default in memory.
     *
     * `suppress_alerts` is NOT NULL with a `true` default applied by the
     * database, so without this a freshly built (unsaved) model reads back
     * null until it is persisted and refreshed, the same trap this codebase
     * hit on {@see StatusPage::$attributes}'s `domain_mode`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'suppress_alerts' => true,
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'suppress_alerts' => 'boolean',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'announced_at' => 'immutable_datetime',
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
     * The status page this window is announced on.
     *
     * @return BelongsTo<StatusPage, self>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /**
     * Monitors expected to be affected by this maintenance window.
     *
     * @return BelongsToMany<Monitor>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'scheduled_maintenance_monitors')
            ->withTimestamps();
    }

    /**
     * Windows that are open at `$at` (default now) AND set to suppress alerts.
     *
     * Every paging path gates on this one scope, and it lives on the model
     * rather than in either caller because the two drifted apart once already:
     * IncidentDispatcher held the immediate page while the escalation ladder,
     * whose steps fire minutes later from delayed jobs, paged straight through
     * planned work. One definition, both callers.
     *
     * `suppress_alerts` is the switch: a window created with it off announces
     * the work publicly and still pages.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpenSuppressing(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        $now = $at ?? CarbonImmutable::now();

        // Columns are table-qualified so the scope survives being composed with
        // a join onto the pivot, which `suppressedMonitorIds()` does.
        return $query
            ->where('scheduled_maintenances.suppress_alerts', true)
            ->where('scheduled_maintenances.starts_at', '<=', $now)
            ->where('scheduled_maintenances.ends_at', '>=', $now);
    }

    /**
     * The ids, out of `$monitorIds`, that sit inside an open suppressing
     * window belonging to `$teamId`.
     *
     * Scoped to the team on purpose. Today no request can attach a foreign
     * monitor (both maintenance form requests pin `monitor_ids` to the acting
     * team), so this predicate removes nothing; it is here so the guarantee is
     * an invariant of the query instead of a property of two validators, since
     * the pivot itself carries no team column.
     *
     * @param  list<string>  $monitorIds
     * @return list<string>
     */
    public static function suppressedMonitorIds(string $teamId, array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        return self::query()
            ->openSuppressing()
            ->where('scheduled_maintenances.team_id', $teamId)
            ->join(
                'scheduled_maintenance_monitors',
                'scheduled_maintenances.id',
                '=',
                'scheduled_maintenance_monitors.scheduled_maintenance_id',
            )
            ->whereIn('scheduled_maintenance_monitors.monitor_id', $monitorIds)
            ->distinct()
            ->pluck('scheduled_maintenance_monitors.monitor_id')
            ->all();
    }
}
