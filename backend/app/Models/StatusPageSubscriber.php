<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor who opted in to incident notifications for a status page.
 *
 * `confirmed_token` and `unsubscribe_token` are opaque links used for
 * double opt-in confirmation and one-click unsubscribe; neither is a
 * secret credential, but both are hidden from array/JSON output since
 * they are single-purpose access tokens with no reason to leak.
 *
 * Relationships:
 * - belongs to {@see StatusPage} (the page this subscriber follows)
 */
class StatusPageSubscriber extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'status_page_id',
        'email',
        'confirmed_token',
        'unsubscribe_token',
        'subscribed_at',
        'confirmed_at',
        'newsletter_opt_in',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'confirmed_token',
        'unsubscribe_token',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'subscribed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'newsletter_opt_in' => 'boolean',
    ];

    /**
     * The status page this subscriber follows.
     *
     * @return BelongsTo<StatusPage, self>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }
}
