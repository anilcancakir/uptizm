<?php

namespace App\Services\Services;

use App\Http\Controllers\Marketing\ShowServiceStatusController;
use App\Models\Service;
use App\Support\Marketing\ChromeData;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;

/**
 * Builds the three sitemap documents this site publishes.
 *
 * An INDEX at `/sitemap.xml` naming exactly two segments, and one `<urlset>` per
 * segment:
 *
 *   - `sitemap-marketing.xml`, the landing page and the long-form documents;
 *   - `sitemap-services.xml`, the catalog hub plus every published service.
 *
 * They are separate documents because an index and a url set are different
 * schemas and mixing them produces invalid XML, and separate SEGMENTS because
 * that is what makes an indexing problem diagnosable per template in Search
 * Console instead of as one undifferentiated list.
 *
 * ## Every URL is composed through ChromeData, and that is the point
 *
 * A sitemap that disagreed with the pages' own hreflang would be worse than no
 * sitemap: it would declare alternates the documents do not claim. So the `loc`
 * and every `xhtml:link` alternate come out of the same
 * {@see ChromeData::toArray()} the page's `<head>` is rendered from, including
 * its `x-default`. `tests/Feature/Marketing/SitemapTest.php` asserts the
 * agreement by EXTRACTING the alternates from the rendered page and from the
 * sitemap entry and comparing the two sets, rather than checking each against a
 * hardcoded list, which would pass while both were wrong.
 *
 * ## `lastmod` is emitted only where a trustworthy stamp exists
 *
 * A service page's `lastmod` is `services.content_changed_at`, which the feed
 * ingester moves ONLY when the normalized reading actually changed
 * ({@see FeedFetcher}). Never `updated_at`, never the last poll, never the last
 * snapshot row: a status page polled every minute is the textbook way to make a
 * `lastmod` untrustworthy, and Google discounts an untrustworthy one sitewide.
 *
 * For the same reason nothing else here carries one. The marketing documents have
 * no substantive-change stamp in the database, and the obvious substitute, the
 * Markdown file's mtime, is the DEPLOY time in a fresh checkout, so it would
 * announce every page as edited on every release. The hub's content is derived
 * from the rows below it and has no stamp of its own either. An absent `lastmod`
 * is a legitimate document; a wrong one poisons the signal for the whole site.
 *
 * No `changefreq` and no `priority`: Google ignores both.
 */
class SitemapBuilder
{
    /**
     * The index, and the two segments it names. Public because
     * `routes/marketing.php` registers a route per document and
     * `public/robots.txt` points at the index by absolute URL; one literal each,
     * in one place.
     */
    public const string INDEX_PATH = 'sitemap.xml';

    public const string MARKETING_PATH = 'sitemap-marketing.xml';

    public const string SERVICES_PATH = 'sitemap-services.xml';

    /**
     * The XML namespaces both schemas require. `xhtml` is what carries the
     * per-locale alternates.
     */
    protected const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    protected const string XHTML_NS = 'http://www.w3.org/1999/xhtml';

    /**
     * The marketing pages, as the locale-free paths `ChromeData` composes each
     * language's URL from. `''` is the landing page.
     *
     * Typed here because the routes themselves are a typed list in
     * `routes/marketing.php` (there is no config or enum behind them), and it has
     * to stay in step with that file: a sitemap entry with no page behind it is a
     * crawl error handed to Google on purpose. `SitemapTest` requests every URL
     * this list produces and fails on anything that is not a 200, which is the
     * guard rather than this comment.
     */
    protected const array MARKETING_PATHS = [
        '',
        'privacy',
        'terms',
        'contact',
        'faq',
        // The crawler's own contact page. Listed because the feed ingester's
        // User-Agent points every provider's operator at it, so it is a page we
        // actively want findable rather than an internal note.
        'bot',
    ];

    /**
     * The index document: two children, and no `<url>` element anywhere in it.
     */
    public function index(): string
    {
        $document = $this->document();

        $index = $document->createElementNS(self::SITEMAP_NS, 'sitemapindex');
        $document->appendChild($index);

        foreach ([self::MARKETING_PATH, self::SERVICES_PATH] as $segment) {
            $child = $document->createElementNS(self::SITEMAP_NS, 'sitemap');
            $child->appendChild($document->createElementNS(self::SITEMAP_NS, 'loc', url($segment)));
            $index->appendChild($child);
        }

        return (string) $document->saveXML();
    }

    /**
     * The marketing segment: the landing page and the long-form documents, each
     * with every language as an alternate.
     */
    public function marketing(): string
    {
        return $this->urlset(array_map(
            fn (string $path): array => $this->entry($path, null),
            self::MARKETING_PATHS,
        ));
    }

