<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use App\Services\Ai\IncidentAnalysisPayload;
use Tests\TestCase;

/**
 * The Privacy notice.
 *
 * `LegalPagesTest` already pins the plumbing for all four documents (routing, canonical,
 * hreflang, titles, footer links, the no-unreplaced-placeholder rule, the table-of-contents
 * anchor walk), so nothing here repeats it. What this file pins is the CONTENT, and only the
 * parts of it that are load-bearing rather than editorial:
 *
 *   - every NUMBER and every named third party on the page is DERIVED, proved by moving the
 *     config or the gate and watching the page move with it. A typed "90 days" passes every
 *     other test in this suite and fails only that one, and a privacy notice whose retention
 *     figure has drifted from the database is a false statement about personal data;
 *   - the two roles are both named. Template notices claim controller for everything, which
 *     for this product would send a data subject to the wrong party;
 *   - the three absences (no EU representative, no data protection officer, no certification)
 *     are stated and never quietly filled in with a name;
 *   - the two disclosures a template would miss are present: monitor credentials leave for the
 *     edge worker inside the signed spec even though the probe engine ignores them, and a
 *     bounded excerpt of the customer's own response body reaches the AI provider;
 *   - the cookie section is DERIVED, not asserted. It was written when this site set no cookies
 *     at all, which is unusual enough to be worth saying, and it stops being true the moment an
 *     analytics container is configured. So the page counts them from configuration and the
 *     count is what flips;
 *   - and the whole thing stays under the word budget, because a notice nobody finishes reading
 *     discloses nothing (GPEN 2024: 89% of reviewed notices failed on length alone).
 *
 * Wording is asserted only where the wording IS the disclosure requirement. Everywhere else the
 * assertion is structural, because copy moves and a test that pins prose stops the copy from
 * improving.
 */
class PrivacyPageTest extends TestCase
{
    /**
     * The length ceiling, from `research/librarian-gdpr-disclosure.md` section 6: a notice past
     * roughly this size is opaque in practice, which regulators name as a failure mode in
     * itself rather than a style preference.
     */
    protected const WORD_BUDGET = 3000;

    public function test_both_roles_are_named_and_the_data_is_split_between_them(): void
    {
        /*
         * THE structural finding of the research (EDPB Guidelines 07/2020): this product is
         * controller for its own account and billing data and PROCESSOR for whatever a
         * customer configures, where the customer is the controller and the duty is Art. 28
         * assistance. A notice that claims controller for everything tells the subscriber on
         * somebody else's status page to bring their request to the wrong party.
         */
        $this->get('/privacy')
            ->assertSee('the operator is the controller')
            ->assertSee('the customer is the controller')
            ->assertSee('a processor');

        $this->get('/tr/privacy')
            ->assertSee('veri sorumlusu')
            ->assertSee('veri işleyen');
    }

    public function test_the_retention_figures_are_read_from_config_and_not_typed_into_the_prose(): void
    {
        /*
         * The load-bearing derivation test. `TIMESCALE_RAW_RETENTION_DAYS` is an env value and
         * a deployment is free to move it; a page carrying a transcribed 90 would then be
         * telling every data subject the wrong retention period, in two languages, with
         * nothing failing.
         */
        config([
            'timescale.retention.raw_days' => 37,
            'timescale.retention.hourly_days' => 111,
            'timescale.retention.daily_days' => 222,
        ]);

        $this->get('/privacy')
            ->assertSee('37 days')
            ->assertSee('111 days')
            ->assertSee('222 days')
            ->assertDontSee('90 days');

        $this->get('/tr/privacy')
            ->assertSee('37 gün')
            ->assertSee('111 gün')
            ->assertSee('222 gün')
            ->assertDontSee('90 gün');
    }

    public function test_the_default_retention_window_is_the_configured_one(): void
    {
        // The other half of the test above: it proves the page FOLLOWS config, this proves the
        // config it is following is the one the database is actually running.
        $days = (string) config('timescale.retention.raw_days');

        $this->get('/privacy')->assertSee($days.' days');
        $this->get('/tr/privacy')->assertSee($days.' gün');
    }

