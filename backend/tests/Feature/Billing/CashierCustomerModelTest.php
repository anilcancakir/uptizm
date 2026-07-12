<?php

namespace Tests\Feature\Billing;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Tests\TestCase;

/**
 * Locks Cashier's customer-model wiring: {@see Team} is the Billable model
 * (not {@see User}), so Cashier resolves subscriptions/checkout against the
 * team, matching the SaaS-team-billable pattern from research/01.
 */
class CashierCustomerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_uses_the_billable_trait(): void
    {
        $this->assertContains(
            Billable::class,
            class_uses_recursive(Team::class),
            'Team must use the Cashier Billable trait so it exposes subscriptions()/checkout().',
        );
    }

    public function test_cashier_resolves_team_as_the_customer_model_by_stripe_id(): void
    {
        $user = User::query()->create([
            'name' => 'Billable Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Billable Team',
            'stripe_id' => 'cus_test_'.Str::random(14),
        ]);

        $billable = Cashier::findBillable($team->stripe_id);

        $this->assertNotNull($billable);
        $this->assertTrue($billable->is($team));
    }
}
