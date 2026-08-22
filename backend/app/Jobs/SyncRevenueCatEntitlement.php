<?php

namespace App\Jobs;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Models\Team;
use App\Services\Billing\RevenueCatClient;
use App\Support\Billing\EntitlementWrite;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The store rail's feeder for the team entitlement column.
 *
 * A RevenueCat webhook is a dirty signal, not a source. The event says
 * something about a subscriber changed; WHAT they are now entitled to is read
 * back from `GET /v1/subscribers/{app_user_id}` ({@see RevenueCatClient}) and
 * claimed through {@see WriteTeamEntitlement}, which is the only code path
 * allowed to write those columns.
 *
 * ## The event type does not decide the tier. That is the whole design.
 *
 * Six of the event types that reach this job can change an entitlement, and
 * four of them mean the opposite of what their name suggests:
 *
 *  - `CANCELLATION` is auto-renew switched off. The customer has paid through
 *    the end of the period and is still entitled. It does NOT revoke.
 *  - `BILLING_ISSUE` is a charge that failed and a store that is retrying, with
 *    a grace window attached. It does NOT revoke.
 *  - `SUBSCRIPTION_PAUSED` (Google Play only) carries a resume date, and the
 *    pause takes effect at the end of the paid period. It does NOT revoke.
 *  - `PRODUCT_CHANGE` announces a change that is NOT yet in effect; the new
 *    product starts at the next renewal, which arrives later as its own
 *    `RENEWAL`. Writing its tier downgrades somebody who has not been
 *    downgraded.
 *
 * Rather than four exceptions, there is one rule: nothing in this job reads a
 * tier, a product id or a period out of the event. It reads WHICH subscribers to
 * re-read (and when the event happened, for ordering), then maps everything else
 * from the authoritative response. `EXPIRATION` revokes not because of its name
 * but because by the time it is delivered the authoritative read shows nothing
 * live.
 *
 * ## Every ambiguity resolves toward keeping the entitlement
 *
 * A tier held a little too long costs a log line; a tier taken from somebody who
 * is paying costs a support ticket and their trust. So: an unmapped product
 * warns and writes nothing (the same rule the Stripe feeder applies to an
 * unmapped price); a subscriber whose only subscriptions are sandbox purchases
 * writes nothing; a subscription whose dates cannot be read writes nothing; a
 * failed read raises so the queue retries instead of revoking. Only an
 * authoritative read that shows nothing live revokes, and then only on the store
 * rail that granted it.
 *
 * ## Idempotency is not this job's problem
 *
 * It needs none of its own. RevenueCat reuses the `id` and the
 * `event_timestamp_ms` on every retry, the webhook endpoint dedupes on the id,
 * and the write action drops any claim whose event is not strictly newer than
 * the one already on record for the same rail. A second mechanism here would
 * only be a second thing to get wrong.
 */
