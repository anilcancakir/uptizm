<?php

namespace Tests\Feature\Legal;

use Tests\TestCase;

/**
 * Pins the operator identity catalog: the single source of truth the Privacy, Terms and
 * Contact pages interpolate.
 *
 * WHY THIS FILE STOPPED FAILING ON AN EMPTY SLOT
 *
 * It used to iterate a required set and fail BY NAME while any slot was empty, which was the
 * right gate while the values lived in `config/legal.php` as code defaults. They do not any
 * more: the operator's legal name, street address, telephone, registry id and tax number came
 * out of the repository entirely, because the Service has not launched and the registered
 * identity that will be published arrives with the launch. A test that fails while those are
 * empty would make the whole suite red as a matter of course, and a permanently red suite
 * gates nothing at all. So the sweep is inverted: the normal run asserts the absence is
 * HONEST (nothing personal ships in version control, and the pages render the absence rather
 * than a blank), and the launch requirement is a separate, clearly named check that
 * enumerates what is still unfilled instead of failing on it.
 *
 * WHAT MUST BE TRUE BEFORE THESE PAGES GO LIVE
 *
 * Every key `launchChecklist()` returns has to hold a real value, and the checklist has to
 * come back empty:
 *
 *   - `operator`  the ticaret unvanı, or the name and surname for an esnaf. TTK 41 makes the
 *                 legal name of a gerçek kişi tacir its ticaret unvanı, so an işletme adı may
 *                 sit beside it and cannot replace it.
 *   - `address`   the merkez adresi as registered with the ticaret sicili (2022 e-commerce
 *                 regulation Madde 4(l) and 5(1)(a), Mesafeli Sözleşmeler Yönetmeliği
 *                 Madde 5(1)(c), CRD Art. 6(1)(c)). A registered office is what keeps a home
 *                 address off a public page; the residence fallback bites only for a trader
 *                 with no fixed premises.
 *   - `phone`     required outright by both Turkish regulations. A business line satisfies it.
 *   - `kep_address` Madde 5(1)(b). The pages carry none today and nobody had raised it.
 *   - one of `registry_number` (MERSİS) or `tax_number` (VKN). Madde 5(1)(a) splits on tacir
 *                 vs esnaf, NOT on natural vs legal person: a tacir publishes MERSİS and is
 *                 never asked for a VKN, an esnaf publishes the VKN. Which one applies is the
 *                 open question for the accountant (TTK 11(2)), so either satisfies the
 *                 checklist and the pages label whichever is filled.
 *
 * Sourced in `.ac/plans/legal-support-pages-uptizm-marketing/research/`
 * `librarian-identity-and-ai-refunds.md` section 1. Nothing in it is legal advice, and the
 * pages say as much on their face.
 *
 * `eu_representative` and `effective_date` stay exempt exactly as they were: each is a
 * deliberate absence with its own assertion below rather than a member of any sweep.
 */
class LegalIdentityTest extends TestCase
{
    /**
     * The slots that must hold a value TODAY, because a page renders each of them inside a
     * sentence rather than as an identity row: the trade name in the intellectual-property
     * clause, the two inboxes in every "write to us" sentence, the authority in the
     * complaints paragraph. An empty one of these is a hole in a sentence, which is why they
     * are not on the launch checklist but asserted outright.
     *
     * @var list<string>
     */
    private const ALWAYS_FILLED = [
        'trade_name',
        'contact_email',
        'rights_email',
        'authority',
    ];

    /**
     * The identity slots that must be filled before launch and are empty until then. None of
     * them is allowed to carry a value in the repository: each is personal data about the
     * operator, and a code default would publish it on eight public URLs.
     *
     * @var list<string>
     */
    private const LAUNCH_REQUIRED = [
        'operator',
        'address',
        'phone',
        'kep_address',
    ];

    /**
     * The registry identifier, where EITHER value satisfies the disclosure: MERSİS for a
     * tacir, VKN for an esnaf. Publishing both is not required and publishing the wrong one
     * is worse than publishing neither, so the checklist asks for one.
     *
     * @var list<string>
     */
    private const LAUNCH_REGISTRY_ALTERNATIVES = [
        'registry_number',
        'tax_number',
    ];

    public function test_no_personal_identity_value_ships_as_a_default_in_the_repository(): void
    {
        // The operator's instruction, and the reason this file was rewritten: the personal
        // values are env-only with a null default, so a clone of this repository carries no
        // legal name, no street address, no telephone number and no national identity number.
        // A default here would republish all four the moment anybody deployed.
        $slots = [
            ...self::LAUNCH_REQUIRED,
            ...self::LAUNCH_REGISTRY_ALTERNATIVES,
            'tax_number_kind',
        ];

        foreach ($slots as $key) {
            $this->assertTrue(
                blank(config("legal.{$key}")),
                "Legal identity slot [{$key}] carries a value with no environment variable set, which means ".
                'config/legal.php holds personal data in version control.',
            );
        }
    }

