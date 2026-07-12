<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A temporary responder swap on an {@see OnCallSchedule}: `user_id` covers
 * the schedule between `starts_at` and `ends_at` instead of whoever the
 * rotation ring would otherwise pick. The rotation resolver (S25) checks
 * for an active override before falling back to the ring.
 *
 * Relationships:
 * - belongs to {@see OnCallSchedule} (the schedule being overridden)
 * - belongs to {@see User} (the temporary responder)
 */
class OnCallOverride extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'schedule_id',
        'user_id',
        'starts_at',
        'ends_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * The schedule this override temporarily supersedes.
     *
     * @return BelongsTo<OnCallSchedule, self>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OnCallSchedule::class, 'schedule_id');
    }

    /**
     * The temporary responder covering the override window.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
