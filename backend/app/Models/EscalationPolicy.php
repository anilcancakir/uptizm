<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team-scoped, named paging policy: an ordered chain of
 * {@see EscalationStep} rows that fire in sequence after an incident opens.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - has many {@see EscalationStep} (the ordered paging chain)
 */
class EscalationPolicy extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'name',
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
     * Ordered paging chain: fires in ascending `position` order.
     *
     * @return HasMany<EscalationStep>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(EscalationStep::class)->orderBy('position');
    }
}
