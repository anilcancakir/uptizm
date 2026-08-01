<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use Illuminate\Support\Arr;
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

    /**
     * The identity slots that are empty until launch and therefore render an honest absence.
     * Each one is personal data about the operator, so every value below is obviously fake.
     *
     * @var array<string, string>
     */
    protected const UNFILLED_UNTIL_LAUNCH = [
        'legal.operator' => 'Example Operator',
        'legal.address' => '1 Test Street, Example',
        'legal.phone' => '+00 000 000 00 00',
        'legal.kep_address' => 'example@hs00.kep.test',
        'legal.registry_number' => '0000000000000000',
        'legal.tax_number' => '0000000000',
    ];

    public function test_the_identity_block_is_read_from_config_and_not_typed_into_the_prose(): void
    {
        /*
         * THE load-bearing test of this step. Every value in the identity block is moved to
         * something no drafter would type, and the page has to move with it: that is the
         * difference between an identity block and a paragraph somebody transcribed.
         *
         * The negative half used to name the operator's own address and national identity
         * number, so the guard against a transcribed literal was itself a copy of the personal
         * data it guarded. It is now a SECOND pass with different fake values: a page that
         * prints one config value and keeps the previous one beside it fails on the second
         * pass, and no personal value has to appear in this file to prove it.
         */
        config([
            ...self::UNFILLED_UNTIL_LAUNCH,
            'legal.trade_name' => 'Trading Name',
            'legal.contact_email' => 'someone@example.test',
            'legal.rights_email' => 'rights@example.test',
        ]);

        foreach ($this->supported() as $locale) {
            $response = $this->get($this->pathFor($locale))->assertOk();

            foreach ([...array_values(self::UNFILLED_UNTIL_LAUNCH), 'Trading Name', 'someone@example.test', 'rights@example.test'] as $value) {
                $response->assertSee($value);
            }

            // The inbox this deployment really carries: a business address, and the one value
            // in the block that has to come from config rather than from the prose today.
            $response->assertDontSee('info@kodizm.com');
        }

        config([
            'legal.operator' => 'Different Trader',
            'legal.address' => '2 Other Street, Elsewhere',
        ]);

        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))
                ->assertSee('Different Trader')
                ->assertSee('2 Other Street, Elsewhere')
                ->assertDontSee('Example Operator')
                ->assertDontSee('1 Test Street, Example');
        }
    }

    public function test_an_unfilled_identity_slot_renders_an_honest_absence_and_never_a_blank(): void
    {
        /*
         * The whole point of taking the personal values out of the repository: with the slots
         * empty the block must still read as a block. A blank after a label reads as a
         * rendering fault, a dash reads as "not applicable", and an invented value is a false
         * statement about who the reader is contracting with, so the page says the detail is
         * not published yet and names the channel that does work.
         *
         * The phrase is asserted on the ENGLISH page only, matching the tax-label test below:
         * it is a `__()` string and `lang/tr.json` is the orchestrator's file, so pinning the
         * Turkish wording here would pin a key this change does not own. The Turkish page is
         * covered by the language-independent half, which is the assertion that matters most
         * anyway: no identity row may render with its value missing.
         */
        config([
            'legal.operator' => null,
            'legal.address' => null,
            'legal.phone' => null,
            'legal.kep_address' => null,
            'legal.registry_number' => null,
            'legal.tax_number' => null,
            'legal.tax_number_kind' => null,
        ]);

        $this->get('/terms')->assertOk()->assertSee('Not published yet');

        foreach ($this->supported() as $locale) {
            $html = $this->get($this->pathFor($locale))->assertOk()->getContent();

            // `- **Label:** [[placeholder]]` renders as `<li><strong>Label:</strong> value`,
            // so an empty replacement leaves the list item ending right after the label.
            foreach (['</strong></li>', '</strong> </li>'] as $dangling) {
                $this->assertStringNotContainsString(
                    $dangling,
                    $html,
                    "The Terms page in \"{$locale}\" renders an identity row whose value is missing entirely.",
                );
            }
        }
    }

    public function test_an_absent_identity_value_is_never_interpolated_into_a_sentence(): void
    {
        /*
         * The structural rule that keeps the honest absence coherent. "Not published yet" is a
         * value for a labelled row and nonsense in the middle of a clause ("send notice to Not
         * published yet"), so every placeholder that can resolve to it may appear ONLY as a
         * list-row value. The inboxes, the trade name and the authority are the placeholders
         * that DO appear mid-sentence, and they are the ones this deployment fills today.
         */
        $rowOnly = ['operator', 'address', 'phone', 'kep_address', 'registry_number', 'tax_number'];

        foreach ($this->supported() as $locale) {
            foreach (preg_split('/\R/u', $this->source($locale)) ?: [] as $number => $line) {
                foreach ($rowOnly as $key) {
                    if (! str_contains($line, "[[legal.{$key}]]")) {
                        continue;
                    }

                    $this->assertMatchesRegularExpression(
                        '/^- \*\*/u',
                        $line,
                        "The Terms source in \"{$locale}\" line ".($number + 1)." puts [[legal.{$key}]] outside the ".
                        'identity block, where an unfilled slot would read as a sentence with "Not published yet" in it.',
                    );
                }
            }
        }
    }

    public function test_the_tax_number_is_labelled_for_what_it_actually_is(): void
    {
        /*
         * config/legal.php holds `tax_number_kind` precisely so a page does not assume every
         * operator publishes a VAT number: for a Turkish esnaf the published tax number IS
         * the national identity number, and mislabelling it as a company tax id would be a
         * false statement about what the operator just published. The kind is empty until
         * launch, so the generic label is what the block carries today.
         *
         * Only the English page is asserted for the `vat` label: the non-`tc` labels are
         * translator strings this step does not own (lang/tr.json is written by the
         * orchestrator), and `Tax number` plus `VAT number` already have Turkish keys, which
         * is why the default label is asserted in both languages.
         */
        $this->assertNull(config('legal.tax_number_kind'), 'A kind is configured, so this test no longer proves anything.');

        $this->get('/terms')->assertSee('Tax number');
        $this->get('/tr/terms')->assertSee('Vergi numarası');

        config(['legal.tax_number_kind' => 'tc']);

        foreach ($this->supported() as $locale) {
            $this->get($this->pathFor($locale))
                ->assertSee('TC Kimlik No')
                ->assertDontSee('Vergi numarası');
        }

        config(['legal.tax_number_kind' => 'vat']);

        $this->get('/terms')
            ->assertSee('VAT number')
            ->assertDontSee('TC Kimlik No');
    }

    public function test_the_identity_block_carries_the_registry_and_kep_rows_the_regulation_asks_for(): void
    {
        /*
         * Madde 5(1)(a) and (b) of the 29.12.2022 e-commerce regulation: the disclosure is
         * "eksiksiz" and it names a KEP adresi and a registry identifier. The pages carried
         * neither until this change, and the reason both rows exist while both values are
         * empty is that a row a reader can see is unfilled is the only honest way to publish
         * a disclosure that is not ready.
         */
        config([
            'legal.kep_address' => 'example@hs00.kep.test',
            'legal.registry_number' => '0000000000000000',
        ]);

        $this->get('/terms')
            ->assertSee('KEP address')
            ->assertSee('MERSİS number')
            ->assertSee('example@hs00.kep.test')
            ->assertSee('0000000000000000');

        $this->get('/tr/terms')
            ->assertSee('KEP adresi')
            ->assertSee('MERSİS numarası')
            ->assertSee('example@hs00.kep.test')
            ->assertSee('0000000000000000');
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

    public function test_the_withdrawal_section_deducts_nothing_for_the_ai_a_plan_already_entitles(): void
    {
        /*
         * The operator asked for the opposite clause: deduct the AI already consumed from a
         * refund. It is not available and writing it would be worse than not writing it, which
         * is why this test pins the refusal rather than the request.
         *
         * CRD Art. 14(4)(a): without the express request AND the acknowledgement, collected at
         * checkout, the consumer bears NO cost for what was supplied in the withdrawal period.
         * Commission Guidance s.5.6.1: a clause in the terms does not collect either of them,
         * it takes a positive action such as an unticked box. Art. 25 then makes the clause
         * simply not binding, so drafting it creates exposure instead of protection. And
         * C-641/19 PE Digital ruling 1: even with the preconditions the amount is pro rata
         * temporis unless the item is supplied in full "and separately, for a price which must
         * be paid separately". Turkish law is worse for the clause, not better: Mesafeli
         * Sözleşmeler Yönetmeliği has no proportionate deduction at all and Madde 14(1) says
         * the consumer owes no masraf, so a deduction reads as a forbidden charge.
         *
         * Sourced in research/librarian-identity-and-ai-refunds.md section 2.
         */
        $this->assertNull(
            $this->firstPricedAiKey(),
            'A tier now prices AI separately, which is the one fact that would change this section: '.
            'a separately priced, separately consented, instantly performed analysis falls under CRD '.
            'Art. 16(a) and MSY Madde 15(1)(ğ) per unit. Revisit the clause with the checkout change.',
        );

        $trials = (string) Arr::get($this->freeTier(), 'limits.ai_analysis_trials');

        $this->get('/terms')
            ->assertSee('no per-analysis charge')
            ->assertSee('nothing is deducted from the refund')
            ->assertSee($trials.' AI monitor setups');

        $this->get('/tr/terms')
            ->assertSee('analiz başına bir ücret yok')
            ->assertSee('hiçbir kesinti yapılmaz')
            ->assertSee($trials.' AI monitör kurulumu');
    }

    public function test_no_language_promises_a_deduction_or_a_lost_withdrawal_right(): void
    {
        /*
         * The two sentences this document must never carry: that consumed usage comes off a
         * refund, and that the withdrawal right is extinguished by performance beginning. The
         * second is available only to a trader that collected the Art. 8(8) request and
         * acknowledgement, and `BillingController::checkout()` validates a plan and two URLs
         * and nothing else, so both would be false statements about this product.
         */
        $forbidden = [
            'en' => ['deducted from your refund', 'deduct the', 'you lose the right', 'forfeit'],
            'tr' => ['kesinti yapılır', 'mahsup edilir', 'hakkınızı yitirirsiniz'],
        ];

        foreach ($this->supported() as $locale) {
            $source = $this->source($locale);

            foreach ($forbidden[$locale] ?? [] as $claim) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $claim,
                    $source,
                    "The Terms source in \"{$locale}\" carries \"{$claim}\", a clause this checkout cannot support.",
                );
            }
        }
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
     * The free tier from the plan catalog, which is where the AI figure the withdrawal
     * section quotes comes from.
     *
     * @return array<string, mixed>
     */
    protected function freeTier(): array
    {
        return (array) Arr::first(
            (array) config('plans.tiers', []),
            static fn (array $tier): bool => ($tier['id'] ?? null) === 'free',
        );
    }

    /**
     * The first plan-catalog key that would price AI per use, or null while none does.
     *
     * The premise the withdrawal clause rests on, expressed as a check rather than a comment.
     * AI is an ENTITLEMENT today: `limits.ai` is a capability level (inbox < analysis < auto <
     * custom) and `limits.ai_analysis_trials` meters the free tier, so an analysis has no
     * separate price and cannot be "supplied in full for a price which must be paid
     * separately" within C-641/19 PE Digital. A key that prices one is therefore the exact
     * event that reopens the clause, and it fails this test by name instead of leaving the
     * page quietly wrong.
     */
    protected function firstPricedAiKey(): ?string
    {
        foreach ((array) config('plans.tiers', []) as $tier) {
            foreach (array_keys(Arr::dot((array) $tier)) as $key) {
                $matched = preg_match('/(ai[_.][a-z_.]*(price|cost|rate|fee)|(price|cost|rate|fee)[a-z_.]*[_.]ai)/i', (string) $key);

                if ($matched === 1) {
                    return (string) $key;
                }
            }
        }

        return null;
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
