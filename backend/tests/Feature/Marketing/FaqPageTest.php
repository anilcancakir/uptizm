<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\NotificationChannelType;
use App\Services\Ai\IncidentAnalysisPayload;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The FAQ page's CONTENT.
 *
 * `LegalPagesTest` already pins the routing, canonical, hreflang and no-unreplaced-
 * placeholder contract every document page shares, so this file only pins what is FAQ-
 * specific: every numeric answer traces to its config/enum source rather than a literal,
 * and no question claims a capability the product does not have.
 *
 * Every figure is asserted WITH the word the answer puts next to it, in each language, the
 * way `PrivacyPageTest` does. A bare number is not an assertion about this document: the
 * shared header and footer print `hover:opacity-90`, `min-h-10`, `z-50`, `size-5`, `gap-3`
 * and a `2500` millisecond timeout, so `assertSee('90')`, `assertSee('10')`, `assertSee('5')`,
 * `assertSee('1')`, `assertSee('3')` and `assertSee('500')` all passed on the chrome and
 * would have kept passing with the answer deleted outright.
 */
class FaqPageTest extends TestCase
{
    public function test_the_page_carries_at_least_eight_accordion_entries(): void
    {
        foreach ($this->supported() as $locale) {
            $path = $this->pathFor($locale);
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertGreaterThanOrEqual(
                8,
                substr_count($html, '<details'),
                "GET {$path} carries fewer than 8 <details> entries.",
            );
        }
    }

    public function test_the_region_count_and_names_equal_the_enum(): void
    {
        // The count carries the answer's own noun: the footer already prints "from 5 regions"
        // on every marketing page, and `size-5`/`z-50` in the header satisfy a bare `5`.
        $count = count(MonitorRegion::cases());
        $phrases = [
            'en' => $count.' supported regions',
            'tr' => $count.' bölgeden',
        ];
        $expectedNames = array_map(fn (MonitorRegion $region): string => $region->label(), MonitorRegion::cases());

        foreach ($this->supported() as $locale) {
            $response = $this->get($this->pathFor($locale))->assertSee($phrases[$locale]);

            foreach ($expectedNames as $name) {
                $response->assertSee($name);
            }
        }
    }

    public function test_the_monitor_types_equal_the_enum(): void
    {
        foreach ($this->supported() as $locale) {
            $response = $this->get($this->pathFor($locale));

            foreach (MonitorType::cases() as $type) {
                $response->assertSee(strtoupper($type->value));
            }
        }
    }

    public function test_the_check_interval_floor_equals_the_plan_catalog(): void
    {
        // The unit word is what makes this an assertion about the answer: `min-h-10`, `gap-10`,
        // `z-10`, `mt-10` in the chrome satisfy a bare `10`, and `ps-5`/`size-5`/`z-50` a bare `5`.
        $units = [
            'en' => ' seconds',
            'tr' => ' saniye',
        ];

        foreach ((array) config('plans.tiers') as $tier) {
            $seconds = (string) Arr::get($tier, 'limits.check_interval_sec');

            foreach ($this->supported() as $locale) {
                $this->get($this->pathFor($locale))->assertSee($seconds.$units[$locale]);
            }
        }
    }

    public function test_the_free_tier_limits_equal_the_plan_catalog(): void
    {
        /*
         * Four of the five free-tier figures are 1 or 3, which `mt-1`, `px-1`, `gap-3` and
         * `max-w-3xl` in the chrome satisfy on their own, so each is asserted with the noun the
         * answer counts. The nouns are per-locale because they are the document's words, not a
         * translator string: the FAQ Markdown is authored per language and only digits and
         * proper nouns route through a placeholder.
         */
        $nouns = [
            'en' => [
                'monitors' => '%s monitor',
                'status_pages' => '%s status page',
                'subscribers' => '%s email subscribers',
                'responders' => '%s responder',
                'ai_analysis_trials' => '%s free AI monitor setups',
            ],
            'tr' => [
                'monitors' => '%s monitör',
                'status_pages' => '%s durum sayfası',
                'subscribers' => '%s e-posta abonesi',
                'responders' => '%s nöbetçi',
                'ai_analysis_trials' => '%s ücretsiz AI monitör kurulumu',
            ],
        ];

        $free = Arr::first(
            (array) config('plans.tiers'),
            fn (array $tier): bool => ($tier['id'] ?? null) === 'free',
        );

        foreach (['monitors', 'status_pages', 'subscribers', 'responders', 'ai_analysis_trials'] as $key) {
            $value = (string) Arr::get($free, "limits.{$key}");

            foreach ($this->supported() as $locale) {
                $this->get($this->pathFor($locale))->assertSee(sprintf($nouns[$locale][$key], $value));
            }
        }
    }

