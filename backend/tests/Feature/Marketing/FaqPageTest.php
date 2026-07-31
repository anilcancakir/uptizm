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
        $expectedCount = (string) count(MonitorRegion::cases());
        $expectedNames = array_map(fn (MonitorRegion $region): string => $region->label(), MonitorRegion::cases());

        foreach ($this->supported() as $locale) {
            $response = $this->get($this->pathFor($locale))->assertSee($expectedCount);

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
        foreach ((array) config('plans.tiers') as $tier) {
            $seconds = (string) Arr::get($tier, 'limits.check_interval_sec');

            foreach ($this->supported() as $locale) {
                $this->get($this->pathFor($locale))->assertSee($seconds);
            }
        }
    }

    public function test_the_free_tier_limits_equal_the_plan_catalog(): void
    {
        $free = Arr::first(
            (array) config('plans.tiers'),
            fn (array $tier): bool => ($tier['id'] ?? null) === 'free',
        );

        foreach (['monitors', 'status_pages', 'subscribers', 'responders', 'ai_analysis_trials'] as $key) {
            $value = (string) Arr::get($free, "limits.{$key}");

            foreach ($this->supported() as $locale) {
                $this->get($this->pathFor($locale))->assertSee($value);
            }
        }
    }

    public function test_the_retention_figure_equals_the_timescale_config(): void
    {
        $days = (string) config('timescale.retention.raw_days');

        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))->assertSee($days);
        }
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
        $limit = (string) IncidentAnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH;

        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))->assertSee($limit);
        }
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
}
