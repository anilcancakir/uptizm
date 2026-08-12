<?php

namespace Tests\Feature\StatusPage;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The manual light/dark layer added on top of the existing
 * `prefers-color-scheme` auto-detect.
 *
 * These assertions pin the MECHANISM, not the copy: the head script runs
 * before the stylesheet (a returning visitor with a stored choice must never
 * see one wrong-themed paint), the render-ready marker stays unconditional
 * once a second script sits ahead of it in `<head>`, and the control offers
 * three distinct choices. Whether the three labels are translated is
 * `lang/{en,tr}/status.php`'s `theme` group, owned and asserted by a sibling
 * step in this wave; asserting the copy here would fail on write ordering
 * across two parallel tracks rather than on behaviour.
 *
 * The two remaining Done-when items (no stored preference still follows the
 * media query; a stored `dark` choice applies on a light-preferring browser)
 * are CSS cascade outcomes a PHP test cannot observe a browser resolving, so
 * they are pinned as the CSS mechanism that produces them: the override
 * blocks exist, carry the exact same custom properties as the `:root` and
 * media blocks they override, and outrank the media query on selector
 * specificity rather than on accidental source order. The visual half of
 * this (a real browser, a real reload) is Step 12's live walk.
 */
class StatusPageThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_theme_script_runs_before_the_stylesheet_link(): void
    {
        // Pin Vite to the built manifest, exactly as StatusPageRenderTest does:
        // with a dev-server hot file present `@vite` would emit a
        // `localhost:5173` tag instead of a real `<link rel="stylesheet">`,
        // and this test needs the real one to locate.
        app(Vite::class)->useHotFile(base_path('tests/vite-hot-file-that-never-exists'));

        $this->makePageWithMonitor('theme-order');

        $response = $this->get('/s/theme-order');
        $response->assertOk();

        $html = (string) $response->getContent();

        $scriptAt = strpos($html, 'window.__uptizmTheme');
        $stylesheetAt = strpos($html, '<link rel="stylesheet"');

        $this->assertIsInt($scriptAt, 'The layout must emit the pre-paint theme script.');
        $this->assertIsInt($stylesheetAt, 'The layout must emit a stylesheet link.');
        $this->assertLessThan(
            $stylesheetAt,
            $scriptAt,
            'The theme script must run before the stylesheet link, or a stored choice paints once with the wrong theme first.',
        );
    }

    public function test_the_ready_marker_stays_unconditional_and_independent_of_the_theme_script(): void
    {
        $this->makePageWithMonitor('theme-marker');

        $response = $this->get('/s/theme-marker');
        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringContainsString(
            "document.documentElement.dataset.timesLocalized = '1'",
            $html,
            'The render-ready marker must still be emitted.',
        );

        // The marker's own script tag never references the theme script: they
        // are two separate <script> elements, which is what lets an uncaught
        // throw in the theme script (outside its own try/catch, for whatever
        // reason) still leave the marker script to run on its own.
        $themeScript = $this->scriptContaining($html, 'window.__uptizmTheme');
        $markerScript = $this->scriptContaining($html, 'timesLocalized');

        $this->assertStringNotContainsString(
            'timesLocalized',
            $themeScript,
            'The theme script and the ready-marker script must not be merged into one <script> tag.',
        );
        $this->assertStringNotContainsString(
            '__uptizmTheme',
            $markerScript,
            'The ready-marker script must not depend on the theme script having run.',
        );
    }

    public function test_an_explicit_choice_also_sets_the_ua_painted_color_scheme(): void
    {
        // `<meta name="color-scheme" content="light dark">` tells the browser to
        // paint the scrollbar, the caret and the autofill dropdown from the OS
        // preference. Setting only `data-theme` therefore themed our own surfaces
        // and left the UA's: Dark chosen on a light OS painted a white scrollbar
        // track down the side of a near-black page. The inline style overrides the
        // meta for this document, and clearing it on `system` hands the furniture
        // back to the OS.
        //
        // Asserted on the template because this is a browser behaviour PHPUnit
        // never runs; the live proof is the CDP walk in this plan's evidence.
        $this->makePageWithMonitor('theme-color-scheme');

        $script = $this->scriptContaining(
            (string) $this->get('/s/theme-color-scheme')->assertOk()->getContent(),
            'window.__uptizmTheme',
        );

        $this->assertStringContainsString('style.colorScheme = choice', $script);
        $this->assertStringContainsString("style.removeProperty('color-scheme')", $script);
    }

    public function test_the_default_render_carries_no_server_side_theme_attribute(): void
    {
        // "No stored preference follows the media query exactly as today" means
        // the server never picks a side: `<html>` must carry no `data-theme` at
        // all, leaving the choice entirely to the client-side script and,
        // absent a stored choice, to `prefers-color-scheme`.
        $this->makePageWithMonitor('theme-default');

        $response = $this->get('/s/theme-default');
        $response->assertOk();

        $this->assertMatchesRegularExpression(
            '/<html lang="[a-z-]+">/',
            (string) $response->getContent(),
            'The server-rendered <html> tag must not carry a data-theme attribute.',
        );
    }

    public function test_the_control_renders_three_distinct_theme_options(): void
    {
        $this->makePageWithMonitor('theme-control');

        $response = $this->get('/s/theme-control');
        $response->assertOk();

        foreach (['system', 'light', 'dark'] as $choice) {
            $response->assertSee('data-theme-option="'.$choice.'"', escape: false);
        }

        $html = (string) $response->getContent();
        preg_match_all('/<div\b[^>]*\bdata-theme-control\b[^>]*>/', $html, $containers);
        $this->assertCount(
            1,
            $containers[0],
            'Exactly one theme control container must render.',
        );
    }

    public function test_the_stylesheet_declares_a_light_and_a_dark_override_block(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        // Each override block must exist and must be keyed off
        // `:root[data-theme=...]` (a pseudo-class plus an attribute selector,
        // which outranks the plain `:root` above on specificity regardless of
        // source order). That the blocks carry the SAME property set as the ones
        // they override is the next test's job: asserting one property here would
        // read like a drift guard while guarding one value out of the palette.
        $this->assertMatchesRegularExpression(
            "/:root\[data-theme=['\"]light['\"]\]\s*\{[^}]*--app-surface:\s*#f9fafb;/s",
            $css,
            'Missing or wrong light override block.',
        );
        $this->assertMatchesRegularExpression(
            "/:root\[data-theme=['\"]dark['\"]\]\s*\{[^}]*--app-surface:\s*#07090c;/s",
            $css,
            'Missing or wrong dark override block.',
        );

        // Placed after the media query in source, for a reader scanning top to
        // bottom, even though correctness here rests on specificity rather
        // than on this ordering.
        $mediaAt = strpos($css, '@media (prefers-color-scheme: dark)');
        $lightOverrideAt = strpos($css, "data-theme='light'");
        $darkOverrideAt = strpos($css, "data-theme='dark'");

        $this->assertIsInt($mediaAt);
        $this->assertIsInt($lightOverrideAt);
        $this->assertIsInt($darkOverrideAt);
        $this->assertGreaterThan($mediaAt, $lightOverrideAt);
        $this->assertGreaterThan($mediaAt, $darkOverrideAt);
    }

    public function test_each_override_block_declares_the_same_palette_as_the_block_it_overrides(): void
    {
        // The actual drift guard, and the reason the plan accepted FOUR
        // duplicated palettes: an explicit choice is only equivalent to following
        // the OS if the two blocks say the same thing. Comparing whole property
        // SETS is what makes that true, because a single-property assertion stays
        // green while `--app-primary` is edited in `:root` and not in
        // `:root[data-theme='light']`, and a reader who chose Light then keeps the
        // previous brand green with nothing failing anywhere.
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $baselineLight = $this->customProperties($css, ':root {');
        $overrideLight = $this->customProperties($css, ":root[data-theme='light'] {");
        $baselineDark = $this->customProperties($css, '@media (prefers-color-scheme: dark) {', nested: ':root {');
        $overrideDark = $this->customProperties($css, ":root[data-theme='dark'] {");

        // Guard against the whole test passing on four empty arrays, which is what
        // a renamed selector or a changed formatting convention would produce.
        $this->assertGreaterThan(10, count($baselineLight), 'The light baseline palette was not parsed.');
        $this->assertGreaterThan(10, count($baselineDark), 'The dark baseline palette was not parsed.');

        $this->assertSame(
            $baselineLight,
            $overrideLight,
            'The light override block drifted from `:root`. Every `--app-*` declaration has to appear in both, with the same value.',
        );
        $this->assertSame(
            $baselineDark,
            $overrideDark,
            'The dark override block drifted from the prefers-color-scheme block. Every `--app-*` declaration has to appear in both, with the same value.',
        );
    }

    /**
     * Every `--app-*` declaration inside one CSS block, keyed by property name
     * and sorted, so two blocks compare as sets rather than as source text.
     *
     * The block is delimited by brace COUNTING from the selector rather than by
     * `[^}]*`, because the dark baseline lives inside a media query and a
     * non-greedy character class would stop at the nested block's own closing
     * brace.
     *
     * @param  string  $selector  The opening selector plus its brace.
     * @param  string|null  $nested  A selector to descend into first, for a block inside a media query.
     * @return array<string, string>
     */
    protected function customProperties(string $css, string $selector, ?string $nested = null): array
    {
        $start = strpos($css, $selector);
        $this->assertIsInt($start, "Could not find the block `{$selector}` in app.css.");

        $open = $start + strlen($selector) - 1;

        if ($nested !== null) {
            $nestedAt = strpos($css, $nested, $open);
            $this->assertIsInt($nestedAt, "Could not find `{$nested}` inside `{$selector}`.");
            $open = $nestedAt + strlen($nested) - 1;
        }

        $depth = 0;
        $end = $open;

        for ($at = $open; $at < strlen($css); $at++) {
            if ($css[$at] === '{') {
                $depth++;
            } elseif ($css[$at] === '}') {
                $depth--;

                if ($depth === 0) {
                    $end = $at;
                    break;
                }
            }
        }

        preg_match_all(
            '/(--app-[a-z0-9-]+)\s*:\s*([^;]+);/i',
            substr($css, $open, $end - $open),
            $matches,
            PREG_SET_ORDER,
        );

        $properties = [];

        foreach ($matches as $match) {
            $properties[$match[1]] = trim($match[2]);
        }

        ksort($properties);

        return $properties;
    }

    /**
     * Returns the content of the single `<script>` tag containing the given
     * needle, so two adjacent inline scripts are never accidentally compared
     * as one blob.
     */
    protected function scriptContaining(string $html, string $needle): string
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $matches);

        foreach ($matches[1] as $script) {
            if (str_contains($script, $needle)) {
                return $script;
            }
        }

        return '';
    }

    /**
     * Creates a status page for a fresh team with one attached, shown monitor
     * plus a daily-uptime row, so the layout has real data to render.
     */
    protected function makePageWithMonitor(string $slug): StatusPage
    {
        $team = $this->makeTeam();

        $page = new StatusPage([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'is_public' => true,
            'subscriptions_enabled' => false,
        ]);
        $page->preview_token = null;
        $page->save();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
        ]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $team->id,
            'date' => now()->format('Y-m-d'),
            'uptime_percent' => 100.0,
            'total_checks' => 100,
            'failed_checks' => 0,
            'worst_status' => 'operational',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->insert($row);

        return $page;
    }

    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Theme Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::create([
            'user_id' => $user->id,
            'name' => 'Theme Team',
            'personal_team' => true,
        ]);
    }
}