    public function test_the_retention_figure_equals_the_timescale_config(): void
    {
        // `hover:opacity-90` in the header satisfied the old bare `assertSee('90')`, so this
        // test passed on a page that never mentioned retention at all.
        $days = (string) config('timescale.retention.raw_days');

        $this->get($this->pathFor('en'))->assertSee($days.' days');
        $this->get($this->pathFor('tr'))->assertSee($days.' gün');
    }

    public function test_the_retention_figure_follows_the_config_rather_than_a_literal(): void
    {
        /*
         * The other half of the test above, mirroring `PrivacyPageTest`: that one proves the
         * page carries the deployed window, this one proves it carries it BECAUSE the config
         * says so. `TIMESCALE_RAW_RETENTION_DAYS` is an env value a deployment is free to move,
         * and a transcribed figure would then tell every reader the wrong retention period in
         * two languages with nothing failing.
         */
        $deployed = (string) config('timescale.retention.raw_days');

        $this->assertNotSame('37', $deployed, 'The deployed window equals the probe value, so this test proves nothing.');

        config(['timescale.retention.raw_days' => 37]);

        $this->get($this->pathFor('en'))
            ->assertSee('37 days')
            ->assertDontSee($deployed.' days');

        $this->get($this->pathFor('tr'))
            ->assertSee('37 gün')
            ->assertDontSee($deployed.' gün');
    }

