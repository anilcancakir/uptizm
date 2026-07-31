<?php

namespace Tests\Feature\Marketing;

use App\Enums\MonitorRegion;
use App\Models\Monitor;
use Tests\TestCase;

/**
 * The decision-rules section.
 *
 * This section's whole value is that the rules are true, so these tests pin the two
 * ways it could stop being true: a number drifting away from the constant the engine
 * enforces, and a claim creeping in that the engine does not implement.
 */
class DecidesTest extends TestCase
{
    public function test_the_section_exists_and_the_page_reaches_it(): void
    {
        // The hero's secondary button and the header nav both point here. The anchor
        // guard in ChromeTest covers the dangling case; this covers the reverse, a
        // section nobody can navigate to.
        $this->get('/')
            ->assertOk()
            ->assertSee('id="how-it-decides"', escape: false)
            ->assertSee('href="#how-it-decides"', escape: false);
    }

    public function test_the_rules_quote_the_numbers_the_engine_actually_enforces(): void
    {
        // Interpolated from the enum and the constant, so a change to either shows up
        // on the page instead of leaving a stale figure in the copy.
        $this->get('/')
            ->assertSee(count(MonitorRegion::cases()).' regions')
            ->assertSee('incident_threshold = '.Monitor::DEFAULT_INCIDENT_THRESHOLD);
    }

    public function test_the_section_does_not_claim_a_quorum(): void
    {
        /*
         * There is no "two regions must agree" rule anywhere in the code. A monitor's
         * `last_status` is whatever the most recent check wrote, and the only thing
         * protecting you from one bad region is that any non-down result resets
         * `consecutive_fails` to zero (CheckPersistenceService).
         *
         * A quorum was the easy sentence to write here, it was in my notes as though it
         * were fact, and it would have been a false public claim about how the product
         * decides to wake somebody up.
         */
        $response = $this->get('/');

        foreach (['quorum', 'majority', 'two regions', 'consensus', 'agree'] as $claim) {
            $response->assertDontSee($claim);
        }
    }

    public function test_the_mechanism_lines_are_left_untranslated(): void
    {
        // They are real identifiers. A translated `consecutive_fails` stops being
        // something a reader can go and grep for, which is the only reason the
        // mechanism is shown at all.
        $this->get('/tr')
            ->assertOk()
            ->assertSee('consecutive_fails = 0')
            ->assertSee('incident_threshold = '.Monitor::DEFAULT_INCIDENT_THRESHOLD);
    }
}
