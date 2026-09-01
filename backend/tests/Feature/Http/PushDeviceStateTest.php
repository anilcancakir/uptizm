<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Models\PushDevice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see PushDeviceController}: the endpoint a signed-in client posts
 * `PushDeliverySnapshot.toMap()` to, one row per device, and the two things it
 * must never do: believe an identity out of the request body, or let one
 * person's report describe somebody else's device.
 *
 * The payload shape is the package's, not this app's
 * (`magic_notifications/lib/src/models/push_delivery_snapshot.dart`), so every
 * body here is spelled the way the client actually sends it, nulls included.
 */
class PushDeviceStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_device_report_is_stored_for_the_authenticated_user(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/devices/push-state', [
            'external_id' => 'user_'.$user->getKey(),
            'subscription_id' => 'sub-phone',
            'reachability' => 'on',
            'captured_at' => now()->toIso8601String(),
        ])->assertNoContent();

        $this->assertDatabaseHas('push_devices', [
            'user_id' => $user->getKey(),
            'subscription_id' => 'sub-phone',
            'reachability' => 'on',
        ]);
    }

    /**
     * The product owner's explicit case: one person, several devices. Each is
     * its own row, keyed by the subscription id, because "can this responder be
     * paged" is a question about ANY of their devices and a single row per
     * person would let the laptop overwrite the phone.
     */
    public function test_a_second_device_of_the_same_person_is_stored_beside_the_first(): void
    {
        $user = $this->actingAsUser();

        $this->report($user, 'sub-phone', 'on')->assertNoContent();
        $this->report($user, 'sub-laptop', 'blocked')->assertNoContent();

        $this->assertSame(2, PushDevice::query()->where('user_id', $user->getKey())->count());
        $this->assertTrue(PushDevice::canReachByPush($user->fresh()));
    }

    public function test_a_repeat_report_updates_that_device_rather_than_adding_a_row(): void
    {
        $user = $this->actingAsUser();

        $this->report($user, 'sub-phone', 'on')->assertNoContent();
        $this->report($user, 'sub-phone', 'blocked')->assertNoContent();

        $this->assertSame(1, PushDevice::query()->where('user_id', $user->getKey())->count());
        $this->assertDatabaseHas('push_devices', [
            'user_id' => $user->getKey(),
            'subscription_id' => 'sub-phone',
            'reachability' => 'blocked',
        ]);
        $this->assertFalse(PushDevice::canReachByPush($user->fresh()));
    }

    /**
     * The authorisation case. `external_id` is the alias the DEVICE reports
     * carrying, and the request is what carries it here, so a body naming
     * somebody else is either a confused device or a caller reaching for
     * another person's row. Neither may write, and neither may write under the
     * session's own id either: silently reattributing the report would make the
     * caller's own reachability depend on a device they do not hold.
     */
    public function test_a_report_naming_somebody_elses_external_id_is_refused(): void
    {
        $caller = $this->actingAsUser();
        $other = User::factory()->create();

        $this->postJson('/api/v1/devices/push-state', [
            'external_id' => 'user_'.$other->getKey(),
            'subscription_id' => 'sub-stolen',
            'reachability' => 'on',
            'captured_at' => now()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['external_id'], responseKey: 'errors');

        $this->assertDatabaseCount('push_devices', 0);
        $this->assertFalse(PushDevice::canReachByPush($caller->fresh()));
        $this->assertFalse(PushDevice::canReachByPush($other->fresh()));
    }

    /**
     * A device that is subscribed as nobody is a real and reportable state: it
     * is exactly what a fresh install, or a device mid-logout, looks like. It
     * stores, and it vouches for nothing.
     */
    public function test_a_device_carrying_no_identity_yet_is_stored_and_reaches_nobody(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/devices/push-state', [
            'external_id' => null,
            'subscription_id' => null,
            'reachability' => 'off',
            'captured_at' => now()->toIso8601String(),
        ])->assertNoContent();

        $this->assertDatabaseHas('push_devices', [
            'user_id' => $user->getKey(),
            'subscription_id' => null,
            'reachability' => 'off',
        ]);
        $this->assertFalse(PushDevice::canReachByPush($user->fresh()));
    }

    /**
     * A device claiming `on` with no address to deliver to is claiming
     * something the client's own derivation cannot produce, and the read
     * refuses it rather than trusting a payload over the reasoning behind it.
     */
    public function test_a_reachable_claim_with_no_subscription_id_reaches_nobody(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/devices/push-state', [
            'external_id' => 'user_'.$user->getKey(),
            'subscription_id' => null,
            'reachability' => 'on',
            'captured_at' => now()->toIso8601String(),
        ])->assertNoContent();

        $this->assertFalse(PushDevice::canReachByPush($user->fresh()));
    }

    public function test_a_reachability_outside_the_vocabulary_is_refused(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/devices/push-state', [
            'external_id' => 'user_'.$user->getKey(),
            'subscription_id' => 'sub-phone',
            'reachability' => 'probably',
            'captured_at' => now()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['reachability'], responseKey: 'errors');

        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_an_unauthenticated_report_is_refused(): void
    {
        $this->postJson('/api/v1/devices/push-state', [
            'external_id' => 'user_'.Str::uuid(),
            'subscription_id' => 'sub-phone',
            'reachability' => 'on',
            'captured_at' => now()->toIso8601String(),
        ])->assertStatus(401);

        $this->assertDatabaseCount('push_devices', 0);
    }

    /**
     * Freshness is measured on the SERVER's clock, so a device with a wrong (or
     * a hand-set) one cannot buy itself a reachable week by claiming an old
     * capture. The row stores what the device said and answers with what the
     * server saw.
     */
    public function test_freshness_is_the_servers_clock_not_the_devices(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/devices/push-state', [
            'external_id' => 'user_'.$user->getKey(),
            'subscription_id' => 'sub-phone',
            'reachability' => 'on',
            'captured_at' => now()->subYear()->toIso8601String(),
        ])->assertNoContent();

        $this->assertTrue(PushDevice::canReachByPush($user->fresh()));
    }

    /**
     * The freshness horizon, named in absolute hours rather than derived from
     * the constant.
     *
     * A test that spells its age as `FRESH_FOR_HOURS + 1` passes at any horizon
     * at all and says nothing about which one is right. This pair is what holds
     * the constant to the error it is sized against: a phone wiped,
     * reinstalled, or with its notification permission revoked while the app
     * was closed reports nothing at all and goes on vouching `on` for exactly
     * as long as this window, and every rung paging it is recorded as having
     * reached somebody. The client reports on every launch, sign-in, permission
     * change and subscription change, so a device in daily use refreshes far
     * inside a day.
     */
    public function test_a_device_silent_since_yesterday_vouches_for_nothing(): void
    {
        $user = $this->actingAsUser();

        $this->report($user, 'sub-phone', 'on')->assertNoContent();
        $this->assertTrue(PushDevice::canReachByPush($user->fresh()));

        PushDevice::query()->update(['reported_at' => now()->subHours(36)]);

        $this->assertFalse(PushDevice::canReachByPush($user->fresh()));
    }

    /**
     * The other axis, and the reason the horizon is not shorter still: a device
     * that reported this morning is a device the server may page. Without this,
     * the case above passes on a horizon of zero, which would strand every
     * responder whose app has been closed since breakfast.
     */
    public function test_a_device_heard_from_this_morning_is_still_reachable(): void
    {
        $user = $this->actingAsUser();

        $this->report($user, 'sub-phone', 'on')->assertNoContent();

        PushDevice::query()->update(['reported_at' => now()->subHours(12)]);

        $this->assertTrue(PushDevice::canReachByPush($user->fresh()));
    }

    /**
     * Post one device report as [$user].
     */
    protected function report(User $user, string $subscriptionId, string $reachability): TestResponse
    {
        return $this->postJson('/api/v1/devices/push-state', [
            'external_id' => 'user_'.$user->getKey(),
            'subscription_id' => $subscriptionId,
            'reachability' => $reachability,
            'captured_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Authenticate as a user on a freshly created team, the way every other
     * `api/v1` test in this directory does.
     */
    protected function actingAsUser(): User
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $user;
    }
}
