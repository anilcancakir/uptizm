<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
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
}
