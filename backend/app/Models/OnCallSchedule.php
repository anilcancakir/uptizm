<?php

namespace App\Models;

use App\Services\OnCall\RotationResolver;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team-scoped on-call schedule: a named rotation ring plus its temporary
 * overrides. {@see RotationResolver} (S25) resolves a
 * schedule against a point in time into "who is on call right now".
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - has many {@see OnCallRotation} (the ordered ring of responders)
 * - has many {@see OnCallOverride} (temporary responder swaps)
 */
class OnCallSchedule extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'timezone',
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
     * The ordered ring of responders for this schedule, sorted by
     * `position` so the rotation resolver can walk the ring in order.
     *
     * @return HasMany<OnCallRotation>
     */
    public function rotations(): HasMany
    {
        return $this->hasMany(OnCallRotation::class, 'schedule_id')->orderBy('position');
    }

    /**
     * Temporary responder swaps layered on top of the rotation.
     *
     * @return HasMany<OnCallOverride>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(OnCallOverride::class, 'schedule_id');
    }
}
