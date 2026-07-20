<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team's public status page: branding, visibility, and the set of
 * monitors and metrics shown to visitors.
 *
 * `preview_token` gates unlisted preview access while a page is private and
 * must never be exposed in array/JSON output (see {@see self::$hidden}).
 * `slug` addresses the page publicly, so implicit route binding resolves by
 * slug rather than by primary key (see {@see self::getRouteKeyName()}).
 *
 * Relationships:
 * - belongs to {@see Team} (tenant boundary)
 * - belongs to many {@see Monitor} via `status_page_monitors` (displayed components)
 * - belongs to many {@see Monitor} via `status_page_metrics` (charted metrics, deferred)
 * - has many {@see StatusPageSubscriber} (incident-notification opt-ins)
 */
class StatusPage extends Model
{
    use ConditionallyUsesUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'domain_mode',
        'custom_domain',
        'brand_color',
        'logo_path',
        'logo_text',
        'description',
        'is_public',
        'subscriptions_enabled',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'preview_token',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_public' => 'boolean',
        'subscriptions_enabled' => 'boolean',
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
     * Monitors displayed on this status page, ordered for the public page's
     * component list. The pivot carries the display position and an
     * optional team-facing rename.
     *
     * @return BelongsToMany<Monitor>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'status_page_monitors')
            ->withPivot([
                'display_order',
                'custom_label',
            ])
            ->orderByPivot('display_order');
    }

    /**
     * Monitor metrics selected for this status page (the pivot carries the
     * `metric_key`). The live-metrics grid render is deferred, so this relation
     * exists for schema completeness and is not yet exercised.
     *
     * @return BelongsToMany<Monitor>
     */
    public function metrics(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'status_page_metrics')
            ->withPivot('metric_key');
    }

    /**
     * Visitors who opted in to incident notifications for this page.
     *
     * @return HasMany<StatusPageSubscriber>
     */
    public function subscribers(): HasMany
    {
        return $this->hasMany(StatusPageSubscriber::class);
    }

    /**
     * Scope to status pages a team has published for public viewing.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Resolve implicit route binding by `slug` instead of the primary key,
     * since a status page is addressed publicly by its slug.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
