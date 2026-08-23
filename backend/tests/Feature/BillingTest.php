<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\Plan;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Invoice as CashierInvoice;
use Laravel\Cashier\PaymentMethod as CashierPaymentMethod;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Invoice as StripeInvoice;
use Stripe\PaymentMethod as StripePaymentMethod;
use Tests\TestCase;

/**
 * Locks the full-screen billing read surface: the static plan catalog plus the
 * four team-scoped endpoints the Flutter billing screen renders.
 *
 * The catalog (`GET /billing/plans`) is served from `config/plans.php`, the
 * single price+limits source; `GET /billing/usage` counts real team resources
 * against that catalog; `GET /billing/invoices` cursor-paginates Cashier
 * invoices; and `GET /billing/payment-method` is the one Stripe-live endpoint,
 * which soft-fails to null fields (never a 500) when the Stripe call throws.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_returns_the_static_catalog_cheapest_first(): void
    {
        [$user] = $this->makeTeam();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing/plans');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'tagline',
                    'monthly',
                    'annual',
                    'currency',
                    'ai_line',
                    'features',
                    'responder_add_on',
                    'recommended',
                    'limits' => [
                        'monitors',
                        'check_interval_sec',
                        'status_pages',
                        'subscribers',
                        'responders',
                        'ai',
                        'white_label',
                        'private_pages',
                        'sso',
                    ],
                ],
            ],
        ]);

        // Order is load-bearing for the upgrade/downgrade CTA: cheapest first.
        $response->assertJsonPath('data.0.id', 'free');
        $response->assertJsonPath('data.1.id', 'pro');
        $response->assertJsonPath('data.2.id', 'business');
        $response->assertJsonPath('data.3.id', 'enterprise');

        $response->assertJsonPath('data.1.monthly', 34);
        $response->assertJsonPath('data.1.annual', 29);
        $response->assertJsonPath('data.1.recommended', true);
        $response->assertJsonPath('data.1.limits.monitors', 50);
        $response->assertJsonPath('data.1.limits.ai', 'analysis');
        $response->assertJsonPath('data.3.limits.monitors', null);
    }

    public function test_usage_returns_team_scoped_counts_against_the_plan_limits(): void
    {
        /*
         * Pinned to the middle of a month, because the fixture below places one check two
         * days back and expects it inside the current-month window. On the 1st and the 2nd
         * of any month that lands in the PREVIOUS month, the count comes back one short, and
         * the failure reads as a broken usage endpoint rather than as a calendar. It fired for
         * real on 1 August. The endpoint was correct both times.
         */
        $this->travelTo(now()->startOfMonth()->addDays(14));

        [$user, $team] = $this->makeTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        // Two additional members plus the owner: three distinct responders.
        $team->users()->attach(User::factory()->create()->id, ['role' => 'editor']);
        $team->users()->attach(User::factory()->create()->id, ['role' => 'member']);

        $monitorA = $this->makeMonitor($team);
        $monitorB = $this->makeMonitor($team);
        $this->makeMonitor($team);

        // Two checks this month for the team's monitors (counted) and one last
        // month (excluded by the current-month window).
        $this->makeCheck($monitorA, $team, now());
        $this->makeCheck($monitorB, $team, now()->subDays(2));
        $this->makeCheck($monitorA, $team, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        // A foreign team's monitor + check must never leak into the counts.
        [, $otherTeam] = $this->makeTeam();
        $otherMonitor = $this->makeMonitor($otherTeam);
        $this->makeCheck($otherMonitor, $otherTeam, now());

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing/usage');

        $response->assertOk();
        $response->assertJsonPath('monitors.used', 3);
        $response->assertJsonPath('monitors.limit', 50);
        $response->assertJsonPath('responders.used', 3);
        $response->assertJsonPath('responders.limit', 3);
        $response->assertJsonPath('checks_this_month.used', 2);
        $response->assertJsonPath('checks_this_month.limit', null);
    }

    public function test_invoices_are_cursor_paginated_from_cashier(): void
    {
        [$user, $team] = $this->makeTeam();

        $invoice = new CashierInvoice($team, StripeInvoice::constructFrom([
            'id' => 'in_test_1',
            'number' => 'INV-0001',
            'customer' => $team->stripe_id,
            'status' => 'paid',
            'total' => 2900,
            'currency' => 'usd',
            'created' => now()->timestamp,
            'invoice_pdf' => 'https://stripe.test/invoice.pdf',
        ]));

        $paginator = new CursorPaginator([$invoice], 24, null, ['path' => '/']);

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('cursorPaginateInvoices')
            ->once()
            ->andReturn($paginator);

        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing/invoices');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'number',
                    'date',
                    'amount',
                    'status',
                    'pdf_url',
                ],
            ],
            'next_cursor',
        ]);
        $response->assertJsonPath('data.0.id', 'in_test_1');
        $response->assertJsonPath('data.0.number', 'INV-0001');
        $response->assertJsonPath('data.0.status', 'paid');
        $response->assertJsonPath('data.0.pdf_url', 'https://stripe.test/invoice.pdf');
        $response->assertJsonPath('next_cursor', null);
    }

    public function test_payment_method_returns_the_card_details_when_present(): void
    {
        [$user, $team] = $this->makeTeam(['plan' => Plan::Pro->value, 'plan_status' => 'active']);

        $renewal = now()->addDays(14);
        Subscription::query()->create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(10),
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_pro',
            'trial_ends_at' => $renewal,
        ]);

        $paymentMethod = new CashierPaymentMethod($team, StripePaymentMethod::constructFrom([
            'id' => 'pm_test_1',
            'type' => 'card',
            'customer' => $team->stripe_id,
            'card' => [
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
            ],
        ]));

        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('defaultPaymentMethod')
            ->once()
            ->andReturn($paymentMethod);

        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing/payment-method');

        $response->assertOk();
        $response->assertJsonPath('brand', 'visa');
        $response->assertJsonPath('last4', '4242');
        $response->assertJsonPath('exp_month', 12);
        $response->assertJsonPath('exp_year', 2030);
        $response->assertJsonPath('renewal_date', $renewal->toIso8601String());
    }

    public function test_payment_method_soft_fails_to_nulls_when_the_stripe_api_fails(): void
    {
        Log::spy();

        [$user, $team] = $this->makeTeam();

        // A Stripe API error, not a bare RuntimeException. The soft-fail is now
        // scoped to the rail's own failures, so the exception TYPE is the thing
        // under test: `ApiConnectionException` extends `ApiErrorException`, which
        // is what the endpoint catches.
        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('defaultPaymentMethod')
            ->andThrow(new ApiConnectionException('Stripe is unreachable.'));

        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/billing/payment-method');

        // Soft-fail is a deliberate degradation: a 200 with null fields, never a
        // 500 that would blank the whole billing screen on a Stripe outage. What
        // changed is that the answer now SAYS which of the two it is, because a
        // body of five nulls was byte-identical to a team with no card on file
        // and the client was left reconstructing the difference from `manage_via`.
        $response->assertOk();
        $response->assertExactJson([
            'available' => false,
            'renewal_date' => null,
            'brand' => null,
            'last4' => null,
            'exp_month' => null,
            'exp_year' => null,
        ]);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_payment_method_does_not_swallow_a_failure_that_is_not_the_rails(): void
    {
        [$user, $team] = $this->makeTeam();

        // The other half of narrowing the catch, and the reason it was narrowed.
        // A `RuntimeException` off this path is not an outage, it is a bug in our
        // own code, and the blanket `Throwable` catch that used to swallow it is
        // what let a real defect sit behind a 200 for months. It propagates now,
        // and this test is what stops the catch being widened back.
        $mockedTeam = Mockery::mock($team);
        $mockedTeam->shouldReceive('defaultPaymentMethod')
            ->andThrow(new RuntimeException('A bug in our own code.'));

        $user->setRelation('currentTeam', $mockedTeam);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/billing/payment-method')->assertStatus(500);
    }

    public function test_billing_read_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/billing/plans')->assertStatus(401);
        $this->getJson('/api/v1/billing/usage')->assertStatus(401);
        $this->getJson('/api/v1/billing/invoices')->assertStatus(401);
        $this->getJson('/api/v1/billing/payment-method')->assertStatus(401);
    }

    /**
     * Build a persisted team + owning user, with the team set as current.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Team}
     */
    protected function makeTeam(array $overrides = []): array
    {
        $user = User::factory()->create();

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Ops Team',
            'personal_team' => true,
            'stripe_id' => 'cus_'.Str::random(14),
            'plan' => Plan::Free->value,
            'plan_status' => null,
            ...$overrides,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        return [$user, $team];
    }

    /**
     * Persist a minimal HTTP monitor owned by the given team.
     */
    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'status' => 'active',
            'last_status' => MonitorStatus::Up,
        ]);
    }

    /**
     * Persist a single check row for the monitor at the given instant.
     */
    protected function makeCheck(Monitor $monitor, Team $team, \DateTimeInterface $checkedAt): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'id' => (string) Str::uuid(),
            'monitor_id' => $monitor->id,
            'team_id' => $team->id,
            'region' => 'us-east-1',
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => 120,
            'checked_at' => $checkedAt,
        ]);
    }
}