class SyncRevenueCatEntitlement implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The queue this job rides.
     *
     * `default`, because `supervisor-1` already drains it (config/horizon.php)
     * and the composer `dev` script's listener already names it. A `billing`
     * queue would need a supervisor in the same breath, and a queue with no
     * supervisor is a job that dispatches successfully and never runs.
     */
    public const string QUEUE = 'default';

    /**
     * RevenueCat abandons a delivery after five attempts inside about three
     * hours, so a read that fails transiently must be retried here rather than
     * left to the reconciler: the alternative is a paying customer on the free
     * tier until the next sweep.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Under the 60-second `supervisor-1` worker timeout, and comfortably above
     * the operation budget of the reads it makes ({@see RevenueCatClient}: 10
     * seconds each). A `TRANSFER` re-reads both sides, so the arithmetic has to
     * hold for a handful of reads rather than one.
     *
     * @var int
     */
    public $timeout = 55;

    /**
     * @param  array<string, mixed>  $event  the RevenueCat webhook `event`
     *                                       object. FOUR fields are read from it
     *                                       and no others: `app_user_id`,
     *                                       `transferred_to`/`transferred_from`
     *                                       (which subscribers to re-read),
     *                                       `event_timestamp_ms` (the ordering
     *                                       key) and `type` (recorded verbatim as
     *                                       the rail's own word). Not the tier,
     *                                       not the product, not the period.
     */
    public function __construct(protected array $event)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Wait a minute, then five, before giving up. A store's API and our own
     * network are the failures being waited out here.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Re-read every subscriber this event touches and claim what each one is
     * owed.
     */
    public function handle(RevenueCatClient $revenueCat, WriteTeamEntitlement $writeEntitlement): void
    {
        foreach ($this->appUserIds() as $appUserId) {
            $team = $this->resolveTeam($appUserId);

            if (! $team instanceof Team) {
                continue;
            }

            $claim = $this->claimFor($team, $revenueCat->subscriber($appUserId));

            if ($claim instanceof EntitlementWrite) {
                $writeEntitlement($claim);
            }
        }
    }

    /**
     * The subscribers this event requires a fresh read of.
     *
     * Normally one. A `TRANSFER` moves a subscription between App User IDs and
     * therefore between TEAMS, so both sides have to be re-read: the destination
     * gains an entitlement and the source loses one, and syncing only the
     * destination leaves a team paying for a subscription that now funds
     * somebody else.
     *
     * Destinations are ordered before sources deliberately. Both writes are
     * independent, so if the job dies between them the customer is left holding
     * a tier rather than missing one.
     *
     * @return array<int, string>
     */
    protected function appUserIds(): array
    {
        $ids = array_merge(
            array_filter([$this->event['app_user_id'] ?? null], 'is_string'),
            array_filter((array) ($this->event['transferred_to'] ?? []), 'is_string'),
            array_filter((array) ($this->event['transferred_from'] ?? []), 'is_string'),
        );

        return array_values(array_unique(array_filter($ids, fn (string $id): bool => trim($id) !== '')));
    }

    /**
     * The team behind an App User ID, or null with a reason logged.
     *
     * The id is validated against the shape team keys actually have BEFORE it
     * reaches a query. That is not belt-and-braces: `teams.id` is a PostgreSQL
     * `uuid` column, and a malformed value in `where id = ?` is a 500 there and a
     * clean null on SQLite, so without this guard the default test engine could
     * not see the failure production would get. An id nobody here owns is not an
     * error either; the webhook already answered 200 for it.
     */
    protected function resolveTeam(string $appUserId): ?Team
    {
        if (! $this->looksLikeATeamKey($appUserId)) {
            $this->warn(
                'malformed_app_user_id',
                'RevenueCat named an app_user_id that cannot be a team key; entitlement left untouched.',
                ['app_user_id' => $appUserId],
            );

            return null;
        }

        $team = Team::query()->find($appUserId);

        if (! $team instanceof Team) {
            $this->warn(
                'unknown_team',
                'RevenueCat named an app_user_id with no team behind it; entitlement left untouched.',
                ['app_user_id' => $appUserId],
            );

            return null;
        }

        return $team;
    }

    /**
     * Whether a string can be a team key at all.
     *
     * Read from the same switch the migrations use rather than hardcoding UUID,
     * so a deployment on integer keys is not refused every event.
     */
    protected function looksLikeATeamKey(string $appUserId): bool
    {
        return config('magic-starter.use_uuids')
            ? Str::isUuid($appUserId)
            : ctype_digit($appUserId);
    }

    /**
     * Turn one authoritative subscriber response into a claim, or into null when
     * the response cannot decide anything (which is never a revocation).
     *
     * `subscriber.subscriptions` is the map this reads, not
     * `subscriber.entitlements`: only the subscriptions carry the `store`, the
     * `is_sandbox` flag and the rail-native product id in its key, and those are
     * three of the fields the entitlement columns need.
     *
     * @param  array<string, mixed>  $subscriber
     */
    protected function claimFor(Team $team, array $subscriber): ?EntitlementWrite
    {
        $all = is_array($subscriber['subscriptions'] ?? null) ? $subscriber['subscriptions'] : [];

        // 1. The sandbox gate, and it is the SECOND place this rail is gated:
        //    the webhook rejects a `SANDBOX` event, and `is_sandbox` is carried
        //    per subscription in the API response as well. A sandbox purchase
        //    granting a production `business` tier is money out of the door, and
        //    it is equally no evidence that a production tier ENDED, so a
        //    subscriber holding nothing else is left exactly as it was.
        $production = $this->acceptsSandbox()
            ? $all
            : array_filter($all, fn (mixed $subscription): bool => ! $this->isSandbox($subscription));

        if ($production === [] && $all !== []) {
            $this->warn(
                'sandbox_only_subscriber',
                'Every RevenueCat subscription for this team is a sandbox purchase; entitlement left untouched.',
                ['team_id' => $team->id],
            );

            return null;
        }

        // 2. A subscription with no readable date can neither grant nor expire,
        //    so it is dropped rather than read as expired. Dropping the last one
        //    means the response cannot decide anything at all.
        $dated = array_filter($production, fn (mixed $subscription): bool => $this->hasReadableDates($subscription));

        if ($dated === [] && $production !== []) {
            $this->warn(
                'undated_subscriptions',
                'RevenueCat returned subscriptions with no readable expiry; entitlement left untouched.',
                ['team_id' => $team->id, 'product_ids' => array_keys($production)],
            );

            return null;
        }

        $ranked = $this->rankedByExpiry($dated);
        $live = array_filter($ranked, fn (array $subscription): bool => $this->isLive($subscription));

        // 3. The subscription that decides is the live one reaching furthest
        //    into the future. Deliberately NOT "the highest tier among the live
        //    ones": ranking tiers is the write action's job, and a second
        //    definition of which tier is higher would be free to disagree with
        //    the one the action already enforces.
        return $live === []
            ? $this->revocation($team, $subscriber, $ranked)
            : $this->grant($team, $subscriber, (string) array_key_first($live), (array) reset($live));
    }

    /**
     * The claim a live subscription makes.
     *
     * @param  array<string, mixed>  $subscriber
     * @param  array<string, mixed>  $subscription
     */
    protected function grant(
        Team $team,
        array $subscriber,
        string $productId,
        array $subscription,
    ): ?EntitlementWrite {
        $provider = $this->providerFor($subscription['store'] ?? null);

        // A subscriber can hold a subscription sold by a rail this job does not
        // feed (`stripe`, `promotional`, `amazon`). Claiming it under a store
        // provider would let this feeder overwrite another rail's grant.
        if (! $provider instanceof BillingProvider) {
            $this->warn(
                'unfed_store',
                'RevenueCat reports a store this feeder does not own; entitlement left untouched.',
                ['team_id' => $team->id, 'store' => $subscription['store'] ?? null],
            );

            return null;
        }

        // An unmapped product is a CONFIG gap, exactly as an unmapped Stripe
        // price is: the absence of a reason to grant is not a reason to revoke.
        // It matters more here, because the real store product ids do not exist
        // yet, so until a human fills `plans.store_products` in, every event
        // lands on this branch. If it downgraded anybody, going live would be a
        // mass revocation.
        $plan = $this->planFor($productId);

        if (! $plan instanceof Plan) {
            $this->warn(
                'unmapped_product',
                'A RevenueCat product id is not mapped to a plan; entitlement left untouched.',
                ['team_id' => $team->id, 'product_id' => $productId],
            );

            return null;
        }

        // Apple Family Sharing: the access is real and the store granted it, so
        // refusing it would deny a tier the customer genuinely has. But the team
        // holding it is not the team whose owner paid, which is worth an
        // operator's attention under the owner-only purchase rule.
        if ($this->isFamilyShared($subscription)) {
            $this->warn(
                'family_shared_entitlement',
                'A RevenueCat entitlement arrived through family sharing; granted, and worth an eye.',
                ['team_id' => $team->id, 'product_id' => $productId],
            );
        }

        return new EntitlementWrite(
            team: $team,
            plan: $plan,
            status: $this->grantingStatus($subscription),
            provider: $provider,
            eventAt: $this->eventAt(),
            providerStatus: $this->eventType(),
            productId: $productId,
            currentPeriodEnd: $this->instant($subscription['expires_date'] ?? null),
            renews: $this->renews($subscription),
            gracePeriodEndsAt: $this->instant($subscription['grace_period_expires_date'] ?? null),
            manageUrl: $this->manageUrl($subscriber),
        );
    }

    /**
     * The claim an authoritative read with nothing live makes.
     *
     * @param  array<string, mixed>  $subscriber
     * @param  array<string, array<string, mixed>>  $ranked  every admissible
     *                                                       subscription, expiry-desc
     */
    protected function revocation(Team $team, array $subscriber, array $ranked): ?EntitlementWrite
    {
        $latest = $ranked === [] ? null : (array) reset($ranked);
        $provider = $this->revokingProvider($team, $latest);

        if (! $provider instanceof BillingProvider) {
            $this->warn(
                'nothing_to_revoke',
                'RevenueCat has nothing live for this team and no store rail granted its plan; left untouched.',
                ['team_id' => $team->id, 'stored_provider' => $team->plan_provider],
            );

            return null;
        }

        return new EntitlementWrite(
            team: $team,
            plan: Plan::Free,
            status: $this->revokedStatus($latest),
            provider: $provider,
            eventAt: $this->eventAt(),
            providerStatus: $this->eventType(),
            productId: $ranked === [] ? null : (string) array_key_first($ranked),
            // No period is carried forward: a subscription that has run out has
            // no period left to run and no dunning window open. `renews` is the
            // one thing this path does know, and it is false.
            renews: false,
            manageUrl: $this->manageUrl($subscriber),
        );
    }

    /**
     * Which rail a revocation is made on behalf of.
     *
     * Read from the expired subscription itself when there is one. When the read
     * is empty there is no `store` field to read, and the rail whose absence is
     * being confirmed is the one on record: this is safe here precisely because
     * the App User ID IS the team key, so a resolvable team plus an empty
     * response cannot mean "we read somebody else's subscriber".
     *
     * Null when the plan on record was not sold by a store. This feeder must not
     * revoke a Stripe or an operator-granted plan; the write action would drop
     * the attempt anyway, and a claim nobody is entitled to make is better not
     * made.
     *
     * @param  array<string, mixed>|null  $latest
     */
    protected function revokingProvider(Team $team, ?array $latest): ?BillingProvider
    {
        $fromRead = $latest === null ? null : $this->providerFor($latest['store'] ?? null);

        if ($fromRead instanceof BillingProvider) {
            return $fromRead;
        }

        $stored = BillingProvider::fromWire($team->plan_provider);

        return $stored->isStore() ? $stored : null;
    }

    /**
     * Where a still-entitled subscription stands, in the neutral vocabulary.
     *
     * An open grace window outranks everything else: it is what says the store
     * is still trying to charge, and it is why a billing issue keeps its tier.
     *
     * @param  array<string, mixed>  $subscription
     */
    protected function grantingStatus(array $subscription): PlanStatus
    {
        $grace = $this->instant($subscription['grace_period_expires_date'] ?? null);

        return match (true) {
            $grace !== null && $grace->greaterThan(CarbonImmutable::now()) => PlanStatus::Grace,
            ($subscription['billing_issues_detected_at'] ?? null) !== null => PlanStatus::PastDue,
            ($subscription['period_type'] ?? null) === 'trial' => PlanStatus::Trialing,
            default => PlanStatus::Active,
        };
    }

    /**
     * How a finished subscription finished.
     *
     * `Paused` is reachable only here, and only once the pause is IN EFFECT: a
     * pending Play pause still has a future `expires_date`, so it is live and
     * keeps its tier. What lands here is the `EXPIRATION` that follows.
     *
     * @param  array<string, mixed>|null  $subscription
     */
    protected function revokedStatus(?array $subscription): PlanStatus
    {
        if ($subscription === null) {
            return PlanStatus::Expired;
        }

        $resume = $this->instant($subscription['auto_resume_date'] ?? null);

        return match (true) {
            $resume !== null && $resume->greaterThan(CarbonImmutable::now()) => PlanStatus::Paused,
            ($subscription['refunded_at'] ?? null) !== null,
            ($subscription['unsubscribe_detected_at'] ?? null) !== null => PlanStatus::Canceled,
            default => PlanStatus::Expired,
        };
    }

    /**
     * Whether the subscription still rolls over.
     *
     * An ABSENT key leaves the column null rather than assuming it renews, the
     * same discipline the Stripe feeder applies to `cancel_at_period_end`: null
     * means the rail has not said, which is a different claim from false.
     *
     * @param  array<string, mixed>  $subscription
     */
    protected function renews(array $subscription): ?bool
    {
        if (! array_key_exists('unsubscribe_detected_at', $subscription)) {
            return null;
        }

        return $subscription['unsubscribe_detected_at'] === null;
    }

    /**
     * The store's own management surface for this subscriber.
     *
     * Passed through rather than hardcoded per platform. RevenueCat returns it
     * (`https://apps.apple.com/account/subscriptions` and the Play equivalent),
     * it is the only source `plan_manage_url` has on a store rail, and a store
     * that moves its management page then needs no app release.
     *
     * @param  array<string, mixed>  $subscriber
     */
    protected function manageUrl(array $subscriber): ?string
    {
        $url = $subscriber['management_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    /**
     * The rail behind a RevenueCat `store` value, or null when this feeder does
     * not own it.
     *
     * Compared case-insensitively on purpose: the API answers `app_store` and a
     * webhook says `APP_STORE`, for the same fact.
     */
    protected function providerFor(mixed $store): ?BillingProvider
    {
        return match (strtolower((string) (is_scalar($store) ? $store : ''))) {
            'app_store', 'mac_app_store' => BillingProvider::AppStore,
            'play_store' => BillingProvider::PlayStore,
            default => null,
        };
    }

    /**
     * The tier a rail-native product id grants, or null when the map does not
     * name it.
     *
     * Google Play sends `<subscription_id>:<base_plan_id>`, so the map keys on
     * that whole string; keyed on the bare subscription id it would be an
     * unmapped-product warning on every Android renewal.
     */
    protected function planFor(string $productId): ?Plan
    {
        $map = (array) config('plans.store_products', []);

        return Plan::tryFrom((string) ($map[$productId] ?? ''));
    }

    /**
     * Whether this deployment is allowed to act on sandbox purchases at all.
     * Absent config means no, which is the direction that cannot cost money.
     */
    protected function acceptsSandbox(): bool
    {
        return (bool) config('revenuecat.accept_sandbox', false);
    }

    /**
     * @param  mixed  $subscription  one `subscriber.subscriptions` entry
     */
    protected function isSandbox(mixed $subscription): bool
    {
        return is_array($subscription) && ($subscription['is_sandbox'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    protected function isFamilyShared(array $subscription): bool
    {
        return strtoupper((string) ($subscription['ownership_type'] ?? '')) === 'FAMILY_SHARED';
    }

    /**
     * Whether a subscription carries at least one date this build can read, and
     * is therefore able to say something about entitlement.
     */
    protected function hasReadableDates(mixed $subscription): bool
    {
        if (! is_array($subscription)) {
            return false;
        }

        return $this->instant($subscription['expires_date'] ?? null) !== null
            || $this->instant($subscription['grace_period_expires_date'] ?? null) !== null;
    }

    /**
     * Whether a subscription still entitles: the paid period, or the dunning
     * window the store is retrying inside, reaches into the future.
     *
     * @param  array<string, mixed>  $subscription
     */
    protected function isLive(array $subscription): bool
    {
        $now = CarbonImmutable::now();

        foreach (['expires_date', 'grace_period_expires_date'] as $field) {
            $instant = $this->instant($subscription[$field] ?? null);

            if ($instant !== null && $instant->greaterThan($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The admissible subscriptions, furthest-reaching first, product ids kept as
     * keys because the key IS the rail-native product id.
     *
     * @param  array<string, mixed>  $subscriptions
     * @return array<string, array<string, mixed>>
     */
    protected function rankedByExpiry(array $subscriptions): array
    {
        uasort(
            $subscriptions,
            fn (mixed $a, mixed $b): int => $this->reachOf($b) <=> $this->reachOf($a),
        );

        /** @var array<string, array<string, mixed>> $subscriptions */
        return $subscriptions;
    }

    /**
     * How far into the future a subscription reaches, as a timestamp, taking
     * whichever of its two windows lasts longer.
     */
    protected function reachOf(mixed $subscription): int
    {
        if (! is_array($subscription)) {
            return 0;
        }

        $reach = 0;

        foreach (['expires_date', 'grace_period_expires_date'] as $field) {
            $instant = $this->instant($subscription[$field] ?? null);

            if ($instant !== null) {
                $reach = max($reach, $instant->getTimestamp());
            }
        }

        return $reach;
    }

    /**
     * The event's OWN timestamp, which is what orders one delivery against
     * another inside the write action.
     *
     * Never `now()`: receipt time only ever increases, so a feeder stamping it
     * would read every write as the freshest truth on record and disarm the
     * monotonic rule while satisfying every type check. RevenueCat puts
     * `event_timestamp_ms` on every event and reuses it across retries, so the
     * fallback is unreachable in practice; it is the epoch rather than `now()`
     * because a degenerate timestamp has to LOSE to a real one.
     */
    protected function eventAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs((int) ($this->event['event_timestamp_ms'] ?? 0));
    }

    /**
     * The rail's own word for what happened, kept verbatim in
     * `plan_provider_status`, which gates nothing.
     *
     * The event type is the nearest thing the store rail has to a status string:
     * the API response carries dates and flags but no status word at all. Stored
     * so an operator can see WHICH delivery last wrote a team's entitlement.
     */
    protected function eventType(): ?string
    {
        $type = $this->event['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Parse one RevenueCat date, or null when there is nothing readable there.
     *
     * The caller decides what null MEANS, and every caller here treats it as
     * "this subscription cannot decide anything" rather than as "expired", which
     * is why an unreadable date is reported by the caller rather than swallowed:
     * a subscription with no readable window is dropped and logged in
     * {@see self::claimFor()}.
     */
    protected function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Report a decision not to write, with the reason and the event behind it.
     *
     * Warning level for every case, because each one means a paying customer's
     * entitlement did not move when a store said something about it. The
     * `reason` field is what a test asserts on: it distinguishes the guard that
     * fired from every other guard that would also have left the row alone.
     *
     * @param  array<string, mixed>  $context
     */
    protected function warn(string $reason, string $message, array $context = []): void
    {
        Log::warning($message, [
            'reason' => $reason,
            'event_id' => $this->event['id'] ?? null,
            'event_type' => $this->eventType(),
            ...$context,
        ]);
    }
}
