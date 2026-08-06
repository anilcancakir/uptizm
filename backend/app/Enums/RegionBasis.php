<?php

namespace App\Enums;

/**
 * Why the MODEL chose the regions it chose.
 *
 * Deliberately a different set from {@see LocationBasis}, which records what
 * a LOOKUP achieved: `unresolved` is a lookup outcome and has no place here,
 * while `content_language` is a reason only a model can give.
 *
 * There is NO mapping between the two sets, and there deliberately is not
 * one. A lookup outcome is not a reason: only this model, which reads the
 * location facts and can weigh them against the page's language, answers
 * anything here other than `default`. The deterministic path always answers
 * `default`, because the region it suggests is the one the request asked to
 * probe from.
 */
enum RegionBasis: string
{
    case Geoip = 'geoip';
    case CdnEdge = 'cdn_edge';
    case ContentLanguage = 'content_language';
    case Default = 'default';

    /**
     * All supported region-basis values (for schema enumeration and validation).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $basis): string => $basis->value, self::cases());
    }
}