    public function test_the_region_count_and_the_region_names_come_from_the_enum(): void
    {
        /*
         * Asserted with the document's own phrase and not the bare count: the shared footer
         * already prints "from 5 regions" on every marketing page, so `assertSee('5 regions')`
         * passed against the empty skeleton and proved nothing.
         */
        $count = count(MonitorRegion::cases());

        $this->get('/privacy')->assertSee($count.' regions to choose from');
        $this->get('/tr/privacy')->assertSee($count.' bölge');

        foreach (MonitorRegion::cases() as $region) {
            $this->get('/privacy')->assertSee($region->label());
        }
    }

    public function test_every_alert_channel_that_exists_is_named_as_a_recipient(): void
    {
        // An exhaustive match with no default arm builds this list in the controller, so adding
        // a channel type breaks the build rather than leaving a recipient undisclosed.
        foreach (['Slack', 'Webhook', 'PagerDuty', 'Microsoft Teams'] as $channel) {
            $this->get('/privacy')->assertSee($channel);
            $this->get('/tr/privacy')->assertSee($channel);
        }

        $this->assertCount(4, NotificationChannelType::cases(), 'A channel type was added; the page must name it.');
    }

    public function test_the_ai_excerpt_cap_is_read_from_the_class_that_enforces_it(): void
    {
        /*
         * One of the two disclosures a template would miss: up to this many characters of the
         * customer's OWN response body reach the AI provider on an incident analysis. The
         * figure comes from the constant the truncation uses, so the two cannot drift.
         */
        $chars = (string) IncidentAnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH;

        $this->get('/privacy')->assertSee($chars.' characters');
        $this->get('/tr/privacy')->assertSee($chars.' karakter');
    }

    public function test_the_credentials_disclosure_does_not_claim_the_edge_honours_them(): void
    {
        /*
         * The other disclosure a template would miss: `auth_config` is transmitted to the edge
         * worker inside the signed spec even though the probe engine ignores it. Both halves
         * have to be on the page: transmitting them is the privacy fact, ignoring them is the
         * fact the Terms page already publishes, and a page saying only the first would
         * contradict it.
         */
        $this->get('/privacy')
            ->assertSee('credentials')
            ->assertSee('the probe engine ignores');

        $this->get('/tr/privacy')->assertSee('yok sayıyor');
    }

    public function test_the_named_third_parties_are_the_ones_configuration_selects(): void
    {
        /*
         * A subprocessor list is the easiest place on a privacy page to publish a fiction:
         * every template names Stripe, Google and an AI vendor whether or not the deployment
         * calls them. Here the list is built from the same gates the runtime uses, so a
         * deployment with no AI key and no Stripe secret names neither.
         */
        $this->get('/privacy')
            ->assertSee('Cloudflare')
            ->assertDontSee('Stripe')
            ->assertDontSee('Anthropic')
            ->assertDontSee('OneSignal');

        config([
            'cashier.secret' => 'sk_test_privacy',
            'ai.default' => 'anthropic',
            'ai.providers.anthropic.key' => 'sk-ant-privacy',
            'magic-starter.onesignal.app_id' => '00000000-0000-4000-8000-000000000000',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.from.address' => 'ops@uptizm.test',
        ]);

        foreach (['/privacy', '/tr/privacy'] as $path) {
            $this->get($path)
                ->assertSee('Stripe')
                ->assertSee('Anthropic')
                ->assertSee('OneSignal')
                ->assertSee('smtp.example.test');
        }
    }

