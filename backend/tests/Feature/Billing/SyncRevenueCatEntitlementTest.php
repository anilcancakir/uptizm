<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Jobs\SyncRevenueCatEntitlement;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\RevenueCatClient;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LoopbackHttpServer;
use Tests\Support\RawWebhookRequest;
use Tests\TestCase;

/**
 * The store rail's feeder, where entitlement is actually decided.
 *
 * Six of RevenueCat's event types reach this job and FOUR of them mean the
 * opposite of what their name suggests to a reader who has not read the docs:
 * `CANCELLATION` means auto-renew was switched off and the customer is still
 * paid up, `BILLING_ISSUE` means a retry is in progress, `SUBSCRIPTION_PAUSED`
 * carries a resume date, and `PRODUCT_CHANGE` announces a change that has NOT
 * happened yet. Each one has a plausible implementation that revokes a tier
 * somebody is paying for, so each gets a test of its own against a team of its
 * own: two guards on one outcome absorb each other's mutation, and a scenario
 * that trips two of these rules would keep passing with either rule gone.
 *
 * The structural answer to all four is that the job NEVER reads the tier from
 * the event. It re-reads the authoritative subscriber from RevenueCat and maps
 * the tier from that; the event type only decided that the job runs at all. The
 * tests are written to bite an implementation that breaks that rule: the
 * `PRODUCT_CHANGE` payload carries a `new_product_id` mapped to a LOWER tier
 * than the authoritative read shows, so a job that trusted the payload would
 * downgrade a paying team and fail here.
 *
 * ## Two layers, measured two ways
 *
 * The mapping tests fake the HTTP layer, because what they measure is which
 * tier a documented response shape produces. The TRANSPORT is measured against
 * a real listener instead ({@see LoopbackHttpServer}), because `Http::fake`
 * short-circuits above Guzzle's `CurlFactory`: under a fake there is no socket,
 * so a wrong path, a missing `Authorization` header and a deadline that is not
 * honoured are all invisible. This is the seam that decides money, so the wire
 * is asserted on the wire.
 */
class SyncRevenueCatEntitlementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The API key every test configures. A fake value: the client refuses to
     * call at all without one, and no real key belongs in a public repository.
     */
    protected const API_KEY = 'sk_test_revenuecat_secret';

    /**
     * The store's own management surface, returned by `GET /subscribers` as
     * `subscriber.management_url`. It is the ONLY source `plan_manage_url` has
     * on a store rail, so it is asserted rather than assumed.
     */
    protected const MANAGE_URL = 'https://apps.apple.com/account/subscriptions';

    /**
     * The two products the mapping tests map from, standing in for the ids a
     * human will create in App Store Connect and Play Console. The Play one
     * carries the `:base_plan_id` suffix that Play actually sends, because a
     * map keyed on the bare subscription id is an unmapped-product warning on
     * every Android renewal.
     */
    protected const APP_STORE_BUSINESS = 'uptizm_business_monthly';

    protected const PLAY_PRO = 'uptizm_pro:monthly';

    /**
     * The signing secret the two redelivery tests sign with. A fake value: the
     * endpoint refuses a delivery it cannot authenticate, and no real secret
     * belongs in a public repository.
     */
    protected const string WEBHOOK_SECRET = 'rcwhsec_test_secret';

    /**
     * The path the store rail's endpoint is registered at.
     *
     * Spelled out because the two tests below claim the event id THROUGH the
     * endpoint rather than by writing the dedup row themselves: the prefixed
     * key is the controller's own spelling, so a release keyed differently
     * fails here instead of passing against a key the test invented.
     */
    protected const string WEBHOOK_ROUTE = 'webhooks/revenuecat';

    protected function setUp(): void
    {
        parent::setUp();

        // Every fixture below decides "is this subscription still live" against
        // `now()`, so the clock is pinned to the moment the entitlement on
        // record was granted. Without this, a grace window sixteen days wide is
        // in the future today and in the past next month, and the test would
        // start failing on a date rather than on a change.
        $this->travelTo($this->grantedAt());

        // The client reads both from config; `config/revenuecat.php` is a later
        // step's file, so every test states them itself rather than depending
        // on a file that does not exist yet.
        config([
            'revenuecat.secret_api_key' => self::API_KEY,
            'revenuecat.base_url' => RevenueCatClient::DEFAULT_BASE_URL,
            'plans.store_products' => [
                self::APP_STORE_BUSINESS => Plan::Business->value,
                self::PLAY_PRO => Plan::Pro->value,
            ],
        ]);
    }

    /**
     * `CANCELLATION` leaves the tier and records that the subscription will not
     * renew.
     *
     * The event means auto-renew was switched off, not that access ended: the
     * customer has paid through the end of the period and RevenueCat sends
     * `EXPIRATION` when that period actually runs out. The authoritative read
     * says the same thing in its own vocabulary, `unsubscribe_detected_at` set
     * with `expires_date` still in the future, and that pairing is exactly what
     * a reasonable implementation reads as "cancelled, so revoke".
     */
    public function test_a_cancellation_leaves_the_tier_and_records_that_it_will_not_renew(): void
    {
        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $this->grantedAt(),
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                    'unsubscribe_detected_at' => $this->grantedAt()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('CANCELLATION', $team));

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'A cancellation revoked a tier the customer has paid for.');
        $this->assertSame(PlanStatus::Active->value, $team->plan_status);
        $this->assertFalse($team->plan_renews, 'The cancellation did not record that auto-renew is off.');
        $this->assertTrue($this->periodEnd()->equalTo($team->plan_current_period_end));
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame('CANCELLATION', $team->plan_provider_status);
        $this->assertSame(self::MANAGE_URL, $team->plan_manage_url);

        Http::assertSent(fn (Request $request): bool => $request->url() === $this->subscriberUrl($team->id));
    }

    /**
     * `BILLING_ISSUE` leaves the tier and records the grace expiry.
     *
     * The charge failed and the store is retrying, so the period end is in the
     * PAST while the grace window is still open. An implementation that decides
     * entitlement on `expires_date` alone revokes here, which cuts a customer
     * off for an expired card the store is still trying.
     */
    public function test_a_billing_issue_leaves_the_tier_and_records_the_grace_expiry(): void
    {
        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $this->grantedAt(),
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $graceEnd = $this->grantedAt()->addDays(16);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->grantedAt()->subDay()->toIso8601ZuluString(),
                    'grace_period_expires_date' => $graceEnd->toIso8601ZuluString(),
                    'billing_issues_detected_at' => $this->grantedAt()->subDay()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('BILLING_ISSUE', $team));

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'A billing retry revoked the tier it was retrying for.');
        $this->assertSame(PlanStatus::Grace->value, $team->plan_status);
        $this->assertTrue(
            $graceEnd->equalTo($team->plan_grace_period_ends_at),
            'The grace expiry was not recorded, so nothing downstream can tell how long the retry has.',
        );
    }

    /**
     * `SUBSCRIPTION_PAUSED` leaves the tier.
     *
     * Google Play only, and the pause takes effect at the END of the paid
     * period: `auto_resume_date` says when it comes back and `expires_date` is
     * still in the future. The revoke arrives later as `EXPIRATION` whose reason
     * is `SUBSCRIPTION_PAUSED`, so reading a resume date as "not entitled"
     * revokes a period the customer already paid for.
     */
    public function test_a_subscription_pause_leaves_the_tier(): void
    {
        $team = $this->makeTeam([
            'plan' => Plan::Pro->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::PlayStore->value,
            'plan_source_event_at' => $this->grantedAt(),
            'plan_product_id' => self::PLAY_PRO,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::PLAY_PRO => $this->subscription([
                    'store' => 'play_store',
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                    'auto_resume_date' => $this->periodEnd()->addMonths(3)->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('SUBSCRIPTION_PAUSED', $team));

        $team->refresh();

        $this->assertSame(Plan::Pro, $team->plan, 'A pending pause revoked a period already paid for.');
        $this->assertSame(BillingProvider::PlayStore->value, $team->plan_provider);
        $this->assertSame(self::PLAY_PRO, $team->plan_product_id);
    }

    /**
     * `PRODUCT_CHANGE` leaves the tier the authoritative read still shows.
     *
     * The event announces a change that is not in effect yet; the new product
     * starts at the next renewal. The payload here names a LOWER tier in
     * `new_product_id` than the read shows, so a job deriving the tier from the
     * payload downgrades a team that has not been downgraded, and fails here.
     */
    public function test_a_product_change_leaves_the_tier_the_authoritative_read_still_shows(): void
    {
        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $this->grantedAt(),
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('PRODUCT_CHANGE', $team, [
            'product_id' => self::APP_STORE_BUSINESS,
            'new_product_id' => self::PLAY_PRO,
        ]));

        $team->refresh();

        $this->assertSame(
            Plan::Business,
            $team->plan,
            'The tier was taken from the payload\'s new_product_id, which is not in effect yet.',
        );
        $this->assertSame(
            self::APP_STORE_BUSINESS,
            $team->plan_product_id,
            'The product id was taken from the payload rather than from the authoritative read.',
        );
    }

    /**
     * `EXPIRATION` revokes.
     *
     * This is the only event whose authoritative read shows nothing live, and
     * the positive control for every "must not revoke" test above: without it,
     * an implementation that never revokes at all would pass the other four.
     */
    public function test_an_expiration_revokes_the_tier(): void
    {
        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $this->grantedAt(),
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->grantedAt()->subDay()->toIso8601ZuluString(),
                    'unsubscribe_detected_at' => $this->grantedAt()->subDays(10)->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('EXPIRATION', $team, ['expiration_reason' => 'UNSUBSCRIBE']));

        $team->refresh();

        // NULL rather than `free`: the authoritative read found nothing live, so
        // the store owes this team nothing, which is not the same claim as
        // selling it the cheapest tier.
        $this->assertNull($team->plan, 'An expired subscription kept its paid tier.');
        $this->assertSame(PlanStatus::Canceled->value, $team->plan_status);
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame('EXPIRATION', $team->plan_provider_status);
    }

    /**
     * A live subscription whose product id is not in the map warns and writes
     * nothing.
     *
     * The same rule the Stripe feeder applies to an unmapped price: the absence
     * of a reason to grant is not a reason to revoke. It matters more here than
     * there, because the real store product ids do not exist yet, so on the day
     * this rail goes live EVERY event is an unmapped product until a human fills
     * the map in. If that state downgraded anybody, the rail would be shipping a
     * mass revocation.
     */
    public function test_a_live_subscription_whose_product_is_unmapped_warns_and_writes_nothing(): void
    {
        Log::spy();

        $grantedAt = $this->grantedAt();

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                'uptizm_business_annual_nobody_mapped' => $this->subscription([
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('RENEWAL', $team));

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'A config gap downgraded a paying team.');
        $this->assertSame(self::APP_STORE_BUSINESS, $team->plan_product_id);
        $this->assertTrue(
            $grantedAt->equalTo($team->plan_source_event_at),
            'The provenance moved, so a write landed where the step says none should.',
        );

        $this->assertWarned([
            'reason' => 'unmapped_product',
            'product_id' => 'uptizm_business_annual_nobody_mapped',
            'team_id' => $team->id,
        ]);
    }

    /**
     * `TRANSFER` is a write on BOTH sides.
     *
     * One store account cannot fund two teams: a second purchase from the same
     * Apple ID transfers the subscription rather than adding one. Asserting only
     * the destination is how the source silently keeps paying for nothing, so
     * both halves are asserted in one test.
     *
     * The source's read is EMPTY, which is what a transferred-away subscriber
     * really looks like, and it is the branch that has to fall back to the rail
     * on record to name a provider at all. That fallback is safe for one
     * structural reason: the app user id IS the team id, so a resolvable team
     * plus an empty read cannot mean "we read somebody else's subscriber".
     */
    public function test_a_transfer_revokes_the_source_team_and_grants_the_destination(): void
    {
        $grantedAt = $this->grantedAt();

        $source = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $destination = $this->makeTeam([
            'plan' => Plan::Free->value,
            'plan_status' => PlanStatus::None->value,
        ]);

        $this->fakeAuthoritativeReads([
            $source->id => $this->subscriber([]),
            $destination->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync([
            'id' => 'rc-transfer-'.Str::uuid()->toString(),
            'type' => 'TRANSFER',
            'event_timestamp_ms' => $this->eventAt()->getTimestampMs(),
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
            'transferred_from' => [$source->id],
            'transferred_to' => [$destination->id],
        ]);

        $source->refresh();
        $destination->refresh();

        $this->assertNull(
            $source->plan,
            'The source team kept a tier whose subscription now funds another team.',
        );
        $this->assertSame(PlanStatus::Expired->value, $source->plan_status);
        $this->assertSame(BillingProvider::AppStore->value, $source->plan_provider);

        $this->assertSame(Plan::Business, $destination->plan, 'The destination team paid and got nothing.');
        $this->assertSame(BillingProvider::AppStore->value, $destination->plan_provider);
        $this->assertSame(self::APP_STORE_BUSINESS, $destination->plan_product_id);
    }

    /**
     * A `FAMILY_SHARED` entitlement grants, and warns.
     *
     * Apple Family Sharing means the access arrived through somebody else's
     * purchase, so the team holding it is not the team whose owner paid. It is
     * still a real entitlement the store granted, and refusing it would deny
     * access Apple says the user has, so the decision is to grant and tell an
     * operator: the alternative resolves an ambiguity by taking a tier away,
     * which is the one direction this whole feeder never takes.
     */
    public function test_a_family_shared_entitlement_grants_and_warns(): void
    {
        Log::spy();

        $team = $this->makeTeam([
            'plan' => Plan::Free->value,
            'plan_status' => PlanStatus::None->value,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                    'ownership_type' => 'FAMILY_SHARED',
                ]),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $team));

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'A family-shared entitlement was refused.');

        $this->assertWarned([
            'reason' => 'family_shared_entitlement',
            'team_id' => $team->id,
        ]);
    }

    /**
     * A subscriber whose only subscription is a sandbox purchase writes nothing,
     * and warns.
     *
     * `is_sandbox` is per subscription in the API response, which is a second
     * place the sandbox gate has to hold beyond the webhook's `environment`
     * field: a sandbox purchase granting a production `business` tier is money
     * out of the door. It must not revoke either, because a sandbox purchase is
     * no evidence at all about a production entitlement.
     */
    public function test_a_sandbox_only_subscriber_writes_nothing_and_warns(): void
    {
        Log::spy();

        $grantedAt = $this->grantedAt();

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                    'is_sandbox' => true,
                ]),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $team));

        $team->refresh();

        $this->assertTrue(
            $grantedAt->equalTo($team->plan_source_event_at),
            'A sandbox subscription wrote a production entitlement.',
        );

        $this->assertWarned([
            'reason' => 'sandbox_only_subscriber',
            'team_id' => $team->id,
        ]);
    }

    /**
     * An app user id that is not a team id never reaches the authoritative read.
     *
     * The id arrives from a webhook payload, `teams.id` is a PostgreSQL `uuid`
     * column, and a malformed value handed to a `where id = ?` is a 500 on
     * PostgreSQL and a clean null on SQLite. The suite runs SQLite by default,
     * so this test asserts the GUARD's own reason rather than the absence of an
     * exception: without the guard the id would fall through to the
     * unknown-team branch and the reason asserted here would not match.
     */
    public function test_an_app_user_id_that_is_not_a_team_id_never_reaches_the_authoritative_read(): void
    {
        Log::spy();

        Http::fake();

        $this->sync([
            'id' => 'rc-malformed-'.Str::uuid()->toString(),
            'type' => 'RENEWAL',
            'app_user_id' => 'not-a-team-id',
            'event_timestamp_ms' => $this->eventAt()->getTimestampMs(),
        ]);

        Http::assertNothingSent();

        $this->assertWarned([
            'reason' => 'malformed_app_user_id',
            'app_user_id' => 'not-a-team-id',
        ]);
    }

    /**
     * A refund does NOT by itself end the tier, and this test exists because the
     * opposite was briefly implemented.
     *
     * `isLive()` was given a `refunded_at` arm on the reasoning that an Apple
     * refund might leave `expires_date` in the future and hand out months of
     * free service. RevenueCat's documentation refutes the premise: "this
     * doesn't mean that a subscription's autorenewal preference has been
     * deactivated since refunds can be given without canceling a subscription.
     * Check the current subscription status to see whether the subscription is
     * still active."
     *
     * So the state below is one the vendor documents as live, and revoking it
     * would have been the only place in this job where an ambiguity resolved
     * toward taking a tier from somebody who may still be paying. The dates are
     * the status we are told to check, and a refund that really did end the
     * subscription moves them, which the sibling assertion pins.
     */
    public function test_a_refund_alone_does_not_take_the_tier_away(): void
    {
        Log::spy();

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    // Months still to run, and a refund on record. The vendor
                    // says to believe the dates.
                    'expires_date' => $this->periodEnd()->addMonths(6)->toIso8601ZuluString(),
                    'refunded_at' => $this->eventAt()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('CANCELLATION', $team));

        $team->refresh();

        $this->assertSame(
            Plan::Business,
            $team->plan,
            'A refund with a live period took the tier away.',
        );
    }

    /**
     * The other half: a refund that DID end the subscription moves the dates,
     * and then the ordinary revocation path runs.
     *
     * Pinned beside its sibling so neither can pass with the liveness rule
     * deleted: this one needs the dates to be read, that one needs `refunded_at`
     * to be ignored.
     */
    public function test_a_refund_that_expired_the_subscription_revokes_normally(): void
    {
        Log::spy();

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription([
                    'expires_date' => $this->eventAt()->subDay()->toIso8601ZuluString(),
                    'refunded_at' => $this->eventAt()->subDay()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('CANCELLATION', $team));

        $team->refresh();

        $this->assertNull($team->plan);
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
    }

    /**
     * When the last-seen id is not a team key, ONE alias that is may stand in.
     *
     * `app_user_id` is documented as the last seen id, and RevenueCat instructs
     * callers to search `original_app_user_id` and `aliases` too. A subscriber
     * whose last-seen id is one the SDK generated (an anonymous id from before
     * `logIn` ran) otherwise arrives malformed and is refused, which is this
     * job's own worst failure: a paying customer left on free, unable to buy
     * again because the store will not resell what they already own.
     */
    public function test_one_alias_can_stand_in_for_an_anonymous_last_seen_id(): void
    {
        Log::spy();

        $team = $this->makeTeam([
            'plan' => Plan::Free->value,
            'plan_status' => PlanStatus::None->value,
        ]);

        $this->fakeAuthoritativeReads([
            '$RCAnonymousID:8e9b21c5f1' => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $team, [
            'app_user_id' => '$RCAnonymousID:8e9b21c5f1',
            'original_app_user_id' => $team->id,
            'aliases' => ['$RCAnonymousID:8e9b21c5f1', $team->id],
        ]));

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'The purchase was refused over an anonymous id.');
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
    }

    /**
     * A padded `app_user_id` resolves to its team rather than being refused.
     *
     * Not cosmetic. `TeamKey::looksLikeOne()` runs `Str::isUuid`, which rejects
     * a padded value, so before the ids were trimmed a payload with whitespace
     * around the id was refused at `resolveTeam()` and no entitlement was
     * written at all. It also made the id compare unequal to `handle()`'s own
     * `$primary`, which is built with `trim()`, so the alias fallback was
     * disabled for the event's OWN subscriber and the refusal said "transferred"
     * about an id nothing had transferred.
     */
    public function test_a_padded_app_user_id_still_finds_its_team(): void
    {
        Log::spy();

        $team = $this->makeTeam([
            'plan' => Plan::Free->value,
            'plan_status' => PlanStatus::None->value,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $team, [
            'app_user_id' => "  {$team->id}  ",
        ]));

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'A padded id was refused its own team.');
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
    }

    /**
     * An alias may never stand in for a TRANSFER side.
     *
     * The two sides of a transfer are different SUBSCRIBERS, while `aliases`
     * describes one. Letting the fallback answer for a transferred id would read
     * one side's subscriber and claim it against the other side's team: an
     * anonymous `transferred_from` resolves to the single team key in the
     * aliases, which is the DESTINATION, and the emptied source subscriber then
     * arrives as a revocation against the team that just gained the
     * subscription. The fallback is therefore scoped to `app_user_id` alone.
     */
    public function test_an_alias_never_stands_in_for_a_transfer_side(): void
    {
        Log::spy();

        $destination = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        // Only the destination answers. If the fallback resolved the anonymous
        // SOURCE id to this team, the job would read the source subscriber and
        // claim a revocation here.
        $this->fakeAuthoritativeReads([
            $destination->id => $this->subscriber([
                self::APP_STORE_BUSINESS => $this->subscription(),
            ]),
        ]);

        $this->sync([
            'id' => 'rc-transfer-'.Str::uuid()->toString(),
            'type' => 'TRANSFER',
            'event_timestamp_ms' => $this->eventAt()->getTimestampMs(),
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
            'app_user_id' => $destination->id,
            'aliases' => ['$RCAnonymousID:8e9b21c5f1', $destination->id],
            'transferred_from' => ['$RCAnonymousID:8e9b21c5f1'],
            'transferred_to' => [$destination->id],
        ]);

        $destination->refresh();

        $this->assertSame(
            Plan::Business,
            $destination->plan,
            'The transfer source was resolved to the destination team and revoked it.',
        );
    }

    /**
     * TWO aliases that could each be a team is not a tie to break.
     *
     * This app calls `identify(teamId)` on every team switch, so an owner moving
     * between two of their own teams on one device makes both team ids aliases
     * of a single subscriber. "The first alias that parses" would then hand one
     * team's subscription to the other, silently, on a rail nobody has watched
     * run. The job declines and says so instead.
     */
    public function test_two_candidate_aliases_are_refused_rather_than_guessed(): void
    {
        Log::spy();

        $one = $this->makeTeam(['plan' => Plan::Free->value, 'plan_status' => PlanStatus::None->value]);
        $two = $this->makeTeam(['plan' => Plan::Free->value, 'plan_status' => PlanStatus::None->value]);

        Http::fake();

        $this->sync($this->event('INITIAL_PURCHASE', $one, [
            'app_user_id' => '$RCAnonymousID:8e9b21c5f1',
            'aliases' => [$one->id, $two->id],
        ]));

        $one->refresh();
        $two->refresh();

        $this->assertSame(Plan::Free, $one->plan);
        $this->assertSame(Plan::Free, $two->plan);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return ($context['reason'] ?? null) === 'ambiguous_aliases';
            })
            ->atLeast()
            ->once();
    }

    /**
     * A subscription from a rail this feeder does not own cannot mask the store
     * subscription behind it.
     *
     * The store check used to sit on the ranked WINNER rather than on the set,
     * and the ranking is by expiry alone. So a `promotional` grant reaching
     * further into the future than a real App Store purchase won the ranking,
     * was refused as an unowned store, and nothing ever looked at the purchase
     * behind it. The customer paid, the webhook arrived, the read was correct,
     * and the entitlement stayed on free.
     *
     * `promotional` is RevenueCat's own store value for a grant issued from
     * their dashboard, so this is an ordinary support action, not an exotic
     * state: comping a customer a month is enough to trigger it.
     */
    public function test_an_unowned_store_does_not_mask_the_purchase_behind_it(): void
    {
        Log::spy();

        $team = $this->makeTeam([
            'plan' => Plan::Free->value,
            'plan_status' => PlanStatus::None->value,
        ]);

        $this->fakeAuthoritativeReads([
            $team->id => $this->subscriber([
                // Reaches furthest, so it wins the expiry ranking, and belongs to
                // a rail this job does not feed.
                'promo_grant' => $this->subscription([
                    'store' => 'promotional',
                    'expires_date' => $this->periodEnd()->addYear()->toIso8601ZuluString(),
                ]),
                self::APP_STORE_BUSINESS => $this->subscription([
                    'store' => 'app_store',
                    'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
                ]),
            ]),
        ]);

        $this->sync($this->event('INITIAL_PURCHASE', $team));

        $team->refresh();

        $this->assertSame(
            Plan::Business,
            $team->plan,
            'The App Store purchase was hidden behind a promotional grant with a later date.',
        );
        $this->assertSame(BillingProvider::AppStore->value, $team->plan_provider);
        $this->assertSame(self::APP_STORE_BUSINESS, $team->plan_product_id);
    }

    /**
     * A well-formed app user id with no team behind it writes nothing and does
     * not fail the job.
     *
     * The webhook already answered 200 for an unknown team, so failing here
     * would only park a permanent failure in the queue for a subscriber this
     * deployment does not own.
     */
    public function test_an_unknown_team_writes_nothing(): void
    {
        Log::spy();

        Http::fake();

        $appUserId = Str::uuid()->toString();

        $this->sync([
            'id' => 'rc-unknown-'.Str::uuid()->toString(),
            'type' => 'RENEWAL',
            'app_user_id' => $appUserId,
            'event_timestamp_ms' => $this->eventAt()->getTimestampMs(),
        ]);

        Http::assertNothingSent();

        $this->assertWarned([
            'reason' => 'unknown_team',
            'app_user_id' => $appUserId,
        ]);
    }

    /**
     * A failing authoritative read writes nothing at all, and says so by
     * failing.
     *
     * This is the property that makes every mapping test above safe: the tier is
     * decided by a response, so a response that never arrived must not be read
     * as "nothing is owed". The job raises instead, and the queue retries it.
     *
     * The attempt count is written out as a LITERAL, and that is the second
     * thing this test measures. The first draft asserted
     * `RevenueCatClient::MAXIMUM_ATTEMPTS`, which derives the expectation from
     * the very constant it is checking: setting that constant to 1 takes retries
     * out of the client and the test stays green. A client that never retries
     * and one that retries forever both satisfy an assertion that only checks
     * the throw.
     */
    public function test_a_failing_authoritative_read_throws_and_writes_nothing(): void
    {
        $grantedAt = $this->grantedAt();

        $team = $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $grantedAt,
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]);

        Http::fake(['*' => Http::response(['message' => 'upstream is unwell'], 503)]);

        $this->assertThrows(
            fn () => $this->sync($this->event('EXPIRATION', $team)),
            RequestException::class,
        );

        $team->refresh();

        $this->assertSame(Plan::Business, $team->plan, 'A failed read revoked a tier.');
        $this->assertTrue($grantedAt->equalTo($team->plan_source_event_at));

        Http::assertSentCount(3);
    }

    /**
     * The job is queued on `default`, AND `default` is drained by a supervisor.
     *
     * Both halves, because the first one alone is what lets an unsupervised
     * queue ship: the dispatch succeeds, the job sits in Redis forever, nothing
     * consumes it and no test fails. Horizon's own note in `config/horizon.php`
     * makes it a two-place registration, so the local `queue:listen` half is
     * checked too; with only the server half, `composer dev` accepts an
     * entitlement sync and never runs it.
     */
    public function test_the_job_is_queued_on_default_and_default_is_drained_by_a_supervisor(): void
    {
        Queue::fake();

        SyncRevenueCatEntitlement::dispatch([
            'id' => 'rc-queued-'.Str::uuid()->toString(),
            'type' => 'RENEWAL',
            'app_user_id' => Str::uuid()->toString(),
            'event_timestamp_ms' => $this->eventAt()->getTimestampMs(),
        ]);

        Queue::assertPushedOn(SyncRevenueCatEntitlement::QUEUE, SyncRevenueCatEntitlement::class);

        $supervisors = (array) config('horizon.defaults');

        $this->assertNotEmpty($supervisors, 'Horizon declares no supervisors, so the check below is vacuous.');

        $draining = array_keys(array_filter(
            $supervisors,
            fn (array $supervisor): bool => in_array(
                SyncRevenueCatEntitlement::QUEUE,
                (array) ($supervisor['queue'] ?? []),
                true,
            ),
        ));

        $this->assertNotEmpty(
            $draining,
            'No Horizon supervisor drains the ['.SyncRevenueCatEntitlement::QUEUE.'] queue, so every '
            .'entitlement sync would dispatch successfully and never run.',
        );

        $this->assertContains(
            SyncRevenueCatEntitlement::QUEUE,
            $this->developmentQueues(),
            'The local half of the two-place queue registration is missing: `composer dev` would accept an '
            .'entitlement sync and never drain it.',
        );
    }

    /**
     * The SHIPPED product map is wired, and its Play keys carry a base plan id.
     *
     * Deliberately asserted against `config/plans.php` rather than against the
     * per-test map above: the ids themselves are placeholders a human replaces
     * from App Store Connect and Play Console, so what is pinned here is the
     * shape that survives that edit. Every value has to name a real tier, and at
     * least one key has to carry the `<subscription_id>:<base_plan_id>` form,
     * because a Play map keyed on the bare subscription id turns every Android
     * renewal into an unmapped-product warning.
     */
    public function test_the_shipped_store_product_map_is_wired_and_keys_the_play_base_plan(): void
    {
        $shipped = (array) require config_path('plans.php');
        $map = (array) ($shipped['store_products'] ?? []);

        $this->assertNotEmpty($map, 'config/plans.php ships no store_products map, so no store product can grant.');

        foreach ($map as $productId => $tier) {
            $this->assertIsString($productId);
            $this->assertNotSame('', trim((string) $productId));
            $this->assertInstanceOf(
                Plan::class,
                Plan::tryFrom((string) $tier),
                "The store product [{$productId}] maps to [{$tier}], which is not a plan tier.",
            );
        }

        $keyed = array_filter(array_keys($map), fn (string $productId): bool => str_contains($productId, ':'));

        $this->assertNotEmpty(
            $keyed,
            'No store product key carries a `:base_plan_id` suffix. Google Play sends '
            .'`<subscription_id>:<base_plan_id>`, so a map without one is an unmapped-product warning on '
            .'every Android renewal.',
        );
    }

    /**
     * THE WIRE. The client asks the documented endpoint, with a bearer key, on a
     * real socket.
     *
     * `Http::fake` cannot make this assertion: it short-circuits inside
     * Laravel's stub handler upstream of Guzzle's `CurlFactory`, so there is no
     * request a listener could have received wrong. This one is a real child
     * process reporting what actually arrived.
     */
    public function test_the_client_asks_the_authoritative_endpoint_with_a_bearer_key(): void
    {
        $server = LoopbackHttpServer::serving(
            body: (string) json_encode([
                'subscriber' => [
                    'original_app_user_id' => 'the-subscriber',
                    'management_url' => self::MANAGE_URL,
                    'subscriptions' => [],
                ],
            ]),
        );

        config(['revenuecat.base_url' => $server->url('/v1')]);

        $subscriber = app(RevenueCatClient::class)->subscriber('the-subscriber');

        $observed = $server->report();

        $this->assertSame('GET', $observed['method'], 'The authoritative read is not a GET.');
        $this->assertSame('/v1/subscribers/the-subscriber', $observed['path']);
        $this->assertSame('Bearer '.self::API_KEY, $observed['headers']['authorization'] ?? null);

        // The exchange completed, so the assertions above describe a request
        // whose answer was really read rather than one nobody consumed.
        $this->assertSame(self::MANAGE_URL, $subscriber['management_url'] ?? null);
    }

    /**
     * A listener told to stall past the deadline produces an honoured timeout
     * rather than a hang.
     *
     * The budget bounds the whole OPERATION rather than one call, because this
     * repo has already shipped a per-call timeout sized against a wall and then
     * watched a retry double it. With one second of budget the client may not
     * start a second attempt at all, so the elapsed time stays under the wall
     * whatever the far end does.
     */
    public function test_the_client_honours_its_operation_budget_against_a_stalling_listener(): void
    {
        $server = LoopbackHttpServer::serving(body: '{"subscriber":{}}', delayMs: 3000);

        config([
            'revenuecat.base_url' => $server->url('/v1'),
            'revenuecat.operation_budget_seconds' => 1,
        ]);

        $startedAt = microtime(true);

        $this->assertThrows(
            fn (): array => app(RevenueCatClient::class)->subscriber('the-subscriber'),
            ConnectionException::class,
        );

        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(
            2.0,
            $elapsed,
            "The client waited {$elapsed}s on a listener stalling 3s, so its budget was not honoured.",
        );

        // The stall was a stall rather than a connection that never happened:
        // the listener holds the request that timed out.
        $observed = $server->report();
        $this->assertSame('/v1/subscribers/the-subscriber', $observed['path']);
    }

    /**
     * A sync that burns its whole retry budget releases the event id the
     * webhook claimed.
     *
     * The endpoint claims the id and dispatches inside ONE transaction, which
     * is the right shape for a queue that refuses the job. But the work happens
     * later, and this job's budget (`$tries` with `backoff()`) is spent inside
     * about six minutes while RevenueCat is still redelivering the same id at
     * 5/10/20/40/80 minutes. Without a release, every remaining delivery
     * answers 200 having done nothing, and a dropped `INITIAL_PURCHASE` is the
     * one failure this rail cannot recover from on its own.
     *
     * THE SEAM IS THE WORKER, and that is the whole point of this test. Calling
     * `failed()` from here would assert a method body while leaving the defect
     * uncovered, because what was missing is that NOTHING invoked it. So the
     * delivery goes through the endpoint, the re-read lands on the database
     * queue as a row, its attempt count is spent, and `queue:work` is what
     * raises `MaxAttemptsExceededException` and calls `failed()` itself.
     *
     * `Http::assertNothingSent()` pins the failure to the BUDGET rather than to
     * a read: the worker refused the job before `handle()` ran, so the release
     * is not a side effect of a failed authoritative call.
     */
    public function test_a_permanently_failed_sync_releases_the_event_id_the_webhook_claimed(): void
    {
        Log::spy();

        Http::fake();

        $event = $this->event('EXPIRATION', $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $this->grantedAt(),
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]));

        $this->deliverToTheWebhook($event)->assertOk();

        $this->assertSame(
            1,
            ProcessedWebhookEvent::query()->count(),
            'The delivery claimed no event id, so there is nothing for the release to be measured against.',
        );

        $this->exhaustTheRetryBudget();

        $this->assertSame(
            0,
            ProcessedWebhookEvent::query()->count(),
            'The burnt event id was not released, so every remaining RevenueCat delivery of it is a '
            .'permanent no-op and the entitlement never moves.',
        );

        Http::assertNothingSent();

        $this->assertSame(
            1,
            DB::table('failed_jobs')->count(),
            'The release swallowed the failure: a released id has to stay a recorded failure as well.',
        );

        $this->assertWarned([
            'reason' => 'released_burnt_event_id',
            'event_id' => $event['id'],
            'exception' => MaxAttemptsExceededException::class,
        ]);
    }

    /**
     * A redelivery of the same event id, after that permanent failure, queues
     * the re-read again.
     *
     * Driven through the ENDPOINT on both deliveries rather than against the
     * model: what has to be true is that
     * {@see ProcessedWebhookEvent::recordIfNew()} answers "new" a second time,
     * and asserting the row's absence would only restate the test above. This
     * one measures the consequence RevenueCat's fourth attempt actually gets.
     */
    public function test_a_redelivery_after_a_permanent_failure_queues_the_re_read_again(): void
    {
        Http::fake();

        $event = $this->event('EXPIRATION', $this->makeTeam([
            'plan' => Plan::Business->value,
            'plan_status' => PlanStatus::Active->value,
            'plan_provider' => BillingProvider::AppStore->value,
            'plan_source_event_at' => $this->grantedAt(),
            'plan_product_id' => self::APP_STORE_BUSINESS,
        ]));

        $this->deliverToTheWebhook($event)->assertOk();

        $this->assertSame(1, $this->queuedJobs(), 'The first delivery queued no re-read.');

        $this->exhaustTheRetryBudget();

        $this->assertSame(0, $this->queuedJobs(), 'The failed re-read is still on the queue.');

        // RevenueCat's next attempt: the same event id, signed afresh.
        $this->deliverToTheWebhook($event)->assertOk();

        $this->assertSame(
            1,
            $this->queuedJobs(),
            'The redelivery short-circuited on a claim nobody released, so the store rail answered 200 '
            .'having done nothing.',
        );

        $this->assertSame(
            1,
            ProcessedWebhookEvent::query()->count(),
            'The redelivery queued a re-read without claiming its event id, so the delivery after it '
            .'would queue a second one.',
        );
    }

    /**
     * Run one webhook event through the job the way the queue does, with the
     * client and the write action resolved from the container.
     *
     * @param  array<string, mixed>  $event
     */
    protected function sync(array $event): void
    {
        dispatch_sync(new SyncRevenueCatEntitlement($event));
    }

    /**
     * Deliver one event to the store rail's endpoint, signed as RevenueCat
     * signs it, with the queue on the DATABASE driver.
     *
     * The driver is the load-bearing half. `phpunit.xml` runs the suite on
     * `sync`, where a dispatch is an inline call and there is no attempt count,
     * no worker and therefore no `failed()` to invoke at all. On the database
     * driver the re-read is a row a real worker reserves, which is the only
     * shape in which the framework's own fail path can be exercised.
     *
     * @param  array<string, mixed>  $event
     */
    protected function deliverToTheWebhook(array $event): TestResponse
    {
        config([
            'revenuecat.webhook_secret' => self::WEBHOOK_SECRET,
            'queue.default' => 'database',
        ]);

        return RawWebhookRequest::withPayload(['api_version' => '1.0', 'event' => $event])
            ->signedWith(self::WEBHOOK_SECRET, CarbonImmutable::now()->getTimestamp())
            ->deliverTo($this, self::WEBHOOK_ROUTE);
    }

    /**
     * Spend the queued re-read's whole retry budget, then let a real worker
     * decide what that means.
     *
     * The budget is read out of the queued PAYLOAD rather than written as a
     * literal `3`: it is the same `maxTries` the worker will compare against,
     * so widening `$tries` cannot leave this fixture quietly one attempt short
     * and start exercising `handle()` instead of the fail path. The reserve
     * `queue:work` is about to make adds one attempt, so a row already at
     * `maxTries` is a job whose last attempt has been made.
     */
    protected function exhaustTheRetryBudget(): void
    {
        $queued = DB::table('jobs')->first();

        $this->assertNotNull($queued, 'Nothing is queued, so there is no retry budget to spend.');

        $payload = (array) json_decode((string) $queued->payload, true, 512, JSON_THROW_ON_ERROR);
        $maxTries = $payload['maxTries'] ?? null;

        $this->assertIsInt(
            $maxTries,
            'The queued job publishes no retry budget, so the worker would run it rather than refuse it.',
        );

        DB::table('jobs')->where('id', $queued->id)->update(['attempts' => $maxTries]);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => SyncRevenueCatEntitlement::QUEUE,
        ])->assertExitCode(0);
    }

    /**
     * How many jobs are waiting on the database queue.
     */
    protected function queuedJobs(): int
    {
        return DB::table('jobs')->count();
    }

    /**
     * A RevenueCat webhook event, carrying only the fields this job is allowed
     * to read plus the ones it must ignore.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function event(string $type, Team $team, array $overrides = []): array
    {
        return [
            'id' => 'rc-'.Str::uuid()->toString(),
            'type' => $type,
            'app_user_id' => $team->id,
            'event_timestamp_ms' => $this->eventAt()->getTimestampMs(),
            'store' => 'APP_STORE',
            'environment' => 'PRODUCTION',
            ...$overrides,
        ];
    }

    /**
     * A `GET /subscribers/{app_user_id}` body, in the shape the API documents.
     *
     * @param  array<string, array<string, mixed>>  $subscriptions
     * @return array<string, mixed>
     */
    protected function subscriber(array $subscriptions): array
    {
        return [
            'subscriber' => [
                'original_app_user_id' => 'irrelevant-to-this-job',
                'first_seen' => $this->grantedAt()->subYear()->toIso8601ZuluString(),
                'management_url' => self::MANAGE_URL,
                'subscriptions' => $subscriptions,
                'entitlements' => [],
                'non_subscriptions' => [],
            ],
        ];
    }

    /**
     * One `subscriber.subscriptions.<product_id>` entry with every field this
     * job reads present, so a test states the value it varies and nothing else.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function subscription(array $overrides = []): array
    {
        return [
            'expires_date' => $this->periodEnd()->toIso8601ZuluString(),
            'grace_period_expires_date' => null,
            'unsubscribe_detected_at' => null,
            'billing_issues_detected_at' => null,
            'refunded_at' => null,
            'auto_resume_date' => null,
            'is_sandbox' => false,
            'store' => 'app_store',
            'period_type' => 'normal',
            'ownership_type' => 'PURCHASED',
            'purchase_date' => $this->grantedAt()->subMonth()->toIso8601ZuluString(),
            ...$overrides,
        ];
    }

    /**
     * Answer `GET /subscribers/{id}` from a per-app-user-id table.
     *
     * An id the test did not name answers 404 rather than an empty subscriber:
     * an empty body is the one answer that could quietly revoke, so a job asking
     * for the wrong subscriber has to fail loudly instead of passing.
     *
     * @param  array<string, array<string, mixed>>  $byAppUserId
     */
    protected function fakeAuthoritativeReads(array $byAppUserId): void
    {
        Http::fake(function (Request $request) use ($byAppUserId): PromiseInterface {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $appUserId = urldecode(basename($path));

            if (! array_key_exists($appUserId, $byAppUserId)) {
                return Http::response(['message' => "unexpected app_user_id [{$appUserId}]"], 404);
            }

            return Http::response($byAppUserId[$appUserId], 200);
        });
    }

    /**
     * The URL the client is expected to call for one app user id.
     */
    protected function subscriberUrl(string $appUserId): string
    {
        return RevenueCatClient::DEFAULT_BASE_URL.'/subscribers/'.$appUserId;
    }

    /**
     * Assert exactly one warning carried these context fields.
     *
     * `once()` is load bearing: it is what shows the run did not ALSO warn for
     * another reason, which a spy asserting only the fields it cares about would
     * never notice.
     *
     * @param  array<string, mixed>  $context
     */
    protected function assertWarned(array $context): void
    {
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $actual) use ($context): bool {
                foreach ($context as $field => $value) {
                    if (($actual[$field] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            });
    }

    /**
     * The queues the composer `dev` script's listener drains.
     *
     * @return array<int, string>
     */
    protected function developmentQueues(): array
    {
        $composer = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($composer['scripts']['dev'] ?? [] as $line) {
            if (is_string($line) && preg_match('/--queue=([a-z,]+)/', $line, $matches) === 1) {
                return explode(',', $matches[1]);
            }
        }

        $this->fail('The composer [dev] script no longer starts a queue listener naming its queues.');
    }

    /**
     * When the entitlement on record was granted.
     */
    protected function grantedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-22 12:00:00');
    }

    /**
     * The incoming event's own timestamp, strictly newer than the one on record
     * so the action's monotonic rule cannot be what decides these tests.
     */
    protected function eventAt(): CarbonImmutable
    {
        return $this->grantedAt()->addMinutes(5);
    }

    /**
     * A period end a month past the pinned clock, i.e. comfortably live.
     */
    protected function periodEnd(): CarbonImmutable
    {
        return $this->grantedAt()->addMonth();
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
