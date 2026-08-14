<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator's rating of one stored analysis.
 *
 * The vote is per (analysis, user) rather than per incident: a thumbs-down
 * says the ANSWER was wrong, and an answer is defined by the evidence it was
 * built from. Re-voting updates the row, so the helpful rate counts people
 * rather than clicks.
 *
 * Relationships:
 * - belongs to {@see AiIncidentAnalysis} (the rated answer)
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to {@see User} (the rater)
 */
class AiAnalysisFeedback extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var string
     */
    protected $table = 'ai_analysis_feedback';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'helpful' => 'boolean',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(AiIncidentAnalysis::class, 'analysis_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
