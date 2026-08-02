<?php

namespace Tests\Feature\StatusPage;

use App\Services\StatusPages\StatusPageAssembler;
use App\Support\StatusPages\StatusPresentation;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Keeps the public status page on the design system, by reading the views
 * themselves rather than one rendered page.
 *
 * The source is the right subject here. A rendered page only exercises the
 * branches its fixture happens to reach: an incident in one lifecycle, a page with
 * a brand colour, a strip with no bad days. A raw `bg-orange-100` sitting in the
 * `identified` arm of a `match` would pass every render assertion in this suite
 * and reach a customer during the exact outage it was written for.
 *
 * Three things are pinned, and each one is a way this surface has drifted or
 * could:
 *
 *   - No numbered Tailwind palette class. Those are fixed colours: `bg-white` and
 *     `text-gray-500` cannot follow the reader, which is why this page was
 *     light-only while the rest of the product was not.
 *   - No `dark:` variant. Light and dark live in the custom properties in
 *     resources/css/app.css and reach Tailwind through `@theme inline`, so a token
 *     already flips on its own. A `dark:` prefix here would be the only one on the
 *     web surface and would fight the variable layer instead of using it.
 *   - No colour literal. The customer's brand colour is the single inline colour on
 *     the page and it arrives as a Blade expression, never as a literal in the
 *     view, so a bare `#rrggbb` in this directory is always a token that was not
 *     looked up.
 */
class StatusPageDesignTokenTest extends TestCase
{
    /**
     * Tailwind's default palette families. A class naming one of these with a
     * numeric step is a fixed colour by definition.
     */
    protected const string PALETTE_FAMILIES = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime'
        .'|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    /**
     * The utility prefixes a colour can arrive through.
     */
    protected const string COLOR_UTILITIES = 'bg|text|border|divide|from|via|to|ring|placeholder'
        .'|outline|decoration|fill|stroke|shadow|accent|caret';

    public function test_no_status_view_uses_a_raw_palette_class(): void
    {
        $pattern = '/\b(?:'.self::COLOR_UTILITIES.')-(?:'.self::PALETTE_FAMILIES.')-\d{2,3}\b/';

        foreach ($this->statusViews() as $path => $source) {
            $this->assertSame(
                [],
                $this->occurrencesOf($pattern, $source),
                $this->explain($path, 'uses a raw Tailwind palette class', [
                    'Map it to a semantic role (surface / surface-container / fg / fg-muted / border)',
                    'or to a status family (up / down / degraded / paused / info, plus -soft and',
                    '-soft-foreground for a badge). DESIGN.md carries the vocabulary.',
                ]),
            );
        }
    }

    public function test_no_status_view_writes_a_dark_variant(): void
    {
        foreach ($this->statusViews() as $path => $source) {
            $this->assertSame(
                [],
                $this->occurrencesOf('/\bdark:[a-z-]/', $source),
                $this->explain($path, 'writes a `dark:` variant', [
                    'The tokens already flip: app.css redefines the custom properties inside a',
                    '`prefers-color-scheme: dark` block and `@theme inline` re-exports them.',
                    'Use the plain token.',
                ]),
            );
        }
    }

    public function test_no_status_view_hardcodes_a_colour(): void
    {
        foreach ($this->statusViews() as $path => $source) {
            $this->assertSame(
                [],
                $this->occurrencesOf('/#[0-9a-fA-F]{3,8}\b|\brgba?\(/', $source),
                $this->explain($path, 'hardcodes a colour', [
                    'The only inline colour this page may carry is the tenant brand colour, and it',
                    'arrives as a Blade expression from the view model.',
                ]),
            );
        }
    }

    public function test_the_layout_asks_the_browser_to_follow_the_reader(): void
    {
        // Without this the UA-painted parts stay light on a dark page: the
        // scrollbar, and the subscribe field's own chrome, focus ring and autofill
        // fill. The form is the reason it matters on this surface specifically.
        foreach (['layout', 'confirmed', 'unsubscribed', 'subscribe-check-inbox'] as $view) {
            $this->assertStringContainsString(
                '<meta name="color-scheme" content="light dark">',
                file_get_contents(resource_path("views/status/{$view}.blade.php")),
                "resources/views/status/{$view}.blade.php must declare `color-scheme: light dark`.",
            );
        }
    }

    public function test_the_status_presentation_map_only_yields_token_classes(): void
    {
        $statuses = [
            'operational',
            'degraded',
            'partial_outage',
            'major_outage',
            StatusPageAssembler::STATUS_UNKNOWN,
            // Not in the map: whatever a future rung or a bad import puts in the
            // column has to resolve to something too.
            'something-new',
        ];

        $pattern = '/-(?:'.self::PALETTE_FAMILIES.')-\d{2,3}$/';

        foreach ($statuses as $status) {
            $class = StatusPresentation::dotClass($status);

            $this->assertSame(
                [],
                $this->occurrencesOf($pattern, $class),
                "StatusPresentation maps `{$status}` to the fixed palette class `{$class}`.",
            );
        }
    }

    public function test_an_unrecognised_status_never_reads_as_healthy(): void
    {
        // The dot, the tab icon and the theme colour are three expressions of one
        // status, so all three have to refuse the green. A status this map does not
        // know is a status nobody can vouch for; painting it operational is the one
        // claim a status page has no basis for making.
        $neutral = StatusPresentation::dotClass(StatusPageAssembler::STATUS_UNKNOWN);

        $this->assertSame($neutral, StatusPresentation::dotClass('something-new'));
        $this->assertNotSame(StatusPresentation::dotClass('operational'), $neutral);

        $this->assertSame(
            StatusPageAssembler::STATUS_UNKNOWN,
            StatusPresentation::faviconStem('something-new'),
        );
        $this->assertSame(
            StatusPresentation::themeColor(StatusPageAssembler::STATUS_UNKNOWN),
            StatusPresentation::themeColor('something-new'),
        );
    }

    /**
     * The status views this surface owns, keyed by their path relative to
     * `resources/views`.
     *
     * `status/emails/` is deliberately excluded: an email client resolves no custom
     * property and strips a stylesheet, so the confirmation email has to inline its
     * colours and is not part of this surface.
     *
     * @return array<string, string>
     */
    protected function statusViews(): array
    {
        $paths = array_merge(
            glob(resource_path('views/status/*.blade.php')) ?: [],
            glob(resource_path('views/status/partials/*.blade.php')) ?: [],
        );

        $this->assertNotEmpty($paths, 'No status views were found to check.');

        $views = [];
        foreach ($paths as $path) {
            $views[Str::afterLast($path, 'views/')] = file_get_contents($path);
        }

        return $views;
    }

    /**
     * Every match of a pattern, deduplicated, so a failure names the offenders
     * rather than reporting a count.
     *
     * @return array<int, string>
     */
    protected function occurrencesOf(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found);

        return array_values(array_unique($found[0]));
    }

    /**
     * @param  array<int, string>  $remedy
     */
    protected function explain(string $path, string $problem, array $remedy): string
    {
        return "resources/views/{$path} {$problem}. ".implode(' ', $remedy);
    }
}
