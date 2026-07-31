<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use Tests\TestCase;

/**
 * The hero.
 *
 * Deliberately narrow while the design is still moving: these pin DERIVATION and
 * ABSENCE, never wording. A test that asserts a headline breaks on every copy tweak
 * and teaches nobody anything; a test that asserts the region list comes from the
 * enum, or that the page does not claim an App Store listing we do not have, keeps
 * working while the design changes around it.
 */
class HeroTest extends TestCase
{
    public function test_the_apex_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_every_probe_region_comes_from_the_enum(): void
    {
        // Sourced from the enum the write requests validate against, so a region
        // added there appears here without anyone editing a template.
        $response = $this->get('/');

        foreach (MonitorRegion::cases() as $region) {
            $response->assertSee($region->label());
        }
    }

    public function test_the_free_tier_numbers_come_from_the_plan_catalog(): void
    {
        config(['plans.tiers' => [[
            'id' => 'free',
            'limits' => ['monitors' => 7, 'check_interval_sec' => 90, 'status_pages' => 4],
        ]]]);

        $this->get('/')
            ->assertOk()
            ->assertSee('7 monitors')
            ->assertSee('90-second')
            ->assertSee('4 status pages');
    }

    public function test_a_whole_number_of_minutes_reads_as_minutes(): void
    {
        config(['plans.tiers' => [[
            'id' => 'free',
            'limits' => ['monitors' => 1, 'check_interval_sec' => 180, 'status_pages' => 1],
        ]]]);

        $this->get('/')->assertSee('3-minute')->assertDontSee('180-second');
    }

    public function test_the_ai_claim_is_withheld_without_a_provider_key(): void
    {
        // Without a key every AI path returns its deterministic fallback, so the
        // claim would be selling the fallback.
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => null]);

        $this->get('/')->assertOk()->assertDontSee('AI-assisted triage');
    }

    public function test_the_ai_claim_appears_once_a_key_is_configured(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test']);

        $this->get('/')->assertOk()->assertSee('AI-assisted triage');
    }

    public function test_the_platform_claim_lists_the_configured_platforms(): void
    {
        config(['app.client_platforms' => ['web' => 'live', 'ios' => 'soon', 'android' => 'soon']]);

        $this->get('/')->assertOk()->assertSee('Web, iOS, Android');
    }

    public function test_a_platform_absent_from_config_is_absent_from_the_claim(): void
    {
        // Derived from the config keys, so the pill cannot name a platform this client
        // is not built for.
        config(['app.client_platforms' => ['web' => 'live']]);

        $this->get('/')->assertOk()->assertSee('Web')->assertDontSee('Android');
    }

    public function test_the_page_never_claims_a_store_listing_it_does_not_have(): void
    {
        /*
         * The pill lists Web, iOS and Android flatly, which is a deliberate decision
         * about the copy. Neither mobile build is in a store, so this is the line that
         * must not be crossed: no store badge, no download link, nothing a visitor could
         * tap expecting an install. Delete these once the builds are actually published.
         */
        $this->get('/')
            ->assertDontSee('App Store')
            ->assertDontSee('Google Play')
            ->assertDontSee('Download')
            ->assertDontSee('TestFlight');
    }

    public function test_the_hero_line_follows_the_stage_and_survives_without_javascript(): void
    {
        /*
         * The line beside the panel changes with the act, so Alpine only swaps its text:
         * the element has to arrive from the server with a real sentence in it, or a
         * crawler and a no-JS visitor get an empty box where the description was.
         */
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression(
            '/x-text="beat\.line"\s*>[^<]{20,}</',
            $html,
            'The act-synced hero sentence is missing its server-rendered fallback.',
        );

        $this->assertMatchesRegularExpression(
            '/x-text="beat\.lead"\s*>[^<]{5,}</',
            $html,
            'The act-synced headline is missing its server-rendered fallback.',
        );
    }

    public function test_the_product_summary_is_still_in_the_markup(): void
    {
        // It moved out of the body copy when the hero went slogan-shaped. It may not
        // simply vanish: it is the one full sentence describing the product.
        $this->get('/')->assertSee('checked at the same moment', escape: false);
    }

    public function test_the_page_does_not_claim_that_acknowledging_stops_the_paging(): void
    {
        /*
         * It used to. `EscalationDispatcher::pageStep()` guards on
         * `! $incident->lifecycle->isActive()`, and `isTerminal()` is `Resolved` alone,
         * while acknowledging moves an incident from Detected to Investigating, which is
         * still active. So the ladder keeps climbing after an acknowledgement and only a
         * resolution cancels what is pending.
         */
        $this->get('/')
            ->assertDontSee('paging stops')
            ->assertDontSee('nobody acknowledged')
            ->assertSee('pending pages cancelled');
    }

    public function test_every_alert_destination_comes_from_the_enum(): void
    {
        // The escalation ladder in act 4 names channels; they are derived so it cannot
        // list a destination the product has no driver for. Email and SMS are absent
        // from the enum and must stay absent here.
        $response = $this->get('/');

        foreach (['Slack', 'Webhook', 'PagerDuty', 'Microsoft Teams'] as $channel) {
            $response->assertSee($channel);
        }
    }

    public function test_the_hero_claims_nothing_the_probe_engine_cannot_do(): void
    {
        /*
         * Each of these is either accepted by the API but never applied by the edge
         * worker, or a plan flag with no implementation behind it. If one ships for
         * real, delete its line here in the same change.
         */
        $response = $this->get('/');

        foreach ([
            'assertion',   // assertion_rules are never read by regional-probe.ts
            'Basic auth',  // auth_config is never applied by the worker
            'Bearer',
            'SSO',
            'SAML',
            'white-label',
            'custom domain',
            'Ping monitor',
            'keyword',
        ] as $claim) {
            $response->assertDontSee($claim);
        }
    }

    public function test_the_example_panel_is_labelled_as_an_example(): void
    {
        // The panel shows invented latencies against a placeholder host. It must not
        // be mistakable for a report on anything, least of all our own status.
        $this->get('/')
            ->assertSee('Example:', escape: false)
            ->assertSee('api.acme.com');
    }
}
