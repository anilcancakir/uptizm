<?php

namespace App\Http\Controllers\Marketing;

use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;

/**
 * The FAQ, in whichever language its URL asked for.
 *
 * Questions and answers live in the Markdown under `resources/legal/faq.<locale>.md` and
 * nowhere else. There is no separate FAQ view on purpose: every marketing document renders
 * through the shared content page, so a second surface would be a second source of truth
 * for the same answers and the two would drift.
 *
 * `$sections` stays at ChromeData's empty default, as on every document page.
 */
class ShowFaqController
{
    /**
     * The route path, the Markdown filename and the path `ChromeData` builds this page's
     * canonical and hreflang set from, held as one constant so they cannot drift apart.
     */
    private const PAGE = 'faq';

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
            'title' => __('Frequently Asked Questions'),
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
        return __('Straight answers about what Uptizm checks, what it costs, and what it cannot do.');
    }
}
