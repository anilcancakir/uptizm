<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use Tests\TestCase;

/**
 * The Terms of Service document.
 *
 * `LegalPagesTest` already pins the plumbing for all four documents (routing, canonical,
 * hreflang, titles, footer links, the no-unreplaced-placeholder rule, the table-of-contents
 * anchor walk), so nothing here repeats it. What this file pins is the CONTENT, and only
 * the parts of it that are load-bearing rather than editorial:
 *
 *   - the operator identity is DERIVED from config/legal.php, proved by moving a config
 *     value and watching the page move with it. A typed address passes every other test in
 *     this suite and fails only this one;
 *   - the section list matches the researched EU order (research/librarian-eu-consumer-law.md
 *     section 7), counted structurally so a dropped section fails the build;
 *   - no availability figure exists anywhere, in either language. DCD Art. 7 folds a public
 *     statement about the service into the conformity bar, so a published percentage would
 *     become a contractual target for a one-person operation running on other people's
 *     infrastructure;
 *   - the four clause types that are VOID if written the obvious way are written the other
 *     way: the liability carve-outs survive, a Service change carries a reason plus notice
 *     plus a free exit, the jurisdiction clause leaves the consumer their home law, and the
 *     free plan is inside the contract rather than outside it.
 *
 * Wording is asserted only where the wording IS the legal requirement. Everywhere else the
 * assertion is structural, because copy moves and a test that pins prose stops the copy from
 * improving.
 */
class TermsPageTest extends TestCase
{
    /**
     * The document's own section count, from the researched order in
     * research/librarian-eu-consumer-law.md section 7: identity, definitions, eligibility
     * and acceptance, description of the Service, account, pricing and renewal, withdrawal,
     * cancellation and termination, changes to the Service, availability, acceptable use,
     * intellectual property, data protection, liability, changes to the terms, governing law,
     * miscellaneous.
     */
    protected const SECTIONS = 17;

