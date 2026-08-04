<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * The egress disclosure, and it has to be PER CLIENT rather than about "both".
     *
     * The availability check leaves through a rotating pool of third-party exits, so
     * blocking one address does not hold. The status-feed read does not: `FeedFetcher`
     * passes no `proxy` option at all (`grep -c proxy` over that file returns 0), so it
     * comes straight from one of our own servers and blocking that address DOES stop it
     * permanently.
     *
     * An earlier revision of this page said "both channels", which was false for the
     * feed read and actively misleading to the one reader this page has: an operator
     * who wants us stopped was told the cheap remedy would not work when for half the
     * traffic it does. This test pins the SCOPING, not just the phrase, which is why it
     * asserts the feed sentence too.
     */
    public function test_the_disclosure_scopes_the_rotating_pool_to_the_availability_check(): void
    {
        config(['proxy.sources' => [
            'eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt'],
        ], 'proxy.direct_region' => '']);

        $this->get($this->pathFor('en'))
            ->assertOk()
            ->assertSee('third-party exit addresses')
            ->assertSee('The availability')
            ->assertSee('comes straight from one of our own servers')
            ->assertDontSee('both channels leave from third-party exit addresses');

        $this->get($this->pathFor('tr'))
            ->assertOk()
            ->assertSee('üçüncü taraf çıkış')
            ->assertSee('bizim sunucularımızdan birinden gelir');
    }

    /**
     * The egress sentence describes THIS deployment, in every one of its four shapes.
     *
     * An operator holds this page to their own access log, so the sentence has to
     * match where the requests actually come from. Two of the four shapes are wrong in
     * opposite directions if the sentence is hardcoded, and both were: before this was
     * derived the page claimed a rotating third-party pool unconditionally, which is
     * false on a deployment probing directly from this server, and the first draft of
     * the derivation claimed a direct probe on a deployment configured for NEITHER,
     * which describes traffic that does not exist at all.
     *
     * @param  array<string, array{kind: string, location: string}>  $sources
     */
    #[DataProvider('egressShapes')]
    public function test_the_egress_sentence_describes_the_configured_egress(
        array $sources,
        string $directRegion,
        string $expected,
        string $expectedTurkish,
        string $forbidden,
    ): void {
        config(['proxy.sources' => $sources, 'proxy.direct_region' => $directRegion]);

        $this->get($this->pathFor('en'))
            ->assertOk()
            ->assertSee($expected)
            ->assertDontSee($forbidden);

        $this->get($this->pathFor('tr'))
            ->assertOk()
            ->assertSee($expectedTurkish);
    }

    /**
     * The region figure is a pluralised PHRASE, because the count really can be one.
     *
     * Production reached that state on its first deploy (one probeable region, the
     * direct one) and the page published "1 regions", since the English sentence
     * carried the noun as literal prose. Asserted in both directions so a revert to a
     * bare number reddens rather than merely reading oddly.
     */
    public function test_a_single_region_reads_as_one_region_and_not_one_regions(): void
    {
        config(['proxy.sources' => [], 'proxy.direct_region' => 'eu-west']);

        $this->get($this->pathFor('en'))
            ->assertOk()
            ->assertSee('<strong>1 region</strong>', escape: false)
            ->assertDontSee('<strong>1 regions</strong>', escape: false);
    }

    /**
     * @return array<string, array{array<string, array{kind: string, location: string}>, string, string, string, string}>
     */
    public static function egressShapes(): array
    {
        $pool = ['eu-west' => ['kind' => 'url', 'location' => 'https://example.test/eu-west.txt']];

        return [
            'a pool only' => [
                $pool, '',
                'rather than from one address of ours',
                'uzun süre dışarıda tutmaz',
                'leaves directly from one of our own servers',
            ],
            'a direct region only' => [
                [], 'us-east',
                'leaves directly from one of our own servers',
                'dolayısıyla o tek adresi engellemek onu durdurur',
                'third-party exit addresses in a rotating pool rather than',
            ],
            'both shapes at once' => [
                $pool, 'us-east',
                'Some of those checks leave from third-party exit addresses',
                'Bu kontrollerin bir kısmı',
                'rather than from one address of ours',
            ],
            // The shape production is in before a proxy provider is wired.
            'neither, so no check is running' => [
                [], '',
                'No availability check is leaving this deployment',
                'hiçbir erişilebilirlik kontrolü çıkmıyor',
                'leaves directly from one of our own servers',
            ],
        ];
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
