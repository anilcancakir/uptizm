<?php

namespace App\Http\Controllers\Marketing;

use App\Services\Services\FeedFetcher;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use App\Support\Proxy\ProxyRegions;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Contracts\View\View;

/**
 * What UptizmBot is, what it fetches, and how to get excluded from it.
 *
 * WHY THIS PAGE HAS TO EXIST
 *
 * The service-catalog ingester introduces itself with
 * `config('uptizm.bot_user_agent')`, which carries this page's URL as its contact
 * point. That is the whole basis on which fetching somebody else's status feed is
 * defensible: an operator who does not want us reading their feed must be able to
 * find out who we are and tell us to stop, instead of having to block an anonymous
 * client. The page was missing while the User-Agent already advertised it, so every
 * request this catalog made pointed at a 404. A contact URL that does not resolve is
 * worse than none, because it looks like a courtesy and is not one.
 *
 * EVERY FIGURE IS DERIVED, NONE IS TYPED
 *
 * Same rule as the rest of this surface ({@see ShowFaqController},
 * {@see ShowPrivacyController}): the polling floor comes from the constant the
 * fetcher actually enforces, and the User-Agent string comes from the config the
 * fetcher actually sends. A page that promised a cadence the code did not honour
 * would be a false statement to the operator reading it in their access log, which
 * is the one reader this page has.
 *
 * The prose is Markdown under `resources/legal/bot.<locale>.md` and is authored per
 * language, so only digits and the literal UA string route through a placeholder.
 *
 * Registered through the same `$documents` loop as the other content pages in
 * `routes/marketing.php`, which is what gives it the apex URL, the 301 from the
 * prefixed default locale, and the `whereIn` locale constraint. It is in
 * `LegalPagesTest::PAGES` for the same reason, so the cookie-free property, the
 * canonical, the hreflang set and the no-surviving-placeholder guard all apply to
 * it without a line of new test code.
 */
class ShowBotController
{
    /**
     * One string doing three jobs: the route path, the Markdown filename under
     * `resources/legal/<page>.<locale>.md`, and the path `ChromeData` composes this
     * page's canonical and hreflang set from. The same constant in all three places
     * is what stops a page declaring itself canonical at an address it is not served
     * on.
     */
    private const PAGE = 'bot';

    public function __construct(
        protected LegalDocument $document,
    ) {}

    public function __invoke(): View
    {
        return view('marketing.content-page', [
            ...(new ChromeData(
                path: self::PAGE,
                summary: $this->summary(),
            ))->toArray(),
            'title' => __('About UptizmBot'),
            // `app()->getLocale()` rather than the route parameter: the apex form
            // carries no `{locale}` at all, so reading the route would render
            // nothing for `/bot`.
            'document' => $this->document->render(self::PAGE, app()->getLocale(), $this->replacements()),
        ]);
    }

