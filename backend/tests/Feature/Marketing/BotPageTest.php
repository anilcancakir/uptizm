<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use Tests\TestCase;

/**
 * The `/bot` page's CONTENT.
 *
 * `LegalPagesTest` already pins the plumbing this page shares with the other content
 * pages (routing, canonical, hreflang, the no-unreplaced-placeholder rule), so nothing
 * here repeats it. What this file pins is the honesty this page exists for:
 *
 *   - the block-promise sentence ("we will read that as the answer it is rather than
 *     routing around it") survives VERBATIM, so a future edit that softens or drops it
 *     reddens here rather than shipping unnoticed;
 *   - the new disclosure naming third-party exit addresses sits next to it, in both
 *     languages;
 *   - `[[bot.probe_regions]]` and `[[bot.probe_daily_requests]]` are DERIVED from
 *     `config('proxy.sources')`, the same source `ServiceCatalogSeeder` seeds a
 *     monitor's `regions` from, and NOT from `MonitorRegion::cases()`. A deployment
 *     configured with two proxy regions must publish "2", never "5".
 */
class BotPageTest extends TestCase
{
    /**
     * The sentence this page committed to and must never quietly lose: blocking the
     * User-Agent is honoured as a stop request rather than routed around. Asserted
     * character-for-character in both languages, including the line break the
     * Markdown source wraps on: CommonMark renders a soft line break as a literal
     * "\n" inside the `<p>` rather than collapsing it to a space, so a needle typed
     * with a plain space never matches the rendered page.
     */
    private const string PROMISE = "Blocking the User-Agent works too, and we will read that as the answer it is\n"
        .'rather than routing around it.';

    private const string PROMISE_TR = "User-Agent değerini engellemeniz de işe yarar ve bunu etrafından dolaşmak\n"
        .'yerine olduğu gibi bir cevap olarak kabul ederiz.';

    public function test_the_promise_sentence_survives_verbatim_in_both_languages(): void
    {
        $this->get($this->pathFor('en'))->assertOk()->assertSee(self::PROMISE);
        $this->get($this->pathFor('tr'))->assertOk()->assertSee(self::PROMISE_TR);
    }

    /**
     * The disclosure this step adds: both channels leave from third-party exit
     * addresses in a rotating pool, not one address of ours, so blocking a single exit
     * does not reliably stop future requests the way blocking the User-Agent does.
     */
    public function test_the_disclosure_names_third_party_exit_addresses_in_both_languages(): void
    {
        $this->get($this->pathFor('en'))
            ->assertOk()
            ->assertSee('third-party exit addresses');

        $this->get($this->pathFor('tr'))
            ->assertOk()
            ->assertSee('üçüncü taraf çıkış adreslerinden');
    }

    /**
     * `[[bot.probe_regions]]` and `[[bot.probe_daily_requests]]` follow the region
     * count a catalog monitor actually carries, not `MonitorRegion::cases()`.
     *
     * The configured count (2) is asserted to differ from the full enum's count (5)
     * before anything else runs, because a page that happened to print "2" while
     * still reading the enum would only prove something if the enum itself carried 2
     * cases. It does not, so a passing assertion here is real evidence the figure
     * moved with the config.
     *
     * Asserted against the Markdown source's own `**bold**` wrapping
     * (`<strong>...</strong>`) rather than a bare number: the shared footer prints
     * "from 5 regions" (`resources/views/marketing/footer.blade.php:38`) as plain
     * text on every marketing page including this one, so a bare `assertSee('5
     * regions')`/`assertDontSee('5 regions')` would read that sentence instead of
     * this one.
     */
    public function test_the_probe_region_count_and_daily_requests_follow_the_configured_proxy_regions(): void
    {
        $enumCount = count(MonitorRegion::cases());
        $configuredCount = 2;

        $this->assertNotSame(
            $enumCount,
            $configuredCount,
            'The full region enum already has 2 cases, so this test cannot prove the figure follows config.',
        );

        config(['proxy.sources' => [
            'eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt'],
            'us-east' => ['kind' => 'url', 'location' => 'https://example.test/us-east.txt'],
        ]]);

        $intervalSeconds = (int) config('uptizm.catalog_probe_interval_sec');
        $dailyRequests = number_format($configuredCount * intdiv(86400, $intervalSeconds));
        $enumDailyRequests = number_format($enumCount * intdiv(86400, $intervalSeconds));

        $this->get($this->pathFor('en'))
            ->assertOk()
            ->assertSee('<strong>'.$configuredCount.' regions</strong>', escape: false)
            ->assertSee('<strong>'.$dailyRequests.' requests a day</strong>', escape: false)
            ->assertDontSee('<strong>'.$enumCount.' regions</strong>', escape: false)
            ->assertDontSee('<strong>'.$enumDailyRequests.' requests a day</strong>', escape: false);

        $this->get($this->pathFor('tr'))
            ->assertOk()
            ->assertSee('<strong>'.$configuredCount.' bölgeden</strong>', escape: false)
            ->assertDontSee('<strong>'.$enumCount.' bölgeden</strong>', escape: false);
    }

    /**
     * This page's own path in one language. The default language lives on the apex.
     */
    protected function pathFor(string $locale): string
    {
        return $locale === config('app.default_locale') ? '/bot' : '/'.$locale.'/bot';
    }
}
