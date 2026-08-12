<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A machine-translated value for one field, in one language, of one row.
 *
 * A translation is an attribute of a ROW, never of a bare string: it is
 * addressed by `(translatable_type, translatable_id, field, locale)`, and
 * `team_id` rides along denormalised so a team-scoped read never needs a
 * join back through the translatable row. `source_hash` only detects that
 * the source text moved under a stored translation; it is never a lookup
 * key. `value` is nullable and a non-null `rejected_at` records that the
 * output-contract rejected the translation without storing the suspect
 * text, which lets the read model distinguish "no translation yet" from
 * "translation unavailable" without re-queueing forever.
 *
 * Relationships:
 * - belongs to {@see Team} (denormalized tenant guard)
 * - morphs to the translated row (Incident, IncidentUpdate, Maintenance, StatusPage)
 */
class StatusPageTranslation extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'translatable_type',
        'translatable_id',
        'field',
        'locale',
        'value',
        'source_hash',
        'rejected_at',
        'rejection_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rejected_at' => 'datetime',
    ];

    /**
     * Denormalized tenant link for direct team-scoped queries.
     *
     * @return BelongsTo<Team, self>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The translated row, resolved polymorphically.
     *
     * @return MorphTo<Model, self>
     */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