    public function test_no_answer_claims_a_rollup_tier_the_database_does_not_build(): void
    {
        /*
         * The FAQ used to publish "rolled up into hourly and daily aggregates". No continuous
         * aggregate exists: `setup_timescaledb_hypertables` attaches retention to
         * `monitor_checks` and `monitor_metric_values` on the RAW window only, and
         * `config/timescale.php`'s `aggregates` block is read by nothing. The one rollup that
         * does exist is the `monitor_daily_uptime` strip the status pages show, which the
         * answer may describe and nothing prunes.
         */
        $claims = [
            'en' => ['rolled up into', 'hourly and daily aggregates'],
            'tr' => ['saatlik ve günlük özetlere'],
        ];

        foreach ($this->supported() as $locale) {
            $source = $this->source($locale);

            foreach ($claims[$locale] ?? [] as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $source,
                    "The FAQ source in \"{$locale}\" publishes a rollup tier no code creates.",
                );
            }
        }
    }

    public function test_the_cancellation_answer_describes_the_route_that_exists(): void
    {
        /*
         * The FAQ used to answer "from your billing settings, any time", which contradicted the
         * Terms and the code: `POST billing/cancel` and `BillingService.cancel()` exist, and no
         * view calls either. The billing screen wires checkout and `openPortal` only, so the
         * email route is the one that reliably works and the portal's contents are the payment
         * provider's configuration.
         */
        $needles = [
            'en' => ['section 8 of the', 'the payment provider'],
            'tr' => ['8. bölümünde', 'ödeme sağlayıcısının'],
        ];

        foreach ($this->supported() as $locale) {
            $response = $this->get($this->pathFor($locale));

            foreach ($needles[$locale] ?? [] as $needle) {
                $response->assertSee($needle);
            }
        }

        $this->get($this->pathFor('en'))->assertDontSee('From your billing settings');
        $this->get($this->pathFor('tr'))->assertDontSee('Faturalandırma ayarlarınızdan');
    }

    public function test_the_refund_answer_deducts_nothing_for_consumed_ai(): void
    {
        /*
         * The answer to the question the operator actually asked, and it is a no: an AI
         * analysis is an entitlement inside the plan rather than a separately priced unit
         * (`config/plans.php` carries `limits.ai` as a capability level and
         * `limits.ai_analysis_trials` as the free-tier meter, and no price), and
         * `BillingController::checkout()` collects neither the CRD Art. 8(8) express request nor
         * the acknowledgement, so Art. 14(4)(a) leaves the consumer bearing no cost at all for
         * what was supplied inside the withdrawal period. The figure comes from the catalog, as
         * every figure on this page does.
         *
         * Full reasoning and sources: research/librarian-identity-and-ai-refunds.md section 2,
         * and `TermsPageTest` pins the Terms clause this answer summarises.
         */
        $trials = (string) Arr::get(
            Arr::first((array) config('plans.tiers'), fn (array $tier): bool => ($tier['id'] ?? null) === 'free'),
            'limits.ai_analysis_trials',
        );

        $this->get($this->pathFor('en'))
            ->assertSee('14 days')
            ->assertSee('nothing is deducted')
            ->assertSee($trials.' AI monitor setups');

        $this->get($this->pathFor('tr'))
            ->assertSee('14 gün')
            ->assertSee('hiçbir kesinti')
            ->assertSee($trials.' AI monitör kurulumu');
    }

    public function test_the_alert_channels_equal_the_notification_channel_enum(): void
    {
        $expected = [
            NotificationChannelType::Slack->value => 'Slack',
            NotificationChannelType::Webhook->value => 'Webhook',
            NotificationChannelType::PagerDuty->value => 'PagerDuty',
            NotificationChannelType::Teams->value => 'Microsoft Teams',
        ];

        foreach ($this->supported() as $locale) {
            $response = $this->get($this->pathFor($locale));

            foreach ($expected as $name) {
                $response->assertSee($name);
            }
        }
    }

    public function test_the_ai_character_cap_equals_the_incident_analysis_payload(): void
    {
        // With the unit word, because the shared layout's own `2500` millisecond timeout
        // contains the cap's digits and satisfied the bare assertion by itself.
        $limit = (string) IncidentAnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH;

        $this->get($this->pathFor('en'))->assertSee($limit.' characters');
        $this->get($this->pathFor('tr'))->assertSee($limit.' karakter');
    }

    public function test_no_question_claims_a_capability_the_product_lacks(): void
    {
        /*
         * `sso`, `white-label`, `saml` and a custom status-page domain are never mentioned
         * at all, positively or negatively: the Description does not ask for them and
         * naming them would invite the exact "sounds good" claim this step exists to
         * prevent. `ping` and `dns`, by contrast, ARE mentioned, deliberately: the monitor-
         * types answer states their absence honestly rather than staying silent, which the
         * next test proves.
         */
        foreach ($this->supported() as $locale) {
            $path = $this->pathFor($locale);
            $html = strtolower($this->get($path)->getContent());

            foreach (['sso', 'white-label', 'white label', 'saml', 'custom domain', 'own domain'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $html,
                    "GET {$path} contains the forbidden term \"{$forbidden}\".",
                );
            }
        }
    }

    public function test_the_monitor_types_answer_honestly_denies_ping_dns_and_browser_checks(): void
    {
        /*
         * Locale-specific because a Turkish sentence joins the three denials with "ya da"
         * (or) and a single trailing "yok" (none exist) rather than repeating "no X" per
         * item the way the English sentence does.
         */
        $needles = [
            'en' => ['no ping', 'no dns', 'no browser'],
            'tr' => ['ping', 'dns', 'kontrol yok'],
        ];

        foreach ($this->supported() as $locale) {
            $html = strtolower($this->get($this->pathFor($locale))->getContent());

            preg_match('/<details.*<\/details>/s', $html, $matches);
            $accordion = $matches[0] ?? '';

            foreach ($needles[$locale] ?? [] as $needle) {
                $this->assertStringContainsString($needle, $accordion, "Locale \"{$locale}\" is missing the honest denial \"{$needle}\".");
            }
        }
    }

    public function test_the_accordion_uses_no_javascript(): void
    {
        /*
         * The whole point of <details>/<summary>: it opens with JavaScript disabled. The
         * shared marketing shell carries its own <script> tags (a motion-preference probe,
         * Vite's dev client), so the check is scoped to the rendered answers themselves:
         * the substring from the first <details> to the last </details>, matching the QA
         * instruction ("no x-data, no @click, no <script> and no Alpine attribute anywhere
         * in your rendered answers").
         */
        foreach ($this->supported() as $locale) {
            $path = $this->pathFor($locale);
            $html = $this->get($path)->getContent();

            preg_match('/<details.*<\/details>/s', $html, $matches);

            $this->assertNotSame([], $matches, "GET {$path} printed no <details> block, so this check tested nothing.");

            $accordion = $matches[0];

            $this->assertStringNotContainsString('x-data', $accordion);
            $this->assertStringNotContainsString('x-show', $accordion);
            $this->assertStringNotContainsString('@click', $accordion);
            $this->assertStringNotContainsString('<script', $accordion);
        }
    }

    /**
     * The languages the whole product speaks, matching `LegalPagesTest`.
     *
     * @return list<string>
     */
    protected function supported(): array
    {
        return array_values((array) config('magic-starter.supported_locales', []));
    }

    /**
     * The FAQ's own path in one language. The default language lives on the apex.
     */
    protected function pathFor(string $locale): string
    {
        return $locale === config('app.default_locale') ? '/faq' : '/'.$locale.'/faq';
    }

    /**
     * The Markdown source for one language, read from disk.
     *
     * Read rather than rendered where the RENDERED page would answer a different question: the
     * shell contributes chrome copy this document did not write, and a claim this page must not
     * make is a claim about the Markdown.
     */
    protected function source(string $locale): string
    {
        return (string) file_get_contents(resource_path("legal/faq.{$locale}.md"));
    }
}
