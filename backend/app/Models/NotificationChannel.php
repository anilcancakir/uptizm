<?php

namespace App\Models;

use App\Enums\NotificationChannelSeverity;
use App\Enums\NotificationChannelType;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team-scoped Slack or generic-webhook integration that fires on
 * incidents, filtered by {@see self::$severity}.
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 */
class NotificationChannel extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'channel_type',
        'credentials',
        'is_enabled',
        'severity',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'channel_type' => NotificationChannelType::class,
        // Credentials are encrypted at rest (column is `text`); the cast reads
        // back the decrypted array transparently for the send-time channels.
        'credentials' => 'encrypted:array',
        'is_enabled' => 'boolean',
        'severity' => NotificationChannelSeverity::class,
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
}
