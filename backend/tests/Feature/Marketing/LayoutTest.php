<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use App\Support\Marketing\ChromeData;
use Illuminate\Testing\TestView;
use Illuminate\View\ViewException;
use Tests\TestCase;
use Throwable;

/**
 * The shared marketing shell, and the content page built on it.
 *
 * The landing page's `<head>` and chrome were extracted into `marketing/layout.blade.php`
 * so the legal, support and pricing pages get the same header, footer and hreflang set
 * without copying them. That extraction has one real hazard, and it is what this file
 * exists for: the chrome dereferences variables UNGUARDED (`count($regions)`,
 * `$platformClaim`, `$summary`, `$canonicalUrl`), so a page that renders the layout
 * without supplying one of them does not degrade, it throws.
 *
 * Three successive drafts of this work enumerated that variable list by hand and each
 * one missed a different name. So the contract is not a list anywhere: it is
 * `ChromeData`, and the binding test below renders a content page from NOTHING but
 * `ChromeData` plus a document. That check keeps working as the chrome grows, which is
 * the property a typed list does not have.
 */
class LayoutTest extends TestCase
{
    public function test_a_content_page_renders_from_nothing_but_chrome_data_and_a_document(): void
    {
        /*
         * THE BINDING TEST. Nothing but ChromeData plus the document: no hand-picked
         * extras, because every extra here would be a variable the real pages could
         * forget. If the chrome grows a dereference that ChromeData does not supply,
         * this render throws and the build stops.
         *
         * It also asserts the chrome arrived with VALUES rather than merely without an
         * error, so an empty shell cannot pass: the footer's derived region count, the
         * language links, the mobile disclosure, the document body and its heading id.
         */
        $view = $this->view('marketing.content-page', [
            ...(new ChromeData(path: 'privacy', summary: 'What Uptizm collects, and what it does not.'))->toArray(),
            'title' => 'Privacy',
            'document' => $this->document(),
        ]);

        $view->assertSee('<title>Privacy | '.config('app.name').'</title>', escape: false)
            ->assertSee(count(MonitorRegion::cases()).' regions')
            ->assertSee('Türkçe')
            ->assertSee('English')
            ->assertSee('id="mobile-menu"', escape: false)
            ->assertSee('href="#content"', escape: false)
            ->assertSee('What we collect')
            ->assertSee('id="what-we-collect"', escape: false)
            ->assertSee('href="#what-we-collect"', escape: false)
            ->assertSee('An address and a team name.');
    }

    public function test_the_undefined_variable_guard_is_not_vacuous(): void
    {
        /*
         * The positive control for the test above. If a missing chrome variable rendered
         * to an empty string instead of raising, the binding test would pass against a
         * layout supplied with nothing at all and would pin exactly nothing.
         *
         * `ViewException` and not the message text: that class means the failure happened
         * while RENDERING (rather than while resolving a view that does not exist, which
         * is an InvalidArgumentException and would make this control vacuous in a second
         * way). The message itself is not asserted because it depends on the error
         * handler in play: under the framework's warning-to-exception handler the first
         * missing variable raises "Undefined variable", and without it the render survives
         * as far as the footer's `count(null)` TypeError instead. Both are the same defect.
         */
        try {
            $this->view('marketing.content-page', [
                'title' => 'Privacy',
                'document' => $this->document(),
            ]);
        } catch (Throwable $e) {
            $this->assertInstanceOf(ViewException::class, $e);

            return;
        }

        $this->fail('The layout rendered without its chrome data, so the contract test proves nothing.');
    }

    public function test_no_anchor_on_a_content_page_points_at_an_id_the_page_does_not_emit(): void
    {
        /*
         * ChromeTest walks the landing page this way. A content page needs its own walk
         * because it grows a second source of in-page links: the table of contents, whose
         * hrefs come from the TOC slugs while the ids come from the rendered Markdown. The
         * two are produced by separate passes in LegalDocument, so they can drift.
         */
        $html = (string) $this->view('marketing.content-page', [
            ...(new ChromeData(path: 'privacy', summary: 'What Uptizm collects.'))->toArray(),
            'title' => 'Privacy',
            'document' => $this->document(),
        ]);

        preg_match_all('/href="#([a-zA-Z0-9_-]+)"/', $html, $matches);

        $this->assertNotSame([], $matches[1], 'The page emitted no in-page anchor at all, so this walk checked nothing.');

        foreach (array_unique($matches[1]) as $anchor) {
            $this->assertStringContainsString(
                'id="'.$anchor.'"',
                $html,
                "The page links to #{$anchor} but nothing on it carries that id.",
            );
        }
    }

