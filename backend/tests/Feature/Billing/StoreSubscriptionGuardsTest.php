<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Actions\StoreSubscriptionGuardedDeleteTeam;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Http\Controllers\Api\V1\BillingController;
use App\Models\Team;
use App\Models\User;
use FlutterSdk\MagicStarter\Actions\DeleteTeam;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two guards a STORE rail needs that the card rail never did, because a
 * store account is a THING OUTSIDE THIS APPLICATION that keeps charging after we
 * stop looking at it.
 *
 * 1. **A team a store is billing cannot be deleted.** The entitlement dies with
 *    the row, the store subscription does not: it keeps taking the owner's money
 *    every month, and the store's own account surface is the only place it can be
 *    cancelled. Team deletion is the starter's endpoint, so the refusal is bound
 *    over the {@see DeletesTeams} contract, exactly as the responder cap is bound
 *    over the invite contract.
 * 2. **One store account funds one team.** The two store SKUs share a
 *    subscription group so upgrade and downgrade work at all, and a store account
 *    holds at most one active subscription per group, so a second purchase
 *    TRANSFERS the subscription and silently stops funding the first team.
 *    {@see BillingController::storeFundedTeam()} is what lets the client refuse
 *    that purchase by NAME instead of discovering it after the money moved.
 *
 * Both guards are LIVE-scoped, and every "still deletable" case below is there to
 * prove it: a guard that refused on the mere presence of a store `plan_provider`
 * would strand every team that ever cancelled a store subscription.
 */
class StoreSubscriptionGuardsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The endpoint the client asks before it offers a store purchase.
     */
    private const FUNDED_TEAM_ENDPOINT = '/api/v1/billing/store-funded-team';

    public function test_the_deletes_teams_contract_resolves_the_guarded_action(): void
    {
        // The mechanism, asserted on its own: without this binding every
        // assertion below would pass or fail for reasons unrelated to the guard,
        // and the starter's own action would run instead.
        $resolved = $this->app->make(DeletesTeams::class);

        $this->assertInstanceOf(StoreSubscriptionGuardedDeleteTeam::class, $resolved);
        $this->assertInstanceOf(DeleteTeam::class, $resolved);
    }

    public function test_a_team_a_store_is_billing_cannot_be_deleted(): void
    {
        [$owner, $team] = $this->makeOwnedTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);
        $member = $this->attachMember($team);
        Sanctum::actingAs($owner);

        $response = $this->deleteJson('/api/v1/teams/'.$team->id);

        // The damage first: the row surviving is the whole point, and the status
        // code is only how the client learns why.
        $this->assertDatabaseHas('teams', ['id' => $team->id]);
        // And the guard ran BEFORE the destructive work rather than after it:
        // the starter's action detaches every member and deletes every
        // invitation before it deletes the team, so a refusal thrown late would
        // leave a team nobody belongs to.
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase(
            'store',
            (string) $response->json('message'),
        );
    }

    public function test_a_team_with_no_subscription_is_still_deleted(): void
    {
        // The falsifier for the test above: a guard that refused every deletion
        // would satisfy it just as well.
        [$owner, $team] = $this->makeOwnedTeam();
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/teams/'.$team->id)->assertOk();

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_a_stripe_billed_team_is_still_deleted(): void
    {
        // The card rail is ours to cancel, and Cashier's own delete path handles
        // it; only a store keeps charging after the row is gone.
        [$owner, $team] = $this->makeOwnedTeam([
            'plan' => Plan::Business->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::Stripe->value,
        ]);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/teams/'.$team->id)->assertOk();

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_a_finished_store_subscription_no_longer_blocks_deletion(): void
    {
        // `plan_provider` is provenance and it SURVIVES the subscription ending,
        // so gating on the rail alone would strand every team that ever bought
        // in a store, forever.
        [$owner, $team] = $this->makeOwnedTeam([
            'plan' => Plan::Free->value,
            'plan_status' => 'expired',
            'plan_provider' => BillingProvider::PlayStore->value,
        ]);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/teams/'.$team->id)->assertOk();

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_a_store_dunning_status_still_blocks_deletion(): void
    {
        // `past_due` still entitles ({@see PlanStatus::grants()}) because the
        // rail is still trying to take the money, which is exactly the state
        // where deleting the team would leave a charge nobody can stop.
        [$owner, $team] = $this->makeOwnedTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => 'past_due',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/teams/'.$team->id)->assertStatus(422);

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_a_free_tier_row_with_a_store_provider_does_not_block_deletion(): void
    {
        // Reaches the guard's THIRD condition on its own, which no other case
        // here does: the finished-subscription test above is dropped by the
        // status check before the tier is ever read, so without this the tier
        // clause would be a condition no test visits.
        //
        // The row is internally inconsistent (a live store status over a Free
        // tier; {@see WriteTeamEntitlement} writes all three together, so a rail
        // does not produce it), and the guard answers it the same way the wire
        // does: `subscribed` is tier-above-Free AND a granting status, so there
        // is no plan here to lose.
        [$owner, $team] = $this->makeOwnedTeam([
            'plan' => Plan::Free->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/teams/'.$team->id)->assertOk();

        $this->assertDatabaseMissing('teams', ['id' => $team->id]);
    }

    public function test_the_endpoint_names_another_team_a_store_already_funds(): void
    {
        [$owner, $current] = $this->makeOwnedTeam();
        $funded = $this->makeTeamFor($owner, [
            'name' => 'Kodizm Ops',
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);
        Sanctum::actingAs($owner);

        $response = $this->getJson(self::FUNDED_TEAM_ENDPOINT);

        $response->assertOk();
        $response->assertJsonPath('store_funded_team.name', 'Kodizm Ops');
        $response->assertJsonPath('store_funded_team.id', $funded->id);
        $this->assertNotSame($current->id, $funded->id);
    }

    public function test_the_endpoint_ignores_the_current_team_itself(): void
    {
        // Buying the other SKU for the team the store already funds is the
        // UPGRADE path, not a transfer: both SKUs sit in one subscription group,
        // so the store replaces rather than adds. Refusing here would make an
        // upgrade impossible on the only rail that sells it.
        [$owner] = $this->makeOwnedTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson(self::FUNDED_TEAM_ENDPOINT)
            ->assertOk()
            ->assertJsonPath('store_funded_team', null);
    }

    public function test_the_endpoint_ignores_a_stripe_billed_sibling(): void
    {
        [$owner] = $this->makeOwnedTeam();
        $this->makeTeamFor($owner, [
            'name' => 'Card Team',
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::Stripe->value,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson(self::FUNDED_TEAM_ENDPOINT)
            ->assertOk()
            ->assertJsonPath('store_funded_team', null);
    }

    public function test_the_endpoint_ignores_a_sibling_whose_store_subscription_ended(): void
    {
        [$owner] = $this->makeOwnedTeam();
        $this->makeTeamFor($owner, [
            'name' => 'Lapsed Team',
            'plan' => Plan::Free->value,
            'plan_status' => 'canceled',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson(self::FUNDED_TEAM_ENDPOINT)
            ->assertOk()
            ->assertJsonPath('store_funded_team', null);
    }

    public function test_the_endpoint_ignores_a_team_the_caller_only_belongs_to(): void
    {
        // A team the caller is merely a MEMBER of was bought by its own owner,
        // on that owner's store account, and only an owner can buy at all. So a
        // member's team says nothing about this caller's store account, and
        // counting it would refuse a legitimate first purchase to anybody who
        // has ever joined a store-billed team.
        [$owner] = $this->makeOwnedTeam();
        [$otherOwner, $otherTeam] = $this->makeOwnedTeam([
            'name' => 'Someone Else Ops',
            'plan' => Plan::Business->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::PlayStore->value,
        ]);
        $otherTeam->users()->attach($owner->id, ['role' => 'member']);
        Sanctum::actingAs($owner);

        $this->getJson(self::FUNDED_TEAM_ENDPOINT)
            ->assertOk()
            ->assertJsonPath('store_funded_team', null);

        $this->assertNotSame($owner->id, $otherOwner->id);
    }

    public function test_the_endpoint_is_scoped_to_the_caller(): void
    {
        // Another person's store-funded team is not this caller's store account
        // and must never be named to them: the refusal carries a team NAME.
        [$owner] = $this->makeOwnedTeam();
        $this->makeOwnedTeam([
            'name' => 'Stranger Ops',
            'plan' => Plan::Pro->value,
            'plan_status' => 'active',
            'plan_provider' => BillingProvider::AppStore->value,
        ]);
        Sanctum::actingAs($owner);

        $response = $this->getJson(self::FUNDED_TEAM_ENDPOINT);

        $response->assertOk()->assertJsonPath('store_funded_team', null);
        $this->assertStringNotContainsString(
            'Stranger Ops',
            $response->getContent() === false ? '' : $response->getContent(),
        );
    }

    /**
     * Create an owner with a NON-PERSONAL current team.
     *
     * Non-personal deliberately: the starter refuses to delete a personal team
     * with its own message, so a personal fixture would make every deletion test
     * above pass without the guard existing.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Team}
     */
    private function makeOwnedTeam(array $overrides = []): array
    {
        $owner = User::factory()->create();
        $team = $this->makeTeamFor($owner, $overrides);

        $owner->forceFill(['current_team_id' => $team->id])->save();

        return [$owner->refresh(), $team];
    }

    /**
     * Create one more team owned by the given user.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeTeamFor(User $owner, array $overrides = []): Team
    {
        return Team::query()->create([
            'user_id' => $owner->id,
            'name' => 'Ops Team '.Str::random(6),
            'personal_team' => false,
            'plan' => Plan::Free->value,
            'plan_status' => null,
            ...$overrides,
        ]);
    }

    /**
     * Attach a non-owning member to the team.
     */
    private function attachMember(Team $team): User
    {
        $member = User::factory()->create();

        $team->users()->attach($member->id, ['role' => 'member']);

        return $member;
    }
}
