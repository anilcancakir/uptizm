<?php

namespace App\Enums;

/**
 * Canonical probe regions the relay supports.
 *
 * Each case maps to a Cloudflare Durable Object `locationHint` bucket
 * so an API-dispatched check actually leaves Cloudflare's network from
 * the intended geography.
 *
 * Cloudflare exposes only 9 geographic hint buckets today, so Asia-
 * Pacific collapses to a single `ap` case. When finer-grained hints
 * become available (or on a paid plan) split into `ap-southeast` /
 * `ap-northeast` and extend {@see self::locationHint()}.
 */
enum MonitorRegion: string
{
    case USEast = 'us-east';
    case USWest = 'us-west';
    case EUWest = 'eu-west';
    case EUCentral = 'eu-central';
    case AP = 'ap';

    /**
     * All supported region values (for validation / UI enumeration).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r): string => $r->value, self::cases());
    }

    /**
     * Cloudflare Durable Object location hint for this region.
     */
    public function locationHint(): string
    {
        return match ($this) {
            self::USEast => 'enam',
            self::USWest => 'wnam',
            self::EUWest => 'weur',
            self::EUCentral => 'eeur',
            self::AP => 'apac',
        };
    }

    /**
     * Human-readable label (used by UI fallbacks and logs).
     */
    public function label(): string
    {
        return match ($this) {
            self::USEast => 'US East',
            self::USWest => 'US West',
            self::EUWest => 'EU West',
            self::EUCentral => 'EU Central',
            self::AP => 'Asia-Pacific',
        };
    }
}
