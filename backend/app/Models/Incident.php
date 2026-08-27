<?php

namespace App\Models;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\SignalSource;
use App\Services\Monitoring\IncidentTitle;
use App\Support\SearchText;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
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
 * The title exists in three forms. `title` holds the English sentence, which is
 * what the LLM prompts and any reader with no locale need; `title_key` plus
 * `title_params` hold the structure a localized surface renders from through
 * {@see IncidentTitle::render()}. A null `title_key` means a human authored the
 * title, and it is also what every row written before that seam existed looks
 * like.
 *
 * `title_search` is the third, and it belongs to the operator rather than to a
 * machine: every one of those forms folded together through
 * {@see SearchText::fold()}, so a search matches the words on somebody's
 * screen whichever language rendered them. Search reads it and nothing else;
 * the English column is not what anyone is looking at. See
 * {@see self::composeSearchText()}.
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
        // Without this cast a persisted title_params arrives as a JSON string,
        // and IncidentTitle::render() would hand it to __() as one replacement
        // rather than as a set, rendering a key with unreplaced placeholders.
        'title_params' => 'array',
        'started_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'postmortem_published_at' => 'immutable_datetime',
    ];

    /**
     * Keep `title_search` in step with whatever the title is made of.
     *
     * A hook rather than a generated column because the value cannot be
     * expressed in SQL: it needs the translation catalogue, which lives in PHP.
     *
     * The dirty check is not an optimisation for its own sake. This model is
     * saved on every lifecycle move, and recomposing would lazy-load the
     * primary monitor each time, so a resolve storm would issue one extra query
     * per incident for a value that cannot have changed.
     */
    protected static function booted(): void
    {
        static::saving(function (self $incident): void {
            // `primary_monitor_id` is not in this list even though the composed
            // value reads it: every write path sets it at create and nothing
            // repoints an incident afterwards, so a save can only find it
            // unchanged. A new row has no `exists` and composes regardless.
            if ($incident->exists && ! $incident->isDirty(['title', 'title_key', 'title_params'])) {
                return;
            }

            $incident->title_search = self::composeSearchText($incident);
        });
    }

    /**
     * Every form of this incident's title at once, folded for comparison.
     *
     * The stored English, the render in each supported locale, and the primary
     * monitor's name, so one `LIKE` over the result matches the words on the
     * operator's screen whichever language produced them. Public because the
     * backfill migration composes the same value for rows written before the
     * column existed.
     *
     * THE MONITOR NAME IS FROZEN HERE, exactly as `title_params` freezes it: a
     * renamed monitor does not rewrite the sentence a past incident was
     * announced under, and having search disagree with the title it is
     * searching would be worse than having both be historical.
     */
    public static function composeSearchText(self $incident): string
    {
        $parts = [(string) $incident->title];

        foreach ((array) config('magic-starter.supported_locales', ['en']) as $locale) {
            $parts[] = IncidentTitle::render($incident, (string) $locale);
        }

        $parts[] = $incident->primaryMonitor?->name;

        $parts = array_unique(array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));

        return SearchText::fold(implode(' ', $parts));
    }

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
     * Narrow to the incidents that still demand operator attention.
     *
     * The SQL half of {@see IncidentStatus::isActive()}, reading the terminal
     * set from the enum so the two cannot drift. Without it "is this one still
     * open" was answerable only after hydration, and every caller that wanted a
     * monitor's one active incident loaded that monitor's entire incident
     * history to find it: unbounded, on a query that runs once per monitor per
     * check tick.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('lifecycle', IncidentStatus::terminalValues());
    }

    /**
     * Monitors participating in this incident.
     *
     * The pivot captures the component status AT OPEN. It also carries a
     * `component_status_current` column that was meant to be live, so the UI
     * could narrate "started as degraded, now operational", but nothing has ever
     * updated it: all three openers write it equal to `component_status_at_start`
     * and no other code touches it. So the narration is served by reading the
     * monitor's own {@see Monitor::effectiveStatus()} at render time, in
     * IncidentResource, which cannot go stale, rather than by synchronising a
     * second copy of a status the monitor already owns.
     *
     * The column is still selected here because existing rows and fixtures carry
     * it; dropping it is a migration plus 18 fixture sites and is not what makes
     * the surface honest.
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
