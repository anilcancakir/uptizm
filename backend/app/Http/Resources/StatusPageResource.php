<?php

namespace App\Http\Resources;

use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single status page consumed by the Flutter status-page
 * editor and list screens.
 *
 * `preview_token` never appears here (mirrors {@see StatusPage::$hidden});
 * the attached `monitors` array is only populated when the relation was
 * eager-loaded ({@see self::monitors()}), so `index` stays a single query
 * while `show` can carry the full component list.
 *
 * @property StatusPage $resource
 */
class StatusPageResource extends JsonResource
{
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
            'domain_mode' => $this->resource->domain_mode,
            'custom_domain' => $this->resource->custom_domain,
            'brand_color' => $this->resource->brand_color,
            'logo_path' => $this->resource->logo_path,
            'logo_text' => $this->resource->logo_text,
            'description' => $this->resource->description,
            // The URL the page is actually served at, resolved from the route
            // itself. The client used to compose this string ("uptizm.com/
            // status/<slug>"), which no route answers: an operator who copied
            // it handed their customers a 404. The public URL is the backend's
            // fact, so the backend states it.
            'public_url' => $this->resource->slug === null
                ? null
                : route('status.show', ['slug' => $this->resource->slug]),
            'is_public' => (bool) $this->resource->is_public,
            'subscriptions_enabled' => (bool) $this->resource->subscriptions_enabled,
            'monitors' => $this->whenLoaded('monitors', function (): array {
                return $this->resource->monitors
                    ->map(static fn ($monitor): array => [
                        'id' => $monitor->id,
                        'name' => $monitor->name,
                        'display_order' => (int) $monitor->pivot->display_order,
                        'custom_label' => $monitor->pivot->custom_label,
                    ])
                    ->all();
            }),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
