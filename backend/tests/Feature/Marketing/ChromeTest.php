<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use Tests\TestCase;

/**
 * The header and the footer.
 *
 * Chrome is where a landing page lies most cheaply. A footer template arrives with
 * Pricing, Docs, Careers, Privacy and four social icons, and every one of them is a
 * claim that something exists. These tests pin the opposite: the chrome links only
 * what this deployment can actually serve, and it derives those destinations rather
 * than hardcoding them.
 */
class ChromeTest extends TestCase
{
    public function test_the_calls_to_action_point_at_the_configured_client(): void
    {
        // The client mounts its auth screens under a prefix, so `/login` on the API
        // host is not where anybody should be sent.
        config([
            'app.frontend_url' => 'https://app.example.test',
            'app.frontend_auth_prefix' => '/auth',
        ]);

        $this->get('/')
            ->assertSee('https://app.example.test/auth/login')
            ->assertSee('https://app.example.test/auth/register');
    }

    public function test_the_chrome_links_nothing_this_deployment_does_not_serve(): void
    {
        /*
         * Every entry is a footer cliche with no page behind it. A link that 404s is
         * worse than an absent link, and an invented Privacy page is worse still.
         *
         * `/privacy`, `/terms` and `/contact` used to be on this list and came off it in
         * the change that added the pages, which is the rule this test exists to enforce:
         * the link and the page it points at land together or neither lands. `/faq` was
         * never listed here and is linked by the same change. `LegalPagesTest` is the
         * other half of the pair, asserting all four answer in both languages.
         *
         * Nothing else comes off this list without a page arriving with it.
         */
        $response = $this->get('/');

        foreach ([
            '/pricing',
            '/docs',
            '/blog',
            '/about',
            '/careers',
            '/changelog',
            'twitter.com',
            'linkedin.com',
            'github.com',
            'discord.gg',
        ] as $absent) {
            $response->assertDontSee($absent);
        }
    }

    public function test_no_in_page_anchor_points_at_a_missing_section(): void
    {
        /*
         * The page is being rebuilt section by section, so a nav or a call to action
         * can very easily outrun the section it targets. It happened: the hero's
         * secondary button pointed at `#pipeline` for several commits while no such
         * section existed, and clicking it did nothing at all.
         */
        $html = $this->get('/')->getContent();

        // Any fragment at all, not `[a-zA-Z0-9_-]+`. That class silently SKIPS a
        // non-ASCII slug, and every Turkish document heading produces one
        // (`href="#haklarınız"`), so it made this walk pass by checking nothing on
        // exactly the pages that needed it most.
        preg_match_all('/href="#([^"]+)"/u', $html, $matches);

        $this->assertNotSame(
            [],
            $matches[1],
            'The page emitted no in-page anchor at all, so this walk checked nothing.',
        );

        foreach (array_unique($matches[1]) as $anchor) {
            $this->assertStringContainsString(
                'id="'.$anchor.'"',
                $html,
                "The page links to #{$anchor} but nothing on it carries that id.",
            );
        }
    }

    public function test_no_translation_placeholder_reaches_the_page(): void
    {
        /*
         * `__('... :count ...')` renders the placeholder literally when the replacement
         * array is forgotten, and it is silent: no exception, no log line, just a
         * heading on the live page reading "the :count in a row". That shipped for one
         * commit because the tests were checking the derived NUMBER elsewhere on the
         * page and never the string that was supposed to contain it.
         *
         * Both languages, because a placeholder can be dropped in a translation while
         * the source string keeps it.
         */
        foreach (['/', '/tr'] as $path) {
            $response = $this->get($path);

            foreach ([':count', ':pending', ':channels', ':interval', ':pages', ':name'] as $placeholder) {
                $response->assertDontSee($placeholder);
            }
        }
    }

    public function test_a_keyboard_visitor_can_skip_the_chrome(): void
    {
        // A sticky header in front of the hero puts the brand and the whole nav
        // ahead of the content on every single Tab pass.
        $this->get('/')
            ->assertSee('href="#content"', escape: false)
            ->assertSee('id="content"', escape: false);
    }

    public function test_the_footer_states_the_current_year(): void
    {
        $this->get('/')->assertSee('© '.now()->year.' Uptizm');
    }

    public function test_the_footer_describes_the_product_from_what_it_actually_runs(): void
    {
        // The region count is the enum's, not a number somebody typed next to a
        // marketing sentence.
        $regions = count(MonitorRegion::cases());

        $this->get('/')->assertSee($regions.' regions');
    }

    public function test_the_chrome_keeps_a_turkish_visitor_in_turkish(): void
    {
        // The brand mark is a link home, and home for this visitor is `/tr`.
        $this->get('/tr')
            ->assertOk()
            ->assertSee('href="/tr"', escape: false)
            ->assertSee('Giriş yap');
    }

    public function test_the_mobile_menu_is_a_disclosure_a_screen_reader_can_follow(): void
    {
        // Alpine toggles it, so the state has to be announced rather than implied by
        // the icon changing shape.
        $this->get('/')
            ->assertSee('aria-expanded', escape: false)
            ->assertSee('aria-controls="mobile-menu"', escape: false)
            ->assertSee('id="mobile-menu"', escape: false);
    }
}
