<?php

namespace Tests\Feature;

use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use Tests\TestCase;

/**
 * The apex host's marketing page.
 *
 * This page makes claims about the product, so the tests are mostly about where
 * a claim COMES FROM. Three failure modes are covered:
 *
 *   - a call to action pointing at this app instead of the Flutter client, which
 *     lands every visitor on the API and 404s
 *   - a hand-written feature list drifting away from the enum or config that
 *     governs the behaviour (regions, alert destinations, free-tier limits)
 *   - advertising a capability this deployment cannot perform: AI without a
 *     provider key, or email subscribers with a mailer that only writes to a log
 *
 * The last test is the blunt one: a list of things the product does not do yet,
 * asserted absent, so nobody reintroduces them into the copy by accident.
 */
class LandingPageTest extends TestCase
{
    public function test_the_apex_root_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_the_calls_to_action_point_at_the_frontend_host(): void
    {
        config([
            'app.url' => 'https://api.uptizm.test',
            'app.frontend_url' => 'https://app.uptizm.test',
            'app.frontend_auth_prefix' => '/auth',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('https://app.uptizm.test/auth/register', escape: false);
        $response->assertSee('https://app.uptizm.test/auth/login', escape: false);
        $response->assertDontSee('https://api.uptizm.test/auth/register', escape: false);
    }

    public function test_the_calls_to_action_carry_the_clients_auth_prefix(): void
    {
        // Regression: these were written as `/login` and `/register`, which the
        // client does not serve. Its auth screens are mounted under a prefix
        // (`auth_prefix` in lib/config/magic_starter.dart), so every visitor who
        // clicked the primary CTA landed on a route that did not exist.
        config([
            'app.frontend_url' => 'https://app.uptizm.test',
            'app.frontend_auth_prefix' => '/auth',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('href="https://app.uptizm.test/login"', escape: false)
            ->assertDontSee('href="https://app.uptizm.test/register"', escape: false);
    }

    public function test_a_trailing_slash_on_the_frontend_url_does_not_double_up(): void
    {
        config([
            'app.frontend_url' => 'https://app.uptizm.test/',
            'app.frontend_auth_prefix' => '/auth/',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('https://app.uptizm.test//auth', escape: false)
            ->assertDontSee('/auth//login', escape: false);
    }

    public function test_every_probe_region_is_listed_from_the_enum(): void
    {
        // Sourced from MonitorRegion, which is what the write requests validate
        // against, NOT from `config/relay.php`'s unread `regions` key. A region
        // added to the enum has to appear here without anyone editing the page.
        $response = $this->get('/');

        foreach (MonitorRegion::cases() as $region) {
            $response->assertSee($region->label());
            $response->assertSee($region->value);
        }
    }

    public function test_the_hero_states_the_real_region_count(): void
    {
        $response = $this->get('/');

        $response->assertSee(
            'HTTP and TCP checks from '.count(MonitorRegion::cases()).' pinned regions',
            escape: false,
        );
    }

    public function test_every_alert_destination_is_listed_from_the_enum(): void
    {
        $response = $this->get('/');

        // The labels are prettier than the enum values ("Microsoft Teams" for
        // `teams`), so assert on the count of cases being represented rather
        // than on the raw values.
        $this->assertSame(4, count(NotificationChannelType::cases()));

        foreach (['Slack', 'Webhook', 'PagerDuty', 'Microsoft Teams'] as $label) {
            $response->assertSee($label);
        }
    }

    public function test_the_free_tier_numbers_come_from_the_plan_catalog(): void
    {
        config(['plans.tiers' => [[
            'id' => 'free',
            'limits' => [
                'monitors' => 7,
                'check_interval_sec' => 90,
                'status_pages' => 4,
                'subscribers' => 250,
            ],
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
            'limits' => ['monitors' => 1, 'check_interval_sec' => 180, 'status_pages' => 1, 'subscribers' => 100],
        ]]]);

        $this->get('/')
            ->assertOk()
            ->assertSee('3-minute checks')
            ->assertDontSee('180-second');
    }

    public function test_the_free_tier_line_is_dropped_when_the_catalog_has_no_free_tier(): void
    {
        config(['plans.tiers' => []]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Free plan:');
    }

    public function test_the_ai_section_is_withheld_without_a_provider_key(): void
    {
        // Without a key every AI path returns its deterministic fallback, so the
        // section would be advertising the fallback.
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => null]);

        $this->get('/')
            ->assertOk()
            // Asserted on the guardrails heading, not on the section's own H2: an
            // accent colour splits every heading across two spans, so a full
            // heading string is not contiguous in the markup.
            ->assertDontSee('The guardrails, specifically');
    }

    public function test_the_ai_section_appears_once_a_provider_key_is_configured(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test']);

        $this->get('/')
            ->assertOk()
            ->assertSee('The guardrails, specifically');
    }

    public function test_the_subscriber_promise_is_withheld_when_mail_only_goes_to_a_log(): void
    {
        // MAIL_MAILER=log accepts the confirmation mail and drops it, so the
        // subscriber would wait forever for an email that was written to a file.
        config(['mail.default' => 'log']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Subscribers who opted in')
            ->assertDontSee('Status pages people can subscribe to');
    }

    public function test_the_subscriber_promise_appears_with_a_real_transport(): void
    {
        config(['mail.default' => 'smtp']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Subscribers who opted in')
            ->assertSee('Status pages people can subscribe to');
    }

    public function test_our_own_status_page_is_linked_only_once_it_exists(): void
    {
        config(['app.own_status_page_url' => null]);
        $this->get('/')->assertOk()->assertDontSee('Our own status page');

        config(['app.own_status_page_url' => 'https://status.uptizm.test']);
        $this->get('/')
            ->assertOk()
            ->assertSee('Our own status page')
            ->assertSee('https://status.uptizm.test', escape: false);
    }

    public function test_platform_availability_is_stated_per_platform_from_config(): void
    {
        /*
         * "Web, iOS and Android" as one finished claim would be false: the web
         * client is live and the mobile builds are in neither store. So each
         * platform states its own availability, and it comes from config rather
         * than from the template.
         */
        config(['app.client_platforms' => ['web' => 'live', 'ios' => 'soon', 'android' => 'soon']]);

        $response = $this->get('/');

        $response->assertSee('Available now');
        $response->assertSee('Not in the stores yet');
    }

    public function test_a_platform_becomes_available_without_touching_the_template(): void
    {
        // The day a store listing exists, flipping the config is the whole change.
        config(['app.client_platforms' => ['web' => 'live', 'ios' => 'live', 'android' => 'live']]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Not in the stores yet');
    }

    public function test_the_page_does_not_advertise_anything_unbuilt(): void
    {
        /*
         * Each of these is either accepted by the API but never applied by the
         * probe engine, or a plan-catalog flag with no implementation behind it.
         * See the audit in the capabilities partial's header comment. If one of
         * them ships for real, delete its line here in the same change.
         */
        $unbuilt = [
            'assertion',      // assertion_rules never read by regional-probe.ts
            'Basic auth',     // auth_config never applied by the worker
            'Bearer token',
            'SAML',           // no implementation
            'SSO',
            'white-label',
            'White-label',
            'audit log',
            'Ping monitor',
            'keyword',
            'custom domain',  // DomainMode::Custom has no route
            'CSV',            // no subscriber export
            'similar incident',
        ];

        $response = $this->get('/');

        foreach ($unbuilt as $claim) {
            $response->assertDontSee($claim);
        }
    }
}
