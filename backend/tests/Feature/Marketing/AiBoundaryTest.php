<?php

namespace Tests\Feature\Marketing;

use App\Enums\AiMode;
use Tests\TestCase;

/**
 * The AI section.
 *
 * Its own file because the gate is the load-bearing part. Without a provider key every
 * AI path in the product returns its deterministic fallback, so a section advertising
 * analysis would be advertising the fallback, and the section plus its nav entry have to
 * disappear together or the nav points at nothing.
 */
class AiBoundaryTest extends TestCase
{
    public function test_the_section_and_its_nav_entry_are_withheld_together(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => null]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('id="ai-boundary"', escape: false)
            ->assertDontSee('href="#ai-boundary"', escape: false)
            ->assertDontSee('The AI boundary');
    }

    public function test_the_section_and_its_nav_entry_appear_together(): void
    {
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test']);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="ai-boundary"', escape: false)
            ->assertSee('href="#ai-boundary"', escape: false);
    }

    public function test_every_mode_comes_from_the_enum(): void
    {
        // The three modes are the enum the write requests validate against, through an
        // exhaustive match with no default arm, so adding a mode is a failure here rather
        // than a section that quietly omits it.
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test']);

        $response = $this->get('/');

        $this->assertCount(3, AiMode::cases(), 'A mode was added or removed; the section needs updating.');

        foreach (['Off', 'Suggest', 'Auto'] as $mode) {
            $response->assertSee($mode);
        }
    }

    public function test_the_section_publishes_what_the_ai_cannot_see(): void
    {
        /*
         * This list is the section's whole argument, and it is the opposite of a feature
         * claim: uptizm has no integration into the customer's product, so its AI cannot
         * reason about a deploy, a log line or an APM trace. Every competing AI feature
         * in this category will happily write "errors started two minutes after deploy
         * a1b2c3"; this one structurally cannot, and says so.
         *
         * If an integration ever ships, the honest change is to remove the line from the
         * list, not to quietly widen what the AI claims.
         */
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test']);

        $this->get('/')
            ->assertSee('cannot see')
            ->assertSee('deploys')
            ->assertSee('APM')
            // It advises and nothing more. The wording moved from "stay advisory" to
            // "are advice" when this section's copy was simplified, because the idioms
            // in the first draft translated into Turkish nonsense.
            ->assertSee('are advice')
            ->assertSee('no way in');
    }

    public function test_the_section_does_not_promise_the_ai_can_act(): void
    {
        // There is no path by which the AI reaches a customer's infrastructure, so the
        // page may not imply one. "Auto" means it drives the incident, never the fix.
        config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'sk-test']);

        $response = $this->get('/');

        foreach (['auto-remediate', 'self-healing', 'roll back', 'rollback', 'one-click fix'] as $claim) {
            $response->assertDontSee($claim);
        }
    }
}
