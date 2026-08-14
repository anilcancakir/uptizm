<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stored answer from the post-incident analyser, keyed by the evidence it
 * was built from.
 *
 * `IncidentAnalysisService::analyzeFor()` reads through this table before it
 * spends a budget unit: a row whose `evidence_fingerprint` still matches the
 * current evidence is the same answer the model would give again, so it is
 * served instead of re-asked. A fingerprint miss is what a stale answer looks
 * like, and it produces a new row rather than overwriting the old one, because
 * the {@see AiAnalysisFeedback} pointing at the old one rates the old text.
 *
 * `result` holds the composed wire shape verbatim (`IncidentAnalysisResult`'s
 * `toArray()`), so the client decodes a stored answer and a fresh one through
 * one path. Only a model-authored answer is stored; a degrade is the absence of
 * one and stays transient.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to {@see Incident}
 * - has many {@see AiAnalysisFeedback} (one vote per user)
 */
class AiIncidentAnalysis extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'result' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiAnalysisFeedback::class, 'analysis_id');
    }
}
