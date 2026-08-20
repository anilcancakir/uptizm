<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\V1\StatusPagePreviewImageController;
use App\Models\StatusPage;
use App\Support\SignedAssetUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * JSON shape for a single status page consumed by the Flutter status-page
 * editor and list screens.
 *
 * `preview_token` never appears here (mirrors {@see StatusPage::$hidden});
 * the attached `monitors` array is only populated when the relation was
 * eager-loaded ({@see self::monitors()}), so `index` stays a single query
 * while `show` can carry the full component list.
 *
 * `preview_image_url` is a SIGNED CAPABILITY, not an identifier, so unlike every
 * other field here it is emitted from `show` alone. See
 * {@see self::previewImageUrl()} for the two properties its construction has to
 * satisfy at once and {@see StatusPagePreviewImageController} for what holding
 * one grants.
 *
 * @property StatusPage $resource
 */
class StatusPageResource extends JsonResource
{
    /**
     * Name of the route this resource serialises a single page for.
     *
     * `apiResource` names its routes without the `api.v1.` prefix the manually
     * registered status-page routes carry, so this is `status-pages.show` and
     * not `api.v1.status-pages.show`.
     */
    protected const SHOW_ROUTE_NAME = 'status-pages.show';

    /**
     * Width of the signed URL's expiry bucket, in seconds.
     *
     * Quantising the expiry is what makes the URL stable; see
     * {@see self::signatureExpiresAt()}.
     */
    /**
     * Transform the status page into its wire representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team_id' => $this->resource->team_id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            // `->value` explicitly: the attribute is a DomainMode cast, and the
            // client reads the wire string, not an object shape.
            'domain_mode' => $this->resource->domain_mode->value,
            'custom_domain' => $this->resource->custom_domain,
            'brand_color' => $this->resource->brand_color,
            // The raw path is deliberately NOT exposed. It is a storage key on a
            // private disk, so it is useless to a client and misleading to read:
            // the only way to see the bytes is the signed URL below.
            'logo_url' => $this->logoUrl(),
            'logo_text' => $this->resource->logo_text,
            'description' => $this->resource->description,
            // The language the public page publishes in, null for the deployment
            // default. Emitted so the operator UI CAN show and change it; it does
            // not yet, because `lib/app/models/status_page.dart` carries no `locale`
            // and the status-page form has no field for it. So today the value is
            // set through the API or a console command, and this key is the half of
            // the contract that has to exist first. Say so rather than implying the
            // product surfaces it.
            'locale' => $this->resource->locale,
            // The URL the page is actually served at, resolved from the route
            // itself. The client used to compose this string ("uptizm.com/
            // status/<slug>"), which no route answers: an operator who copied
            // it handed their customers a 404. The public URL is the backend's
            // fact, so the backend states it.
            // NOT route(): it resolves against the request host, and the client calls
            // this API at `api.<host>`, so the editor was showing an address on the API
            // host for the operator to paste into a customer email. The model composes it
            // from configuration and honours `domain_mode`.
            'public_url' => $this->resource->slug === null
                ? null
                : $this->resource->publicUrl(),
            'is_public' => (bool) $this->resource->is_public,
            'subscriptions_enabled' => (bool) $this->resource->subscriptions_enabled,
            'monitors' => $this->whenLoaded('monitors', function (): array {
                return $this->resource->monitors
                    ->map(static fn ($monitor): array => [
                        'id' => $monitor->id,
                        'name' => $monitor->name,
                        'display_order' => (int) $monitor->pivot->display_order,
                        'custom_label' => $monitor->pivot->custom_label,
                        // The component's live health, so the client can render a
                        // page's real overall status instead of assuming
                        // "Operational". Null means never checked yet, which the
                        // client shows as pending rather than as up.
                        'last_status' => $monitor->effectiveStatus()?->value,
                        // The SECOND gate on public visibility. Attaching a
                        // monitor is not enough: StatusPageAssembler also filters
                        // on show_on_status_page, so without this the client's
                        // in-app preview would promise a component the real public
                        // page hides.
                        'show_on_status_page' => (bool) $monitor->show_on_status_page,
                    ])
                    ->all();
            }),
            // Emitted from `show` only, so the capability is not multiplied
            // across list responses. `whenLoaded` cannot express that here:
            // `index` eager-loads `monitors` too, so the loaded relations of the
            // two endpoints are identical and the request itself is the only
            // thing that distinguishes them.
            'preview_image_url' => $this->when(
                $request->routeIs(self::SHOW_ROUTE_NAME),
                fn (): ?string => $this->previewImageUrl(),
            ),
            'preview_rendered_at' => $this->resource->preview_rendered_at?->toIso8601String(),
            // Null means never rendered. The enum has no `pending` case, so the
            // client reads the absence of a value rather than a second spelling
            // of the same fact.
            'preview_render_status' => $this->resource->preview_render_status?->value,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Mint the signed URL the client's image widget fetches the PNG from, or
     * null when there is no render to point at.
     *
     * The URL has to satisfy two requirements that pull in opposite directions,
     * and getting either wrong is visible in the editor:
     *
     *   - It must be STABLE while the image is unchanged. Flutter's `ImageCache`
     *     is keyed on the URL string, and the editor re-reads this resource on
     *     every poll tick, so a URL carrying a raw `expires` timestamp would be
     *     a new URL each time and the pane would visibly reload.
     *   - It must CHANGE when the image changes. The PNG is overwritten at one
     *     path per page, so a URL that never changed would keep serving the
     *     previous render out of that same cache after a refresh.
     *
     * Hence a bucketed expiry plus `v`, the render's own version. Second
     * granularity for `v` matches the column's: two renders inside one second
     * are not distinguishable here, which no real render can be (one holds a
     * Chromium process for seconds, and the job is unique per page while queued).
     */
    /**
     * Signed URL for the uploaded brand logo, or null when the page has none.
     *
     * Minted by {@see SignedAssetUrl::forStatusPageLogo()} rather than here,
     * because the assembled public read model mints the same URL for the Blade
     * page and the two must agree.
     *
     * Unlike the preview PNG this is NOT restricted to `show`: the editor needs
     * it, and so does anything rendering a page's brand mark from a list.
     */
    protected function logoUrl(): ?string
    {
        return SignedAssetUrl::forStatusPageLogo($this->resource);
    }

    protected function previewImageUrl(): ?string
    {
        $path = $this->resource->preview_image_path;
        $renderedAt = $this->resource->preview_rendered_at;

        if (! is_string($path) || $path === '' || $renderedAt === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            StatusPagePreviewImageController::ROUTE_NAME,
            $this->signatureExpiresAt(),
            [
                'statusPage' => $this->resource->getKey(),
                'v' => $renderedAt->getTimestamp(),
            ],
        );
    }

    /**
     * Expiry quantised to the next-but-one bucket boundary.
     *
     * The rule, and why it rounds down and adds two buckets rather than rounding
     * up, lives on {@see SignedAssetUrl::expiresAt()}: the logo URL is minted
     * from there too, and two copies of an expiry rule drift into an image that
     * 403s on one surface and not the other.
     */
    protected function signatureExpiresAt(): Carbon
    {
        return SignedAssetUrl::expiresAt();
    }
}
