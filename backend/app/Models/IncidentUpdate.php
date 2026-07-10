<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single entry on an incident's unified timeline, mirroring the mockup's
 * `TimelineEntry`. The public status page renders only the rows where
 * `is_public = true`; internal notes stay private.
 *
 * `actor` is the origin kind (human / ai / system); `author` is the
 * free-text display label shown on the timeline (there is no `users` foreign
 * key backing it, so no `author()` relation is declared, see the model's
 * source note). `autonomous` flags an update posted by the AI without human
 * confirmation.
 *
 * Relationships:
 * - belongs to {@see Incident}
 */
class IncidentUpdate extends Model
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
        'status' => IncidentStatus::class,
        'is_public' => 'boolean',
        'autonomous' => 'boolean',
        'display_at' => 'immutable_datetime',
    ];

    /**
     * Incident this update belongs to.
     *
     * @return BelongsTo<Incident, self>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
