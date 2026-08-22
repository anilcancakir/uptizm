<?php

namespace App\Actions\Billing;

use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Models\Team;
use App\Support\Billing\EntitlementWrite;
use Illuminate\Support\Facades\Log;

/**
 * The single code path that writes a team's entitlement columns.
 *
 * {@see Team::entitledPlan()} reads `teams.plan` as the one source of truth for
 * what a customer is owed, and more than one payment rail feeds that column. Two
 * feeders writing the same column unconditionally is not two features, it is a
 * race: whichever event is delivered last wins, and delivery order is a property
 * of the internet rather than of the truth. Every feeder therefore passes an
 * {@see EntitlementWrite} through here, and the two rules below decide.
 *
 * RULE 1, monotonic per rail. A write from the rail that already granted the
 * entitlement is dropped unless its event is strictly newer than the one on
 * record. This is not defensive coding, it is the documented delivery behaviour
 * of the rails: RevenueCat retries a failed webhook at 5, 10, 20, 40 and 80
 * minutes and can deliver a cancellation up to two hours late, so a promptly
 * delivered EXPIRATION genuinely does arrive before a RENEWAL whose first
 * attempt failed. Applied in delivery order, that sequence puts a paying team
 * on the free tier and leaves it there until somebody complains.
 *
 * RULE 2, a rail may only revoke what it granted. A downgrade whose provider
 * differs from the stored one is dropped. This generalises the rule the Stripe
 * feeder already carries, where an unmapped price is treated as a config gap
 * rather than as a downgrade: both say that the absence of a reason to grant is
 * not a reason to revoke. Concretely it is what stops a late Stripe
 * `customer.subscription.deleted` from cancelling a store grant during a
 * web-to-store migration, where both rails legitimately hold a record of the
 * same customer and only one of them is still being paid.
 *
 * Neither rule is symmetric, and that is deliberate. A write wrongly dropped
 * leaves a customer on a tier for longer than they paid for it, and a log line
 * says so; a write wrongly applied takes a tier away from somebody who is
 * paying. Every ambiguity here resolves toward keeping the entitlement.
 */
class WriteTeamEntitlement
{
    /**
     * Direction labels a write can move the entitlement in.
     */
    protected const string DIRECTION_UPGRADE = 'upgrade';

    protected const string DIRECTION_SAME = 'same';

    protected const string DIRECTION_DOWNGRADE = 'downgrade';

    protected const string DIRECTION_UNKNOWN = 'unknown';

    /**
     * The team holds no tier at all, so no write can move it in any direction.
     *
     * Distinct from `unknown`, and the distinction is load-bearing rather than
     * descriptive. `unknown` means the two tiers could not be compared and the
     * write MIGHT be a loss, which is why {@see self::revokes()} reads it as one.
     * This one means there is nothing on record to lose, so reading it as a
     * revocation would refuse every cross-rail write against a team that holds
     * nothing, including the purchase that grants it a tier for the first time.
     */
    protected const string DIRECTION_NOTHING_STORED = 'nothing-stored';

