<?php

namespace App\Actions\Billing;

use App\Enums\BillingProvider;
use App\Enums\Plan;
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

        // RULE 1, monotonic per rail. An event older than the one already on
        // record from the SAME rail is a late or re-ordered delivery, and the
        // record it would overwrite was written from a fresher truth.
        if ($storedProvider === $write->provider && ! $this->isNewerThanStored($write, $team)) {
            $this->logDrop('stale', $write, $storedProvider, $direction);

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
     * Whether the incoming event is STRICTLY newer than the one that wrote the
     * stored entitlement.
     *
     * Strictly, not "newer or equal", because equal timestamps carry no
     * ordering information and the safe reading of "I cannot tell which came
     * first" is to keep what is already there. The cost is real and accepted:
     * Stripe stamps events to the second, so two events on one customer inside
     * the same second let only the first through.
     *
     * A null stored timestamp means this rail has never written here, so there
     * is nothing for the incoming event to be newer THAN and it applies.
     */
    protected function isNewerThanStored(EntitlementWrite $write, Team $team): bool
    {
        $stored = $team->plan_source_event_at;

        return $stored === null || $write->eventAt->greaterThan($stored);
    }

    /**
     * Which way this write would move the tier, in terms of the catalogue's own
     * order.
     *
     * Defined once and used by both rules, because "is this a downgrade" has to
     * mean exactly one thing: two definitions of it would eventually disagree,
     * and the disagreement would be a revocation.
     */
    protected function direction(EntitlementWrite $write, Team $team): string
    {
        $incoming = $this->tierRank($write->plan);
        $stored = $this->tierRank($team->entitledPlan());

        if ($incoming === null || $stored === null) {
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
     */
    protected function revokes(string $direction): bool
    {
        return match ($direction) {
            self::DIRECTION_DOWNGRADE, self::DIRECTION_UNKNOWN => true,
            self::DIRECTION_UPGRADE, self::DIRECTION_SAME => false,
        };
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
            'stored_plan' => $team->entitledPlan()->value,
            'incoming_plan' => $write->plan->value,
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
            'stored_plan' => $team->entitledPlan()->value,
            'incoming_plan' => $write->plan->value,
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
            'plan' => $write->plan->value,
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
