<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\WriteTeamEntitlement;
use App\Enums\Plan;
use App\Jobs\SyncRevenueCatEntitlement;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RawWebhookRequest;
use Tests\TestCase;

/**
 * The store rail's front door: what a RevenueCat delivery is allowed to make
 * happen, and what it must never be able to.
 *
 * The endpoint does four things and no more (verify, claim, decide, queue), so
 * these tests are written against those four and against the ways each one has
 * a plausible implementation that passes a naive test:
 *
 *  1. VERIFY over the RAW BYTES. The awkward-payload test is the one that bites
 *     a handler verifying over `$request->all()` re-encoded: a body with an
 *     unescaped slash, literal Turkish letters and `9.90` survives a signature
 *     check only if the bytes were never reparsed. Its negative control is the
 *     tampered-body test, without which a verifier that answered "valid"
 *     unconditionally would pass.
 *  2. TOLERANCE sized to the SIGNING time, not the retry window. RevenueCat
 *     re-signs every attempt with the current time and abandons a delivery after
 *     roughly 80 minutes, so 80 minutes is the tempting wrong tolerance. One test
 *     rejects a signature that old; its companion accepts a four-minute-old one,
 *     which is what stops a zero tolerance from passing both.
 *  3. ALWAYS 200. A non-200 burns one of five deliveries and then the event is
 *     gone forever, so an unknown team, a sandbox event and an event type this
 *     rail ignores are each asserted to answer 200 while writing nothing.
 *  4. NO ENTITLEMENT WRITE HERE. The controller never decides grant-or-revoke,
 *     asserted by delivering an `INITIAL_PURCHASE` for a free team with the queue
 *     faked and finding every entitlement column exactly as it was.
 *
 * ## The CSRF assertion, and why it needs a manoeuvre
 *
 * A route registered in `routes/web.php` inherits the `web` group and 419s every
 * delivery, which is silent permanent data loss: five identical 419s and then
 * RevenueCat abandons the event. The suite cannot see it by default, because
 * `PreventRequestForgery::handle()` short-circuits on `runningUnitTests()`, so a
 * plain POST here is exempt whatever `bootstrap/app.php` says. So the CSRF test
 * flips the container's `env` binding to make that short-circuit false, and it
 * carries its own negative control: a throwaway `web` route asserted to be a 419
 * under the same manoeuvre, without which the assertion would pass with CSRF
 * still switched off.
 *
 * ## What the SQLite run proves, and what only PostgreSQL can
 *
 * The replay test means strictly less on the default engine. On PostgreSQL a
 * failed statement aborts the whole transaction, so the unique violation the
 * second delivery raises inside the handler's transaction would poison it
 * without the SAVEPOINT in {@see ProcessedWebhookEvent::recordIfNew()}; SQLite
 * carries on regardless. Both engines were run for this file:
 *
 *     DB_CONNECTION=pgsql DB_DATABASE=uptizm_pgtest php artisan test --filter=RevenueCatWebhookTest
 */
class RevenueCatWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The signing secret both halves of every signature test share. A fake
     * value: no real secret belongs in a public repository.
     */
    protected const string WEBHOOK_SECRET = 'rcwhsec_test_secret';

    /**
     * The path the route is registered at, spelled out because it is also the
     * string that has to appear in the CSRF exemption list.
     */
    protected const string ROUTE = 'webhooks/revenuecat';

    /**
     * Every event type RevenueCat documents that can change what a subscriber is
     * entitled to, and is therefore worth an authoritative re-read.
     *
     * @var array<int, string>
     */
    protected const array ENTITLEMENT_TYPES = [
        'INITIAL_PURCHASE',
        'RENEWAL',
        'CANCELLATION',
        'UNCANCELLATION',
        'NON_RENEWING_PURCHASE',
        'SUBSCRIPTION_PAUSED',
        'EXPIRATION',
        'BILLING_ISSUE',
        'PRODUCT_CHANGE',
        'SUBSCRIPTION_EXTENDED',
        'REFUND_REVERSED',
        'TRANSFER',
        'TEMPORARY_ENTITLEMENT_GRANT',
    ];

    /**
     * Every other type RevenueCat documents. None of them says anything about
     * entitlement, and a re-read for one is a store API call bought for nothing
     * (`PAYWALL_IMPRESSION` fires on app opens).
     *
     * @var array<int, string>
     */
    protected const array IGNORED_TYPES = [
        'TEST',
        'SUBSCRIBER_ALIAS',
        'EXPERIMENT_ENROLLMENT',
        'INVOICE_ISSUANCE',
        'PURCHASE_REDEEMED',
        'VIRTUAL_CURRENCY_TRANSACTION',
        'PRICE_INCREASE_CONSENT_REQUIRED',
        'PRICE_INCREASE_CONSENT_APPROVED',
        'PAYWALL_IMPRESSION',
        'PAYWALL_CLOSE',
        'PAYWALL_CANCEL',
        'PAYWALL_EXIT_OFFER',
        'PAYWALL_COMPONENT_INTERACTED',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The re-read is asserted as a DISPATCH, never as its outcome: what the
        // job then does with the event is its own test's subject.
        Queue::fake();

        config(['revenuecat.webhook_secret' => static::WEBHOOK_SECRET]);
    }

    public function test_a_signed_expiration_queues_the_authoritative_re_read(): void
    {
        $team = $this->makeTeam();
        $event = $this->event('EXPIRATION', $team->id);

        $this->deliver($event)->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);

        // The job is handed the EVENT, not a team or a tier: the controller has
        // decided only that a re-read is worth making.
        $this->assertSame([$event], $this->queuedEvents());

        $this->assertTrue(
            ProcessedWebhookEvent::query()->where('event_id', 'rc:'.$event['id'])->exists(),
            'The event id was not claimed, so a re-delivery would queue a second re-read.',
        );
    }

    public function test_the_namespace_prefix_keeps_the_two_id_spaces_apart(): void
    {
        // A Stripe event id and a RevenueCat event id are issued by different
        // systems and can collide as strings. Without the prefix, a Stripe
        // delivery could make a store delivery a permanent no-op, and the
        // failure would be a tier that silently never moved.
        $event = $this->event('RENEWAL', $this->makeTeam()->id);

        ProcessedWebhookEvent::query()->create([
            'event_id' => $event['id'],
            'type' => 'customer.subscription.updated',
            'processed_at' => now(),
        ]);

        $this->deliver($event)->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
        $this->assertTrue(
            ProcessedWebhookEvent::query()->where('event_id', 'rc:'.$event['id'])->exists(),
        );
    }

    public function test_the_signature_is_verified_over_the_raw_bytes_the_sender_sent(): void
    {
        // Every property of this body is one a decode-and-re-encode round trip
        // changes: the unescaped `/`, the literal Turkish letters, `9.90`, and
        // the indentation. A handler verifying `$request->all()` re-encoded
        // rejects a genuinely valid signature here, which in production is five
        // 403s and an abandoned event.
        $raw = $this->awkwardBody();

        $response = RawWebhookRequest::withBody($raw)
            ->signedWith(static::WEBHOOK_SECRET, $this->signedAt())
            ->deliverTo($this, static::ROUTE);

        $response->assertOk();
        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    public function test_a_body_altered_after_signing_is_rejected(): void
    {
        // The negative control for the test above: same helper, same secret, one
        // field changed after signing.
        $raw = $this->awkwardBody();
        $tampered = str_replace('"RENEWAL"', '"EXPIRATION"', $raw);
        $signedAt = $this->signedAt();

        $this->assertNotSame($raw, $tampered, 'The fixture was not actually tampered with.');

        RawWebhookRequest::withBody($tampered)
            ->withHeader(
                RawWebhookRequest::SIGNATURE_HEADER,
                "t={$signedAt},v1=".RawWebhookRequest::signatureFor($raw, static::WEBHOOK_SECRET, $signedAt),
            )
            ->deliverTo($this, static::ROUTE)
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
    }

    public function test_a_delivery_carrying_no_signature_at_all_is_rejected(): void
    {
        RawWebhookRequest::withBody((string) json_encode($this->payload($this->event('RENEWAL', Str::uuid()->toString()))))
            ->deliverTo($this, static::ROUTE)
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_signature_signed_eighty_minutes_ago_is_outside_the_tolerance(): void
    {
        // 80 minutes is roughly RevenueCat's whole retry window, and it is the
        // tempting wrong tolerance. `t` is the signing time of THAT attempt, so a
        // header this old is a replayed capture rather than a late retry.
        $this->deliver(
            $this->event('RENEWAL', $this->makeTeam()->id),
            $this->signedAt() - 80 * 60,
        )->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_signature_signed_four_minutes_ago_is_still_inside_the_tolerance(): void
    {
        // The companion that stops a zero tolerance from passing the test above:
        // a real delivery crossing a slow network still has to be accepted.
        $this->deliver(
            $this->event('RENEWAL', $this->makeTeam()->id),
            $this->signedAt() - 4 * 60,
        )->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    public function test_an_unconfigured_signing_secret_refuses_every_delivery(): void
    {
        // Fails closed. The endpoint queues a job that can move a paid tier, so
        // an endpoint that cannot authenticate its caller must not accept one.
        config(['revenuecat.webhook_secret' => null]);

        $this->deliver($this->event('RENEWAL', $this->makeTeam()->id))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_sandbox_event_writes_nothing_and_still_returns_200(): void
    {
        // A sandbox purchase granting a production `business` tier is money out
        // of the door, and it is rejected HERE in code rather than trusted to a
        // dashboard filter. 200 because a non-200 costs a delivery, and there is
        // nothing to retry.
        $team = $this->makeTeam();

        $this->deliver($this->event('INITIAL_PURCHASE', $team->id, ['environment' => 'SANDBOX']))
            ->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
        $this->assertSame(Plan::Free, $team->refresh()->plan);
    }

    public function test_a_sandbox_event_is_accepted_only_where_the_deployment_opted_in(): void
    {
        // The other half of the gate: the flag WIDENS what the event field is
        // allowed to say, it never replaces reading it.
        config(['revenuecat.accept_sandbox' => true]);

        $this->deliver($this->event('RENEWAL', $this->makeTeam()->id, ['environment' => 'SANDBOX']))
            ->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    public function test_an_event_naming_no_environment_is_treated_as_not_production(): void
    {
        // An absent or unrecognised `environment` is not evidence of a production
        // purchase, and the direction that cannot cost money is to ignore it.
        $team = $this->makeTeam();

        $this->deliver($this->event('RENEWAL', $team->id, ['environment' => null]))->assertOk();

        // And a value that is not a string at all. `(string) $event['environment']`
        // on an array is "Array to string conversion", which `HandleExceptions`
        // promotes to an ErrorException: a 500 that burns the delivery, which is
        // the same one-line failure this repo already met on two public write
        // paths (see the limiter note in bootstrap/app.php).
        $this->deliver($this->event('RENEWAL', $team->id, ['environment' => ['PRODUCTION']]))->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_a_replayed_event_id_queues_the_re_read_only_once(): void
    {
        // Each delivery is signed afresh, exactly as RevenueCat re-signs a retry
        // while reusing the payload `id`.
        $event = $this->event('EXPIRATION', $this->makeTeam()->id);

        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
        $this->assertSame(1, ProcessedWebhookEvent::query()->count());
    }

    public function test_an_unknown_team_returns_200(): void
    {
        // The controller does not resolve teams at all: whether an App User ID
        // has a team behind it is the job's decision, and it answers it with a
        // warning. What matters here is that the delivery is not burned.
        $this->deliver($this->event('RENEWAL', Str::uuid()->toString()))->assertOk();

        Queue::assertPushed(SyncRevenueCatEntitlement::class, 1);
    }

    public function test_an_unreadable_body_returns_200_and_queues_nothing(): void
    {
        // Correctly signed and still unusable: nothing about a retry can improve
        // it, so it must not burn one of five deliveries.
        RawWebhookRequest::withBody('{"api_version":"1.0"}')
            ->signedWith(static::WEBHOOK_SECRET, $this->signedAt())
            ->deliverTo($this, static::ROUTE)
            ->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
    }

    public function test_the_controller_writes_no_entitlement_of_its_own(): void
    {
        // The load-bearing one. With the queue faked, nothing downstream runs, so
        // any entitlement column that moved was moved by the controller.
        // Refreshed before the snapshot: `plan` carries a database default, so an
        // unrefreshed model has no value for it to compare against.
        $team = $this->makeTeam()->refresh();
        $before = $team->only($this->entitlementColumns());

        $this->deliver($this->event('INITIAL_PURCHASE', $team->id))->assertOk();

        $this->assertSame(
            $before,
            $team->refresh()->only($this->entitlementColumns()),
            'The controller decided grant-or-revoke, which is the job\'s decision and only its.',
        );
    }

    public function test_a_paywall_impression_is_ignored_outright(): void
    {
        // Named on its own rather than left to the exhaustive test below, because
        // it is the type whose cost is measurable: it fires on app opens, so
        // queueing one would buy a store API call and a dedup row per impression.
        $this->deliver($this->event('PAYWALL_IMPRESSION', $this->makeTeam()->id))->assertOk();

        Queue::assertNotPushed(SyncRevenueCatEntitlement::class);
        $this->assertSame(0, ProcessedWebhookEvent::query()->count());
    }

    public function test_every_type_that_can_change_entitlement_is_queued_and_every_other_type_is_ignored(): void
    {
        // An ALLOWLIST, asserted against RevenueCat's whole documented list, so a
        // type added next year defaults to ignored: an unknown type dispatched is
        // a re-read that could revoke on a payload nobody has read the docs for.
        $team = $this->makeTeam();

        foreach ([...static::ENTITLEMENT_TYPES, ...static::IGNORED_TYPES] as $type) {
            $this->deliver($this->event($type, $team->id))->assertOk();
        }

        $this->assertSame(
            static::ENTITLEMENT_TYPES,
            array_values(array_map(
                static fn (array $event): string => (string) $event['type'],
                $this->queuedEvents(),
            )),
            'The queued set is not exactly the entitlement-changing set.',
        );

        $this->assertSame(
            count(static::ENTITLEMENT_TYPES),
            ProcessedWebhookEvent::query()->count(),
            'An ignored type claimed an event id, which fills the dedup table with events that have no side effect.',
        );
    }

    public function test_the_route_is_not_csrf_gated_while_a_web_route_is(): void
    {
        // THE FAILURE THAT LOOKS LIKE NOTHING. A `web.php` route inherits the
        // `web` group and 419s every delivery; RevenueCat retries five times and
        // abandons, so the symptom is five identical log lines and permanent
        // silence.
        Route::middleware('web')->post('__csrf/probe', fn (): string => 'reached');

        $this->withCsrfEnforced(function (): void {
            $this->post('__csrf/probe')->assertStatus(419);

            $response = $this->deliver($this->event('RENEWAL', $this->makeTeam()->id));

            $this->assertNotSame(
                419,
                $response->getStatusCode(),
                'The webhook route is CSRF-gated, so every real delivery is a 419.',
            );

            // And 200 rather than merely not-419: a route that does not exist is
            // also not a 419, which is how this assertion passes vacuously.
            $response->assertOk();
        });
    }

    /**
     * Run a closure with CSRF verification genuinely armed.
     *
     * `PreventRequestForgery::handle()` short-circuits on
     * `$app->runningInConsole() && $app->runningUnitTests()`, and the second half
     * reads the container's `env` binding, so the whole suite is CSRF-exempt by
     * default and cannot see this class of failure. Rebinding `env` for the
     * duration of the request is what re-arms it; the probe route above is what
     * proves the manoeuvre worked rather than merely appearing to.
     */
    protected function withCsrfEnforced(callable $callback): void
    {
        $environment = $this->app->make('env');

        $this->app->instance('env', 'production');

        try {
            $callback();
        } finally {
            $this->app->instance('env', $environment);
        }
    }

    /**
     * Deliver one event, signed, as RevenueCat would.
     *
     * @param  array<string, mixed>  $event
     * @param  int|null  $signedAt  the signing time carried in the header, for
     *                              the tolerance tests
     */
    protected function deliver(array $event, ?int $signedAt = null): TestResponse
    {
        return RawWebhookRequest::withPayload($this->payload($event))
            ->signedWith(static::WEBHOOK_SECRET, $signedAt ?? $this->signedAt())
            ->deliverTo($this, static::ROUTE);
    }

    /**
     * The envelope RevenueCat posts: an `api_version` and the event beside it.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function payload(array $event): array
    {
        return [
            'api_version' => '1.0',
            'event' => $event,
        ];
    }

    /**
     * One event, carrying the four fields this endpoint reads and a couple it
     * must ignore.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function event(string $type, string $appUserId, array $overrides = []): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'type' => $type,
            'app_user_id' => $appUserId,
            'event_timestamp_ms' => CarbonImmutable::now()->getTimestampMs(),
            'environment' => 'PRODUCTION',
            'store' => 'APP_STORE',
            // Deliberately present and deliberately never read here: the tier is
            // the authoritative read's answer, not the payload's.
            'product_id' => 'uptizm_business_monthly',
            ...$overrides,
        ];
    }

    /**
     * The events the controller queued a re-read for, in dispatch order.
     *
     * The job keeps its event array protected, so it is read through a closure
     * bound to the job rather than by widening the job's surface for a test.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function queuedEvents(): array
    {
        return Queue::pushed(SyncRevenueCatEntitlement::class)
            ->map(static fn (SyncRevenueCatEntitlement $job): array => (array) (fn (): array => $this->event)->call($job))
            ->values()
            ->all();
    }

    /**
     * The signing time a delivery carries by default: now, as the handler reads
     * it.
     *
     * Read through Carbon rather than `time()` so the handler's tolerance and the
     * harness agree about what "now" is.
     */
    protected function signedAt(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }

    /**
     * Every column {@see WriteTeamEntitlement} owns, which
     * is exactly the set this controller must never touch.
     *
     * @return array<int, string>
     */
    protected function entitlementColumns(): array
    {
        return [
            'plan',
            'plan_status',
            'plan_provider',
            'plan_provider_status',
            'plan_product_id',
            'plan_source_event_at',
            'plan_current_period_end',
            'plan_renews',
            'plan_grace_period_ends_at',
            'plan_manage_url',
        ];
    }

    /**
     * A team on the free tier, with no store rail on record.
     */
    protected function makeTeam(): Team
    {
        $user = User::factory()->create();

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Ops Team',
            'personal_team' => true,
        ]);
    }

    /**
     * A body written the way a sender writes one, not the way `json_encode`
     * would: the unescaped `/`, literal Turkish letters, `9.90` rather than
     * `9.9`, and the sender's own indentation. Each one alone breaks a signature
     * that was recomputed over a reparsed body.
     */
    protected function awkwardBody(): string
    {
        return <<<'JSON'
            {
              "api_version": "1.0",
              "event": {
                "id": "9d1e1a5c-4f2a-4c1b-9f0e-2b7d3c5a8e11",
                "type": "RENEWAL",
                "app_user_id": "3f1b8f7e-2c4a-4f1e-9b0d-7a5c2e8d1b40",
                "store": "APP_STORE",
                "environment": "PRODUCTION",
                "price": 9.90,
                "currency": "USD",
                "note": "Ödeme alındı, yenilendi",
                "management_url": "https://apps.apple.com/account/subscriptions"
              }
            }
            JSON;
    }
}
