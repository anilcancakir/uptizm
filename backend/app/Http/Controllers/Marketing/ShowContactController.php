<?php

namespace App\Http\Controllers\Marketing;

use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;

/**
 * The Contact page, in whichever language its URL asked for.
 *
 * A page today, not a form: it renders the contact document and the operator's address.
 * The form itself is deliberately absent until it can be built sessionless and gated on
 * whether this deployment can actually send mail, because a form that silently posts into
 * `MAIL_MAILER=log` is worse than an address a visitor can copy.
 *
 * `$sections` stays at ChromeData's empty default, as on every document page.
 */
class ShowContactController
{
    /**
     * The route path, the Markdown filename and the path `ChromeData` builds this page's
     * canonical and hreflang set from, held as one constant so they cannot drift apart.
     */
    private const PAGE = 'contact';

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
            'title' => __('Contact'),
            // The locale `SetMarketingLocale` set from the path, not the route parameter:
            // the apex form carries no `{locale}` parameter to read.
            'document' => $this->document->render(self::PAGE, app()->getLocale(), $this->replacements()),
        ]);
    }

    /**
     * The values interpolated into the Markdown at render time.
     *
     * The contact address is the one fact on this page that must never be typed into the
     * prose: it appears in two languages, it is the address a regulator and a data subject
     * both use, and `config/legal.php` is where a change to it belongs. `LegalDocument`
     * applies these AFTER its cache read, so a config change reaches the page without the
     * Markdown file having to be re-saved.
     *
     * An unmapped placeholder survives into the output verbatim rather than vanishing, so
     * a forgotten entry here shows up as `[[legal.contact_email]]` on the page instead of
     * as a sentence with a hole in it. `LegalPagesTest` fails on exactly that.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        return [
            '[[legal.contact_email]]' => (string) config('legal.contact_email'),
        ];
    }

    /**
     * This page's own meta description, never the landing page's: a crawler and a link
     * preview both read it, and two pages sharing one sentence claim to be one document.
     */
    protected function summary(): string
    {
        // "The operator" and not "our team": one person runs this service, and the
        // identity block on the Terms page says so.
        return __('How to reach the operator who runs Uptizm.');
    }
}
