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

    public function test_the_platform_claim_is_qualified_while_the_stores_are_empty(): void
    {
        /*
         * The web client is live; the iOS and Android builds come from the same Flutter
         * source but are in neither store, so nobody can install them. A flat "Web, iOS
         * and Android" would be false, and it was, briefly: the qualification used to
         * live in an act-4 platform matrix and went missing when that act was rewritten
         * as an escalation ladder. It is derived from config now, so it cannot outrun
         * the stores again.
         */
        config(['app.client_platforms' => ['web' => 'live', 'ios' => 'soon', 'android' => 'soon']]);

        $this->get('/')
            ->assertOk()
            ->assertSee('On the web today, iOS and Android next')
            ->assertDontSee('App Store')
            ->assertDontSee('Google Play')
            ->assertDontSee('Download');
    }

    public function test_the_claim_becomes_unqualified_only_when_every_platform_is_live(): void
    {
        config(['app.client_platforms' => ['web' => 'live', 'ios' => 'live', 'android' => 'live']]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Web, iOS and Android')
            ->assertDontSee('next');
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
