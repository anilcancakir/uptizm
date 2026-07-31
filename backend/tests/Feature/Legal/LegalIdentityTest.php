<?php

namespace Tests\Feature\Legal;

use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

/**
 * Pins the operator identity catalog: the single source of truth the Privacy, Terms and
 * Contact pages interpolate.
 *
 * The governing rule for this plan is that a legal page must never go live with a placeholder
 * or empty identity field. This test is that gate: it iterates a declared required set and
 * fails BY NAME when a slot is empty, so a missing value is a loud test failure rather than a
 * silent blank rendered on a public page. `eu_representative` and `effective_date` are the two
 * deliberately-absent exceptions and each gets its own assertion instead of being swept into
 * the required set.
 */
class LegalIdentityTest extends TestCase
{
    /**
     * Every Art. 5 field that must carry a real value before this deployment can go live.
     * Deliberately excludes `eu_representative` (GDPR Art. 27 gap, accepted risk) and
     * `effective_date` (no publish date has been set yet); those two are asserted separately
     * below rather than hidden from this list.
     *
     * @var list<string>
     */
    private const array REQUIRED_KEYS = [
        'operator',
        'trade_name',
        'address',
        'phone',
        'tax_number',
        'tax_number_kind',
        'contact_email',
        'rights_email',
        'authority',
    ];

    public function test_every_required_identity_slot_holds_a_value(): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            $value = config("legal.{$key}");

            $this->assertNotEmpty(
                $value,
                "Required legal identity slot [{$key}] is empty. A legal page cannot render an ".
                'honest identity block until config/legal.php sets this value.',
            );
        }
    }

    public function test_the_required_sweep_fails_naming_the_specific_empty_key(): void
    {
        // Simulate the exact failure the plan guards against: a required slot going blank in
        // production. Blanking `contact_email` and asserting the failure message names it
        // proves the guard actually points a fixer at the right key, rather than just failing.
        config(['legal.contact_email' => '']);

        try {
            foreach (self::REQUIRED_KEYS as $key) {
                $value = config("legal.{$key}");

                $this->assertNotEmpty(
                    $value,
                    "Required legal identity slot [{$key}] is empty. A legal page cannot render an ".
                    'honest identity block until config/legal.php sets this value.',
                );
            }

            $this->fail('Expected the required-slot sweep to fail while contact_email is blank.');
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString('contact_email', $exception->getMessage());
        }
    }

    public function test_eu_representative_is_a_deliberate_absence_not_an_oversight(): void
    {
        // GDPR Art. 27: a non-EU controller monitoring EU data subjects continuously should
        // designate an EU representative. None has been designated yet. This is recorded as an
        // accepted risk in config/legal.php, not swept into the required set, so the Privacy
        // page must render this absence honestly rather than inventing a representative.
        $this->assertNull(config('legal.eu_representative'));
    }

    public function test_effective_date_is_null_until_the_env_var_is_set(): void
    {
        // No effective date was supplied and this plan forbids inventing one. The config reads
        // it from LEGAL_EFFECTIVE_DATE with a null default, so until that env var is set in a
        // deployment, the page must show an honest absence rather than a guessed date.
        $this->assertNull(config('legal.effective_date'));
    }
}
