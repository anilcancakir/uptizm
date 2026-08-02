<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;

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
 * contracting party is, and it appears in two languages. A transcribed copy of it in
 * eight Markdown files would drift the day any of those facts changed.
 *
 * WHY HALF OF THAT BLOCK HAS NO ROW AT ALL RIGHT NOW
 *
 * The operator's legal name, registered address, telephone, KEP address and registry
 * identifier are not in this repository at all: they are personal data, the Service has
 * not launched, and the registered company details arrive with the launch. Those slots are
 * therefore empty, and {@see self::identityRow()} omits the row entirely rather than
 * rendering a blank, a dash, a "not published yet" notice or an invented value. A blank
 * after a label reads as a rendering fault, a notice reads as an announcement the operator
 * did not ask this page to make, and an invented value would be a false statement about who
 * the reader is contracting with. The block grows one row at a time as `config/legal.php`
 * is filled in, never sooner.
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
     * shows up as `[[legal.address_row]]` on the page instead of as a legal sentence with a
     * hole in it, and `LegalPagesTest` fails on exactly that.
     *
     * Three of these are not identity fields and are here for the same reason the landing
     * page derives its claims: the region count is the one number this document asserts
     * about the product, the currency is the one it asserts about the price, and the free
     * tier's AI meter is the one the withdrawal section quotes. All three come from the
     * source that governs the behaviour, so the page cannot advertise a region we do not
     * probe from, a currency we do not charge in, or an AI allowance the plan gate does not
     * grant.
     *
     * The `_row` placeholders resolve to a whole `<li>` element, or to the empty string that
     * drops the row from the page entirely; `resources/legal/terms.*.md` wraps them in a raw
     * `<ul>` HTML block for that reason (see {@see self::row()}). The six identity slots that
     * can be unfilled route through {@see self::identityRow()}; the trade name, the two
     * inboxes and the authority never route through it because each carries a real value
     * today, and the two inboxes also interpolate mid-sentence elsewhere in the document
     * through their own bare placeholder, which must never resolve to anything but a value.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        return [
            '[[legal.operator_row]]' => $this->identityRow(__('Operator'), 'operator'),
            '[[legal.trade_name_row]]' => $this->row(__('Trading name'), (string) config('legal.trade_name')),
            '[[legal.address_row]]' => $this->identityRow(__('Registered address'), 'address'),
            '[[legal.contact_email_row]]' => $this->row(__('Email'), (string) config('legal.contact_email')),
            '[[legal.rights_email_row]]' => $this->row(__('Data protection requests'), (string) config('legal.rights_email')),
            '[[legal.phone_row]]' => $this->identityRow(__('Telephone'), 'phone'),
            '[[legal.kep_address_row]]' => $this->identityRow(__('KEP address'), 'kep_address'),
            '[[legal.registry_number_row]]' => $this->identityRow(__('MERSİS number'), 'registry_number'),
            '[[legal.tax_number_row]]' => $this->identityRow($this->taxNumberLabel(), 'tax_number'),
            '[[legal.trade_name]]' => (string) config('legal.trade_name'),
            '[[legal.contact_email]]' => (string) config('legal.contact_email'),
            '[[legal.rights_email]]' => (string) config('legal.rights_email'),
            '[[legal.authority]]' => (string) config('legal.authority'),
            '[[service.region_count]]' => (string) count(MonitorRegion::cases()),
            '[[service.currency]]' => $this->currency(),
            '[[service.free_ai_setups]]' => $this->freeAiSetups(),
        ];
    }

    /**
     * One `<li>` fragment for the identity block: a label and a value.
     *
     * Built as raw HTML rather than Markdown, because {@see LegalDocument::applyReplacements()}
     * runs `strtr` AFTER the Markdown-to-HTML pass: a `**bold**` marker inserted here would
     * ship as two literal asterisks instead of a `<strong>` tag. `resources/legal/terms.*.md`
     * wraps the whole identity list in a raw `<ul>` HTML block for exactly that reason
     * (CommonMark passes a block opened by a block-level tag through untouched), and every
     * row inside it is either one of these fragments or the empty string {@see self::identityRow()}
     * returns for an unset slot.
     */
    protected function row(string $label, string $value): string
    {
        return sprintf('<li><strong>%s:</strong> %s</li>', e($label), e($value));
    }

    /**
     * One identity row for a slot that can be unset, or the empty string that drops the row
     * from the page entirely.
     *
     * The six slots this covers are personal data the repository does not hold (see the
     * class docblock and `config/legal.php`), so "unfilled" is the normal state until the
     * Service launches. This used to render a "Not published yet" notice in the row's place;
     * the operator asked for the row itself to disappear instead, so the block prints only
     * what is genuinely known today.
     */
    protected function identityRow(string $label, string $key): string
    {
        $value = config("legal.{$key}");

        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return $this->row($label, $value);
    }

    /**
     * What to call the number in `legal.tax_number`.
     *
     * Derived from `legal.tax_number_kind` rather than typed into the Markdown, because
     * the two facts are independent: for a Turkish esnaf the published tax number IS the
     * national identity number (`tc`), and labelling it "VAT number" would be a false
     * statement about the number the page just published. Both the kind and the number are
     * empty until launch, so the generic label is what the row carries today; an operator
     * who turns out to be a tacir publishes `legal.registry_number` instead and this row
     * stays unfilled.
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
     * How many AI monitor setups the free tier grants before the plan wall.
     *
     * The one figure the withdrawal section quotes about AI, read from the same catalog the
     * billing gate meters against (`PlanGate::aiAnalysisTrialLimit()`, named in backticks
     * rather than as a `{@see}` so this marketing controller does not import a billing
     * service to hold a comment).
     * It is there to support a statement of what an AI analysis IS today: an entitlement
     * inside the plan, metered on the free tier and granted outright above it, with no
     * separate price. That is the fact the section rests on, so a catalog that ever prices
     * an analysis separately changes what the section may say.
     */
    protected function freeAiSetups(): string
    {
        $free = Arr::first(
            (array) config('plans.tiers', []),
            static fn (array $tier): bool => ($tier['id'] ?? null) === 'free',
        );

        return (string) Arr::get($free ?? [], 'limits.ai_analysis_trials');
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
