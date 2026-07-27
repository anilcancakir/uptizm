<?php

namespace App\Models;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\SignalSource;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An unhealthy window on one or more monitors, opened by a threshold
 * breach, an AI signal, or a manual create.
 *
 * Lifecycle follows the mockup's simplified state machine
 * (detected -> investigating -> identified -> monitoring -> resolved).
 * The affected components live in the `incident_monitors` pivot;
 * `primary_monitor_id` is the denormalized primary-affected hint so the
 * list view can badge an incident without loading the pivot. `ai_owned`
 * distinguishes AI-driven incidents from user-threshold ones for badging
 * and filtering.
 *
 * The postmortem lives on the row as `postmortem_body` +
 * `postmortem_published_at`: the body alone is an internal draft, and only a
 * non-null publication stamp makes it customer-visible on the public status
 * page.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to {@see Monitor} (primary monitor hint)
 * - belongs to many {@see Monitor} via `incident_monitors` (affected components)
 * - has many {@see IncidentUpdate} (unified public + internal timeline)
 * - belongs to {@see User} (the assigned responder, nullable)
 */
class Incident extends Model
{
    use ConditionallyUsesUuids;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'impact' => IncidentImpact::class,
        'severity' => IncidentSeverity::class,
        'signal_source' => SignalSource::class,
        'lifecycle' => IncidentStatus::class,
        'ai_owned' => 'boolean',
        'started_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'postmortem_published_at' => 'immutable_datetime',
    ];

    /**
     * Whether the postmortem has been published to the public status page.
     * A body with no publication stamp is an internal draft.
     */
    public function postmortemIsPublished(): bool
    {
        return $this->postmortem_published_at !== null
            && $this->postmortem_body !== null
            && trim($this->postmortem_body) !== '';
    }

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
     * Primary monitor that opened this incident. The full affected-component
     * set lives on {@see self::monitors()}.
     *
     * @return BelongsTo<Monitor, self>
     */
    public function primaryMonitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'primary_monitor_id');
    }

    /**
     * Monitors participating in this incident. The pivot captures the
     * component status at open and the live status, so the UI can narrate
     * "started as degraded, now operational".
     *
     * @return BelongsToMany<Monitor>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'incident_monitors')
            ->withPivot([
                'component_status_at_start',
                'component_status_current',
            ])
            ->withTimestamps();
    }

    /**
     * The team member currently driving the response, or null when the
     * incident is unassigned. Set through the operator assign endpoint, which
     * enforces that the user belongs to the incident's team.
     *
     * @return BelongsTo<User, self>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * The unified incident timeline (public + internal updates).
     *
     * @return HasMany<IncidentUpdate>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('display_at');
    }
}