    public function test_the_cookie_section_counts_zero_while_no_analytics_container_is_configured(): void
    {
        /*
         * The measured position on this deployment, and the reason the section exists: these
         * pages are registered outside the middleware group that starts a session, so they set
         * nothing at all, including on the contact form's POST. Unusual enough to be worth
         * stating plainly rather than leaving a reader to assume the usual.
         *
         * The two framework cookies are named as former cookies with all four fields, so a
         * reader who remembers them or finds one in an old browser profile is answered.
         */
        $this->assertNull(config('analytics.gtm_container_id'), 'Analytics is configured, so this test proves the other branch.');

        $this->get('/privacy')
            ->assertSee('on your device: 0')
            ->assertSee('XSRF-TOKEN')
            ->assertSee((string) config('session.cookie'))
            ->assertSee(config('session.lifetime').' minutes');

        $this->get('/tr/privacy')
            ->assertSee('çerez sayısı: 0')
            ->assertSee('XSRF-TOKEN')
            ->assertSee(config('session.lifetime').' dakika');
    }

    public function test_the_cookie_section_flips_when_an_analytics_container_is_configured(): void
    {
        /*
         * The claim "this site sets no cookies" was true when it was written and stops being
         * true the moment a container id is configured, so it is DERIVED like every other
         * claim on this surface instead of asserted. The consent layer keeps Consent Mode
         * defaults denied, so nothing is set until the visitor accepts; the count is of what
         * the site CAN store, which is the honest number to publish.
         */
        config(['analytics.gtm_container_id' => 'GTM-TESTONLY']);

        $this->get('/privacy')
            ->assertSee('on your device: 2')
            ->assertSee('_ga')
            ->assertSee('_gid')
            ->assertSee('2 years')
            ->assertSee('24 hours')
            ->assertDontSee('on your device: 0');

        $this->get('/tr/privacy')
            ->assertSee('çerez sayısı: 2')
            ->assertSee('_gid')
            ->assertSee('24 saat')
            ->assertDontSee('çerez sayısı: 0');
    }

    public function test_the_former_session_cookie_is_named_from_config(): void
    {
        // `SESSION_COOKIE` derives from the app name, so a deployment renaming either would
        // leave a transcribed `uptizm-session` naming a cookie that never existed.
        config(['session.cookie' => 'renamed-session']);

        foreach (['/privacy', '/tr/privacy'] as $path) {
            $this->get($path)
                ->assertSee('renamed-session')
                ->assertDontSee('uptizm-session');
        }
    }

    public function test_the_browser_token_that_is_not_a_cookie_is_disclosed(): void
    {
        // Storing a bearer token in browser storage is an Art. 5(3) "storing information in
        // terminal equipment" event just as a cookie is, exempt because it IS the
        // authentication the user asked for. A cookie table that omits it is incomplete.
        $this->get('/privacy')
            ->assertSee('bearer token')
            ->assertSee('local storage');

        $this->get('/tr/privacy')->assertSee('yerel depolama');
    }

