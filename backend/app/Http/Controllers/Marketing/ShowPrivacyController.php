<?php

namespace App\Http\Controllers\Marketing;

use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;

/**
 * The Privacy notice, in whichever language its URL asked for.
 *
 * The document itself is Markdown under `resources/legal/`, so the prose lives in version
 * control and in one file per language rather than inside a Blade template. This
 * controller only says which document, in which language, wearing which chrome.
 *
 * `$sections` is deliberately left at ChromeData's empty default. It means "the in-page
 * anchors the header and footer nav may offer", and those are the LANDING page's; this
 * page's own anchors are its headings and they belong in the table of contents beside the
 * text. Handing over the landing list would put nav links on this page pointing at ids it
 * never emits, which `ChromeTest`'s dangling-anchor guard fails the build on.
 */
class ShowPrivacyController
{
    /**
     * One string doing three jobs: the route path, the Markdown filename under
     * `resources/legal/<page>.<locale>.md`, and the path `ChromeData` composes this page's
     * own canonical and hreflang set from. Keeping them the same constant is what stops a
     * page declaring itself canonical at an address it is not served on.
     */
    private const PAGE = 'privacy';

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
            'title' => __('Privacy Policy'),
            // `app()->getLocale()`, which `SetMarketingLocale` has already set from the
            // path, rather than the route parameter: the apex form carries no `{locale}`
            // parameter at all, so reading the route would render the default language's
            // document for the prefixed URLs and nothing for `/privacy`.
            'document' => $this->document->render(self::PAGE, app()->getLocale()),
        ]);
    }

    /**
     * This page's own meta description.
     *
     * Per page and never the landing page's sentence: it is what a crawler and a link
     * preview show, so a document reusing the home page's summary tells both that the two
     * are the same document.
     */
    protected function summary(): string
    {
        return __('What Uptizm stores about you, why it stores it, and how long it keeps it.');
    }
}
