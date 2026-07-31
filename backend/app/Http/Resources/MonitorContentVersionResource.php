<?php

namespace App\Http\Resources;

use App\Models\MonitorContentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape of one archived page content: its address plus the metadata needed
 * to decide whether it is worth downloading, and NOTHING else.
 *
 * THE ARCHIVED BYTES NEVER APPEAR HERE, the same deliberate omission
 * {@see MonitorCheckResource} makes for the response-body preview. The content is
 * whatever the monitored target chose to serve, so it only ever leaves this
 * system through the download action, which forces an attachment; a body field on
 * a metadata response would put attacker-controlled markup into every JSON
 * consumer that renders a list, bypassing that whole guarantee. Adding one is the
 * defect this docblock exists to prevent.
 *
 * `content_hash` doubles as the row's public identifier: it is the address the
 * download endpoint takes, so the surrogate `id` is not emitted and a caller has
 * no second handle to confuse with it. `content_hash_normalized` is withheld for
 * the same reason it is not an address: it is the internal change signal, and a
 * caller that treated it as a download key would get a 404 for every request.
 *
 * @property MonitorContentVersion $resource
 */
class MonitorContentVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'content_hash' => $this->resource->content_hash,
            // The RAW decoded length, not the size of the gzipped blob on disk.
            'byte_size' => $this->resource->byte_size,
            'content_type' => $this->resource->content_type,
            'truncated' => $this->resource->truncated,
            'first_seen_at' => $this->resource->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->resource->last_seen_at?->toIso8601String(),
        ];
    }
}
