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
 * IN THE PAGE, OUT OF THE PAGE
 *
 * The class is a DESIGN TOKEN (`bg-up`, `bg-down`), so the dot follows the
 * reader's colour scheme like everything else the product renders. The hex cannot
 * be one: `<meta name="theme-color">` and an SVG on disk can neither read a custom
 * property nor be handed two values here, and the browser tab is a status light
 * that has to work before the stylesheet arrives. So the tab keeps ONE hex per
 * status, and those hexes are the ones baked into `public/favicon/<status>.svg`
 * (regenerate both together, see public/favicon/README.md). Tab and page therefore
 * agree on the STATUS and on its family, in the same hue, without being the same
 * literal colour in both schemes.
 */
class StatusPresentation
{
    /**
     * @var array<string, array{class: string, hex: string}>
     */
    /*
     * The hexes are DESIGN.md's own status-family values, not Tailwind's palette.
     * They cannot be CSS variables because the browser tab and the favicon are
     * native UI that takes a literal, but a literal off the design system meant the
     * tab rendered a slightly different green from the dot two centimetres below it.
     * `partial_outage` shares `down`'s hex for the reason the class comment gives.
     */
    protected const array PRESENTATION = [
        'major_outage' => ['class' => 'bg-down', 'hex' => '#DF202E'],
        // `down` too, not an orange of its own. The status vocabulary carries three
        // health families (up / degraded / down) and no fourth rung, and of the two
        // it could collapse into, `degraded` would paint a real outage as mere
        // slowness. The banner label ("Partial System Outage") and the row's own
        // tooltip keep the distinction the colour gives up; understating an outage
        // on a status page is the one direction that costs trust.
        'partial_outage' => ['class' => 'bg-down', 'hex' => '#DF202E'],
        'degraded' => ['class' => 'bg-degraded', 'hex' => '#E69825'],
        // Not a rung on the severity ladder: the absence of a verdict, for a page
        // with nothing published. Neutral rather than green, because a green tab
        // would claim health the page is not tracking.
        StatusPageAssembler::STATUS_UNKNOWN => ['class' => 'bg-paused', 'hex' => '#79828A'],
        'operational' => ['class' => 'bg-up', 'hex' => '#30A556'],
    ];

    /**
     * Tailwind class for the banner dot.
     */
    public static function dotClass(string $status): string
    {
        return self::for($status)['class'];
    }

    /**
     * Hex for the browser theme colour, in the same family as the dot.
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
            : StatusPageAssembler::STATUS_UNKNOWN;
    }

    /**
     * The presentation for a status, falling back to the NO-VERDICT entry.
     *
     * Unknown resolves to `unknown`, not to `operational`, and that is the whole
     * point of the fallback: a status this map does not recognise is a status
     * nobody here can vouch for, and painting it green would have the page make
     * the one claim it has no basis for. `unknown` is also the only other entry
     * guaranteed to be safe to fall back to, since it has its own artwork on disk
     * (`public/favicon/unknown.svg`) exactly like a named rung.
     *
     * @return array{class: string, hex: string}
     */
    protected static function for(string $status): array
    {
        return self::PRESENTATION[$status] ?? self::PRESENTATION[StatusPageAssembler::STATUS_UNKNOWN];
    }
}
