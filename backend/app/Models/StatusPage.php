<?php

namespace App\Models;

use App\Enums\DomainMode;
use App\Enums\StatusPagePreviewStatus;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A team's public status page: branding, visibility, and the set of
 * monitors and metrics shown to visitors.
 *
 * `preview_token` gates unlisted preview access while a page is private and
 * must never be exposed in array/JSON output (see {@see self::$hidden}). It is
 * generated on create (see {@see self::booted()}) because the public controller
 * fails closed on an empty stored token: an ungenerated token makes a private
 * page unreadable by every client, the headless preview renderer included.
 * `slug` addresses the page publicly, so implicit route binding resolves by
 * slug rather than by primary key (see {@see self::getRouteKeyName()}).
 *
 * The rendered preview PNG is filesystem state the row owns, so its cleanup
 * lives in a `deleted` hook rather than in the controller that happens to
 * delete: that covers every Eloquent delete path (API, console, seeder,
 * `Model::destroy`) instead of one. Two paths still leak the file because they
 * raise no Eloquent event at all: a mass `Builder::delete()`, and the
 * DATABASE-level `team_id` cascade when a team is deleted. The latter is pinned
 * by a characterization test rather than left as a comment.
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
     * Disk the rendered preview PNG lives on, pinned BY NAME.
     *
     * Never `config('filesystems.default')`: a deploy that points that at a
     * public disk would publish the PNG of a page whose whole design is a 404
     * mask. `local` is `storage/app/private`.
     */
    public const PREVIEW_DISK = 'local';

    /**
     * Directory holding one PNG per status page.
     */
    public const PREVIEW_DIRECTORY = 'status-page-previews';

    /**
     * Number of characters in a generated `preview_token`, matching the value
     * the seeder has always used.
     */
    protected const PREVIEW_TOKEN_LENGTH = 40;

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
     * Mirror the schema default in memory.
     *
     * `domain_mode` is NOT NULL with a `path` default applied by the database,
     * so without this a freshly created model reads back null until it is
     * refreshed, and the API answered `domain_mode: null` for a row the database
     * stores as `path`. The client's own default for an unknown wire value is
     * `subdomain`, so a page created as path-addressed was displayed as
     * subdomain-addressed.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'domain_mode' => 'path',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'domain_mode' => DomainMode::class,
        'is_public' => 'boolean',
        'subscriptions_enabled' => 'boolean',
        'preview_rendered_at' => 'immutable_datetime',
        'preview_render_status' => StatusPagePreviewStatus::class,
    ];

    /**
     * Registers the two pieces of lifecycle the row owns beyond its columns.
     *
     * Both are model hooks on purpose: a controller-side equivalent would cover
     * one endpoint and miss the seeder, a factory, a console command and
     * `Model::destroy`. What a model hook cannot see is a delete that never
     * becomes an Eloquent event, which is why the class docblock names those two
     * paths explicitly rather than implying full coverage.
     */
    protected static function booted(): void
    {
        // 1. A page with no preview token cannot be read while private, not even
        //    by the renderer, since the public controller fails closed on an
        //    empty stored token. Fill only when empty so a token a caller chose
        //    deliberately is never silently rotated.
        static::creating(function (self $page): void {
            if (empty($page->preview_token)) {
                $page->preview_token = Str::random(self::PREVIEW_TOKEN_LENGTH);
            }
        });

        // 2. The stored PNG is the row's own filesystem state, so it dies with
        //    the row. Guarded on the column, so a page that never rendered is a
        //    no-op, and the `local` disk is configured with `throw => false`, so
        //    an already-missing file cannot block the delete.
        static::deleted(function (self $page): void {
            if (empty($page->preview_image_path)) {
                return;
            }

            Storage::disk(self::PREVIEW_DISK)->delete($page->preview_image_path);
        });
    }

    /**
     * Deterministic storage key of this page's rendered preview PNG.
     *
     * The single definition of the path convention: the renderer writes here,
     * the delete hook cleans up here, and neither can drift from the other. One
     * file per page, overwritten on every render, so storage stays bounded.
     */
    public function previewImageStoragePath(): string
    {
        return self::PREVIEW_DIRECTORY.'/'.$this->getKey().'.png';
    }

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
    /**
     * The one URL this page should be shared, indexed and linked under.
     *
     * BUILT FROM CONFIGURATION, NEVER FROM THE INCOMING REQUEST. `route()` resolves an
     * absolute URL against whatever host the request arrived on, and this application
     * answers on more than one: the Flutter client calls the API at `api.<host>`, so a
     * resource built with `route()` handed the operator
     * `https://api.uptizm.com/s/<slug>` to paste into a customer email. The API host is
     * not where a customer reads a status page.
     *
     * It lives on the model rather than in the controller that first needed it, because
     * the address is a property OF the page: the rendered page's canonical tag and the
     * API resource the editor displays have to be the same string, and they were not.
     *
     * `domain_mode` picks the form. A mode whose prerequisite is missing (a custom
     * domain that is not set, a subdomain host that is not configured) falls back to the
     * path form, which always resolves.
     */
    public function publicUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

        if ($this->domain_mode === DomainMode::Custom && is_string($this->custom_domain) && $this->custom_domain !== '') {
            return $scheme.'://'.$this->custom_domain.'/';
        }

        $subdomainHost = config('status_pages.subdomain_host');

        if ($this->domain_mode === DomainMode::Subdomain && is_string($subdomainHost) && $subdomainHost !== '') {
            return $scheme.'://'.$this->slug.'.'.$subdomainHost.'/';
        }

        return $base.'/s/'.$this->slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
