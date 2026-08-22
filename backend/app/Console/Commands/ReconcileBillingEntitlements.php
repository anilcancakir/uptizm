<?php

namespace App\Console\Commands;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Jobs\SyncRevenueCatEntitlement;
use App\Models\Team;
use App\Services\Billing\RevenueCatClient;
use App\Support\Billing\EntitlementWrite;
use App\Support\Billing\StripeSubscriptionState;
use App\Support\TeamKey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use RuntimeException;

/**
 * The only thing that heals a dropped webhook.
 *
 * Both rails give up. RevenueCat retries a failed delivery five times inside
 * about three hours (5, 10, 20, 40 and 80 minutes) and then abandons the event
 * permanently; Stripe stops after roughly three days. Nothing after that point
 * ever mentions the event again, so the drift it left is permanent, and neither
 * rail's abandonment raises anything here. That makes the two failure modes
 * silent and opposite:
 *
 *  - a dropped `EXPIRATION` (or `customer.subscription.deleted`) is a paid tier
 *    held for free, forever, with nobody to complain;
 *  - a dropped `INITIAL_PURCHASE` is a paying customer stuck on the free tier
 *    with NO self-serve recovery, because the store will not sell them a
 *    subscription they already own.
 *
 * This command re-reads what each rail says and routes every correction through
 * {@see WriteTeamEntitlement}, which stays the single code path that writes the
 * entitlement columns. Every correction is logged at warning level: a
 * reconciler that silently fixes things is a reconciler that hides a broken
 * webhook, and the log line is the only evidence a delivery was ever missed.
 *
 * ## The two directions of wrongness are not symmetric
 *
 * Revoking too eagerly takes a tier away from somebody who is paying, on a
 * schedule, from every affected team at once. Correcting nothing leaves
 * pre-existing drift in place. So every ambiguity here resolves toward keeping
 * the entitlement, and in particular a failed authoritative READ is never a
 * revocation: {@see RevenueCatClient} raises rather than answering an empty
 * subscriber precisely so that a 503, a timeout or a missing API key can skip
 * the team instead of emptying it. Absence of a reason to grant is not a reason
 * to revoke.
 *
 * ## The two rails are read differently, and the asymmetry is principled
 *
 * The STORE rail has an authority to ask: `GET /v1/subscribers/{app_user_id}` is
 * the truth, freshly read, so its answer is applied unconditionally and the
 * mapping is not re-implemented here at all. {@see SyncRevenueCatEntitlement} is
 * invoked directly as the store rail's read-and-claim operation, because a
 * second liveness rule is the one thing this command must not invent: a
 * subscription entitles while EITHER `expires_date` OR
 * `grace_period_expires_date` reaches into the future, and two definitions of
 * that would eventually disagree about whether a customer is paid up.
 *
 * The STRIPE rail has no local authority. `subscriptions.stripe_status` and
 * `stripe_price` are themselves a projection of the same webhooks that may have
 * been dropped, so a local read cannot be fresher truth than the delivery that
 * wrote it. It therefore claims only when it DISAGREES with the entitlement on
 * record, which is real drift the webhook projection can leave behind (an
 * unmapped price at the time, a claim the ordering rules dropped, a manual
 * database edit). What a local read cannot do is heal a Stripe delivery that
 * never arrived, because Cashier's sync and the entitlement projection run in
 * one transaction and a dropped event leaves both stale. Closing that would
 * take a live Stripe read per team, and `Subscription::currentPeriodEnd()` is
 * one Stripe round-trip PER subscription item, so a loop over the fleet would
 * put thousands of synchronous calls behind an hourly schedule. The gap is
 * named rather than papered over.
 *
 * ## Which teams are walked
 *
 * The three rails that have something to re-read: `stripe`, `app_store` and
 * `play_store`. `manual` is deliberately excluded even though it is not `none`,
 * because an operator-granted plan has no authority to compare against and
 * every possible comparison would revoke it. An unrecognised provider value,
 * which is what an older deploy sees after a newer one ships a fourth rail, is
 * excluded by the same `whereIn`: a rail this build cannot read is a rail this
 * build must not correct.
 */
class ReconcileBillingEntitlements extends Command
{
    protected $signature = 'billing:reconcile
        {--team= : Reconcile a single team by id, for diagnosing one customer}';

    protected $description = 'Re-read each billing rail and correct any team entitlement that drifted';

    /**
     * Teams held in memory at once. `chunkById` keyset-paginates, so the walk is
     * stable across the writes it makes inside the callback.
     */
    protected const int CHUNK_SIZE = 100;

    /**
     * Cashier's subscription type. One per team in this product; the parameter
     * exists in Cashier for multi-plan billing that uptizm does not sell.
     */
    protected const string STRIPE_SUBSCRIPTION_TYPE = 'default';

