<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;

/**
 * The Terms of Service, in whichever language its URL asked for.
 *
 * Same shape as its three siblings: the prose is Markdown under `resources/legal/`, this
 * controller names the document, the language and the chrome. `$sections` stays at
 * ChromeData's empty default, because it means "the in-page anchors the site nav may
 * offer" and this page's own headings belong in its table of contents instead.
 *
 * The one thing this controller does that its siblings do not is supply the operator
 * identity block. Every field in it comes from `config/legal.php` through a bracketed
 * placeholder, never from a literal in the Markdown, because that block is the
 * e-Commerce Art. 5 disclosure: it is read by a regulator as a statement of who the
 * contracting party is, it appears in two languages, and the tax number in it is the
 * operator's national identity number. A transcribed copy of it in eight Markdown files
 * would drift the day any of those facts changed.
 */
class ShowTermsController
{
    /**
     * The route path, the Markdown filename and the path `ChromeData` builds this page's
     * canonical and hreflang set from, held as one constant so they cannot drift apart.
     */
    private const PAGE = 'terms';

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
            'title' => __('Terms of Service'),
            // The locale `SetMarketingLocale` set from the path, not the route parameter:
            // the apex form carries no `{locale}` parameter to read.
            'document' => $this->document->render(self::PAGE, app()->getLocale(), $this->replacements()),
        ]);
    }

    /**
     * The values interpolated into the Markdown at render time.
     *
     * `LegalDocument` applies these AFTER its cache read, so a config change reaches the
     * page without the Markdown file having to be re-saved. An unmapped placeholder
     * survives into the output verbatim rather than vanishing, so a forgotten entry here
     * shows up as `[[legal.address]]` on the page instead of as a legal sentence with a
     * hole in it, and `LegalPagesTest` fails on exactly that.
     *
     * Two of these are not identity fields and are here for the same reason the landing
     * page derives its claims: the region count is the one number this document asserts
     * about the product, and the currency is the one it asserts about the price. Both come
     * from the source that governs the behaviour, so the page cannot advertise a region we
     * do not probe from or a currency we do not charge in.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        return [
            '[[legal.operator]]' => (string) config('legal.operator'),
            '[[legal.trade_name]]' => (string) config('legal.trade_name'),
            '[[legal.address]]' => (string) config('legal.address'),
            '[[legal.phone]]' => (string) config('legal.phone'),
            '[[legal.tax_number]]' => (string) config('legal.tax_number'),
            '[[legal.tax_number_label]]' => $this->taxNumberLabel(),
            '[[legal.contact_email]]' => (string) config('legal.contact_email'),
            '[[legal.rights_email]]' => (string) config('legal.rights_email'),
            '[[legal.authority]]' => (string) config('legal.authority'),
            '[[service.region_count]]' => (string) count(MonitorRegion::cases()),
            '[[service.currency]]' => $this->currency(),
        ];
    }

    /**
     * What to call the number in `legal.tax_number`.
     *
     * Derived from `legal.tax_number_kind` rather than typed into the Markdown, because
     * the two facts are independent: for this operator, a Turkish sole proprietorship, the
     * tax number IS the national identity number (`tc`), and labelling it "VAT number"
     * would be a false statement about the number the page just published. A future
     * operator with a real company VAT id changes one config key and both languages
     * follow.
     *
     * `tc` returns the Turkish term untranslated on purpose: "TC Kimlik No" is the name of
     * the thing in both languages, and routing it through the translator would ask for a
     * key whose Turkish value is the English one.
     */
    protected function taxNumberLabel(): string
    {
        return match ((string) config('legal.tax_number_kind')) {
            'tc' => 'TC Kimlik No',
            'vat' => __('VAT number'),
            default => __('Tax number'),
        };
    }

    /**
     * The currency the plan catalog prices its tiers in, upper-cased for display.
     *
     * Read from `config/plans.php`, the same catalog the billing screen and the
     * `/billing/plans` endpoint serve, so the Terms cannot name a currency the product
     * does not charge in. Every tier carries the key; the distinct set is joined rather
     * than the first one taken, so a catalog that ever prices tiers in two currencies
     * makes the page say both instead of quietly naming one.
     */
    protected function currency(): string
    {
        $currencies = array_unique(array_filter(array_map(
            static fn (array $tier): string => strtoupper((string) ($tier['currency'] ?? '')),
            (array) config('plans.tiers', []),
        )));

        return implode(' / ', $currencies);
    }

    /**
     * This page's own meta description, never the landing page's: a crawler and a link
     * preview both read it, and two pages sharing one sentence claim to be one document.
     */
    protected function summary(): string
    {
        return __('The terms this service is provided under, and who is behind it.');
    }
}
