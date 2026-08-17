<?php

namespace App\Models;

use App\Enums\AiConfidence;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Jobs\SweepAiSuggestions;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single AI-proposed action awaiting an operator decision (suggest mode).
 *
 * `evidence` is a REDACTED, non-secret jsonb payload written by the detection
 * job (observed/baseline/threshold/unit/region_votes/window); the model only
 * casts it to an array, it never accepts writer-supplied secret keys.
 * `source` distinguishes an LLM-authored proposal from the statistical
 * degrade path, and `dedupe_key` (unique at the DB level) stops the same
 * signal from flooding the inbox.
 *
 * `confirmed` is the model's own verdict on the anomaly, and NULL is a real
 * state rather than a missing one: the statistical degrade path calls no model,
 * so it has no verdict to record. It is a label and never a suppression switch,
 * the anomaly stands either way; what it does gate is the AUTONOMOUS open in
 * {@see SweepAiSuggestions}, which declines to act without a human when the
 * model itself does not read the evidence as a real deviation.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to {@see Monitor} (the monitor the signal fired on)
 * - belongs to {@see Incident} (set once an operator accepts the suggestion)
 */
class AiSuggestion extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'monitor_id',
        'kind',
        'signal',
        'method',
        'score',
        'severity',
        'confidence',
        'confirmed',
        'source',
        'recommendation',
        'evidence',
        'dedupe_key',
        'status',
        'expires_at',
        'accepted_incident_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'confidence' => AiConfidence::class,
        'kind' => AiSuggestionKind::class,
        'status' => AiSuggestionStatus::class,
        'evidence' => 'array',
        'score' => 'float',
        'confirmed' => 'boolean',
        'expires_at' => 'datetime',
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
     * The monitor the underlying signal fired on.
     *
     * @return BelongsTo<Monitor, self>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * The incident this suggestion was accepted into, when an operator
     * has acted on it. Null while {@see self::$status} is pending.
     *
     * @return BelongsTo<Incident, self>
     */
    public function acceptedIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'accepted_incident_id');
    }

    /**
     * Scope to suggestions still awaiting an operator decision.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AiSuggestionStatus::Pending);
    }

    /**
     * Scope to suggestions belonging to the given team.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTeam(Builder $query, string $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }
}
