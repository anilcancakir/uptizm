<?php

namespace App\Models;

use App\Enums\AiConfidence;
use App\Http\Controllers\Api\V1\DigestController;
use App\Jobs\GenerateWeeklyDigest;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One persisted weekly AI digest for a team, written by
 * {@see GenerateWeeklyDigest}.
 *
 * `summary`/`highlights` are already allowlist-cleaned (or the deterministic
 * degrade text); `uptime_percent`/`incident_count` are the trusted aggregate
 * stats the narration is grounded in. The digest is generated asynchronously
 * on a queue and read by
 * {@see DigestController}; a request never
 * triggers generation synchronously.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 */
class TeamDigest extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'week_start',
        'week_end',
        'uptime_percent',
        'incident_count',
        'confidence',
        'summary',
        'highlights',
        'stripped_citations',
        'generated_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'uptime_percent' => 'float',
        'incident_count' => 'integer',
        'confidence' => AiConfidence::class,
        'highlights' => 'array',
        'stripped_citations' => 'array',
        'generated_at' => 'datetime',
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
     * Scope to digests belonging to the given team.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTeam(Builder $query, string $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }
}
