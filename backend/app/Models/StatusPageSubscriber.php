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
 * Two timestamps look alike and are not: `confirmed_at` says the row is active,
 * while `opt_in_confirmed_at` says THIS system recorded a click on a confirm
 * link for this address. Only the public confirm endpoint may write the second
 * one, and outbound announcements select on it alone, because rows written
 * before it existed carry `confirmed_at` with no way to tell a proven opt-in
 * from a pasted address.
 *
 * `locale` is the language this subscriber was reading the page in at
 * subscribe time (falling back to the page's own canonical language when the
 * form submitted none), captured once and read by every subsequent
 * subscriber-facing render instead of the page's current language, which may
 * have changed since.
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
        'opt_in_confirmed_at',
        'newsletter_opt_in',
        'locale',
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
        'opt_in_confirmed_at' => 'datetime',
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
