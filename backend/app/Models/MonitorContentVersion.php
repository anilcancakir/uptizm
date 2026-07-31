<?php

namespace App\Models;

use Database\Factories\MonitorContentVersionFactory;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One distinct page content ever observed for a monitor, keyed by hash.
 *
 * `content_hash` is the raw hash of the stored bytes: it is the version's
 * ADDRESS and the stem of its blob filename, nothing more. It is NOT the
 * change signal; on an unchanged check it is an EARLIER body's raw hash, not
 * a description of the current one. `content_hash_normalized` is the change
 * signal, and `(monitor_id, content_hash_normalized, normalizer_version)` is
 * the key the archive's change decision reads to answer "have we seen this
 * content before". Reading the two hashes as interchangeable views of the
 * same bytes is the mistake this docblock exists to prevent.
 *
 * Unlike {@see MonitorCheck}, this model KEEPS `updated_at`: a version row is
 * revised in place every time its content is seen again (`last_seen_at`
 * advances), it is not an immutable log entry.
 *
 * Relationships:
 * - belongs to {@see Monitor}
 * - belongs to {@see Team} (denormalized for direct team-scoped queries)
 *
 * @method static MonitorContentVersionFactory factory(...$parameters)
 */
class MonitorContentVersion extends Model
{
    use ConditionallyUsesUuids;
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'byte_size' => 'integer',
        'truncated' => 'boolean',
        'normalizer_version' => 'integer',
        'first_seen_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
    ];

    /**
     * Owning monitor.
     *
     * @return BelongsTo<Monitor, self>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * Denormalized tenant link for direct team-scoped queries.
     *
     * @return BelongsTo<Team, self>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
