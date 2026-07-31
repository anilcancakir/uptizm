<?php

namespace App\Http\Controllers\Marketing;

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
            'document' => $this->document->render(self::PAGE, app()->getLocale()),
        ]);
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
