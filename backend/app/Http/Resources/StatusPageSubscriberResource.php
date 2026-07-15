<?php

namespace App\Http\Resources;

use App\Models\StatusPageSubscriber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single status-page subscriber consumed by the Flutter
 * status-page admin (Subscribers) screen.
 *
 * The opaque `confirmed_token`/`unsubscribe_token` never appear here (they are
 * single-purpose link tokens hidden on the model): only the fields the admin
 * screen renders are exposed. `confirmed` collapses the nullable
 * `confirmed_at` timestamp into the boolean the client decodes.
 *
 * @property StatusPageSubscriber $resource
 */
class StatusPageSubscriberResource extends JsonResource
{
    /**
     * Transform the subscriber into its wire representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'email' => $this->resource->email,
            'subscribed_at' => $this->resource->subscribed_at?->toIso8601String(),
            'confirmed' => $this->resource->confirmed_at !== null,
            'newsletter_opt_in' => (bool) $this->resource->newsletter_opt_in,
        ];
    }
}