    /**
     * Apply one rail's claim to the team's entitlement columns.
     *
     * Returns true when the columns were written, false when a rule dropped the
     * write. Every false return has logged why.
     */
    public function __invoke(EntitlementWrite $write): bool
    {
        $team = $write->team;
        $storedProvider = BillingProvider::fromWire($team->plan_provider);
        $direction = $this->direction($write, $team);

        // RULE 1, monotonic per rail. An event OLDER than the one already on
        // record from the SAME rail is a late or re-ordered delivery, and the
        // record it would overwrite was written from a fresher truth.
        if ($storedProvider === $write->provider && $this->isOlderThanStored($write, $team)) {
            $this->logDrop('stale', $write, $storedProvider, $direction);

            return false;
        }

        // RULE 1b, a TIE resolves by direction rather than by arrival order.
        // Treating an equal timestamp as stale was a real defect: Stripe's
        // `created` is a Unix timestamp in SECONDS
        // ({@see \App\Http\Controllers\StripeWebhookController::eventAt()}), and
        // one plan swap emits `customer.subscription.updated` and
        // `invoice.payment_succeeded` from a single API call inside the same
        // second, in an order Stripe does not guarantee. Delivered
        // invoice-first, the invoice handler re-affirms the OLD tier from a
        // not-yet-resynced Cashier price, so the event carrying the tier the
        // customer actually bought arrived at the same second and lost, leaving
        // a payer on the cheaper plan until the hourly reconcile healed it.
        //
        // Resolving by what the write would DO rather than simply letting ties
        // win is what keeps this consistent with rule 2 below: a tie that would
        // TAKE the entitlement away still loses. "Away" is not only a lower
        // tier, and reading it that way left half the promise unkept. The pair
        // above can also agree on the tier and disagree about whether it still
        // grants, which ranks as SAME, so the cancelling half landed and a payer
        // lost access on a coin flip.
        if ($storedProvider === $write->provider
            && $this->isSameInstantAsStored($write, $team)
            && $this->takesAccessAway($direction, $write, $team)
        ) {
            $this->logDrop('same-instant revocation', $write, $storedProvider, $direction);

            return false;
        }

        // RULE 2, a rail may only revoke what it granted. A rail that did not
        // sell this entitlement cannot take it away, and a direction the
        // catalogue cannot rank counts as revocation for the same reason: an
        // unprovable upgrade applied cross-rail is a revocation with better
        // manners.
        if ($storedProvider !== $write->provider && $this->revokes($direction)) {
            $this->logDrop('cross-rail revocation', $write, $storedProvider, $direction);

            return false;
        }

        // RULE 2b, a PROJECTION may not take over the record of a rail that is
        // still granting.
        //
        // Rule 2 above only stops a cross-rail REVOCATION, so a cross-rail write
        // carrying the SAME tier passed and `apply()` then rewrote
        // `plan_provider` unconditionally. That handed the rail-on-record to a
        // rail that had not sold the subscription, and the damage was one step
        // later: the next revocation from that rail was now SAME-rail, so rule 2
        // could no longer see it and rule 1 let it through. Concretely, a team
        // billed by Apple that still held an uncancelled Stripe subscription got
        // its provenance moved by one `invoice.payment_succeeded`, and the Stripe
        // cancellation that followed wrote `plan = free` while Apple kept
        // charging. Two further guards disarmed with it: `storeIsBilling()` on
        // the team-delete path stopped seeing a store, and the reconciler began
        // re-reading Stripe and never RevenueCat, so nothing healed it.
        //
        // The status half is DEFENCE rather than a live requirement in THIS app:
        // `$storedProvider->grants()` is a per-RAIL table, true for every real
        // rail, and no feeder here stores a paid tier with a lapsed status
        // because every non-granting path writes a NULL plan in the same apply.
        // (It wrote `Plan::Free` until the sentinel was removed; the invariant is
        // the same one, and it is now stated by the column rather than by a tier
        // standing in for absence.) It stays because the starter's copy takes
        // `plan` and `status` from a consumer independently and CAN reach that
        // pair, and because the invariant is held by convention across four
        // feeders rather than by a constraint.
        //
        // The test is the WRITER's standing, not the tier and not the clock.
        //
        // This replaced a blanket same-tier drop, which closed one direction of
        // the problem by opening the other. A store selling the tier a customer
        // already holds on Stripe is a MIGRATION and the record has to follow
        // it; refusing it left `plan_provider` on Stripe, so the cancellation
        // that followed was SAME-rail, newer, and revoked a tier Apple was
        // still charging for. Both directions are the same question asked of
        // different writers, and the tier could never answer it.
        //
        // A PROJECTION may refresh what the record says and may never move who
        // it says is billing. It is assembled from something the rail wrote into
        // our database earlier, so it cannot testify to a handover: the invoice
        // re-affirmation reads a `stripe_price` Cashier may not have resynced,
        // and the hourly reconciler reads the same row. An AUTHORITATIVE claim
        // is the rail speaking now, and it takes the record.
        if ($storedProvider !== $write->provider
            && $storedProvider->grants()
            && $this->storedStatusStillGrants($team)
            && ! $write->authoritative
        ) {
            $this->logDrop('projected cross-rail takeover', $write, $storedProvider, $direction);

            return false;
        }

        // A second rail claiming a customer the first rail is still billing
        // means somebody is paying twice. The write lands, because it carries
        // the tier they paid the most for, but it cannot land quietly.
        if ($storedProvider !== $write->provider && $storedProvider->grants()) {
            $this->warnCrossRailGrant($write, $storedProvider, $direction);
        }

        $this->apply($write);

        return true;
    }

    /**
     * Whether the incoming event is STRICTLY older than the one that wrote the
     * stored entitlement, which is the only case rule 1 drops outright.
     *
     * A null stored timestamp means this rail has never written here, so there
     * is nothing for the incoming event to be older THAN and it applies.
     *
     * This used to be its own inverse, an `isNewerThanStored` whose docblock
     * argued that "equal timestamps carry no ordering information and the safe
     * reading is to keep what is already there", and named the cost: Stripe
     * stamps to the second, so two events on one customer inside one second let
     * only the first through. The reasoning was right and the conclusion was
     * wrong, because it assumed the loser of a tie is always the one that would
     * take something away. On the Stripe rail the loser is routinely a paid
     * UPGRADE, so "keep what is already there" kept the customer on the tier
     * they had just stopped paying for. A tie now goes to
     * {@see self::isSameInstantAsStored()} and is judged by direction.
     */
    protected function isOlderThanStored(EntitlementWrite $write, Team $team): bool
    {
        $stored = $team->plan_source_event_at;

        return $stored !== null && $write->eventAt->lessThan($stored);
    }