    public function test_a_content_page_carries_none_of_the_landing_page_anchors(): void
    {
        /*
         * `$sections` means "the anchors on THIS page", so it defaults to empty and a
         * content page must never be handed the landing page's list. Handing it over
         * would put four header links and four footer links on a page that emits none of
         * those ids, which is precisely the dangling-anchor defect the walk above catches.
         */
        $chrome = new ChromeData(path: 'privacy', summary: 'What Uptizm collects.');

        $this->assertSame([], $chrome->toArray()['sections']);

        $view = $this->view('marketing.content-page', [
            ...$chrome->toArray(),
            'title' => 'Privacy',
            'document' => $this->document(),
        ]);

        foreach (['how-it-decides', 'beyond-status-codes', 'status-pages', 'ai-boundary'] as $landingAnchor) {
            $view->assertDontSee('href="#'.$landingAnchor.'"', escape: false);
        }
    }

    public function test_the_canonical_the_alternates_and_the_description_belong_to_the_page(): void
    {
        /*
         * The extraction's other trap: the head used to compute its canonical and its
         * hreflang set from the landing route by name, so a legal page rendering through
         * the same head would have declared itself a copy of the home page and told every
         * crawler the two were the same document.
         *
         * `x-default` is the DEFAULT LOCALE's copy of this page, not the site root.
         */
        $view = $this->view('marketing.content-page', [
            ...(new ChromeData(path: 'privacy', summary: 'What Uptizm collects, and what it does not.'))->toArray(),
            'title' => 'Privacy',
            'document' => $this->document(),
        ]);

        $view->assertSee('rel="canonical" href="'.url('/privacy').'"', escape: false)
            ->assertSee('hreflang="en" href="'.url('/privacy').'"', escape: false)
            ->assertSee('hreflang="tr" href="'.url('/tr/privacy').'"', escape: false)
            ->assertSee('hreflang="x-default" href="'.url('/privacy').'"', escape: false)
            ->assertSee('name="description" content="What Uptizm collects, and what it does not."', escape: false)
            // The language switcher moves to the same page in the other language, not
            // home. Losing your place is what makes a switcher on a long document useless.
            ->assertSee('href="/tr/privacy"', escape: false)
            // The brand mark still goes home, in the language being read.
            ->assertSee('href="/"', escape: false);
    }

    public function test_the_effective_date_is_the_configured_one_or_an_honest_absence(): void
    {
        /*
         * `legal.effective_date` is null on this deployment and config/legal.php says in
         * terms that the catalog does not invent one. So the line has to state the absence:
         * a blank date on a legal document reads as a rendering bug, and a guessed one is a
         * false statement about when the terms took effect.
         */
        config(['legal.effective_date' => null]);

        $this->contentPage()
            ->assertSee('No effective date has been published for this document yet.')
            ->assertDontSee('Effective from');

        config(['legal.effective_date' => '1 March 2026']);

        $this->contentPage()
            ->assertSee('Effective from 1 March 2026')
            ->assertDontSee('No effective date has been published');
    }

    /**
     * A content page rendered from the standard chrome plus the fixture document.
     */
    protected function contentPage(): TestView
    {
        return $this->view('marketing.content-page', [
            ...(new ChromeData(path: 'privacy', summary: 'What Uptizm collects.'))->toArray(),
            'title' => 'Privacy',
            'document' => $this->document(),
        ]);
    }

    /**
     * A rendered document in `LegalDocument::render()`'s shape.
     *
     * Built here rather than rendered from `resources/legal/`, which does not exist yet:
     * the Markdown arrives in a later step and this shell must be under test before it.
     * The ids on the headings are the ones LegalDocument's heading_permalink config
     * writes (bare slug on the heading element, no prefix, no glyph), so the TOC hrefs
     * below resolve exactly as they will against real content.
     *
     * @return array{html: string, toc: array<int, array{level: int, text: string, slug: string}>}
     */
    protected function document(): array
    {
        return [
            'html' => '<h2 id="what-we-collect">What we collect</h2>'
                .'<p>An address and a team name.</p>'
                .'<h3 id="from-your-monitors">From your monitors</h3>'
                .'<p>The URL you asked us to check.</p>',
            'toc' => [
                ['level' => 2, 'text' => 'What we collect', 'slug' => 'what-we-collect'],
                ['level' => 3, 'text' => 'From your monitors', 'slug' => 'from-your-monitors'],
            ],
        ];
    }
}
