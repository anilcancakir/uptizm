<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted send through a team-scoped {@see NotificationChannel}: the
 * audit and future-SLA record this plan adds beside the email lane that
 * already works.
 *
 * `channel_id` survives its channel's deletion as null ({@see NotificationChannel}'s
 * FK is `nullOnDelete()`); `team_id` and the denormalised `channel_type` are
 * what keep the row legible once that happens.
 *
 * This table records ATTEMPTED deliveries only: upstream gates in
 * `IncidentDispatcher` can drop a send before any event fires, so an absent
 * row must never be read as a delivery that should have happened.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to {@see NotificationChannel} (nullable once the channel is deleted)
 */
class NotificationDelivery extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'channel_id',
        'channel_type',
        'notification_type',
        'event',
        'outcome',
        'status_code',
        'error_code',
        'exception_class',
        'is_test',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_test' => 'boolean',
        'status_code' => 'integer',
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
     * The channel this delivery went through, or null once that channel has
     * been deleted ({@see NotificationChannel}'s FK is `nullOnDelete()`).
     *
     * @return BelongsTo<NotificationChannel, self>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }
}