    /**
     * Whether the STATUS already on record is one that entitles the team.
     *
     * Distinct from {@see BillingProvider::grants()}, which answers "is this a
     * real billing rail" for every rail alive. This answers "is that rail
     * currently granting anything", which is the question rule 2b needs: a
     * stored record that has expired, been cancelled or paused is not an
     * entitlement another rail can take over, it is a slot another rail can
     * fill.
     */
    protected function storedStatusStillGrants(Team $team): bool
    {
        return PlanStatus::fromWire($team->plan_status)->grants();
    }

    /**
     * Whether the incoming event carries the SAME instant as the stored one.
     *
     * A tie is not evidence of late delivery; it is an absence of evidence
     * either way, which is why the caller resolves it with the same predicate
     * rule 2 uses instead of with arrival order.
     */
    protected function isSameInstantAsStored(EntitlementWrite $write, Team $team): bool
    {
        $stored = $team->plan_source_event_at;

        return $stored !== null && $write->eventAt->equalTo($stored);
    }

    /**
     * Which way this write would move the tier, in terms of the catalogue's own
     * order.
     *
     * Defined once and used by both rules, because "is this a downgrade" has to
     * mean exactly one thing: two definitions of it would eventually disagree,
     * and the disagreement would be a revocation.
     *
     * ABSENCE IS READ DIFFERENTLY ON THE TWO SIDES, and that asymmetry is the
     * whole reason this method reads the RAW `plan` column instead of
     * {@see Team::entitledPlan()}. That reader answers `Plan::Free` for a null
     * column, which is right for a display or a guard caller that needs a tier
     * to name, and fatal here: it would collapse "holds nothing" into "holds the
     * cheapest tier" and make a stored null rank like any other tier. Two
     * readers of one column with two different needs, named apart rather than
     * merged.
     */
    protected function direction(EntitlementWrite $write, Team $team): string
    {
        // 1. Nothing on record. Off the ladder rather than at the bottom of it,
        //    because a write cannot take away what the team does not hold.
        if ($team->plan === null) {
            return self::DIRECTION_NOTHING_STORED;
        }

        $stored = $this->tierRank($team->plan);

        if ($stored === null) {
            return self::DIRECTION_UNKNOWN;
        }

        // 2. A rail saying nothing is owed ranks BELOW every tier the catalogue
        //    names, so a revocation always reads as a downgrade. It has to be
        //    ranked rather than treated as unrankable: unrankable is a maybe, and
        //    a revocation is not a maybe. That is what the old `Plan::Free`
        //    sentinel got wrong in both directions at once, ranking SAME against
        //    a team already on `free` and UNKNOWN in a catalogue that publishes
        //    no `free` row at all.
        if ($write->plan === null) {
            return self::DIRECTION_DOWNGRADE;
        }

        $incoming = $this->tierRank($write->plan);

        if ($incoming === null) {
            return self::DIRECTION_UNKNOWN;
        }

        return match (true) {
            $incoming > $stored => self::DIRECTION_UPGRADE,
            $incoming < $stored => self::DIRECTION_DOWNGRADE,
            default => self::DIRECTION_SAME,
        };
    }

    /**
     * Whether a write moving the tier in this direction would take entitlement
     * away.
     *
     * `unknown` is grouped with `downgrade` rather than left to a default arm:
     * the two error directions are not symmetric here. Reading an unrankable
     * write as harmless lets it land, and if it was in fact a downgrade the
     * customer loses a tier they paid for; reading it as a revocation only
     * delays a legitimate change until an operator sees the log.
     *
     * `nothing-stored` is the one absence that is NOT grouped with them, for the
     * reason its own constant records: the asymmetry above is about what the
     * customer stands to lose, and a team holding no tier stands to lose nothing.
     * Grouping it here would have rule 2 refuse the first purchase of every team
     * whose plan column is null.
     */
    protected function revokes(string $direction): bool
    {
        return match ($direction) {
            self::DIRECTION_DOWNGRADE, self::DIRECTION_UNKNOWN => true,
            self::DIRECTION_UPGRADE, self::DIRECTION_SAME, self::DIRECTION_NOTHING_STORED => false,
        };
    }

