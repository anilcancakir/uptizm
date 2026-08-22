<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\ReconcileBillingEntitlements;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\RevenueCatClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

/**
 * The only thing that heals a dropped webhook.
 *
 * RevenueCat retries a failed delivery five times inside about three hours and
 * then abandons it; Stripe gives up after roughly three days. Nothing after
 * that point ever mentions the event again, so a single dropped `EXPIRATION` is
 * a paid tier held for free forever and a single dropped `INITIAL_PURCHASE` is a
 * paying customer stuck on the free tier with no self-serve way out. Neither
 * failure raises anything, which is why the reconciler exists and why every
 * correction it makes is logged at warning level: a reconciler that silently
 * fixes things is a reconciler that hides a broken webhook.
 *
 * ## The two directions of wrongness are not symmetric
 *
 * A reconciler that revokes too eagerly takes a tier away from somebody who is
 * paying, and it does it on a schedule, to every affected team at once. A
 * reconciler that corrects nothing merely leaves the pre-existing drift in
 * place. So the tests below are weighted toward the first: a failed
 * authoritative read, a Stripe team with no local subscription row, an unmapped
 * price and a `manual` grant each get a test asserting the entitlement was left
 * ALONE, because each one has a plausible implementation that revokes.
 *
 * ## The registration is asserted, not assumed
 *
 * A test that instantiates the command proves the class exists, which is not the
 * property that matters: an unscheduled command is an absent thing, and this
 * repo has shipped two of them (the weekly digest and the AI-suggestion prune,
 * both recorded in `routes/console.php`). The first test therefore reads the
 * scheduler and asserts the task's name AND its cron expression, so a
 * registration that silently drifts to daily is a failure rather than a
 * surprise.
 */
class ReconcileBillingEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fake RevenueCat key. The client refuses to call at all without one, and
     * no real secret belongs in a public repository.
     */
    protected const API_KEY = 'sk_test_revenuecat_secret';

    /**
     * The store's own management surface, the only source `plan_manage_url` has
     * on a store rail.
     */
    protected const MANAGE_URL = 'https://apps.apple.com/account/subscriptions';

    /**
     * The store product and the Stripe price the maps below resolve, standing in
     * for the ids a human will create in App Store Connect and in Stripe.
     */
    protected const STORE_BUSINESS = 'uptizm_business_monthly';

    protected const STRIPE_PRICE_BUSINESS = 'price_business_monthly';

    protected function setUp(): void
    {
        parent::setUp();

        // Every fixture decides "does this subscription still entitle" against
        // `now()`, so the clock is pinned. Without it a period end a month wide
        // is in the future today and in the past next month, and the suite would
        // start failing on a date rather than on a change.
        $this->travelTo($this->now());

        config([
            'revenuecat.secret_api_key' => self::API_KEY,
            'revenuecat.base_url' => RevenueCatClient::DEFAULT_BASE_URL,
            'plans.store_products' => [
                self::STORE_BUSINESS => Plan::Business->value,
            ],
            'cashier.plans' => [
                self::STRIPE_PRICE_BUSINESS => Plan::Business->value,
            ],
        ]);
    }

    /**
     * The registration itself, name and cadence both.
     *
     * Hourly is the claim being pinned, not merely "scheduled": RevenueCat
     * abandons a delivery after about three hours, so a daily sweep would leave
     * a dropped `EXPIRATION` un-healed for most of a day, and the cadence is the
     * whole reason the reconciler is worth running at all.
     */
    public function test_the_reconciler_is_scheduled_hourly(): void
    {
        $all = $this->app->make(Schedule::class)->events();

        // Named here rather than left to the filter below: an empty schedule is
        // what `routes/console.php` failing to load looks like, and without this
        // the failure would read "billing:reconcile is not scheduled" and send a
        // reader looking for a deleted registration.
        $this->assertNotEmpty(
            $all,
            'The scheduler holds no events at all, so routes/console.php did not load in this context.',
        );

        $events = collect($all)
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'billing:reconcile'));

        $this->assertCount(
            1,
            $events,
            'The billing:reconcile command is not scheduled, so no dropped webhook is ever healed.',
        );

        $this->assertSame(
            '0 * * * *',
            $events->first()->expression,
            'The reconciler must run hourly: RevenueCat abandons a delivery after about three hours.',
        );

        $this->assertSame(
            'billing:reconcile',
            (string) $events->first()->description,
            'The scheduled task needs the name Tests\Feature\Console\ScheduleTest pins it by.',
        );
    }

    /**
     * The QA scenario: a paid tier on record, an authoritative read that says
     * the subscription expired, and nothing in between but this command.
     */
    public function test_an_expired_store_subscription_drops_the_entitlement_and_says_so(): void
    {
        $team = $this->storeTeam();

        $this->fakeReads([
            $team->id => $this->subscriber([
                self::STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->now()->subDay()->toIso8601ZuluString(),
                    'unsubscribe_detected_at' => null,
                ]),
            ]),
        ]);

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $team->refresh();

        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame(PlanStatus::Expired->value, $team->plan_status);
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertNull($team->plan_current_period_end);
        $this->assertFalse($team->plan_renews);

        $this->assertWarnedOnce([
            'reason' => 'entitlement_corrected',
            'team_id' => $team->id,
            'rail' => 'store',
            'changed' => ['plan', 'plan_status', 'plan_current_period_end', 'plan_renews'],
            'before.plan' => Plan::Business->value,
            'before.plan_status' => PlanStatus::Active->value,
            'after.plan' => Plan::Free->value,
            'after.plan_status' => PlanStatus::Expired->value,
        ]);
    }

    /**
     * The other direction, and the one nobody notices: a dropped
     * `INITIAL_PURCHASE` leaves a paying customer on the free tier, and there is
     * no self-serve recovery because the store will not sell them the
     * subscription they already own.
     */
    public function test_a_live_store_subscription_restores_a_team_stuck_on_free(): void
    {
        $team = $this->storeTeam([
            'plan' => Plan::Free->value,
            'plan_status' => PlanStatus::None->value,
            'plan_current_period_end' => null,
            'plan_renews' => null,
        ]);

        $this->fakeReads([
            $team->id => $this->subscriber([
                self::STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);
        $this->assertSame(self::MANAGE_URL, $team->plan_manage_url);

        $this->assertWarnedOnce([
            'reason' => 'entitlement_corrected',
            'team_id' => $team->id,
            'before.plan' => Plan::Free->value,
            'after.plan' => Plan::Business->value,
        ]);
    }

    /**
     * Idempotence, measured as the absence of a SECOND correction rather than
     * as the absence of a second write.
     *
     * The store rail re-applies its authoritative read on every run by design
     * (it is the freshest truth there is, and re-applying it heals a stale
     * period as well as a stale tier), so "wrote nothing" would be the wrong
     * assertion. What must not happen twice is a CORRECTION: the second run has
     * nothing left to correct, so a warning on it would be the reconciler
     * reporting drift it invented.
     *
     * The clock advances by the cadence between the runs, because that is what
     * the schedule does and because the monotonic ordering rule is measured in
     * time: two runs inside one millisecond would have the second one's claim
     * dropped as stale, which would make this test pass for a reason production
     * never has. `plan_source_event_at` is asserted to have MOVED for the same
     * reason, so "corrected nothing" cannot be satisfied by a claim that was
     * silently dropped instead of applied.
     */
    public function test_a_second_consecutive_run_corrects_nothing(): void
    {
        $team = $this->storeTeam();

        $this->fakeReads([
            $team->id => $this->subscriber([
                self::STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->now()->subDay()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $afterFirstRun = $team->refresh()->getAttributes();

        $this->travelTo($this->now()->addHour());

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $team->refresh();

        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame($afterFirstRun['plan_status'], $team->plan_status);
        $this->assertSame($afterFirstRun['plan_provider'], $team->plan_provider);
        $this->assertTrue(
            $team->plan_source_event_at->greaterThan($afterFirstRun['plan_source_event_at']),
            'The second run must have applied its read; a dropped claim would make this test vacuous.',
        );

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * The failure that matters most. A 503 is not an answer, and reading it as
     * "nothing is owed" would revoke every paying team on the store rail at once
     * the moment RevenueCat had an outage.
     *
     * {@see RevenueCatClient} raises rather than returning an empty subscriber
     * precisely so this is possible; the reconciler must let the raise stop that
     * TEAM and not the sweep, and must never treat it as a revocation.
     */
    public function test_a_failed_authoritative_read_skips_the_team_rather_than_revoking_it(): void
    {
        $team = $this->storeTeam();

        Http::fake(fn (Request $request) => Http::response(['message' => 'service unavailable'], 503));

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::FAILURE);

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'A failed read must never revoke an entitlement.');
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);

        $this->assertWarnedOnce([
            'reason' => 'authoritative_read_failed',
            'team_id' => $team->id,
            'rail' => 'store',
        ]);
    }

    /**
     * The sweep continues past a team it could not read.
     *
     * A single unreachable subscriber must not cost every team behind it in the
     * walk its correction, which is what an uncaught raise inside the chunk
     * callback would do.
     */
    public function test_one_unreadable_team_does_not_stop_the_sweep(): void
    {
        $unreadable = $this->storeTeam();
        $drifted = $this->storeTeam();

        $this->fakeReads([
            $drifted->id => $this->subscriber([
                self::STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->now()->subDay()->toIso8601ZuluString(),
                ]),
            ]),
        ], notFoundStatus: 503);

        $this->artisan('billing:reconcile')->assertExitCode(Command::FAILURE);

        $this->assertSame(Plan::Business, $unreadable->refresh()->plan);
        $this->assertSame(Plan::Free, $drifted->refresh()->plan);
    }

    /**
     * The Stripe rail, from a purely local read: the Cashier row says the
     * subscription was canceled and ran out, while `teams.plan` still says
     * business.
     *
     * This is drift the webhook projection can genuinely leave behind. An
     * unmapped price, a cross-rail drop or a manual database edit each end with
     * a canceled `subscriptions` row and an untouched entitlement, and none of
     * them will ever be mentioned again by Stripe.
     */
    public function test_a_canceled_local_stripe_subscription_drops_the_entitlement(): void
    {
        $team = $this->stripeTeam();

        $this->makeSubscription($team, [
            'stripe_status' => 'canceled',
            'ends_at' => $this->now()->subDay(),
        ]);

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $team->refresh();

        $this->assertSame(Plan::Free, $team->plan);
        $this->assertSame(PlanStatus::Canceled->value, $team->plan_status);
        $this->assertSame(BillingProvider::Stripe->value, $team->plan_provider);

        $this->assertWarnedOnce([
            'reason' => 'entitlement_corrected',
            'team_id' => $team->id,
            'rail' => 'stripe',
            'before.plan' => Plan::Business->value,
            'after.plan' => Plan::Free->value,
        ]);
    }

    /**
     * A Stripe rail that AGREES with the record is not written at all, and this
     * is the assertion that keeps the reconciler quiet.
     *
     * A local Cashier row is no fresher than the delivery that wrote it, so
     * re-applying an agreeing read would stamp this run's provenance over a real
     * event's, once an hour, for every Stripe team, forever.
     *
     * The mechanism behind that used to be a drop: the claim carried the Cashier
     * row's `updated_at`, so the next run's identical claim tied with the stamp
     * and the ordering rule refused it, loudly, which is how a log stops being
     * read. Since the claim is stamped `now()` it is strictly newer instead, so
     * an unconditional write would be APPLIED silently rather than refused
     * noisily. The cost changed shape; `agreesWithRecord()` is what prevents it
     * either way, and this test is what pins it.
     *
     * `plan_source_event_at` is what proves nothing was written: the entitlement
     * columns would be unchanged either way, which is exactly why asserting them
     * would pass with the guard deleted.
     */
    public function test_a_stripe_rail_that_agrees_with_the_record_is_not_written(): void
    {
        $team = $this->stripeTeam();

        $this->makeSubscription($team);

        $stamp = $team->plan_source_event_at;

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);
        $this->travelTo($this->now()->addHour());
        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan);
        $this->assertTrue(
            $team->plan_source_event_at->equalTo($stamp),
            'An agreeing local read must not restamp the provenance a real Stripe event wrote.',
        );

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * A local Cashier row whose price is not in `cashier.plans` is a CONFIG gap,
     * and the absence of a reason to grant is not a reason to revoke. Same rule
     * the webhook feeder already applies to an unmapped price.
     */
    public function test_an_unmapped_stripe_price_never_downgrades_the_payer(): void
    {
        $team = $this->stripeTeam();

        $this->makeSubscription($team, ['stripe_price' => 'price_nobody_mapped']);

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $this->assertSame(Plan::Business, $team->refresh()->plan);

        $this->assertWarnedOnce([
            'reason' => 'unmapped_price',
            'team_id' => $team->id,
            'price_id' => 'price_nobody_mapped',
        ]);
    }

    /**
     * A team on the Stripe rail with no local subscription row at all.
     *
     * The absence of a row is an ABSENCE, not a statement from Stripe that the
     * subscription ended, and the local table is a projection of the same
     * webhooks that may have been dropped. Revoking here would take the tier off
     * a manually migrated customer, and off anybody whose Cashier row was
     * deleted by the `incomplete_expired` branch while their entitlement was
     * granted by something else.
     */
    public function test_a_stripe_team_with_no_local_subscription_is_skipped_rather_than_revoked(): void
    {
        $team = $this->stripeTeam();

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $this->assertSame(Plan::Business, $team->refresh()->plan);

        $this->assertWarnedOnce([
            'reason' => 'no_local_subscription',
            'team_id' => $team->id,
            'rail' => 'stripe',
        ]);
    }

    /**
     * The team every existing paying customer actually IS: `plan_provider` NULL.
     *
     * The provenance migration adds all eight columns nullable and backfills
     * nothing on purpose, so on the day this command first ran against real data
     * every paying team's provenance was NULL, and `whereIn` is satisfied by no
     * NULL on either engine. The dropped `INITIAL_PURCHASE` this command exists
     * to heal leaves the same NULL by construction, so the filter excluded
     * exactly the rows the command was written for.
     *
     * A non-null `stripe_id` is the signal here, deliberately WITHOUT a
     * subscription row, so this test pins the `stripe_id` half of the
     * disjunction on its own. What proves the walk is the count the command
     * reports plus the skip it logs: a team that was never selected reports
     * neither.
     */
    public function test_a_null_provenance_team_with_a_stripe_customer_id_is_walked(): void
    {
        $team = $this->unprovenancedTeam();

        Http::preventStrayRequests();

        Log::spy();

        $this->artisan('billing:reconcile')
            ->expectsOutputToContain('Reconciled 1 team(s)')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(Plan::Business, $team->refresh()->plan);

        $this->assertWarnedOnce([
            'reason' => 'no_local_subscription',
            'team_id' => $team->id,
            'rail' => 'stripe',
        ]);
    }

    /**
     * The other half of the local signal, and the one that actually heals.
     *
     * `stripe_id` is NULL here, so the Cashier row is the only thing selecting
     * the team, which is what keeps this test from passing through the arm the
     * test above already pins. The row grants the business tier while the record
     * says free, which is a paying customer stuck on the free tier: the exact
     * state a dropped delivery leaves behind and the reason a widened selection
     * is worth anything at all.
     */
    public function test_a_null_provenance_team_with_a_local_subscription_row_is_healed(): void
    {
        $team = $this->unprovenancedTeam([
            'plan' => Plan::Free->value,
            'plan_status' => PlanStatus::None->value,
            'stripe_id' => null,
        ]);

        $this->makeSubscription($team);

        Http::preventStrayRequests();

        Log::spy();

        $this->artisan('billing:reconcile')
            ->expectsOutputToContain('Reconciled 1 team(s)')
            ->assertExitCode(Command::SUCCESS);

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan);
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);
        $this->assertSame(BillingProvider::Stripe->value, $team->plan_provider);

        $this->assertWarnedOnce([
            'reason' => 'entitlement_corrected',
            'team_id' => $team->id,
            'rail' => 'stripe',
            'before.plan' => Plan::Free->value,
            'after.plan' => Plan::Business->value,
        ]);
    }

    /**
     * NULL provenance is not on its own a reason to walk a team.
     *
     * Without a `stripe_id` and without a Cashier row there is nothing any rail
     * can be asked about: the store rail has no local signal to select on at all
     * (`app_user_id` only ever arrives on an event), and the Stripe rail reads
     * the Cashier row that is absent. Walking such a team would put an hourly
     * `no_local_subscription` warning against every team that never bought
     * anything, which is the whole free tier.
     *
     * The count is the assertion rather than the untouched columns: a team with
     * nothing to reconcile ends the run untouched whether it was walked or not,
     * so asserting the columns would pass with the selection wide open.
     */
    public function test_a_null_provenance_team_with_no_local_billing_signal_is_never_walked(): void
    {
        $team = $this->unprovenancedTeam(['stripe_id' => null]);

        Http::preventStrayRequests();

        Log::spy();

        $this->artisan('billing:reconcile')
            ->expectsOutputToContain('Reconciled 0 team(s)')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(Plan::Business, $team->refresh()->plan);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * An operator-granted plan has no rail to re-read, so the reconciler must
     * not walk it at all. The operator IS the authority; there is nothing to
     * compare against and every possible comparison would revoke.
     */
    public function test_a_manual_grant_is_never_touched(): void
    {
        $team = $this->storeTeam(['plan_provider' => BillingProvider::Manual->value]);

        Http::preventStrayRequests();

        Log::spy();

        $this->artisan('billing:reconcile')->assertExitCode(Command::SUCCESS);

        $this->assertSame(Plan::Business, $team->refresh()->plan);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * `--team=` exists so an operator diagnosing one customer does not have to
     * sweep the fleet, and the assertion that matters is the negative one: the
     * OTHER drifted team is left exactly as it was.
     */
    public function test_the_team_option_reconciles_only_that_team(): void
    {
        $target = $this->storeTeam();
        $bystander = $this->storeTeam();

        $expired = $this->subscriber([
            self::STORE_BUSINESS => $this->subscription([
                'expires_date' => $this->now()->subDay()->toIso8601ZuluString(),
            ]),
        ]);

        $this->fakeReads([
            $target->id => $expired,
            $bystander->id => $expired,
        ]);

        $this->artisan('billing:reconcile', ['--team' => $target->id])->assertExitCode(Command::SUCCESS);

        $this->assertSame(Plan::Free, $target->refresh()->plan);
        $this->assertSame(Plan::Business, $bystander->refresh()->plan);
    }

    /**
     * A malformed `--team=` is refused before it reaches a query.
     *
     * `teams.id` is a PostgreSQL `uuid` column, so a malformed value in
     * `where id = ?` is a 500 there and a clean empty result on SQLite. Without
     * the guard the default test engine could not see the failure production
     * would get, which is a trap this repository has already paid for once.
     */
    public function test_a_malformed_team_option_is_refused_before_it_reaches_the_query(): void
    {
        $this->artisan('billing:reconcile', ['--team' => 'not-a-team-key'])
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * The command's signature, pinned so the schedule entry and an operator's
     * muscle memory cannot drift apart from it.
     */
    public function test_the_command_is_named_billing_reconcile(): void
    {
        $this->assertSame(
            'billing:reconcile',
            $this->app->make(ReconcileBillingEntitlements::class)->getName(),
        );
    }

    /**
     * A team on a store rail holding the business tier, which is the state every
     * store test starts from unless it says otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function storeTeam(array $attributes = []): Team
    {
        return $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $this->now()->subDays(2),
            'plan_provider_status' => 'RENEWAL',
            'plan_product_id' => self::STORE_BUSINESS,
            'plan_current_period_end' => $this->now()->addMonth(),
            'plan_renews' => true,
            ...$attributes,
        ]);
    }

    /**
     * The same, on the Stripe rail.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function stripeTeam(array $attributes = []): Team
    {
        return $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::Stripe->value,
            'plan_source_event_at' => $this->now()->subDays(2),
            'plan_provider_status' => 'active',
            'plan_product_id' => self::STRIPE_PRICE_BUSINESS,
            'plan_current_period_end' => $this->now()->addMonth(),
            'plan_renews' => true,
            ...$attributes,
        ]);
    }

    /**
     * A team holding the business tier with NO provenance at all, which is what
     * every row on this table looked like before the first rail event landed.
     *
     * All eight provenance columns are NULL together rather than only
     * `plan_provider`: they are written in one apply, so a row carrying a
     * product id and no provider is a state no feeder can produce, and a fixture
     * that invents one would test a shape production never has.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function unprovenancedTeam(array $attributes = []): Team
    {
        return $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => null,
            'plan_source_event_at' => null,
            'plan_provider_status' => null,
            'plan_product_id' => null,
            'plan_current_period_end' => null,
            'plan_renews' => null,
            ...$attributes,
        ]);
    }

    /**
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

    /**
     * A persisted Cashier subscription row, which is the whole of what a local
     * Stripe read can see.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSubscription(Team $team, array $attributes = []): Subscription
    {
        return Subscription::query()->create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(10),
            'stripe_status' => 'active',
            'stripe_price' => self::STRIPE_PRICE_BUSINESS,
            ...$attributes,
        ]);
    }

    /**
     * Answer `GET /subscribers/{app_user_id}` per team, and decide what an
     * unlisted subscriber gets.
     *
     * A 404 is the default because RevenueCat answers one for a subscriber it
     * has never seen and the client treats it as a permanent answer;
     * `notFoundStatus: 503` turns the same listener into the transient failure
     * the skip path is measured against.
     *
     * @param  array<string, array<string, mixed>>  $byAppUserId
     */
    protected function fakeReads(array $byAppUserId, int $notFoundStatus = 404): void
    {
        Http::fake(function (Request $request) use ($byAppUserId, $notFoundStatus) {
            $appUserId = urldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));

            if (! array_key_exists($appUserId, $byAppUserId)) {
                return Http::response(['message' => "unlisted app_user_id [{$appUserId}]"], $notFoundStatus);
            }

            return Http::response($byAppUserId[$appUserId], 200);
        });
    }

    /**
     * One `GET /subscribers` body.
     *
     * @param  array<string, array<string, mixed>>  $subscriptions
     * @return array<string, mixed>
     */
    protected function subscriber(array $subscriptions): array
    {
        return [
            'subscriber' => [
                'original_app_user_id' => 'irrelevant-to-this-command',
                'first_seen' => $this->now()->subYear()->toIso8601ZuluString(),
                'management_url' => self::MANAGE_URL,
                'subscriptions' => $subscriptions,
                'entitlements' => [],
                'non_subscriptions' => [],
            ],
        ];
    }

    /**
     * One live, production, App Store subscription in RevenueCat's own shape.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function subscription(array $overrides = []): array
    {
        return [
            'expires_date' => $this->now()->addMonth()->toIso8601ZuluString(),
            'grace_period_expires_date' => null,
            'unsubscribe_detected_at' => null,
            'billing_issues_detected_at' => null,
            'refunded_at' => null,
            'auto_resume_date' => null,
            'is_sandbox' => false,
            'store' => 'app_store',
            'period_type' => 'normal',
            'ownership_type' => 'PURCHASED',
            'purchase_date' => $this->now()->subMonth()->toIso8601ZuluString(),
            ...$overrides,
        ];
    }

    /**
     * Assert exactly one warning whose context matches every given path.
     *
     * `->once()` is load-bearing rather than tidy: it is what proves no SECOND
     * warning fired, which is how a correction logged for the right reason is
     * distinguished from one logged alongside a skip nobody intended. Keys are
     * `data_get` paths, so `before.plan` reaches into the snapshot.
     *
     * @param  array<string, mixed>  $context
     */
    protected function assertWarnedOnce(array $context): void
    {
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $actual) use ($context): bool {
                foreach ($context as $path => $expected) {
                    if (data_get($actual, $path) !== $expected) {
                        return false;
                    }
                }

                return true;
            });
    }

    /**
     * The instant every fixture is measured against.
     */
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-22 12:00:00');
    }
}
