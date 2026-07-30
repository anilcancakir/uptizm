<?php

namespace App\Support\StatusPages;

use App\Services\StatusPages\StatusPageAssembler;

/**
 * The single place the public status page decides what a status LOOKS like.
 *
 * One status has to be expressed three ways on the same page: a Tailwind class
 * for the banner dot, a hex for the favicon file and the browser's theme colour,
 * and a file stem for that favicon. They are three representations of one
 * decision, so they live in one map. Split across a Blade `match` and a
 * controller they drift, and the drift is visible to customers as a tab whose
 * colour contradicts the banner it sits above.
 *
 * The hex values ARE the Tailwind steps named beside them, so the dot the visitor
 * sees and the dot in their tab are the same green.
 */
class StatusPresentation
{
    /**
     * @var array<string, array{class: string, hex: string}>
     */
    protected const array PRESENTATION = [
        'major_outage' => ['class' => 'bg-red-500', 'hex' => '#ef4444'],
        'partial_outage' => ['class' => 'bg-orange-500', 'hex' => '#f97316'],
        'degraded' => ['class' => 'bg-amber-500', 'hex' => '#f59e0b'],
        // Not a rung on the severity ladder: the absence of a verdict, for a page
        // with nothing published. Grey rather than green, because a green tab
        // would claim health the page is not tracking.
        StatusPageAssembler::STATUS_UNKNOWN => ['class' => 'bg-gray-400', 'hex' => '#9ca3af'],
        'operational' => ['class' => 'bg-green-500', 'hex' => '#22c55e'],
    ];

    /**
     * Tailwind class for the banner dot.
     */
    public static function dotClass(string $status): string
    {
        return self::for($status)['class'];
    }

    /**
     * Hex for the browser theme colour, matching the dot exactly.
     */
    public static function themeColor(string $status): string
    {
        return self::for($status)['hex'];
    }

    /**
     * File stem under `public/favicon/` for this status.
     *
     * The stems are the status keys themselves, so a new status needs a new SVG
     * beside the existing ones and nothing else. A status with no file would 404
     * the favicon rather than show the wrong colour, which is the failure worth
     * having.
     */
    public static function faviconStem(string $status): string
    {
        return array_key_exists($status, self::PRESENTATION)
            ? $status
            : 'operational';
    }

    /**
     * @return array{class: string, hex: string}
     */
    protected static function for(string $status): array
    {
        return self::PRESENTATION[$status] ?? self::PRESENTATION['operational'];
    }
}