    /**
     * Whether a write would leave the team holding LESS access than it has now.
     *
     * A tier can be taken away through either of two columns, and {@see
     * revokes()} only reads one of them. The Stripe pair described at rule 1b
     * shares a second, and a pair that keeps the tier while disagreeing about
     * whether it still grants ranks as SAME: the tie-break let the cancelling
     * half land, so the promise that a tie which would take the entitlement away
     * still loses held for a downgrade and not for a cancellation. Access is
     * what is being arbitrated, so the tie-break asks about access.
     *
     * Deliberately NOT folded into `revokes()`, which rule 2 also uses. Rule 2
     * asks whether a rail may revoke what ANOTHER rail granted, and a cross-rail
     * status change is already answered there by rule 2b's standing test.
     * Widening the shared predicate would move rule 2's behaviour as a side
     * effect of fixing rule 1b's.
     */
    protected function takesAccessAway(string $direction, EntitlementWrite $write, Team $team): bool
    {
        if ($this->revokes($direction)) {
            return true;
        }

        return ! $write->status->grants() && $this->storedStatusStillGrants($team);
    }

    /**
     * A tier's position in the plan catalogue, which is ordered cheapest-first.
     *
     * The order is read from `config('plans.tiers')` rather than from the enum's
     * case order on purpose: the catalogue is what the client walks for its
     * upgrade CTA, so ranking against anything else would let the two disagree
     * about which tier is higher.
     *
     * Null when the catalogue does not name the tier at all. The two id sets are
     * NOT the same set in either direction: the catalogue carries an
     * `enterprise` row no {@see Plan} case matches, and a catalogue edited to
     * drop a row would leave a live `teams.plan` value unrankable. An unrankable
     * tier has no direction, and callers must treat that as unknown rather than
     * as cheapest, which would read every write as an upgrade.
     */
    protected function tierRank(Plan $plan): ?int
    {
        $ids = array_column((array) config('plans.tiers', []), 'id');
        $rank = array_search($plan->value, $ids, true);

        return $rank === false ? null : (int) $rank;
    }

    /**
     * Report a dropped write with everything needed to reconstruct the decision.
     *
     * A drop is the action declining to change a customer's plan, so it is never
     * silent: without this line the only symptom is a support ticket saying the
     * plan did not change, and nothing to answer it with. Warning rather than
     * info for the same reason. Both drop reasons mean two sources disagreed
     * about a paying customer, which is not routine even though the retry
     * schedule that produces it is.
     *
     * Both plan fields are the RAW values {@see self::direction()} compared, not
     * what {@see Team::entitledPlan()} would answer. An operator reading this
     * line is reconstructing an arbitration decision, and a `stored_plan` of
     * `free` over a row holding NULL would show them a comparison that never
     * happened.
     */
    protected function logDrop(
        string $reason,
        EntitlementWrite $write,
        BillingProvider $storedProvider,
        string $direction,
    ): void {
        $team = $write->team;

        Log::warning('Entitlement write dropped; the stored entitlement stands.', [
            'reason' => $reason,
            'team_id' => $team->id,
            'stored_provider' => $storedProvider->value,
            'incoming_provider' => $write->provider->value,
            'stored_event_at' => $team->plan_source_event_at?->toIso8601String(),
            'incoming_event_at' => $write->eventAt->toIso8601String(),
            'stored_plan' => $team->plan?->value,
            'incoming_plan' => $write->plan?->value,
            'direction' => $direction,
        ]);
    }

    /**
     * Report a rail taking over an entitlement another rail is still billing.
     *
     * Warning level, and addressed to an operator rather than to code: nothing
     * automated can resolve this. Refunding the losing side is a money movement
     * no webhook handler should make on its own, and cancelling it needs to know
     * which subscription the customer meant to keep, which the payload cannot
     * say. So the write applies at the higher tier, the customer keeps what they
     * paid for, and a human is told there are two live subscriptions.
     */
    protected function warnCrossRailGrant(
        EntitlementWrite $write,
        BillingProvider $storedProvider,
        string $direction,
    ): void {
        $team = $write->team;

        Log::warning('Entitlement claimed by a second billing rail; the higher tier applied.', [
            'team_id' => $team->id,
            'stored_provider' => $storedProvider->value,
            'incoming_provider' => $write->provider->value,
            'stored_event_at' => $team->plan_source_event_at?->toIso8601String(),
            'incoming_event_at' => $write->eventAt->toIso8601String(),
            'stored_plan' => $team->plan?->value,
            'incoming_plan' => $write->plan?->value,
            'direction' => $direction,
        ]);
    }

    /**
     * Persist the claim: the entitlement itself plus the provenance that lets
     * the next write reason about this one.
     */
    protected function apply(EntitlementWrite $write): void
    {
        $write->team->forceFill([
            'plan' => $write->plan?->value,
            'plan_status' => $write->status->value,
            'plan_provider' => $write->provider->value,
            'plan_source_event_at' => $write->eventAt,
            'plan_provider_status' => $write->providerStatus,
            'plan_product_id' => $write->productId,
            'plan_current_period_end' => $write->currentPeriodEnd,
            'plan_renews' => $write->renews,
            'plan_grace_period_ends_at' => $write->gracePeriodEndsAt,
            'plan_manage_url' => $write->manageUrl,
        ])->save();
    }
}