    public function test_the_slots_the_pages_need_today_hold_a_value(): void
    {
        foreach (self::ALWAYS_FILLED as $key) {
            $this->assertNotEmpty(
                config("legal.{$key}"),
                "Legal identity slot [{$key}] is empty. Every page renders it inside a sentence, so an ".
                'empty value is a sentence with a hole in it rather than an honest absence.',
            );
        }
    }

    public function test_the_launch_checklist_enumerates_every_identity_slot_still_unfilled(): void
    {
        /*
         * Named for what it is: a checklist, not a failure. It reports the slots a launch has
         * to fill, and it agrees with the deployment it is run against, so it is green with
         * everything empty (today) and green again once everything is filled.
         *
         * The positive control matters as much as the sweep: a checklist that happened to
         * return every key in the catalog would satisfy the first loop and mean nothing, so
         * the slots the pages need TODAY are asserted absent from it.
         */
        $checklist = $this->launchChecklist();

        foreach (self::LAUNCH_REQUIRED as $key) {
            blank(config("legal.{$key}"))
                ? $this->assertContains($key, $checklist, "Slot [{$key}] is empty and the launch checklist omits it.")
                : $this->assertNotContains($key, $checklist, "Slot [{$key}] holds a value and the launch checklist still asks for it.");
        }

        foreach (self::ALWAYS_FILLED as $key) {
            $this->assertNotContains(
                $key,
                $checklist,
                "Slot [{$key}] is on the launch checklist, but the pages need it filled today and it is.",
            );
        }
    }

    public function test_the_launch_checklist_names_all_five_requirements_while_nothing_is_filled(): void
    {
        // The enumeration itself, written out, so a reader of this file can see the whole
        // launch requirement in one place. Blanked through `config()` first so the assertion
        // says the same thing on a deployment that has already filled some of the slots.
        config([
            'legal.operator' => null,
            'legal.address' => null,
            'legal.phone' => null,
            'legal.kep_address' => null,
            'legal.registry_number' => null,
            'legal.tax_number' => null,
        ]);

        $this->assertSame(
            [
                'operator',
                'address',
                'phone',
                'kep_address',
                'registry_number|tax_number',
            ],
            $this->launchChecklist(),
        );
    }

    public function test_the_launch_checklist_is_empty_once_every_slot_is_filled(): void
    {
        // Obviously-fake values on purpose: a test fixture is a place personal data leaks
        // into a repository just as easily as a config default is.
        config([
            'legal.operator' => 'Example Operator',
            'legal.address' => '1 Test Street, Example',
            'legal.phone' => '+00 000 000 00 00',
            'legal.kep_address' => 'example@hs00.kep.test',
            'legal.registry_number' => '0000000000000000',
            'legal.tax_number' => null,
        ]);

        $this->assertSame([], $this->launchChecklist());
    }

    public function test_the_registry_requirement_is_satisfied_by_a_tax_number_too(): void
    {
        // The tacir/esnaf split from the research: an esnaf publishes a VKN and has no MERSİS
        // number to publish, so a checklist demanding MERSİS specifically would demand a
        // number that operator will never have.
        config([
            'legal.operator' => 'Example Operator',
            'legal.address' => '1 Test Street, Example',
            'legal.phone' => '+00 000 000 00 00',
            'legal.kep_address' => 'example@hs00.kep.test',
            'legal.registry_number' => null,
            'legal.tax_number' => '0000000000',
        ]);

        $this->assertSame([], $this->launchChecklist());
    }

    public function test_eu_representative_is_a_deliberate_absence_not_an_oversight(): void
    {
        // GDPR Art. 27: a non-EU controller monitoring EU data subjects continuously should
        // designate an EU representative. None has been designated yet. This is recorded as an
        // accepted risk in config/legal.php, not swept into any list, so the Privacy page must
        // render this absence honestly rather than inventing a representative.
        $this->assertNull(config('legal.eu_representative'));
    }

    public function test_effective_date_is_null_until_the_env_var_is_set(): void
    {
        // No effective date was supplied and this plan forbids inventing one. The config reads
        // it from LEGAL_EFFECTIVE_DATE with a null default, so until that env var is set in a
        // deployment, the page must show an honest absence rather than a guessed date.
        $this->assertNull(config('legal.effective_date'));
    }

    /**
     * The identity slots a launch still has to fill, in the order this file documents them.
     *
     * The registry entry is reported as the pseudo-key `registry_number|tax_number` because
     * the requirement is one identifier and not two: naming both in one entry is what stops a
     * reader filling MERSİS and then hunting for a tax number the operator does not have.
     *
     * @return list<string>
     */
    private function launchChecklist(): array
    {
        $unfilled = [];

        foreach (self::LAUNCH_REQUIRED as $key) {
            if (blank(config("legal.{$key}"))) {
                $unfilled[] = $key;
            }
        }

        $registry = array_filter(
            self::LAUNCH_REGISTRY_ALTERNATIVES,
            static fn (string $key): bool => filled(config("legal.{$key}")),
        );

        if ($registry === []) {
            $unfilled[] = implode('|', self::LAUNCH_REGISTRY_ALTERNATIVES);
        }

        return $unfilled;
    }
}
