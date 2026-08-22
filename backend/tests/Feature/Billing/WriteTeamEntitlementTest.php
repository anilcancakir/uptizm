<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Http\Controllers\StripeWebhookController;
use App\Models\Team;
use App\Models\User;
use App\Support\Billing\EntitlementWrite;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the two ordering rules of the single entitlement write path.
 *
 * Both rules guard the SAME column, so they are pinned in separate tests
 * against separate teams on purpose. A single scenario that trips both would
 * keep passing with either rule entirely absent: each guard would absorb the
 * other's mutation, and the test would certify a protection that is not there.
 * Every test below is therefore arranged so that exactly ONE rule can decide
 * its outcome.
 *
 * The stakes are the reason for that care. A dropped write that should have
 * landed leaves a customer on a tier they no longer pay for; a landed write
 * that should have dropped revokes a tier someone IS paying for. Only the
 * second one is a support ticket from an angry paying customer, which is why
 * every ambiguous case in {@see WriteTeamEntitlement} resolves toward keeping
 * the entitlement rather than toward taking it away.
 */
class WriteTeamEntitlementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RULE 1, monotonic per rail: a write from the rail that already granted
     * the entitlement, carrying an event OLDER than the one on record, is
     * dropped.
     *
     * Same rail on both sides, so rule 2 (which only fires cross-rail) cannot
     * reach this scenario and the timestamp is the only thing that can decide
     * it. The hazard is real delivery behaviour: RevenueCat retries at 5, 10,
     * 20, 40 and 80 minutes, so a promptly-delivered EXPIRATION can arrive
     * before the RENEWAL whose first delivery failed. Without this rule the
     * late renewal's team lands on free while still paying.
     */
    public function test_a_stale_write_on_the_same_rail_is_dropped(): void
    {
        Log::spy();

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'uptizm_business_monthly',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Free,
            status: PlanStatus::Expired,
            provider: BillingProvider::AppStore,
            eventAt: $grantedAt->subMinute(),
            authoritative: true,
            providerStatus: 'EXPIRATION',
        ));

        $this->assertFalse($applied);

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);
        $this->assertSame('uptizm_business_monthly', $team->plan_product_id);
        $this->assertTrue($grantedAt->equalTo($team->plan_source_event_at));

        $this->assertDropWasLogged($team, 'app_store', 'app_store', 'downgrade');
    }

    /**
     * An EQUAL timestamp is not a stale delivery, and treating it as one dropped
     * a paid upgrade.
     *
     * The scenario is ordinary Stripe. `created` is a Unix timestamp in SECONDS
     * ({@see StripeWebhookController::eventAt()}), and one
     * plan swap emits `customer.subscription.updated` and
     * `invoice.payment_succeeded` from a single API call, routinely inside the
     * same second, in an order Stripe does not guarantee. Delivered
     * invoice-first, the invoice handler reads the team's not-yet-resynced
     * Cashier price and re-affirms the OLD tier at second T; the subscription
     * event then carries the tier the customer actually bought, stamped with the
     * same second.
     *
     * Dropping it left a customer who had just paid for Business sitting on Pro
     * until the hourly `billing:reconcile` healed it, which is the hour they are
     * looking at the screen. The other delivery order was always correct, so the
     * bug was invisible half the time.
     */
    public function test_a_same_instant_upgrade_on_the_same_rail_is_applied(): void
    {
        Log::spy();

        $eventAt = CarbonImmutable::parse('2026-08-22 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_source_event_at' => $eventAt,
            'plan_product_id' => 'price_pro_monthly',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Business,
            status: PlanStatus::Active,
            provider: BillingProvider::Stripe,
            eventAt: $eventAt,
            authoritative: true,
            providerStatus: 'active',
            productId: 'price_business_monthly',
        ));

        $this->assertTrue($applied);

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame('price_business_monthly', $team->plan_product_id);
    }

    /**
     * The other half of the tie-break, and the reason it is a direction test
     * rather than a blanket "equal timestamps now win".
     *
     * A tie that would TAKE the tier away still loses, because this class's
     * doctrine resolves every ambiguity toward keeping the entitlement. Without
     * this test the fix above could have been written as "apply on equal" and
     * passed, which would have let a same-second `customer.subscription.deleted`
     * revoke a subscription the paired event had just confirmed.
     */
    public function test_a_same_instant_downgrade_on_the_same_rail_is_still_dropped(): void
    {
        Log::spy();

        $eventAt = CarbonImmutable::parse('2026-08-22 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_source_event_at' => $eventAt,
            'plan_product_id' => 'price_business_monthly',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Free,
            status: PlanStatus::Expired,
            provider: BillingProvider::Stripe,
            eventAt: $eventAt,
            authoritative: true,
            providerStatus: 'canceled',
        ));

        $this->assertFalse($applied);

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);

        $this->assertDropWasLogged($team, 'stripe', 'stripe', 'downgrade');
    }

    /**
     * RULE 2, a rail may only revoke what it granted: a downgrade arriving from
     * a rail OTHER than the one on record is dropped.
     *
     * The incoming event is strictly newer, so rule 1 cannot decide this one
     * and the provenance mismatch is the only thing that can. This is the
     * generalisation of the existing "an unmapped price never auto-downgrades"
     * precedent, and it is what stops a late Stripe
     * `customer.subscription.deleted` from revoking a store grant halfway
     * through a web-to-store migration, where BOTH rails legitimately hold a
     * record of the same customer.
     */
    public function test_a_cross_rail_downgrade_is_dropped(): void
    {
        Log::spy();

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'uptizm_business_monthly',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Free,
            status: PlanStatus::Canceled,
            provider: BillingProvider::Stripe,
            eventAt: $grantedAt->addMinute(),
            authoritative: true,
            providerStatus: 'canceled',
        ));

        $this->assertFalse($applied);

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame('uptizm_business_monthly', $team->plan_product_id);
        $this->assertTrue($grantedAt->equalTo($team->plan_source_event_at));

        $this->assertDropWasLogged($team, 'app_store', 'stripe', 'downgrade');
    }

    /**
     * A cross-rail UPGRADE is honoured, and warns.
     *
     * Two rails claiming the same customer at different tiers means somebody is
     * demonstrably paying twice. The resolution writes the HIGHER tier: they
     * paid for it, and refusing it would punish the double payment. Nothing
     * here attempts a refund, because no automated path can know which of the
     * two subscriptions the customer meant to keep.
     *
     * The warning is the whole point of the branch. Without it a double charge
     * is invisible until the customer notices, and an operator has to be told
     * to go and cancel one side by hand.
     */
    public function test_a_cross_rail_upgrade_is_honoured_and_warns(): void
    {
        Log::spy();

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');
        $eventAt = $grantedAt->addMinute();

        $team = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_pro',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Business,
            status: PlanStatus::Active,
            provider: BillingProvider::AppStore,
            eventAt: $eventAt,
            authoritative: true,
            providerStatus: 'ACTIVE',
            productId: 'uptizm_business_monthly',
        ));

        $this->assertTrue($applied);

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame('uptizm_business_monthly', $team->plan_product_id);
        $this->assertTrue($eventAt->equalTo($team->plan_source_event_at));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($team): bool {
                return $context['team_id'] === $team->id
                    && $context['stored_provider'] === 'stripe'
                    && $context['incoming_provider'] === 'app_store'
                    && $context['direction'] === 'upgrade';
            });
    }

    /**
     * RULE 2b: a PROJECTED write does not get to take over the record of a rail
     * that is still granting.
     *
     * Rule 2 above only stops a cross-rail REVOCATION, so this write used to
     * pass and `apply()` rewrote `plan_provider` unconditionally. The damage was
     * one step later, which is why no earlier test caught it: with the record
     * now naming Stripe, the Stripe cancellation that follows is SAME-rail, so
     * rule 2 can no longer see it and rule 1 lets it through. The team lands on
     * free while Apple is still charging, and two further guards disarm with it,
     * the team-delete `storeIsBilling()` check and the reconciler's choice of
     * which rail to re-read.
     *
     * The state is reachable without anything exotic: a customer who migrated
     * from web to store and did not cancel the web subscription is paying twice,
     * which is exactly the case the cross-rail warning exists to announce.
     *
     * The guard was first written as a blanket SAME-TIER drop, and its sibling
     * test above is why that was wrong: it also refused the store purchase that
     * performs the migration. What separates the two writes is not the tier, it
     * is that this one is assembled from the local Cashier row while that one is
     * a fresh read of the rail that took the money.
     */
    public function test_a_projected_cross_rail_write_cannot_take_over_the_record(): void
    {
        Log::spy();

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'uptizm_business_monthly',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Business,
            status: PlanStatus::Active,
            provider: BillingProvider::Stripe,
            eventAt: $grantedAt->addMinute(),
            authoritative: false,
            providerStatus: 'active',
            productId: 'price_business_monthly',
        ));

        $this->assertFalse($applied);

        $team->refresh();
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame('uptizm_business_monthly', $team->plan_product_id);
        $this->assertTrue($grantedAt->equalTo($team->plan_source_event_at));

        $this->assertDropWasLogged($team, 'app_store', 'stripe', 'same');
    }

    /**
     * A store selling the tier a customer already holds on Stripe is a MIGRATION,
     * and the record has to follow it.
     *
     * This is the sequence rule 2b got wrong when it was written as a blanket
     * same-tier drop. The store's purchase was refused, `plan_provider` stayed
     * `stripe`, and the Stripe cancellation that follows was then SAME-rail,
     * newer, and landed `plan = free` while Apple went on charging. Before rule
     * 2b existed the purchase landed and that same cancellation was cross-rail
     * DOWNGRADE, which rule 2 drops: the correct outcome. So the guard closed
     * one direction of the provenance problem by opening the other.
     *
     * What separates the two is not the tier and not the clock, it is how well
     * the writer knows its own claim. A store purchase is an AUTHORITATIVE read
     * of the rail that just took the money; the write that used to steal
     * provenance was a projection of local state. Only the first may move the
     * record.
     */
    public function test_an_authoritative_cross_rail_claim_takes_the_record_at_the_same_tier(): void
    {
        Log::spy();

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_business_monthly',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Business,
            status: PlanStatus::Active,
            provider: BillingProvider::AppStore,
            eventAt: $grantedAt->addMinute(),
            authoritative: true,
            providerStatus: 'ACTIVE',
            productId: 'uptizm_business_monthly',
        ));

        $this->assertTrue($applied, 'The store bought the tier and the record ignored it.');

        $team->refresh();
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame('uptizm_business_monthly', $team->plan_product_id);
    }

    /**
     * The control that decides how rule 2b is allowed to be written, and the
     * reason it asks about the stored STATUS rather than only the stored rail.
     *
     * `BillingProvider::grants()` is a per-RAIL table: it is true for every real
     * rail, so a rule 2b gated on it alone would drop this write. That would be
     * a customer buying Business in the App Store, for a team whose long-expired
     * Stripe record still names Business, and receiving nothing for it. Worse
     * than the defect rule 2b closes.
     *
     * An expired record is not an entitlement another rail can take over; it is
     * a slot another rail can fill, so the provenance moves.
     */
    public function test_a_cross_rail_same_tier_write_applies_when_the_stored_rail_has_lapsed(): void
    {
        Log::spy();

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');
        $eventAt = $grantedAt->addMinute();

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Expired->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_business_monthly',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Business,
            status: PlanStatus::Active,
            provider: BillingProvider::AppStore,
            eventAt: $eventAt,
            authoritative: true,
            providerStatus: 'ACTIVE',
            productId: 'uptizm_business_monthly',
        ));

        $this->assertTrue($applied);

        $team->refresh();
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);
        $this->assertSame('uptizm_business_monthly', $team->plan_product_id);
    }

    /**
     * The positive control: a strictly newer write from the rail on record
     * applies, and lands EVERY provenance column.
     *
     * The column sweep is here rather than in its own test because this is the
     * only path that writes at all. A field the action forgets has no other
     * symptom: the wire the client reads simply serves null forever, on a rail
     * whose data was present in the payload the whole time.
     *
     * The absent warning is a mutation guard. An implementation that logged a
     * warning on every write would satisfy the three tests above and say
     * nothing; asserting silence on the ordinary path is what makes those
     * warnings mean something.
     */
    public function test_a_newer_write_on_the_same_rail_applies_every_column(): void
    {
        Log::spy();

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');
        $eventAt = $grantedAt->addMinute();
        $periodEnd = CarbonImmutable::parse('2026-09-22 12:00:00');
        $graceEnd = CarbonImmutable::parse('2026-09-29 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => 'price_pro',
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Business,
            status: PlanStatus::PastDue,
            provider: BillingProvider::Stripe,
            eventAt: $eventAt,
            authoritative: true,
            providerStatus: 'past_due',
            productId: 'price_business',
            currentPeriodEnd: $periodEnd,
            renews: true,
            gracePeriodEndsAt: $graceEnd,
            manageUrl: 'https://billing.stripe.com/p/session/test_123',
        ));

        $this->assertTrue($applied);

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(PlanStatus::PastDue->value, $team->plan_status);
        $this->assertSame(BillingProvider::Stripe->value, $team->plan_provider);
        $this->assertTrue($eventAt->equalTo($team->plan_source_event_at));
        $this->assertSame('past_due', $team->plan_provider_status);
        $this->assertSame('price_business', $team->plan_product_id);
        $this->assertTrue($periodEnd->equalTo($team->plan_current_period_end));
        $this->assertTrue($team->plan_renews);
        $this->assertTrue($graceEnd->equalTo($team->plan_grace_period_ends_at));
        $this->assertSame('https://billing.stripe.com/p/session/test_123', $team->plan_manage_url);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * A cross-rail write whose tier the catalogue cannot rank is dropped.
     *
     * The tier order comes from `config('plans.tiers')`, so a tier missing from
     * that catalogue is unrankable and the direction of the write is genuinely
     * unknown. Unknown is not an upgrade: applying it could revoke a tier
     * another rail granted, which is the exact loss rule 2 exists to prevent.
     *
     * This is not a hypothetical branch. The catalogue carries an `enterprise`
     * row that no {@see Plan} case matches, so catalogue ids and plan cases
     * already disagree in production, and a `??` right-hand side no fixture
     * ever reaches is code nobody has run.
     */
    public function test_a_cross_rail_write_of_an_unrankable_tier_is_dropped(): void
    {
        Log::spy();

        // A catalogue that has lost the Business row: the stored tier can no
        // longer be ranked, so no incoming tier can be proven higher than it.
        config(['plans.tiers' => [
            ['id' => 'free'],
            ['id' => 'pro'],
            ['id' => 'enterprise'],
        ]]);

        $grantedAt = CarbonImmutable::parse('2026-08-22 12:00:00');

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
        ]);

        $applied = $this->write(new EntitlementWrite(
            team: $team,
            plan: Plan::Pro,
            status: PlanStatus::Active,
            provider: BillingProvider::Stripe,
            eventAt: $grantedAt->addMinute(),
            authoritative: true,
            providerStatus: 'active',
        ));

        $this->assertFalse($applied);

        $team->refresh();
        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);

        $this->assertDropWasLogged($team, 'app_store', 'stripe', 'unknown');
    }

    /**
     * Assert the drop was reported with every fact an operator needs to
     * reconstruct the decision: which team, both rails, both timestamps and
     * the direction the write would have moved the tier.
     */
    protected function assertDropWasLogged(
        Team $team,
        string $storedProvider,
        string $incomingProvider,
        string $direction,
    ): void {
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use (
                $team,
                $storedProvider,
                $incomingProvider,
                $direction,
            ): bool {
                return $context['team_id'] === $team->id
                    && $context['stored_provider'] === $storedProvider
                    && $context['incoming_provider'] === $incomingProvider
                    && $context['direction'] === $direction
                    && $context['stored_event_at'] !== null
                    && $context['incoming_event_at'] !== null;
            });
    }

    /**
     * Run one entitlement write through the container-resolved action.
     */
    protected function write(EntitlementWrite $write): bool
    {
        return app(WriteTeamEntitlement::class)($write);
    }

    /**
     * A team carrying whatever entitlement provenance the scenario needs.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTeam(array $attributes): Team
    {
        $user = User::factory()->create();

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Ops Team',
            'personal_team' => true,
            'stripe_id' => 'cus_'.Str::random(14),
            ...$attributes,
        ]);
    }
}