    public function test_the_identity_facts_are_read_from_config_and_the_tax_number_is_not_published(): void
    {
        config([
            'legal.operator' => 'Someone Else (Trading Name)',
            'legal.address' => 'A Street 1, Somewhere',
            'legal.contact_email' => 'someone@example.test',
            'legal.rights_email' => 'rights@example.test',
        ]);

        foreach (['/privacy', '/tr/privacy'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('Someone Else (Trading Name)')
                ->assertSee('A Street 1, Somewhere')
                ->assertSee('someone@example.test')
                ->assertSee('rights@example.test')
                // The supplied values, which the Markdown must not carry as literals.
                ->assertDontSee('Konak')
                ->assertDontSee('info@kodizm.com')
                // The tax number is the operator's national identity number. It belongs to
                // the Terms identity block and has no business on a privacy notice at all.
                ->assertDontSee('44938660202');
        }
    }

    public function test_the_three_absences_are_stated_and_never_filled_in_with_a_name(): void
    {
        /*
         * There is no Art. 27 representative (a recorded, accepted gap), no data protection
         * officer and no certification. Each is stated rather than omitted, because omission
         * reads as "did not think about it" and a template would invent all three.
         */
        $this->assertNull(config('legal.eu_representative'), 'A representative exists now, so this page must name it.');

        $this->get('/privacy')
            ->assertSee('no representative in the European Union')
            ->assertDontSee('Data Protection Officer')
            ->assertDontSee('DPO');

        $this->get('/tr/privacy')
            ->assertSee('bir temsilci bulunmuyor')
            ->assertDontSee('Data Protection Officer');
    }

    public function test_the_supervisory_authority_wording_is_the_operators_own_plus_the_readers(): void
    {
        // The operator has no EU establishment, so there is no lead authority to name. KVKK is
        // the operator's own; an EEA reader is pointed at their own authority with the EDPB
        // list rather than at an authority this service is not answerable to.
        $this->get('/privacy')
            ->assertSee((string) config('legal.authority'))
            ->assertSee('the data protection authority of your country')
            ->assertSee('edpb.europa.eu');

        $this->get('/tr/privacy')
            ->assertSee((string) config('legal.authority'))
            ->assertSee('kendi ülkenizin veri koruma otoritesine');
    }

    public function test_the_rights_list_carries_the_one_month_deadline(): void
    {
        config(['legal.rights_email' => 'rights@example.test']);

        $this->get('/privacy')
            ->assertSee('one month')
            ->assertSee('rights@example.test');

        $this->get('/tr/privacy')
            ->assertSee('bir ay')
            ->assertSee('haklarınız');
    }

    public function test_the_article_14_duties_are_met_for_data_inside_a_monitored_response(): void
    {
        // Art. 14 adds two duties Art. 13 does not have: the CATEGORIES of data and its
        // SOURCE. For a name that turns up inside a monitored response there is no way to
        // reach the person, so the 14(5)(b) carve-out is relied on out loud, with the
        // categories and the source stated as the "appropriate measure" that replaces a
        // message nobody could send.
        $this->get('/privacy')
            ->assertSee('disproportionate effort')
            ->assertSee('the source');

        $this->get('/tr/privacy')->assertSee('ölçüsüz');
    }

    public function test_the_transfer_section_names_the_mechanism_and_says_it_is_not_settled(): void
    {
        // Art. 13(1)(f) wants the mechanism CATEGORY, not the clauses. The dated caveat is the
        // point: the adequacy decision covering the United States is under challenge, so a
        // notice presenting it as permanent would be making a promise about somebody else's
        // litigation.
        $this->get('/privacy')
            ->assertSee('Data Privacy Framework')
            ->assertSee('standard contractual clauses')
            ->assertSee('under challenge');

        $this->get('/tr/privacy')
            ->assertSee('standart sözleşme hükümleri')
            ->assertSee('yurt dışına aktarım');
    }

    public function test_the_retention_section_is_honest_about_what_deletion_means(): void
    {
        // Three facts a template gets wrong in the customer's favour: a deleted monitor is
        // soft-deleted, unsubscribing really does erase the row, and the in-app notification
        // inbox is pruned by nothing at all.
        $this->get('/privacy')
            ->assertSee('hidden rather than erased')
            ->assertSee('removes the subscriber row');

        $this->get('/tr/privacy')
            ->assertSee('satırı kalır')
            ->assertSee('tümüyle siler');
    }

    public function test_no_security_measure_beyond_the_implemented_ones_is_claimed(): void
    {
        // The inventory's section E is the whole of what may be claimed. Everything below is
        // what a template would add for reassurance and this deployment cannot back.
        foreach (['/privacy', '/tr/privacy'] as $path) {
            $response = $this->get($path);

            foreach ([
                'ISO 27001',
                'SOC 2',
                'penetration test',
                'end-to-end encryption',
                'sızma testi',
                'uçtan uca şifrele',
                'bank-grade',
                'military',
            ] as $forbidden) {
                $response->assertDontSee($forbidden);
            }
        }
    }

    public function test_no_compliance_claim_is_made(): void
    {
        // Describing what the system does is this page's job. Certifying it against a regime
        // is not the operator's call to make, and the page says on its face that no lawyer has
        // read it.
        foreach (['/privacy', '/tr/privacy'] as $path) {
            $response = $this->get($path);

            foreach (['GDPR compliant', 'KVKK uyumlu', 'fully compliant', 'CCPA', 'tam uyumlu'] as $forbidden) {
                $response->assertDontSee($forbidden);
            }
        }
    }

    public function test_the_page_says_who_wrote_it_and_that_it_is_not_legal_advice(): void
    {
        $this->get('/privacy')
            ->assertSee('not a lawyer')
            ->assertSee('legal advice');

        $this->get('/tr/privacy')
            ->assertSee('avukat değil')
            ->assertSee('hukuki tavsiye');
    }

    public function test_the_turkish_document_is_written_in_turkish(): void
    {
        /*
         * A word-for-word rendering of English legalese reads as machine output, and that
         * exact failure already happened once in this work. The register below is what a
         * Turkish privacy notice actually uses; the English headings are asserted absent so a
         * half-translated file cannot pass.
         */
        $response = $this->get('/tr/privacy');

        foreach ([
            'veri sorumlusu',
            'veri işleyen',
            'açık rıza',
            'meşru menfaat',
            'saklama süresi',
            'ilgili kişi',
            'haklarınız',
            'yurt dışına aktarım',
        ] as $term) {
            $response->assertSee($term);
        }

        foreach (['Your rights', 'How long it is kept', 'Who else receives it', 'Two roles'] as $english) {
            $response->assertDontSee($english);
        }
    }

    public function test_no_effective_date_is_invented_in_either_language(): void
    {
        // `legal.effective_date` is null on purpose and the shell renders that absence, so a
        // year typed into the prose is the one place a date could still be invented.
        $this->assertNull(config('legal.effective_date'), 'A date is configured, so this test no longer proves anything.');

        foreach ($this->supported() as $locale) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(19|20)\d{2}\b/u',
                $this->source($locale),
                "The Privacy source in \"{$locale}\" contains a year, which this document has no business asserting.",
            );
        }
    }

    public function test_no_translator_placeholder_survives_into_either_source(): void
    {
        // Two placeholder dialects could reach these files: the pipeline's own `[[key]]`,
        // which `LegalPagesTest` catches on the rendered page, and Laravel's `:name`, which
        // nothing replaces here because these documents are not translator strings.
        foreach ($this->supported() as $locale) {
            $this->assertDoesNotMatchRegularExpression(
                '/\s:[a-z_]{3,}/u',
                $this->source($locale),
                "The Privacy source in \"{$locale}\" carries a `:placeholder` token nothing will replace.",
            );
        }
    }

    public function test_neither_language_exceeds_the_word_budget(): void
    {
        /*
         * Length is a disclosure failure in its own right, not a style preference: the GPEN
         * 2024 sweep found 89% of reviewed notices opaque largely on length, and CNIL names
         * the same. A notice nobody finishes reading discloses nothing, so the ceiling is a
         * test rather than an intention.
         */
        foreach ($this->supported() as $locale) {
            $words = count(preg_split('/\s+/u', trim($this->source($locale)), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            $this->assertLessThan(
                self::WORD_BUDGET,
                $words,
                "The Privacy notice in \"{$locale}\" is {$words} words, past the ".self::WORD_BUDGET.'-word ceiling.',
            );
        }
    }

    public function test_the_document_starts_at_a_second_level_heading(): void
    {
        // The shared shell emits the document's own `<h1>` and styles `h2`/`h3` only, so an
        // `#` heading in the Markdown produces a second first-level heading and a TOC entry
        // the layout deliberately skips.
        foreach ($this->supported() as $locale) {
            $this->assertDoesNotMatchRegularExpression(
                '/^# /mu',
                $this->source($locale),
                "The Privacy source in \"{$locale}\" carries a first-level heading; the shell owns that.",
            );
        }
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
     * The Markdown source for one language, read from disk.
     *
     * Read rather than rendered for the assertions where the RENDERED page would answer a
     * different question: the shell contributes chrome copy and CSS classes that can carry a
     * year or a colon-prefixed token legitimately, and neither is this document's doing.
     */
    protected function source(string $locale): string
    {
        return (string) file_get_contents(resource_path("legal/privacy.{$locale}.md"));
    }
}
