<?php

namespace App\Http\Resources;

use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for a single {@see NotificationChannel} consumed by the Flutter
 * notification-channels view.
 *
 * The raw `credentials` (Slack bot token, webhook signing secret, webhook url)
 * are secrets encrypted at rest and MUST never travel back to the client. The
 * `credentials` block this resource emits is a masked projection: presence
 * booleans plus the non-secret display bits (the Slack channel name, the
 * webhook host), so the UI can render "connected" state and let a user
 * re-enter a secret without ever reading one back (see
 * {@see self::maskedCredentials()}).
 *
 * @mixin NotificationChannel
 */
class NotificationChannelResource extends JsonResource
{
    /**
     * Transform the channel into its wire representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'team_id' => $this->resource->team_id,
            'name' => $this->resource->name,
            'channel_type' => $this->resource->channel_type->value,
            'is_enabled' => $this->resource->is_enabled,
            'severity' => $this->resource->severity->value,
            'credentials' => $this->maskedCredentials(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Project the encrypted credentials to a non-secret, per-type mask.
     *
     * Slack exposes whether a bot token is stored and the (non-secret) target
     * channel name; webhook exposes whether a url + signing secret are stored
     * and the url's host only (never the full url, which could carry a token
     * in its query string); PagerDuty exposes only whether a routing key is
     * stored; Teams exposes whether a url is stored and its host only (the
     * Workflows url carries a `?sig=` SAS token that must never travel back).
     * No branch ever returns a token, secret, full url, or routing key.
     *
     * @return array<string, mixed>
     */
    protected function maskedCredentials(): array
    {
        $credentials = $this->resource->credentials ?? [];

        return match ($this->resource->channel_type) {
            NotificationChannelType::Slack => [
                'has_token' => $this->hasValue($credentials['token'] ?? null),
                'channel' => $credentials['channel'] ?? null,
            ],
            NotificationChannelType::Webhook => [
                'has_url' => $this->hasValue($credentials['url'] ?? null),
                'url_host' => $this->hostOf($credentials['url'] ?? null),
                'has_secret' => $this->hasValue($credentials['secret'] ?? null),
            ],
            NotificationChannelType::PagerDuty => [
                'has_routing_key' => $this->hasValue($credentials['routing_key'] ?? null),
            ],
            NotificationChannelType::Teams => [
                'has_url' => $this->hasValue($credentials['url'] ?? null),
                'url_host' => $this->hostOf($credentials['url'] ?? null),
            ],
        };
    }

    /**
     * Whether a credential value is a present, non-empty string.
     */
    protected function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Extract the host of a webhook url for display, or null when unparseable.
     *
     * Only the host is returned so a secret embedded in the url's path or query
     * never leaks through the resource.
     */
    protected function hostOf(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
