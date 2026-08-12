<?php

namespace Tests\Feature\StatusPage;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Mail\StatusPageSubscribeConfirmation;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
use App\Services\StatusPages\StatusPageAssembler;
use App\Support\StatusPages\StatusPageLocale;
use Carbon\CarbonImmutable;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public status page renders in the ONE language its owner chose, and
 * nothing about it is left in English by accident.
 *
 * `status_pages.locale` is the whole input: no path segment, no switcher, no
 * `Accept-Language`. `ShowStatusPageController` applies it per request, and every
 * string below it reads that locale implicitly, which is what makes two of these
 * tests unusual.
 *
 * The English-original assertions run in PAIRS on purpose. An `assertDontSee`
 * over a string that was mistyped, or that this fixture never renders, passes for
 * free and certifies nothing; so every English original in the two lists below is
 * asserted PRESENT on an English page built from the same fixture and ABSENT on
 * its Turkish twin. A key left untranslated fails the second half, and a list
 * entry that has drifted from the template fails the first.
 *
 * The locale-leak test issues TWO requests in one process, which is the only shape
 * that can see the defect the controller's unconditional `setLocale` exists to
 * prevent: under Octane the translator is a singleton that survives between
 * requests, so a worker that just served a Turkish page would answer the next
 * visitor in Turkish. One request can never observe that.
 */
class StatusPageLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * English copy a POPULATED page renders (components, incidents, a published
     * postmortem, a maintenance window, the subscribe box).
     *
     * Each entry must appear on the English page and must not appear on the
     * Turkish one.
     */
    protected const array POPULATED_ENGLISH_COPY = [
        // The banner label, composed in PHP rather than in a Blade, which is why
        // a template grep never found it.
        'Degraded Performance',
        'updated ',
        'Components',
        'Incidents',
        // The per-update status prefix, and the lifecycle badge's humanised value.
        'Investigating',
        'investigating',
        'started ',
        'Postmortem',
        'published',
        'Scheduled maintenance',
        'in progress',
        'Subscribe to updates',
        'posts a new incident',
        'Subscribe',
    ];

    /**
     * English copy only an EMPTY page renders: the three "nothing here" states.
     */
    protected const array EMPTY_ENGLISH_COPY = [
        'No Components Published',
        'No components are currently published on this page.',
        'No incidents reported.',
    ];

    /**
     * The five rungs the banner can publish, paired with their Turkish copy.
     *
     * `partial_outage` is unreachable through the HTTP route today:
     * {@see StatusPageAssembler::componentStatus()} maps a monitor onto
     * `major_outage`, `degraded`, `operational` or the unknown family and never
     * onto `partial_outage`, so `worstOf` cannot roll one up. It is still on the
     * ladder, `StatusPresentation` still paints it, and a label for it still has
     * to exist, which is why this pair is asserted against the assembler directly
     * rather than against a rendered page.
     */
    protected const array BANNER_LADDER = [
        'operational' => 'Tüm sistemler çalışıyor',
        'degraded' => 'Performans düşük',
        'partial_outage' => 'Kısmi kesinti',
        'major_outage' => 'Büyük kesinti',
        StatusPageAssembler::STATUS_UNKNOWN => 'Yayınlanmış bileşen yok',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the deployment default rather than inheriting whatever APP_LOCALE
        // the machine running the suite happens to export: half of these
        // assertions are about what a page with a NULL locale renders, and that
        // answer comes from `app.default_locale`.
        config(['app.default_locale' => 'en']);
        App::setLocale('en');
    }

    public function test_a_turkish_page_renders_its_copy_its_banner_and_its_incident_title_in_turkish(): void
    {
        $turkish = $this->makePopulatedPage('locale-tr-populated', 'tr');
        $english = $this->makePopulatedPage('locale-en-populated', null);

        $turkishHtml = $this->get('/s/locale-tr-populated')->assertOk()->getContent();
        $englishHtml = $this->get('/s/locale-en-populated')->assertOk()->getContent();

        // The document declares the language its copy is actually in.
        $this->assertStringContainsString('<html lang="tr">', $turkishHtml);
        $this->assertStringContainsString('<html lang="en">', $englishHtml);

        $this->assertEnglishCopyMovedOver(self::POPULATED_ENGLISH_COPY, $englishHtml, $turkishHtml);

        // The dates, which are the only copy on this page composed by Carbon rather
        // than resolved from a catalogue, so no key-based assertion reaches them.
        // Both the incident stamp and the maintenance window's two bounds shipped an
        // English month abbreviation inside a Turkish sentence, and only one of the
        // two was caught first: asserting the English month is ABSENT from the
        // Turkish page is what makes the pair a pair.
        //
        // The month comes from the fixture's own clock rather than a literal, since
        // both fixtures are built relative to `now()`.
        $englishMonth = CarbonImmutable::now()->format('M');
        $turkishMonth = CarbonImmutable::now()->locale('tr')->translatedFormat('M');

        $this->assertStringContainsString($englishMonth, $englishHtml);

        if ($turkishMonth !== $englishMonth) {
            $this->assertStringNotContainsString(
                $englishMonth,
                $turkishHtml,
                "[{$englishMonth}] reached the Turkish page: a date is still formatted "
                .'without the page locale, so its month stays English.',
            );
            $this->assertStringContainsString($turkishMonth, $turkishHtml);
        }

        // The Turkish the customer actually reads, key by key.
        foreach ([
            'Performans düşük',
            'güncellendi',
            'Bileşenler',
            'Olaylar',
            'İnceleniyor',
            'inceleniyor',
            'itibarıyla başladı',
            'Olay sonrası inceleme',
            'yayınlandı',
            'Planlı bakım',
            'sürüyor',
            'Güncellemelere abone ol',
            'yeni bir olay yayınladığında e-posta alın',
            'Abone ol',
        ] as $turkishCopy) {
            $this->assertStringContainsString(
                $turkishCopy,
                $turkishHtml,
                "The Turkish status page must read [{$turkishCopy}].",
            );
        }

        // The incident TITLE, which is the seam this PR opened: the row stores the
        // English render plus a key, and the page renders the key.
        $this->assertStringContainsString('Checkout API kesintide', $turkishHtml);
        $this->assertStringNotContainsString('Checkout API is down', $turkishHtml);
        $this->assertStringContainsString('Checkout API is down', $englishHtml);

        // The day heading is the one date on the page that no client-side script
        // rewrites, so it carries the language's own month order.
        $today = CarbonImmutable::now();
        $this->assertStringContainsString($today->locale('tr')->translatedFormat('j F Y'), $turkishHtml);
        $this->assertStringContainsString($today->locale('en')->translatedFormat('F j, Y'), $englishHtml);

        // The brand line is the deliberate exception, in both languages.
        $this->assertStringContainsString('Powered by Uptizm', $turkishHtml);
        $this->assertStringContainsString('Powered by Uptizm', $englishHtml);

        $this->assertSame($turkish->locale, 'tr');
        $this->assertNull($english->locale);
    }

    public function test_a_turkish_page_renders_its_three_empty_states_in_turkish(): void
    {
        $this->makeEmptyPage('locale-tr-empty', 'tr');
        $this->makeEmptyPage('locale-en-empty', null);

        $turkishHtml = $this->get('/s/locale-tr-empty')->assertOk()->getContent();
        $englishHtml = $this->get('/s/locale-en-empty')->assertOk()->getContent();

        $this->assertEnglishCopyMovedOver(self::EMPTY_ENGLISH_COPY, $englishHtml, $turkishHtml);

        $this->assertStringContainsString('Yayınlanmış bileşen yok', $turkishHtml);
        $this->assertStringContainsString('Bu sayfada şu anda yayınlanmış bir bileşen yok.', $turkishHtml);
        $this->assertStringContainsString('Bildirilen bir olay yok.', $turkishHtml);
    }

    public function test_a_turkish_page_does_not_leave_the_next_visitor_reading_turkish(): void
    {
        /*
         * TWO requests, one process, two different pages. This is the defect the
         * controller's unconditional `setLocale` exists to prevent: the translator
         * is a singleton that outlives a request under Octane, so a controller that
         * only set the locale "when it is tr" would leave the worker in Turkish and
         * the NEXT page (locale null, so the deployment default) would render
         * Turkish copy to whoever asked for it.
         *
         * The assertion has to be about the second response, and a single-request
         * test cannot make it.
         */
        $this->makePopulatedPage('locale-leak-tr', 'tr');
        $this->makePopulatedPage('locale-leak-default', null);

        $first = $this->get('/s/locale-leak-tr')->assertOk()->getContent();
        $second = $this->get('/s/locale-leak-default')->assertOk()->getContent();

        $this->assertStringContainsString('Performans düşük', $first);

        $this->assertStringContainsString('<html lang="en">', $second);
        $this->assertStringContainsString('Degraded Performance', $second);
        $this->assertStringNotContainsString('Performans düşük', $second);
        $this->assertStringNotContainsString('Bileşenler', $second);

        // And back again, so the reverse order is covered too: a default page
        // must not pin the worker to English for a Turkish page behind it.
        $third = $this->get('/s/locale-leak-tr')->assertOk()->getContent();
        $this->assertStringContainsString('Performans düşük', $third);
    }

    public function test_the_render_language_falls_from_the_url_to_the_page_to_the_default(): void
    {
        $default = new StatusPage(['locale' => null]);
        $turkish = new StatusPage(['locale' => 'tr']);

        // The URL wins: a page published in English is readable in Turkish, which
        // is the whole point of the prefixed route.
        $this->assertSame('tr', StatusPageLocale::render('tr', $default));

        // No URL segment, so the owner's column decides.
        $this->assertSame('tr', StatusPageLocale::render(null, $turkish));

        // Neither, so the deployment default. This is every page that existed
        // before the column did.
        $this->assertSame('en', StatusPageLocale::render(null, $default));

        // A language we do not publish in is not a language: the routes already
        // refuse it with `whereIn`, and this is the second lock, because a page
        // rendered under an unsupported code shows a customer dotted catalogue
        // keys.
        $this->assertSame('en', StatusPageLocale::render('de', $default));
        $this->assertSame('tr', StatusPageLocale::render('de', $turkish));
    }

    public function test_the_page_decides_which_language_needs_no_prefix(): void
    {
        /*
         * The one fact four layers have to agree on: the routes accept every
         * language, the controller 404s the prefix that duplicates this one, the
         * chrome leaves this one unprefixed, and the sitemap publishes its URL. It
         * lives in a single accessor because when each layer answered it for itself,
         * three of them answered `app.default_locale` and the page published an
         * `hreflang="en"` naming a Turkish document.
         */
        $this->assertSame('tr', StatusPageLocale::canonical(new StatusPage(['locale' => 'tr'])));

        // Null is the common shape, and every page that existed before the column
        // did: the deployment default keeps the bare URL.
        $this->assertSame('en', StatusPageLocale::canonical(new StatusPage(['locale' => null])));

        // Read from `app.default_locale`, never from `app.locale`: a request that
        // has already rendered a Turkish page rewrites the latter for the rest of
        // its life, which under Octane is longer than one request.
        App::setLocale('tr');

        $this->assertSame('en', StatusPageLocale::canonical(new StatusPage(['locale' => null])));
    }

    public function test_the_supported_list_leads_with_the_deployment_default(): void
    {
        /*
         * Public so the routes, the chrome's switcher links and the offer
         * negotiation cannot enumerate three different lists, and ORDERED so the
         * default comes first: `getPreferredLanguage()` answers `$locales[0]` on a
         * total mismatch, and {@see StatusPageLocale::offer()} reads that answer as
         * "no offer". A raw config read here loses both properties at once.
         */
        config([
            'app.default_locale' => 'de',
            'magic-starter.supported_locales' => ['en', 'tr'],
        ]);

        $this->assertSame(['de', 'en', 'tr'], StatusPageLocale::supported());

        // The default already inside the config array is not duplicated: it would
        // put two identical links in the switcher and two identical alternates in
        // the head.
        config(['app.default_locale' => 'tr']);

        $this->assertSame(['tr', 'en'], StatusPageLocale::supported());
    }

    public function test_the_url_language_wins_over_the_pages_own(): void
    {
        // ONE request, on a page whose own locale is the deployment default: what
        // makes it Turkish is the URL and nothing else.
        $this->makePopulatedPage('locale-url-wins', null);

        $html = $this->get('/tr/s/locale-url-wins')->assertOk()->getContent();

        $this->assertStringContainsString('<html lang="tr">', $html);
        $this->assertStringContainsString('Bileşenler', $html);
        $this->assertStringNotContainsString('Degraded Performance', $html);
    }

    public function test_the_client_side_date_rewrite_formats_in_the_page_language(): void
    {
        /*
         * The layout rewrites every `<time datetime>` into the reader's own zone
         * client-side. Passing no locale took the FORMAT from the browser too,
         * which put "8/12/2026, 6:06:45 PM" under the Turkish heading
         * "12 Ağustos 2026" on the same page for any visitor whose browser is
         * en-US. The zone is a fact about where the reader is; the language is
         * the one they asked this page for.
         *
         * This asserts the template, because the behaviour itself is the
         * browser's and PHPUnit never runs it. The live proof is the CDP walk in
         * this plan's evidence: same instant, same zone, `/s/acme` renders
         * "8/12/2026, 6:58:08 PM" and `/tr/s/acme` renders "12.08.2026 18:58:08"
         * in one headless Chrome running as en-US. The third assertion is the one
         * with teeth: a bare `toLocaleString()` anywhere is the regression.
         */
        $this->makePopulatedPage('date-locale', null);

        $html = $this->get('/tr/s/date-locale')->assertOk()->getContent();

        $this->assertStringContainsString('document.documentElement.lang || undefined', $html);
        $this->assertStringContainsString('toLocaleString(pageLocale)', $html);
        $this->assertStringNotContainsString('toLocaleString()', $html);
    }

    public function test_the_language_offer_is_null_unless_the_visitor_named_another_supported_language(): void
    {
        /*
         * Asserted on `offer()` directly rather than through a rendered page: the
         * banner that would show it does not exist yet, and the case that matters
         * most here is the one that produces NOTHING, which a page cannot
         * distinguish from a banner nobody wrote.
         */
        $turkishBrowser = $this->requestPreferring('tr-TR,tr;q=0.9,en;q=0.8');

        // A Turkish-preferring visitor on the English page: the one case that is
        // an offer.
        $this->assertSame('tr', StatusPageLocale::offer($turkishBrowser, 'en'));

        // The same visitor already reading Turkish. Nothing to offer.
        $this->assertNull(StatusPageLocale::offer($turkishBrowser, 'tr'));

        /*
         * A visitor who speaks neither. `getPreferredLanguage()` answers
         * `$locales[0]` on a TOTAL mismatch rather than null, so without the
         * check that the visitor actually named the answer, this German reader
         * would be offered English on a Turkish page: a banner in a language they
         * did not ask for, pointing at a page they did not want.
         */
        $germanBrowser = $this->requestPreferring('de-DE,de;q=0.9');

        $this->assertNull(StatusPageLocale::offer($germanBrowser, 'en'));
        $this->assertNull(StatusPageLocale::offer($germanBrowser, 'tr'));

        // A crawler sends no `Accept-Language` at all, and the same fallback
        // would answer with the default locale.
        $crawler = $this->requestAnnouncingNoLanguage();

        $this->assertNull(StatusPageLocale::offer($crawler, 'en'));
        $this->assertNull(StatusPageLocale::offer($crawler, 'tr'));

        // The mirror image of the first case, so the German null above is not
        // passing for the wrong reason: an English-preferring visitor on the
        // Turkish page IS offered English.
        $this->assertSame('en', StatusPageLocale::offer($this->requestPreferring('en-US,en;q=0.9'), 'tr'));

        // A region we do not publish in still prefers its language, because the
        // negotiator widens `tr-TR` to `tr` and this check widens with it.
        $this->assertSame('tr', StatusPageLocale::offer($this->requestPreferring('tr-CY'), 'en'));
    }

    public function test_the_rendered_view_data_carries_the_pages_language_chrome(): void
    {
        /*
         * Every one of these four is dereferenced UNGUARDED by a partial, so a
         * render that omits one throws rather than degrading. They are asserted
         * on the view data rather than on the HTML because the partials that
         * consume them land in a later step, and a test that waited for the
         * markup would leave the controller's half unproven until then.
         *
         * Two pages rather than one URL twice: the cached read model is keyed by
         * slug alone, so a second request for the same page in another language
         * would read the first one's payload.
         */
        config(['app.url' => 'https://uptizm.test']);

        $this->makeEmptyPage('locale-chrome-en', null);
        $this->makeEmptyPage('locale-chrome-tr', null);

        $english = $this->get('/s/locale-chrome-en')->assertOk();

        $this->assertSame('https://uptizm.test/s/locale-chrome-en', $english->viewData('canonicalUrl'));
        $this->assertSame('https://uptizm.test/s/locale-chrome-en', $english->viewData('canonicalLocaleUrl'));
        $this->assertSame(['en', 'tr'], array_column($english->viewData('localeLinks'), 'code'));
        $this->assertSame([true, false], array_column($english->viewData('localeLinks'), 'current'));
        $this->assertNull($english->viewData('languageOffer'));

        // The prefixed URL is canonical for ITSELF, which is what stops the two
        // languages of one page from competing as duplicates.
        $turkish = $this->get('/tr/s/locale-chrome-tr', [
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
        ])->assertOk();

        $this->assertSame('https://uptizm.test/tr/s/locale-chrome-tr', $turkish->viewData('canonicalUrl'));
        $this->assertSame('https://uptizm.test/s/locale-chrome-tr', $turkish->viewData('canonicalLocaleUrl'));
        $this->assertSame([false, true], array_column($turkish->viewData('localeLinks'), 'current'));

        // The visitor is already reading what they asked for, so the controller
        // offers nothing even though it negotiated.
        $this->assertNull($turkish->viewData('languageOffer'));

        // And the wiring itself: the same browser on the English URL is offered
        // the language it prefers.
        $offered = $this->get('/s/locale-chrome-en', [
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
        ])->assertOk();

        $this->assertSame('tr', $offered->viewData('languageOffer'));
    }

    public function test_an_offer_the_switcher_cannot_link_never_reaches_the_banner(): void
    {
        /*
         * A PUBLIC page 500s if these two lists disagree, and they did:
         * `StatusPageLocale::supported()` PREPENDS `app.default_locale` before
         * negotiating, while the chrome's switcher links came from
         * `magic-starter.supported_locales` raw. On a deployment whose default is
         * absent from that array a visitor is offered a language the switcher has
         * no link for, and the banner dereferences that link unguarded on purpose
         * (a missing link is a broken contract, not a visitor-facing state).
         *
         * The page is published in Turkish so the German offer is not suppressed
         * as "already reading it", and the assertions are on the RENDERED banner
         * because the missing-link failure only exists once something dereferences
         * the lookup.
         *
         * The offered link is composed rather than resolved through the router:
         * routes are registered at boot from the real config, so on this fixture
         * `/de/...` is not registered even though a genuine `APP_LOCALE=de`
         * deployment would register it from the same list.
         */
        config([
            'app.default_locale' => 'de',
            'magic-starter.supported_locales' => ['en', 'tr'],
        ]);

        $this->makeEmptyPage('locale-offer-de', 'tr');

        $html = (string) $this->get('/s/locale-offer-de', [
            'Accept-Language' => 'de-DE,de;q=0.9',
        ])->assertOk()->getContent();

        // The banner, in the offered language, naming it in its own words.
        $this->assertStringContainsString('lang="de"', $html);
        $this->assertStringContainsString('Deutsch', $html);

        // And the switcher carries the link that banner points at, so the two
        // lists cannot drift apart again without this failing.
        $this->assertStringContainsString('href="/de/s/locale-offer-de"', $html);
    }

    public function test_the_switcher_links_each_language_to_the_url_that_serves_it(): void
    {
        /*
         * The user-visible half of the canonical-locale defect: on a page
         * published in Turkish the switcher rendered `href="/s/{slug}"` labelled
         * "English" while that URL serves Turkish, and the English link the page
         * needed (`/en/s/{slug}`) existed nowhere.
         *
         * Asserted over the rendered anchors rather than over the chrome array,
         * because the label and the href are only a promise to each other once
         * they are in one element.
         */
        $this->makeEmptyPage('locale-switcher-tr', 'tr');

        $html = (string) $this->get('/s/locale-switcher-tr')->assertOk()->getContent();

        $this->assertSame(
            [
                'en' => ['/en/s/locale-switcher-tr', 'English'],
                'tr' => ['/s/locale-switcher-tr', 'Türkçe'],
            ],
            $this->switcherLinks($html),
        );

        // The page's own language is the one marked current, and it is the one on
        // the unprefixed URL.
        $this->assertMatchesRegularExpression(
            '/href="\/s\/locale-switcher-tr"\s+hreflang="tr"\s+lang="tr"\s+aria-current="true"/',
            $html,
        );

        // The common shape has not moved: a page with no locale of its own still
        // serves the deployment default on the bare URL.
        $this->makeEmptyPage('locale-switcher-null', null);

        $this->assertSame(
            [
                'en' => ['/s/locale-switcher-null', 'English'],
                'tr' => ['/tr/s/locale-switcher-null', 'Türkçe'],
            ],
            $this->switcherLinks((string) $this->get('/s/locale-switcher-null')->assertOk()->getContent()),
        );
    }

    /**
     * The switcher's anchors as `code => [href, label]`.
     *
     * Matched on the `href`-then-`hreflang`-then-`lang` attribute order the
     * partial writes, which is what keeps the head's `<link rel="alternate">`
     * tags (they carry `hreflang` before `href`) out of the result.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    protected function switcherLinks(string $html): array
    {
        preg_match_all(
            '/href="([^"]+)"\s+hreflang="([^"]+)"\s+lang="[^"]+"[^>]*>([^<]+)<\/a>/s',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        $links = [];

        foreach ($matches as $match) {
            $links[$match[2]] = [$match[1], trim($match[3])];
        }

        ksort($links);

        return $links;
    }

    public function test_every_rung_of_the_banner_ladder_has_copy_in_both_locales(): void
    {
        // A test that only opens a green page sees one of the five labels. All
        // five are reachable here because the mapping is a pure function of the
        // status, and `partial_outage` is reachable ONLY here (see the constant).
        $assembler = new class extends StatusPageAssembler
        {
            public function labelFor(string $status): string
            {
                return $this->overallLabel($status);
            }
        };

        foreach (self::BANNER_LADDER as $status => $turkish) {
            App::setLocale('en');
            $english = $assembler->labelFor($status);

            App::setLocale('tr');
            $this->assertSame(
                $turkish,
                $assembler->labelFor($status),
                "The banner label for [{$status}] must read [{$turkish}] in Turkish.",
            );

            App::setLocale('en');
            $this->assertNotSame(
                $turkish,
                $assembler->labelFor($status),
                "The banner label for [{$status}] resolved to Turkish under the English locale.",
            );
            $this->assertSame($english, $assembler->labelFor($status));
        }

        // Guard against a vacuous pass: the English side is the copy that shipped
        // before this change, so it is pinned literally rather than only compared
        // against the Turkish.
        App::setLocale('en');
        $this->assertSame('All Systems Operational', $assembler->labelFor('operational'));
        $this->assertSame('Degraded Performance', $assembler->labelFor('degraded'));
        $this->assertSame('Partial System Outage', $assembler->labelFor('partial_outage'));
        $this->assertSame('Major System Outage', $assembler->labelFor('major_outage'));
        $this->assertSame('No Components Published', $assembler->labelFor(StatusPageAssembler::STATUS_UNKNOWN));
    }

    public function test_the_cached_read_model_is_keyed_per_locale(): void
    {
        /*
         * This key carried no locale segment for as long as one page served
         * exactly one language. It cannot now: the same page answers on
         * `/s/{slug}` and on `/{locale}/s/{slug}`, and the cached payload is
         * per-language BY CONSTRUCTION rather than by content, because the
         * assembler composes the banner label and every incident title under the
         * render locale before `toArray()` runs. A single key would hand whichever
         * language warmed it to every visitor behind it for the next 60 seconds.
         *
         * Each URL is requested TWICE and the second pass is the one that proves
         * it: that response comes out of the entry the first pass wrote, so a
         * shared key surfaces here as one language answering with the other's
         * body. One request per URL renders fresh and can never see it.
         */
        $this->makePopulatedPage('locale-cache-key', null);

        $english = $this->get('/s/locale-cache-key')->assertOk()->getContent();
        $turkish = $this->get('/tr/s/locale-cache-key')->assertOk()->getContent();

        $cachedEnglish = $this->get('/s/locale-cache-key')->assertOk()->getContent();
        $cachedTurkish = $this->get('/tr/s/locale-cache-key')->assertOk()->getContent();

        foreach (['fresh' => $english, 'cached' => $cachedEnglish] as $pass => $html) {
            $this->assertStringContainsString('Degraded Performance', (string) $html);
            $this->assertStringNotContainsString(
                self::BANNER_LADDER['degraded'],
                (string) $html,
                "The {$pass} English response carries the Turkish banner: the two URLs share one cache entry.",
            );
        }

        foreach (['fresh' => $turkish, 'cached' => $cachedTurkish] as $pass => $html) {
            $this->assertStringContainsString(self::BANNER_LADDER['degraded'], (string) $html);
            $this->assertStringNotContainsString(
                'Degraded Performance',
                (string) $html,
                "The {$pass} Turkish response carries the English banner: the two URLs share one cache entry.",
            );
        }

        // Two URLs, two entries, and nothing under the old locale-free key: an
        // entry no reader can reach is one no bust has any reason to clear.
        $this->assertTrue(Cache::has(ShowStatusPageController::CACHE_KEY_PREFIX.'locale-cache-key:en'));
        $this->assertTrue(Cache::has(ShowStatusPageController::CACHE_KEY_PREFIX.'locale-cache-key:tr'));
        $this->assertFalse(
            Cache::has(ShowStatusPageController::CACHE_KEY_PREFIX.'locale-cache-key'),
            'The locale-free key is still written, so a bust that fans out over the languages would miss it.',
        );
    }

    public function test_the_three_subscribe_result_pages_resolve_their_copy_from_the_catalogue(): void
    {
        /*
         * These three render OUTSIDE the status page's own path
         * (`SubscribeController` returns them directly), so they get the page's
         * locale from that controller rather than from `ShowStatusPageController`.
         * This test asserts only that their copy resolves from the catalogue, by
         * rendering each view under a forced locale; the sibling test below drives
         * the real ROUTES, which is the only thing that can prove the controller
         * applies the page's language rather than the deployment default.
         */
        $page = $this->makePage($this->makeTeam(), 'locale-subscribe-results', null);

        $views = [
            'status.confirmed' => ['page' => $page],
            'status.subscribe-check-inbox' => ['page' => $page],
            'status.unsubscribed' => [],
        ];

        $english = [
            'status.confirmed' => ['Subscription confirmed', 'You are subscribed', 'one-click unsubscribe link'],
            'status.subscribe-check-inbox' => ['Check your inbox', 'a confirmation'],
            'status.unsubscribed' => ['Unsubscribed', 'You are unsubscribed', 'You can subscribe again any time'],
        ];

        $turkish = [
            'status.confirmed' => ['Abonelik onaylandı', 'Aboneliğiniz aktif', 'abonelikten çıkma bağlantısı'],
            'status.subscribe-check-inbox' => ['E-postanızı kontrol edin', 'onay e-postası yolda'],
            'status.unsubscribed' => ['Abonelikten çıkıldı', 'Aboneliğiniz sona erdi', 'yeniden abone olabilirsiniz'],
        ];

        foreach ($views as $view => $data) {
            App::setLocale('en');
            $englishHtml = view($view, $data)->render();

            App::setLocale('tr');
            $turkishHtml = view($view, $data)->render();

            $this->assertStringContainsString('<html lang="en">', $englishHtml);
            $this->assertStringContainsString('<html lang="tr">', $turkishHtml);

            $this->assertEnglishCopyMovedOver($english[$view], $englishHtml, $turkishHtml);

            foreach ($turkish[$view] as $copy) {
                $this->assertStringContainsString($copy, $turkishHtml, "{$view} must read [{$copy}] in Turkish.");
            }
        }
    }

    public function test_the_subscribe_routes_answer_in_the_pages_language(): void
    {
        /*
         * The gap two independent reviews found: every string in the subscribe flow
         * resolved from the catalogue and NONE of it could reach a Turkish reader,
         * because `SubscribeController` renders its three views and sends its mail
         * outside `ShowStatusPageController` and nothing applied the page's locale
         * there. Seventeen Turkish entries were dead copy.
         *
         * Rendering a view under a forced locale cannot catch that, which is why the
         * test above did not: this one drives the real ROUTES and asserts on the
         * response body, so it fails if the controller stops applying the language.
         */
        Mail::fake();

        $page = $this->makePage($this->makeTeam(), 'locale-subscribe-routes', 'tr');

        // 1. The subscribe POST answers with the check-inbox page.
        $this->post(route('status.subscribe', ['slug' => $page->slug]), [
            'email' => 'abone@ornek.com',
        ])
            ->assertOk()
            ->assertSee('E-postanızı kontrol edin')
            ->assertDontSee('Check your inbox')
            ->assertSee('<html lang="tr">', false);

        // 2. The confirmation mail carries the page's language, subject included.
        //    Asserted through the rendered envelope rather than the locale property,
        //    because a `->locale()` that never reaches the render would still pass a
        //    property check.
        Mail::assertSent(StatusPageSubscribeConfirmation::class, function ($mail) use ($page) {
            $mail->locale($page->locale);

            return $mail->envelope()->subject === __(
                'status.emails.confirm.subject',
                ['page' => $page->name],
                'tr',
            );
        });

        // 3. The confirm link, followed from that mail.
        $subscriber = $page->subscribers()->firstOrFail();

        $this->get(route('status.subscribe.confirm', [
            'slug' => $page->slug,
            'token' => $subscriber->confirmed_token,
        ]))
            ->assertOk()
            ->assertSee('Abonelik onaylandı')
            ->assertDontSee('Subscription confirmed');

        // 4. The unsubscribe link, which carries a token and NO page: the language
        //    has to come through the subscriber's own page, and it is read before the
        //    row is deleted.
        $this->get(route('status.unsubscribe', [
            'token' => $subscriber->refresh()->unsubscribe_token,
        ]))
            ->assertOk()
            ->assertSee('Abonelikten çıkıldı')
            ->assertDontSee('Unsubscribed');
    }

    public function test_the_two_subscriber_emails_resolve_their_copy_from_the_catalogue(): void
    {
        // Bodies only, rendered under a forced locale. Both SUBJECTS now resolve
        // from `status.emails.*.subject` and both send paths carry the page's
        // locale, which the route-level test below proves for the confirm mail. The
        // bold page name stays wrapped in both languages, which is why each
        // sentence is a prefix or a suffix rather than one string with a `:page`
        // parameter.
        $emails = [
            'status.emails.confirm' => [
                'pageName' => 'Acme Status',
                'confirmUrl' => 'https://uptizm.test/s/acme/subscribe/confirm/token',
            ],
            'status.emails.maintenance' => [
                'pageName' => 'Acme Status',
                'title' => 'Upgrading the payments database',
                'description' => null,
                'startsAt' => '11 Aug 2026, 21:00 UTC',
                'endsAt' => '11 Aug 2026, 22:00 UTC',
                'componentNames' => ['Checkout API'],
                'unsubscribeUrl' => 'https://uptizm.test/unsubscribe/token',
            ],
        ];

        $english = [
            'status.emails.confirm' => [
                'Confirm your subscription',
                'You asked to receive incident updates for',
                'Confirm your email address to start receiving them:',
                'Confirm subscription',
                'you can ignore this email',
            ],
            'status.emails.maintenance' => [
                'Scheduled maintenance',
                'has planned maintenance coming up.',
                'Affected components:',
                'You are receiving this because you confirmed a subscription to',
                'Unsubscribe',
            ],
        ];

        $turkish = [
            'status.emails.confirm' => [
                'Aboneliğinizi onaylayın',
                'olay güncellemeleri almak istediniz',
                'e-posta adresinizi onaylayın',
                'Aboneliği onayla',
                'yok sayabilirsiniz',
            ],
            'status.emails.maintenance' => [
                'Planlı bakım',
                'planlı bir bakım yaklaşıyor.',
                'Etkilenen bileşenler:',
                'aboneliğinizi onayladığınız için alıyorsunuz',
                'Abonelikten çık',
            ],
        ];

        foreach ($emails as $view => $data) {
            App::setLocale('en');
            $englishHtml = view($view, $data)->render();

            App::setLocale('tr');
            $turkishHtml = view($view, $data)->render();

            $this->assertEnglishCopyMovedOver($english[$view], $englishHtml, $turkishHtml);

            foreach ($turkish[$view] as $copy) {
                $this->assertStringContainsString($copy, $turkishHtml, "{$view} must read [{$copy}] in Turkish.");
            }

            // The page name keeps its emphasis in both languages: it is the one
            // piece of markup the translated sentences are shaped around.
            $this->assertStringContainsString('<strong>Acme Status</strong>', $englishHtml);
            $this->assertStringContainsString('<strong>Acme Status</strong>', $turkishHtml);
        }
    }

    public function test_no_status_view_carries_an_unescaped_echo(): void
    {
        /*
         * `title_params` can carry a monitored response body, and this surface is
         * where it first reaches an anonymous visitor. `{{ }}` escapes, `{!! !!}`
         * does not, so the guard is that the second form appears nowhere in the
         * directory. Asserted over the SOURCE rather than over one rendered page:
         * a rendered page only exercises the branches its fixture reaches.
         *
         * A second check covers the context the first one is blind to: inside a
         * `<script>` block, `{{ }}` is HTML escaping applied in a JavaScript
         * context, which is the wrong escaping (it does not stop a `"` or a `<`
         * from breaking out of a string literal). A switcher or a theme control is
         * exactly where someone later interpolates a locale map into a script, so
         * this guard fails that the moment it appears, not after it ships.
         */
        $paths = $this->statusViewPaths();
        $this->assertNotEmpty($paths, 'No status views were found to check.');

        $offenders = [];

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);

            if (str_contains($source, '{!!')) {
                $offenders[] = Str::afterLast($path, 'views/');
            }

            if ($this->hasEchoInsideScript($source)) {
                $offenders[] = Str::afterLast($path, 'views/').' (echo inside <script>)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A status view uses `{!! !!}`, or a `{{ }}` echo inside a <script> block; '
            .'HTML escaping is never correct there.',
        );
    }

    /**
     * Detects a Blade echo `{{ ... }}` placed inside a `<script>` block.
     *
     * Two forms are deliberately NOT offences: `@{{ ... }}` is Blade's own
     * escaped literal (a front-end framework's braces passed through verbatim),
     * matched by excluding a `{{` immediately preceded by `@`; and
     * `{{-- ... --}}` is a Blade comment, matched by excluding a `{{`
     * immediately followed by `--`. Without both exclusions this would flag the
     * `@{{ }}` or `{{-- --}}` that a later contributor adds legitimately.
     */
    protected function hasEchoInsideScript(string $source): bool
    {
        if (! preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $source, $matches)) {
            return false;
        }

        foreach ($matches[1] as $scriptBody) {
            if (preg_match('/(?<!@)\{\{(?!--).*?\}\}/s', $scriptBody)) {
                return true;
            }
        }

        return false;
    }

    public function test_both_status_catalogues_carry_the_same_keys(): void
    {
        // A key present in one locale and missing in the other renders its own
        // dotted name to a customer. The key SETS are what this compares; whether
        // a Turkish value is still English is something only a human reading the
        // page can catch, which is what this step's QA artifact is for.
        $english = $this->flattenKeys(require lang_path('en/status.php'));
        $turkish = $this->flattenKeys(require lang_path('tr/status.php'));

        $this->assertNotEmpty($english);
        $this->assertSame($english, $turkish);
    }

    /**
     * Asserts each English original renders on the English page and renders
     * NOWHERE on the Turkish one.
     *
     * The first half is what stops the second from passing vacuously: a mistyped
     * entry, or one this fixture never reaches, fails here rather than silently
     * certifying a string nobody rendered.
     *
     * @param  array<int, string>  $copy
     */
    protected function assertEnglishCopyMovedOver(array $copy, string $englishHtml, string $turkishHtml): void
    {
        foreach ($copy as $original) {
            $this->assertStringContainsString(
                $original,
                $englishHtml,
                "[{$original}] is not on the English page, so asserting its absence from the Turkish one proves nothing.",
            );

            $this->assertStringNotContainsString(
                $original,
                $turkishHtml,
                "[{$original}] reached a Turkish page: that string still resolves in English.",
            );
        }
    }

    /**
     * A request from a browser announcing these languages.
     *
     * The header is the whole input the offer is negotiated from, so it is built
     * literally rather than through a helper that might normalise it: quality
     * values and region subtags are exactly what the negotiation reads.
     */
    protected function requestPreferring(string $acceptLanguage): Request
    {
        return Request::create('/s/acme', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);
    }

    /**
     * A request announcing no language at all, which is what Googlebot sends.
     *
     * The header is REMOVED rather than left unset: `Request::create()` seeds a
     * default `Accept-Language: en-us,en;q=0.5`, so a fixture built without one
     * is a visitor asking for English rather than a client asking for nothing,
     * and it would certify the opposite of what this case is about.
     */
    protected function requestAnnouncingNoLanguage(): Request
    {
        $request = Request::create('/s/acme');
        $request->headers->remove('Accept-Language');

        return $request;
    }

    /**
     * Every status view this surface owns, the emails included: the escaping
     * guard applies to them too.
     *
     * @return array<int, string>
     */
    protected function statusViewPaths(): array
    {
        return array_merge(
            glob(resource_path('views/status/*.blade.php')) ?: [],
            glob(resource_path('views/status/partials/*.blade.php')) ?: [],
            glob(resource_path('views/status/emails/*.blade.php')) ?: [],
        );
    }

    /**
     * Flattens a catalogue into a sorted list of dotted keys.
     *
     * @param  array<string, mixed>  $catalogue
     * @return array<int, string>
     */
    protected function flattenKeys(array $catalogue, string $prefix = ''): array
    {
        $keys = [];

        foreach ($catalogue as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        sort($keys);

        return $keys;
    }

    /**
     * A public page carrying one degraded component, two incidents (one active
     * with a public update, one resolved with a published postmortem), an open
     * maintenance window and the subscribe box: every section of the page at once,
     * so one render exercises all of its copy.
     *
     * Every operator-authored string in it is deliberately language-NEUTRAL (a
     * monitor named `Checkout API`, Turkish update text on the Turkish page's twin
     * would differ) so the English-original assertions cannot trip over a fixture
     * word instead of a template one.
     */
    protected function makePopulatedPage(string $slug, ?string $locale): StatusPage
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team, $slug, $locale);

        $monitor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Degraded]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        $this->seedUptime($monitor, CarbonImmutable::now()->format('Y-m-d'), 'degraded', 97.5);

        $active = $this->seedIncident($team, $monitor, IncidentStatus::Investigating);
        IncidentUpdate::query()->create([
            'incident_id' => $active->id,
            'message' => 'Kontroller bir bölgede yavaşlama gösteriyor.',
            'actor' => 'human',
            'status' => IncidentStatus::Investigating,
            'is_public' => true,
            'autonomous' => false,
            'display_at' => CarbonImmutable::now(),
        ]);

        $resolved = $this->seedIncident($team, $monitor, IncidentStatus::Resolved);
        $resolved->forceFill([
            'resolved_at' => CarbonImmutable::now(),
            'postmortem_body' => 'Kaynak havuzu yeniden boyutlandırıldı.',
            'postmortem_published_at' => CarbonImmutable::now(),
        ])->save();

        $window = ScheduledMaintenance::factory()->create([
            'team_id' => $team->id,
            'status_page_id' => $page->id,
            'title' => 'Veritabani yukseltmesi',
            'description' => null,
            'starts_at' => CarbonImmutable::now()->subMinutes(30),
            'ends_at' => CarbonImmutable::now()->addMinutes(30),
        ]);
        $window->monitors()->attach([$monitor->id]);

        return $page;
    }

    /**
     * A public page with nothing published on it, which is the only state the
     * three "nothing here" strings render in.
     */
    protected function makeEmptyPage(string $slug, ?string $locale): StatusPage
    {
        return $this->makePage($this->makeTeam(), $slug, $locale, subscriptions: false);
    }

    protected function makePage(Team $team, string $slug, ?string $locale, bool $subscriptions = true): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'is_public' => true,
            'subscriptions_enabled' => $subscriptions,
            'locale' => $locale,
        ]);
    }

    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Locale Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Locale Team',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeMonitor(Team $team, array $attributes = []): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Checkout API',
            'type' => MonitorType::Http,
            'url' => 'https://secret-internal-host.example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
            ...$attributes,
        ]);
    }

    /**
     * An incident carrying the STRUCTURED title this PR introduced: the English
     * render in `title`, the catalogue key in `title_key`, the display-ready
     * values in `title_params`. Composed rather than hand-written, so the row
     * looks exactly like one the evaluator would open.
     */
    protected function seedIncident(Team $team, Monitor $monitor, IncidentStatus $lifecycle): Incident
    {
        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            ...IncidentTitle::compose(IncidentTitle::MONITOR_DOWN, ['monitor' => $monitor->name]),
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => $lifecycle,
            'ai_owned' => false,
            'started_at' => CarbonImmutable::now(),
        ]);

        $incident->monitors()->attach([
            $monitor->id => [
                'component_status_at_start' => 'degraded',
                'component_status_current' => 'degraded',
            ],
        ]);

        return $incident;
    }

    protected function seedUptime(Monitor $monitor, string $date, string $worst, float $percent): void
    {
        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'date' => $date,
            'uptime_percent' => $percent,
            'total_checks' => 100,
            'failed_checks' => 3,
            'worst_status' => $worst,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->insert($row);
    }
}
