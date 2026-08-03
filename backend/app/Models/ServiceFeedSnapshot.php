<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single fetch of a catalog service's official status feed.
 *
 * Immutable in spirit (a new row per fetch, never updated in place): only
 * the parsed `indicator`/`components`/`incidents` and the normalized content
 * hash are stored, never the raw provider payload (see the table's own
 * migration docblock for why). `etag` is what a later ingestion step sends
 * back as `If-None-Match` on the next fetch of the same service.
 *
 * Relationships:
 * - belongs to {@see Service}
 */
class ServiceFeedSnapshot extends Model
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
        'fetched_at' => 'immutable_datetime',
        'http_status' => 'integer',
        'components' => 'array',
        'incidents' => 'array',
    ];

    /**
     * The catalog service this snapshot was fetched for.
     *
     * @return BelongsTo<Service, self>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