    /**
     * Every fact the page states, mapped from its bracketed placeholder.
     *
     * An unmapped placeholder survives into the output verbatim rather than
     * vanishing, and `LegalPagesTest` fails the build on exactly that, so a
     * forgotten entry here is a red test rather than a sentence with a hole in it.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        return [
            // The floor the fetcher enforces against the newest recorded fetch, not
            // a number somebody remembered. If the constant moves, this sentence
            // moves with it.
            '[[bot.min_interval_seconds]]' => (string) FeedFetcher::MIN_INTERVAL_SECONDS,
            // The exact string that leaves the machine, so an operator can match
            // what this page says against what their access log shows.
            '[[bot.user_agent]]' => (string) config('uptizm.bot_user_agent'),
            '[[bot.contact_email]]' => (string) config('legal.contact_email'),
            /*
             * The availability check: the LARGER of the two channels, and the one this
             * page originally denied the existence of.
             *
             * Read from `uptizm.catalog_probe_interval_sec`, the same key the seeder
             * builds these monitors from, and NOT from the monitor rows. Querying them
             * here is the obvious idea and it is wrong: these content pages are served
             * without a database (`LegalPagesTest` runs them with no connection), so a
             * query 500s the page. The cost of the config indirection is real and worth
             * stating: an operator who retunes one catalog monitor in the panel
             * desynchronises this figure, so the copy speaks of the configured cadence
             * rather than promising each monitor's own.
             *
             * The daily total is spelled out because a per-region interval understates
             * what an operator sees: five regions on a one-minute cadence is not one
             * request a minute.
             */
            // The whole noun phrase, not a bare number: the English sentence used to
            // carry "regions" as literal prose and published "1 regions" the moment a
            // deployment had one probeable region, which production promptly did.
            '[[bot.probe_regions]]' => trans_choice('{1} :count region|[2,*] :count regions', $this->probeRegionCount()),
            '[[bot.probe_egress]]' => $this->egressDisclosure(),
            '[[bot.probe_interval_seconds]]' => (string) $this->probeIntervalSeconds(),
            '[[bot.probe_daily_requests]]' => number_format(
                $this->probeRegionCount() * intdiv(86400, $this->probeIntervalSeconds()),
            ),
        ];
    }

    /**
     * Where this deployment's availability checks actually leave from.
     *
     * Derived rather than written, for the same reason every figure on this page is:
     * the sentence is a promise an operator will hold us to from their access log, so
     * it has to describe the egress THIS deploy uses. Three shapes are possible and
     * they are not interchangeable to the reader, because they imply different
     * answers to "can I block it": a rotating third-party pool cannot usefully be
     * blocked one address at a time, and our own server can.
     *
     * A deployment with no proxy provider wired probes its one direct region from
     * this server, and saying "third-party exit addresses in a rotating pool" there
     * would be simply false. The earlier revision of this page said exactly that
     * unconditionally.
     */
    protected function egressDisclosure(): string
    {
        $pooled = ProxyRegions::sourced() !== [];
        $direct = ProxyRegions::directRegion() !== null;

        return match (true) {
            $pooled && $direct => __('Some of those checks leave from third-party exit addresses in a rotating pool, and some leave directly from one of our own servers, so blocking a single address will not keep all of them out.'),
            $pooled => __('The availability check leaves from third-party exit addresses in a rotating pool rather than from one address of ours, so blocking a single exit will not keep those requests out for long.'),
            $direct => __('The availability check leaves directly from one of our own servers, so blocking that one address stops it.'),
            // Neither shape is configured, so the check is not running at all. Saying
            // it leaves from anywhere would describe traffic this deployment does not
            // send, and an operator finding none of it in their log would rightly
            // stop believing the rest of this page.
            default => __('No availability check is leaving this deployment at present, so the only client you should see is the status-feed read below.'),
        };
    }

    /**
     * The catalog probe cadence, from the config the seeder builds its monitors
     * from, floored at 1 so a misconfigured 0 cannot divide by zero on a public page.
     */
    protected function probeIntervalSeconds(): int
    {
        return max(1, (int) config('uptizm.catalog_probe_interval_sec'));
    }

    /**
     * The number of regions a catalog monitor actually carries.
     *
     * {@see ProxyRegions} is the shared answer, and sharing it is the point:
     * {@see ServiceCatalogSeeder::catalogRegions()} stamps a monitor's `regions`
     * column from the same list, so this page cannot publish a count the seeded
     * monitors do not carry. It reads config rather than the database because
     * this page is rendered with no connection available (see the class docblock
     * above), so querying the monitor rows here would 500 it.
     *
     * Counting `MonitorRegion::cases()` instead (as this used to) published the
     * number of regions the relay KNOWS about rather than the number a catalog
     * monitor can actually reach, which overstated both this figure and the
     * daily-request total below it once the seeder stopped claiming every region
     * by default.
     */
    protected function probeRegionCount(): int
    {
        return ProxyRegions::probeableCount();
    }

    /**
     * This page's own meta description.
     *
     * Per page and never the landing page's sentence: a document reusing the home
     * page's summary tells a crawler and a link preview that the two are the same
     * document, and `ChromeTest` fails the build on it.
     */
    protected function summary(): string
    {
        return __('The crawler Uptizm uses to read public status feeds, what it requests, and how to have it stop.');
    }
}