    /**
     * The word this command leaves in `plan_provider_status` on the store rail.
     *
     * That column holds the RAIL's own last word, which on a store is the
     * webhook event type; a reconciled row has no event behind it, so it says so
     * instead. It gates nothing, and an operator reading it learns the useful
     * thing: this row was last written by the sweep, not by a delivery.
     */
    protected const string RECONCILED = 'RECONCILIATION';

    /**
     * How many teams were walked, corrected, and skipped without a correction.
     */
    protected int $walked = 0;

    protected int $corrected = 0;

    protected int $unreadable = 0;

    public function __construct(
        protected RevenueCatClient $revenueCat,
        protected WriteTeamEntitlement $writeEntitlement,
    ) {
        parent::__construct();
    }

    /**
     * Walk the readable rails and correct what drifted.
     *
     * Exits non-zero when at least one team's authoritative read FAILED, which
     * is the one outcome an operator needs to see from a hand-run `--team=`: the
     * entitlement was not checked, so the absence of a correction says nothing.
     * A config gap (an unmapped price, a team with no local subscription row) is
     * not a failure of this command and exits zero, having logged.
     */
    public function handle(): int
    {
        $only = $this->option('team');

        // A malformed id must not reach the query. `teams.id` is a PostgreSQL
        // `uuid` column, so a bad value in `where id = ?` raises there while
        // SQLite answers an empty set, which would make the default test engine
        // blind to a 500 production gets.
        if (is_string($only) && ! TeamKey::looksLikeOne($only)) {
            $this->error("[{$only}] cannot be a team key, so nothing was queried.");

            return self::FAILURE;
        }

        $this->onAReadableRail(is_string($only) ? $only : null)
            ->chunkById(self::CHUNK_SIZE, function (Collection $teams): void {
                foreach ($teams as $team) {
                    $this->reconcile($team);
                }
            });

        $this->info(sprintf(
            'Reconciled %d team(s): %d corrected, %d unreadable.',
            $this->walked,
            $this->corrected,
            $this->unreadable,
        ));

        return $this->unreadable === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The teams whose entitlement some rail can still be asked about.
     *
     * Candidates, not drift: which of them actually drifted is only knowable
     * after the rail has answered.
     *
     * `subscriptions` is eager-loaded because the Stripe half reads it per team
     * and Cashier's `subscription()` is a lazy relation access, which over a
     * fleet-wide walk is one query each.
     *
     * @return Builder<Team>
     */
    protected function onAReadableRail(?string $only): Builder
    {
        return Team::query()
            ->with('subscriptions')
            ->whereIn('plan_provider', [
                BillingProvider::Stripe->value,
                BillingProvider::AppStore->value,
                BillingProvider::PlayStore->value,
            ])
            ->when($only !== null, fn (Builder $query): Builder => $query->whereKey($only));
    }

    /**
     * Re-read one team's rail, then report what actually moved.
     *
     * The correction is measured as a BEFORE/AFTER diff of the row rather than
     * as the claim that was made, and the difference matters: the write action's
     * ordering rules can legitimately drop a claim, and a claim that was dropped
     * is not a correction. Reporting the diff means the log carries exactly the
     * changes that landed, while a dropped claim is reported by the action's own
     * warning with the rule that dropped it.
     */
    protected function reconcile(Team $team): void
    {
        $this->walked++;

        $provider = BillingProvider::fromWire($team->plan_provider);
        $before = $this->snapshot($team);

        $read = $provider->isStore()
            ? $this->reconcileStoreRail($team)
            : $this->reconcileStripeRail($team);

        if (! $read) {
            return;
        }

        $after = $this->snapshot($team->refresh());
        $changed = array_keys(array_filter(
            $after,
            fn (mixed $value, string $field): bool => $value !== $before[$field],
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return;
        }

        $this->corrected++;
        $this->reportCorrection($team, $provider, $before, $after, $changed);
    }

    /**
     * The store rail: read the authoritative subscriber and claim what it owes.
     *
     * Delegated to {@see SyncRevenueCatEntitlement} rather than reimplemented.
     * That job's body IS this operation (re-read one subscriber, map the tier,
     * claim it through the action); the only thing a webhook adds is the reason
     * to run it. Calling it here keeps ONE liveness rule, one sandbox gate, one
     * unmapped-product rule and one revocation rule in the codebase, which is
     * the property that decides whether a paying customer keeps their tier.
     *
     * Returns false when the read failed, which is the skip: the entitlement is
     * left exactly as it was.
     */
    protected function reconcileStoreRail(Team $team): bool
    {
        try {
            (new SyncRevenueCatEntitlement($this->reconciliationEvent($team)))
                ->handle($this->revenueCat, $this->writeEntitlement);
        } catch (ConnectionException|RequestException|RuntimeException $failure) {
            // The three failures {@see RevenueCatClient} documents: nothing
            // reached RevenueCat, RevenueCat answered non-2xx, or the rail is
            // unconfigured. Each one means "no answer", and no answer is not an
            // expired subscription. Caught per TEAM so one unreachable
            // subscriber does not cost every team behind it in the walk its
            // correction. Anything else is a defect rather than a rail failure
            // and is deliberately NOT caught: it stops the sweep loudly instead
            // of being filed as an unreadable subscriber.
            $this->unreadable++;

            Log::warning('A billing rail could not be read; entitlement left untouched.', [
                'reason' => 'authoritative_read_failed',
                'team_id' => $team->getKey(),
                'rail' => 'store',
                'exception' => $failure::class,
                'message' => $failure->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The store rail's synthetic event.
     *
     * Four fields, which is all {@see SyncRevenueCatEntitlement} reads: which
     * subscriber to re-read, when, the rail's word for what happened, and an id
     * for the log.
     *
     * `event_timestamp_ms` is `now()`, and that is honest HERE while it would be
     * a defect in a webhook feeder. The monotonic ordering rule compares the
     * moment a truth was ESTABLISHED, and for an authoritative re-read that
     * moment is the read itself; a feeder stamping receipt time instead of its
     * event's time would claim freshness it does not have. The consequence is
     * intended: a webhook whose event predates this run is dropped by the
     * action, because this run already read the rail's own state later than that
     * event was emitted. It costs at most one cadence of delay if RevenueCat's
     * API ever lags its own webhook.
     *
     * @return array<string, mixed>
     */
    protected function reconciliationEvent(Team $team): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => 'reconcile-'.$team->getKey().'-'.$now->getTimestampMs(),
            'type' => self::RECONCILED,
            'app_user_id' => (string) $team->getKey(),
            'event_timestamp_ms' => $now->getTimestampMs(),
        ];
    }

    /**
     * The Stripe rail, read locally and claimed only on disagreement.
     *
     * Returns true whenever the rail was READ, including when it had nothing to
     * say: none of the three skips below is a failure of this command, and none
     * of them may revoke.
     */
    protected function reconcileStripeRail(Team $team): bool
    {
        $subscription = $team->subscription(self::STRIPE_SUBSCRIPTION_TYPE);

        // An absent row is an ABSENCE, not a statement from Stripe that the
        // subscription ended, and the local table is itself a projection of
        // deliveries that may have been dropped. Revoking on it would empty the
        // tier of a migrated customer and of anybody whose Cashier row Stripe's
        // `incomplete_expired` branch deleted.
        if (! $subscription instanceof Subscription) {
            $this->skip($team, 'no_local_subscription', 'stripe', [
                'stored_plan' => $team->entitledPlan()->value,
            ]);

            return true;
        }

        // A row with no `updated_at` cannot be ordered against the stored
        // provenance, and the ordering rule is what keeps a stale write from
        // overwriting a fresh one. Required by the value object's type rather
        // than defensive: `timestamps()` leaves the column nullable.
        if (! $subscription->updated_at instanceof CarbonInterface) {
            $this->skip($team, 'undated_subscription_row', 'stripe', [
                'subscription_id' => $subscription->stripe_id,
            ]);

            return true;
        }

        $claim = $this->stripeClaim($team, $subscription, $subscription->updated_at);

        // Only a disagreement is claimed. A local read is no fresher than the
        // delivery that wrote it, so re-applying an agreeing one would stamp
        // this run's provenance over a genuine event's and then have the
        // ordering rule drop the NEXT run, once an hour, forever.
        if ($claim instanceof EntitlementWrite && ! $this->agreesWithRecord($team, $claim)) {
            ($this->writeEntitlement)($claim);
        }

        return true;
    }

    /**
     * What the local Cashier row says the team is owed, or null when it cannot
     * say.
     *
     * `$eventAt` is a parameter rather than read from the row here because the
     * caller has already established that the row HAS one, and re-deriving a
     * nullable value inside a method whose value object forbids null would make
     * this depend on a guard it cannot see.
     */
    protected function stripeClaim(
        Team $team,
        Subscription $subscription,
        CarbonInterface $eventAt,
    ): ?EntitlementWrite {
        $status = (string) $subscription->stripe_status;

        // A locally recorded non-granting status is a positive statement that
        // the subscription finished, so it really does revoke. That is the
        // asymmetry with the absent row above: this is an answer, not a gap.
        if (! StripeSubscriptionState::grants($status)) {
            return new EntitlementWrite(
                team: $team,
                plan: Plan::Free,
                status: StripeSubscriptionState::planStatusFor($status),
                provider: BillingProvider::Stripe,
                eventAt: $eventAt,
                providerStatus: $status,
                productId: $subscription->stripe_price,
                // A finished subscription has no period left to run and nothing
                // left to renew, matching what the webhook feeder writes on a
                // deletion.
                renews: false,
            );
        }

        // An unmapped price is a CONFIG gap, exactly as it is in the webhook
        // feeder: the absence of a reason to grant is not a reason to revoke.
        $plan = StripeSubscriptionState::planForPrice($subscription->stripe_price);

        if (! $plan instanceof Plan) {
            $this->skip($team, 'unmapped_price', 'stripe', [
                'price_id' => $subscription->stripe_price,
            ]);

            return null;
        }

        return new EntitlementWrite(
            team: $team,
            plan: $plan,
            status: StripeSubscriptionState::planStatusFor($status),
            provider: BillingProvider::Stripe,
            eventAt: $eventAt,
            providerStatus: $status,
            productId: $subscription->stripe_price,
            // The local row carries no period column, so the stored value is
            // carried forward: the action writes every column on each apply, so
            // passing null would BLANK a period a subscription event had already
            // established, and reading Cashier's accessor for the real one would
            // be a live Stripe call per subscription item.
            //
            // The webhook feeder guards this carry-forward with a "is Stripe the
            // rail on record" check, because an invoice can arrive for a team a
            // store rail has since taken over. Here that check would be dead
            // code: this branch is only reached for a team whose stored
            // `plan_provider` IS stripe, which is what selected it.
            currentPeriodEnd: $team->plan_current_period_end,
            // This one the local row does know: `ends_at` is Cashier's
            // cancellation-effective date, so a row that carries one will not
            // roll over.
            renews: $subscription->ends_at === null,
        );
    }

    /**
     * Whether a claim says exactly what the row already says.
     *
     * Compared through the same five fields {@see self::snapshot()} projects, so
     * that "agrees" and "was corrected" cannot mean two different things.
     */
    protected function agreesWithRecord(Team $team, EntitlementWrite $claim): bool
    {
        return $this->snapshot($team) === [
            'plan' => $claim->plan->value,
            'plan_status' => $claim->status->value,
            'plan_provider' => $claim->provider->value,
            'plan_current_period_end' => $claim->currentPeriodEnd?->toIso8601ZuluString(),
            'plan_renews' => $claim->renews,
        ];
    }

    /**
     * The entitlement's MEANING, as five comparable fields.
     *
     * Deliberately not the whole row. `plan_source_event_at` and
     * `plan_provider_status` are provenance and move on every store-rail read,
     * so including them would report a correction on every run; `plan_manage_url`
     * and `plan_product_id` are debug and navigation. What is left is what a
     * customer would notice: the tier, where it stands, who is billing it, when
     * the period ends and whether it rolls over. A dropped `RENEWAL` moves only
     * the last two, which is why they are in here rather than assumed harmless.
     *
     * Timestamps are compared as UTC ISO-8601 strings, which is also what the
     * log prints, so the field that reported a correction is the field that was
     * compared.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Team $team): array
    {
        return [
            'plan' => $team->entitledPlan()->value,
            'plan_status' => PlanStatus::fromWire($team->plan_status)->value,
            'plan_provider' => BillingProvider::fromWire($team->plan_provider)->value,
            'plan_current_period_end' => $team->plan_current_period_end?->toIso8601ZuluString(),
            'plan_renews' => $team->plan_renews,
        ];
    }

    /**
     * Report a correction, which is always a delivery that was missed.
     *
     * Warning level, and the wording says the cause rather than the symptom: an
     * entitlement that needed correcting means a webhook the application was
     * relying on never landed, and that is worth an operator's attention even
     * though the customer is now on the right tier. The `changed` list is what
     * separates a tier correction from a period correction at a glance.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $changed
     */
    protected function reportCorrection(
        Team $team,
        BillingProvider $provider,
        array $before,
        array $after,
        array $changed,
    ): void {
        Log::warning('Billing entitlement corrected by the reconciler; a rail delivery was missed.', [
            'reason' => 'entitlement_corrected',
            'team_id' => $team->getKey(),
            'rail' => $provider->isStore() ? 'store' : 'stripe',
            'provider' => $provider->value,
            'changed' => $changed,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Report a rail that was read and had nothing it could decide.
     *
     * Warning rather than info for the same reason the write action logs its
     * drops that way: each case means a paying customer's entitlement could not
     * be verified, and the two Stripe cases are both states an operator can fix
     * (fill in the price map, or look at why a team on the Stripe rail has no
     * subscription row).
     *
     * @param  array<string, mixed>  $context
     */
    protected function skip(Team $team, string $reason, string $rail, array $context = []): void
    {
        Log::warning('A billing rail had nothing to decide; entitlement left untouched.', [
            'reason' => $reason,
            'team_id' => $team->getKey(),
            'rail' => $rail,
            ...$context,
        ]);
    }
}
