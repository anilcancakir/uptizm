<?php

namespace App\Models;

use App\Enums\ServiceStatusSource;
use App\Support\Monitoring\HostGuard;
use Database\Factories\ServiceFactory;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

/**
 * A third-party service in uptizm's own catalog: monitored by uptizm's
 * existing probe pipeline, optionally enriched by the provider's own
 * official status feed, curated from a staff-only Filament panel, and
 * published as a localized public SEO page.
 *
 * `is_published` gates the public page and cannot honestly be set true
 * until {@see self::canPublish()} holds; `terms_reviewed_at` records that a
 * human signed off on the provider's terms before this catalog started
 * republishing their status. `content_changed_at` (not `updated_at`) is what
 * a later step's sitemap reads for `lastmod`, and it moves only on a
 * substantive change, never on a routine re-poll.
 *
 * Relationships:
 * - belongs to many {@see Monitor} via `service_monitor` (uptizm's own
 *   own-measurement for this service; the inverse of {@see Monitor::services()})
 * - has one {@see ServiceFeedSnapshot} (the latest fetch of the official feed)
 *
 * @method static ServiceFactory factory(...$parameters)
 */
class Service extends Model
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
        'status_source' => ServiceStatusSource::class,
        'is_published' => 'boolean',
        'terms_reviewed_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'feed_disabled_at' => 'immutable_datetime',
        'content_changed_at' => 'immutable_datetime',
        'display_order' => 'integer',
    ];

    /**
     * Whether this service currently satisfies BOTH publication prerequisites.
     *
     * This is the SOLE enforcement point for the plan's Must Have that a
     * public page cannot go live on a re-rendered provider feed alone: a
     * service with zero attached monitors has no own-measurement to show,
     * which is precisely the thin-content exposure this catalog is only
     * willing to accept on the strength of a mandatory own-measurement
     * block. `tests/Feature/Services/ServiceCatalogTest.php` asserts all three
     * branches (neither condition, terms reviewed but no monitor, and both).
     */
    public function canPublish(): bool
    {
        return $this->terms_reviewed_at !== null
            && $this->monitors()->exists();
    }

    /**
     * Validate a candidate `status_source_url` and throw a re-keyed
     * exception when it is unsafe to fetch.
     *
     * {@see HostGuard::resolveAndAssertAllowed()} is the STRICTER of the
     * guard's two entry points (https-only, no port, no embedded
     * credentials, host must resolve outside the SSRF denylist): this URL is
     * fetched BY the platform itself during ingestion (a later step in this
     * plan), the same shape as an outbound webhook, not merely probed like a
     * customer's own monitor target, so the webhook-grade check applies
     * here rather than the looser {@see HostGuard::assertUrlAllowed()}.
     *
     * Every throw site in {@see HostGuard} hardcodes its
     * `ValidationException` key to `url` (see that class's docblock), so an
     * un-rekeyed message would land on a field no form (this catalog's
     * Filament `ServiceForm`, added in a later step) ever renders, and the
     * operator would see nothing. This method catches that exception and
     * re-throws it keyed on `status_source_url`, the field name every
     * caller actually validates against.
     * `tests/Feature/Services/ServiceCatalogTest.php` pins the rekey by
     * asserting the exception's error bag KEYS, not a message substring,
     * because a substring match would pass on the un-rekeyed version too.
     *
     * A null or empty URL is not an error here: `status_source_url` is
     * nullable, and a service whose `status_source` is `none` legitimately
     * has no feed to validate.
     *
     * @throws ValidationException When the URL is not https, carries a port
     *                             or embedded credentials, has no host,
     *                             cannot be resolved, or resolves to an
     *                             internal address.
     */
    public static function assertStatusSourceUrlAllowed(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        try {
            (new HostGuard)->resolveAndAssertAllowed($url);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'status_source_url' => $exception->errors()['url'],
            ]);
        }
    }

    /**
     * Monitors providing this service's own-measurement: the plan's Must
     * Have that a status page always carries uptizm's OWN probe as
     * first-class content, never only a re-rendered provider feed. The
     * pivot carries an optional public-facing `label`;
     * {@see self::canPublish()} requires at least one row here before the
     * service can go live.
     *
     * @return BelongsToMany<Monitor>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'service_monitor')
            ->withPivot('label');
    }

    /**
     * Most recent fetch of this service's official status feed, or null for
     * a `status_source` of `none` or a service a later ingestion step has
     * not yet fetched.
     *
     * @return HasOne<ServiceFeedSnapshot>
     */
    public function latestFeedSnapshot(): HasOne
    {
        return $this->hasOne(ServiceFeedSnapshot::class)->latestOfMany('fetched_at');
    }
}
