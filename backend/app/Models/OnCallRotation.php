<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single responder's slot in an {@see OnCallSchedule}'s rotation ring.
 *
 * `position` orders the ring (0-based); `shift_hours` is how long this
 * responder holds the on-call slot before the ring advances to the next
 * position. The rotation resolver (S25) walks the ring using these two
 * columns.
 *
 * Relationships:
 * - belongs to {@see OnCallSchedule} (the ring this slot belongs to)
 * - belongs to {@see User} (the responder)
 */
class OnCallRotation extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'schedule_id',
        'user_id',
        'position',
        'shift_hours',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
        'shift_hours' => 'integer',
    ];

    /**
     * The schedule this rotation slot belongs to.
     *
     * @return BelongsTo<OnCallSchedule, self>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OnCallSchedule::class, 'schedule_id');
    }

    /**
     * The responder assigned to this rotation slot.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