    /**
     * The services segment: the hub, then every PUBLISHED service whose terms
     * were reviewed, in the order the hub lists them.
     *
     * The predicate is deliberately identical to the hub's own
     * (`app/Http/Controllers/Marketing/ShowServiceIndexController.php`), because
     * a service the hub links to and this omits (or the reverse) is a crawl error
     * either way. A draft, unpublished or terms-unreviewed row appears in
     * neither, and its page answers 404.
     */
    public function services(): string
    {
        $entries = [
            $this->entry(ShowServiceStatusController::PATH, null),
        ];

        $services = Service::query()
            ->where('is_published', true)
            ->whereNotNull('terms_reviewed_at')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get([
                'slug',
                'content_changed_at',
            ]);

        foreach ($services as $service) {
            $entries[] = $this->entry(
                ShowServiceStatusController::PATH.'/'.$service->slug,
                $service->content_changed_at,
            );
        }

        return $this->urlset($entries);
    }

    /**
     * One entry: the default language's URL as the `loc`, and every language plus
     * `x-default` as an `xhtml:link` alternate, exactly as the page's own `<head>`
     * declares them.
     *
     * @return array{loc: string, alternates: list<array{hreflang: string, href: string}>, lastmod: string|null}
     */
    protected function entry(string $path, ?CarbonInterface $lastmod): array
    {
        $chrome = (new ChromeData(path: $path, summary: ''))->toArray();

        $alternates = array_map(
            static fn (array $link): array => [
                'hreflang' => $link['code'],
                'href' => $link['url'],
            ],
            $chrome['localeLinks'],
        );

        // The same fallback the page emits, pointing at this document in the
        // default language rather than at the site root.
        $alternates[] = [
            'hreflang' => 'x-default',
            'href' => $chrome['defaultLocaleUrl'],
        ];

        return [
            // The default language's URL, which is the one the page in that
            // language names as its own canonical.
            'loc' => $chrome['defaultLocaleUrl'],
            'alternates' => $alternates,
            'lastmod' => $lastmod?->toAtomString(),
        ];
    }

    /**
     * Render a `<urlset>` from entries.
     *
     * @param  list<array{loc: string, alternates: list<array{hreflang: string, href: string}>, lastmod: string|null}>  $entries
     */
    protected function urlset(array $entries): string
    {
        $document = $this->document();

        $urlset = $document->createElementNS(self::SITEMAP_NS, 'urlset');
        // Declared on the root, which is where a reader of a multilingual sitemap
        // looks for it. `createElementNS` on each `xhtml:link` below re-declares the
        // same prefix on the element as well, because a DOM namespace declaration
        // added as an attribute is not a namespace node libxml will reconcile
        // against. Verbose, and correct: repeating an identical declaration is
        // well-formed, while binding the prefix by hand and hoping the ancestor
        // declaration covers it is what produces an unparseable document.
        $urlset->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xhtml', self::XHTML_NS);
        $document->appendChild($urlset);

        foreach ($entries as $entry) {
            $urlset->appendChild($this->url($document, $entry));
        }

        return (string) $document->saveXML();
    }

    /**
     * One `<url>` element with its alternates.
     *
     * @param  array{loc: string, alternates: list<array{hreflang: string, href: string}>, lastmod: string|null}  $entry
     */
    protected function url(DOMDocument $document, array $entry): DOMElement
    {
        $url = $document->createElementNS(self::SITEMAP_NS, 'url');
        $url->appendChild($document->createElementNS(self::SITEMAP_NS, 'loc', $entry['loc']));

        if ($entry['lastmod'] !== null) {
            $url->appendChild($document->createElementNS(self::SITEMAP_NS, 'lastmod', $entry['lastmod']));
        }

        foreach ($entry['alternates'] as $alternate) {
            $link = $document->createElementNS(self::XHTML_NS, 'xhtml:link');
            $link->setAttribute('rel', 'alternate');
            $link->setAttribute('hreflang', $alternate['hreflang']);
            $link->setAttribute('href', $alternate['href']);
            $url->appendChild($link);
        }

        return $url;
    }

    /**
     * An empty UTF-8 document.
     *
     * Built through DOM rather than concatenated as a string on purpose: DOM
     * escapes every value it is handed, and these values include URLs carrying
     * `&` from a query string and service names from the database. A
     * hand-concatenated sitemap is one unescaped ampersand away from being
     * unparseable, and a malformed sitemap fails silently in Search Console days
     * later.
     */
    protected function document(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        return $document;
    }
}