    public function test_the_identity_block_is_read_from_config_and_not_typed_into_the_prose(): void
    {
        /*
         * THE load-bearing test of this step. Every value in the identity block is moved to
         * something no drafter would type, and the page has to move with it: that is the
         * difference between an identity block and a paragraph somebody transcribed. The
         * negative assertion is the other half, because a page that prints the config value
         * AND keeps a stale literal beside it satisfies the positive one.
         */
        config([
            'legal.operator' => 'Someone Else (Trading Name)',
            'legal.trade_name' => 'Trading Name',
            'legal.address' => 'A Street 1, Somewhere',
            'legal.phone' => '+99 000 000 00 00',
            'legal.tax_number' => '00000000000',
            'legal.contact_email' => 'someone@example.test',
            'legal.rights_email' => 'rights@example.test',
        ]);

        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))
                ->assertOk()
                ->assertSee('Someone Else (Trading Name)')
                ->assertSee('A Street 1, Somewhere')
                ->assertSee('+99 000 000 00 00')
                ->assertSee('00000000000')
                ->assertSee('someone@example.test')
                ->assertSee('rights@example.test')
                // The supplied address and the supplied inbox, which the Markdown must not
                // carry as literals. `Konak` appears in no other legal key.
                ->assertDontSee('Konak')
                ->assertDontSee('info@kodizm.com')
                ->assertDontSee('44938660202');
        }
    }

    public function test_the_tax_number_is_labelled_for_what_it_actually_is(): void
    {
        /*
         * config/legal.php holds `tax_number_kind` precisely so a page does not assume every
         * operator publishes a VAT number: for a Turkish sole proprietorship the number IS
         * the national identity number, and mislabelling it as a company tax id would be a
         * false statement about what the operator just published.
         *
         * Only the English page is asserted after the kind changes: the non-`tc` labels are
         * translator strings this step does not own (lang/tr.json is written by the
         * orchestrator), so pinning them on the Turkish page would pin a missing key.
         */
        $this->assertSame('tc', config('legal.tax_number_kind'), 'This deployment publishes a TC kimlik number.');

        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))->assertSee('TC Kimlik No');
        }

        config(['legal.tax_number_kind' => 'vat']);

        $this->get($this->pathFor(config('app.default_locale')))
            ->assertSee('VAT number')
            ->assertDontSee('TC Kimlik No');
    }

    public function test_the_researched_section_order_is_present_in_both_languages(): void
    {
        /*
         * Counted rather than listed by heading text, so the assertion holds in a language
         * this test cannot read and does not have to be edited when a heading is reworded.
         * The rendered page carries one extra `<h2 id=` for the table of contents itself
         * (content-page.blade.php's "On this page"), hence the minus one.
         */
        foreach ($this->supported() as $locale) {
            $html = $this->get($this->pathFor($locale))->getContent();

            $this->assertSame(
                self::SECTIONS,
                substr_count($html, '<h2 id="') - 1,
                "The Terms page in \"{$locale}\" does not carry the ".self::SECTIONS
                .' sections of the researched EU order.',
            );
        }
    }

    public function test_no_availability_figure_is_published(): void
    {
        /*
         * The product IS uptime, so a number about our own uptime is the single most
         * expensive sentence this page could contain: DCD Art. 7 makes a public statement
         * about the service part of what conformity is measured against, with no SLA clause
         * needed. The percent SIGN is asserted against the Markdown source rather than the
         * rendered page because the rendered page carries CSS classes that can legitimately
         * contain one.
         */
        foreach ($this->supported() as $locale) {
            $source = $this->source($locale);

            $this->assertStringNotContainsString('%', $source, "The Terms source in \"{$locale}\" publishes a percentage.");
            $this->assertDoesNotMatchRegularExpression(
                '/\b\d+[.,]\d+\b/u',
                $source,
                "The Terms source in \"{$locale}\" publishes a decimal figure, which on this page can only be an availability claim.",
            );

            $content = $this->get($this->pathFor($locale))->getContent();

            foreach (['99.9', '99,9', 'guarantee', 'garanti'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $content,
                    "The Terms page in \"{$locale}\" contains \"{$forbidden}\".",
                );
            }
        }
    }

    public function test_the_page_says_affirmatively_that_no_availability_is_promised(): void
    {
        // Absence is not the same as denial: a page that simply never mentions availability
        // leaves the reader to assume, and the researched position is to state the negative
        // out loud (research/librarian-eu-consumer-law.md section 3).
        $this->get('/terms')
            ->assertSee('No availability percentage')
            ->assertSee('no service level agreement');

        $this->get('/tr/terms')
            ->assertSee('erişilebilirlik yüzdesi')
            ->assertSee('taahhüt edilmez');
    }

    public function test_liability_keeps_the_carve_outs_that_cannot_be_excluded(): void
    {
        // Unfair Terms Annex (a): a clause excluding liability for death or personal injury
        // is void outright, and intent plus gross negligence cannot be excluded under the
        // national civil codes the consumer's own law brings with it. A cap that swallows
        // them takes the whole cap down with it, so the carve-outs are asserted positively.
        $this->get('/terms')
            ->assertSee('death or personal injury')
            ->assertSee('gross negligence');

        $this->get('/tr/terms')
            ->assertSee('ölüm veya bedensel zarar')
            ->assertSee('ağır ihmal');
    }

    public function test_the_jurisdiction_clause_leaves_the_consumer_their_home_law(): void
    {
        // Rome I Art. 6 and Brussels Ia Arts. 17/19 bind any trader directing activity at EU
        // consumers regardless of establishment, so "our law, our courts" is unenforceable
        // against a consumer and has to carry the proviso instead of pretending otherwise.
        $this->get('/terms')
            ->assertSee('habitual residence')
            ->assertSee('without prejudice');

        $this->get('/tr/terms')
            ->assertSee('mutad meskeninin')
            ->assertSee('tüketicinin');
    }

    public function test_a_service_change_needs_a_reason_advance_notice_and_a_free_exit(): void
    {
        // DCD Art. 19 is more prescriptive than the Annex: a change to the Service is
        // permitted only for a valid contractual reason, at no extra cost, with reasonable
        // advance notice on a durable medium, and with a free termination right where the
        // change harms access more than minimally.
        $this->get('/terms')
            ->assertSee('valid reason')
            ->assertSee('advance')
            ->assertSee('free of charge');

        $this->get('/tr/terms')
            ->assertSee('geçerli bir sebep')
            ->assertSee('önceden')
            ->assertSee('ücretsiz');
    }

    public function test_the_free_plan_is_inside_the_contract(): void
    {
        // DCD Art. 3(1) covers a contract where the consumer provides personal data instead
        // of a price, so the conformity duties and the remedies attach to the free plan too.
        // Excluding it would be the natural drafting mistake and it would be wrong.
        $this->get('/terms')->assertSee('free plan');
        $this->get('/tr/terms')->assertSee('ücretsiz plan');
    }

    public function test_the_withdrawal_section_describes_the_route_that_exists(): void
    {
        /*
         * CRD Art. 11a has required an online withdrawal function since 19 June 2026 and
         * this product has none (accepted risk, deferred to its own plan). So the section
         * describes the route that works, says the button does not exist, and does not
         * invent a control the reader would go looking for.
         */
        config(['legal.contact_email' => 'someone@example.test']);

        $this->get('/terms')
            ->assertSee('14 days')
            ->assertSee('someone@example.test')
            ->assertSee('no button');

        $this->get('/tr/terms')
            ->assertSee('14 gün')
            ->assertSee('someone@example.test')
            ->assertSee('düğme');
    }

    public function test_the_acceptance_paragraph_matches_the_sign_up_screen_the_client_renders(): void
    {
        /*
         * This paragraph used to publish the opposite: "the sign-up screen in the application
         * does not link to this page yet". It was true when it was written, and the sentence
         * after it concedes that a term the reader had no opportunity to read is not enforced
         * against them, so the page was waiving the enforceability of its own terms on a premise
         * that has since stopped holding.
         *
         * The client is the other half of this claim and it lives in this repository (the
         * Flutter app is the repo root, `backend/` is a subdirectory), so the two halves are
         * pinned together rather than left to drift: `lib/app/support/web_links.dart` fills
         * magic_starter's `legal` block with locale-aware URLs and `lib/config/magic_starter.dart`
         * hands that block to the config, which is what makes `MagicStarterConfig.hasLegalLinks()`
         * true and renders the two links above the create-account button. Null either key again
         * and the register screen hides the whole legal line, at which point this paragraph has
         * to go back to describing a footer link.
         */
        $resolver = base_path('../lib/app/support/web_links.dart');
        $clientConfig = base_path('../lib/config/magic_starter.dart');

        $this->assertFileExists($resolver, 'The client web-link resolver moved; this paragraph names what it renders.');
        $this->assertFileExists($clientConfig, 'The client magic_starter config moved; this paragraph names what it renders.');

        $this->assertStringContainsString(
            "'terms_url': terms",
            (string) file_get_contents($resolver),
            'The client no longer resolves a Terms URL, so the sign-up screen shows no link to this page.',
        );
        $this->assertStringContainsString(
            'WebLinks.legalConfig',
            (string) file_get_contents($clientConfig),
            'The client no longer hands the legal URLs to magic_starter, so the sign-up screen hides the legal line.',
        );

        // The stale claim, in the words each language used to carry it in.
        $stale = [
            'en' => 'does not link',
            'tr' => 'bağlantı vermiyor',
        ];

        foreach ($this->supported() as $locale) {
            $this->assertStringNotContainsString(
                $stale[$locale],
                $this->source($locale),
                "The Terms source in \"{$locale}\" still says the sign-up screen carries no link to this page.",
            );
        }

        $this->get('/terms')
            ->assertSee('sign-up screen')
            ->assertSee('Privacy Policy');

        $this->get('/tr/terms')
            ->assertSee('kayıt ekranı')
            ->assertSee('Gizlilik Politikası');
    }

    public function test_the_page_says_who_wrote_it_and_that_it_is_not_legal_advice(): void
    {
        // The plan's own framing: this document is factually correct about the system and
        // structured to the disclosure checklist, which is not the same as reviewed. Saying
        // so is cheaper than being caught implying otherwise.
        $this->get('/terms')
            ->assertSee('not a lawyer')
            ->assertSee('legal advice');

        $this->get('/tr/terms')
            ->assertSee('avukat değil')
            ->assertSee('hukuki tavsiye');
    }

    public function test_no_effective_date_is_invented_in_either_language(): void
    {
        /*
         * `legal.effective_date` is null on purpose and the shell renders that absence, so a
         * year typed into the prose would be the one place a date could still be invented.
         * Directives are therefore named in words on this page rather than by number, which
         * also keeps it readable for the consumer it is written for.
         */
        $this->assertNull(config('legal.effective_date'), 'A date is configured, so this test no longer proves anything.');

        foreach ($this->supported() as $locale) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(19|20)\d{2}\b/u',
                $this->source($locale),
                "The Terms source in \"{$locale}\" contains a year, which this document has no business asserting.",
            );
        }
    }

    public function test_the_turkish_document_is_written_in_turkish(): void
    {
        /*
         * A word-for-word rendering of English legalese reads as machine output, and that
         * exact failure already happened once in this work. The register terms below are the
         * ones a Turkish reader expects to find; the English headings are asserted absent so
         * a half-translated file cannot pass.
         */
        $response = $this->get('/tr/terms');

        foreach (['Cayma hakkı', 'Sorumluluğun sınırlandırılması', 'Uyuşmazlıkların çözümü'] as $heading) {
            $response->assertSee($heading);
        }

        foreach (['Right of withdrawal', 'Governing law', 'Acceptable use', 'Intellectual property'] as $english) {
            $response->assertDontSee($english);
        }
    }

    public function test_the_region_count_comes_from_the_enum(): void
    {
        /*
         * The one product claim this document makes with a number in it, so it is derived
         * from the enum the write requests validate against, exactly as the landing page's
         * is: the page cannot advertise a region we do not probe from.
         *
         * The asserted phrase is the DOCUMENT's, not the count on its own: the shared
         * footer already prints "from 5 regions" on every marketing page, so a bare
         * `assertSee('5 regions')` passed against the empty skeleton and proved nothing.
         */
        $count = count(MonitorRegion::cases());

        $this->get('/terms')->assertSee('up to '.$count.' regions');
        $this->get('/tr/terms')->assertSee('en fazla '.$count.' bölgeden');
    }

    /**
     * The languages the whole product speaks, from the same config the routes read.
     *
     * @return list<string>
     */
    protected function supported(): array
    {
        return array_values((array) config('magic-starter.supported_locales', []));
    }

    /**
     * The path the Terms document is served on in one language. The default language lives
     * on the apex, so it takes no prefix.
     */
    protected function pathFor(string $locale): string
    {
        return $locale === config('app.default_locale')
            ? '/terms'
            : '/'.$locale.'/terms';
    }

    /**
     * The Markdown source for one language, read from disk.
     *
     * Read rather than rendered for the assertions where the RENDERED page would answer a
     * different question: the shell contributes CSS classes and chrome copy that can contain
     * a percent sign or a number legitimately, and neither is this document's doing.
     */
    protected function source(string $locale): string
    {
        return (string) file_get_contents(resource_path("legal/terms.{$locale}.md"));
    }
}
