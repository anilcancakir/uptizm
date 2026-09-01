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
     * The sign-out case, and the one the freshness horizon deliberately does
     * NOT cover.
     *
     * That constant enumerates the false negatives it accepts (a phone wiped,
     * reinstalled or silenced while the app was closed) and argues a day bounds
     * them. Every one of those is a device that went SILENT. A sign-out is the
     * opposite: an event the client observes on a live session with a valid
     * token in hand, and until this endpoint existed there was no verb for it,
     * so the row kept the previous person's alias and its `on` for the whole
     * window while the device had been detached from them.
     *
     * Idempotent, because a sign-out can be retried: the second release finds
     * nothing to remove and still answers 204, so a client that reports twice
     * (or reports after a failure it could not tell from a timeout) is not
     * handed an error for a state that is already correct.
     */
    public function test_a_released_device_stops_vouching_for_the_person_who_signed_out(): void
    {
        $user = $this->actingAsUser();

        $this->report($user, 'sub-phone', 'on')->assertNoContent();
        $this->assertTrue(PushDevice::canReachByPush($user->fresh()));

        $this->release('sub-phone')->assertNoContent();

        $this->assertFalse(PushDevice::canReachByPush($user->fresh()));
        $this->assertDatabaseCount('push_devices', 0);

        $this->release('sub-phone')->assertNoContent();
        $this->assertFalse(PushDevice::canReachByPush($user->fresh()));
    }

    /**
     * The release is per DEVICE, not per person, and this is the case that
     * decides it: an operator who signs out of the browser on their laptop is
     * still carrying the phone that pages them.
     *
     * Releasing every row the caller owns would strand that responder for the
     * rest of the freshness window, and the ladder would walk past somebody it
     * could in fact have woken, which is the same class of harm as the stale
     * `on` this endpoint exists to remove.
     */
    public function test_a_release_leaves_this_persons_other_handset_vouching(): void
    {
        $user = $this->actingAsUser();

        $this->report($user, 'sub-phone', 'on')->assertNoContent();
        $this->report($user, 'sub-laptop', 'on')->assertNoContent();

        $this->release('sub-laptop')->assertNoContent();

        $this->assertSame(1, PushDevice::query()->where('user_id', $user->getKey())->count());
        $this->assertTrue(PushDevice::canReachByPush($user->fresh()));
        $this->assertDatabaseHas('push_devices', [
            'user_id' => $user->getKey(),
            'subscription_id' => 'sub-phone',
        ]);
    }

    /**
     * The authorisation case, and it is the same one `store` answers: the row is
     * addressed by the SESSION's user plus the subscription id, so there is
     * nothing in this request a caller can point at somebody else's device.
     *
     * It matters more here than on the write side. A subscription id is not a
     * secret (it travels in every report, and two people share a handset), and
     * if a release were keyed on the id alone, knowing one would be enough to
     * make another team's on-call responder unreachable for a day.
     */
    public function test_a_release_cannot_reach_somebody_elses_device(): void
    {
        $caller = $this->actingAsUser();
        $other = User::factory()->create();

        PushDevice::query()->create([
            'user_id' => $other->getKey(),
            'external_id' => 'user_'.$other->getKey(),
            'subscription_id' => 'sub-shared-handset',
            'reachability' => 'on',
            'captured_at' => now(),
            'reported_at' => now(),
        ]);

        $this->release('sub-shared-handset')->assertNoContent();

        $this->assertDatabaseCount('push_devices', 1);
        $this->assertTrue(PushDevice::canReachByPush($other->fresh()));
        $this->assertFalse(PushDevice::canReachByPush($caller->fresh()));
    }

    /**
     * A release names one device or it names nothing. There is no "release
     * whatever I have" shape on purpose: the only rows that can vouch for
     * anybody hold a subscription id ({@see PushDevice::canReachByPush()}), so a
     * body without one describes no device this endpoint could act on, and
     * guessing would be the all-devices release the case above refuses.
     */
    public function test_a_release_naming_no_device_is_refused(): void
    {
        $user = $this->actingAsUser();
        $this->report($user, 'sub-phone', 'on')->assertNoContent();

        $this->postJson('/api/v1/devices/push-state/release', [
            'subscription_id' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['subscription_id'], responseKey: 'errors');

        $this->assertTrue(PushDevice::canReachByPush($user->fresh()));
    }

    public function test_an_unauthenticated_release_is_refused(): void
    {
        $user = User::factory()->create();
        PushDevice::query()->create([
            'user_id' => $user->getKey(),
            'external_id' => 'user_'.$user->getKey(),
            'subscription_id' => 'sub-phone',
            'reachability' => 'on',
            'captured_at' => now(),
            'reported_at' => now(),
        ]);

        $this->postJson('/api/v1/devices/push-state/release', [
            'subscription_id' => 'sub-phone',
        ])->assertStatus(401);

        $this->assertDatabaseCount('push_devices', 1);
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
     * Release one device, the way the client does on its way out of a session.
     *
     * The caller is whoever is authenticated: the request carries the device,
     * never the person.
     */
    protected function release(?string $subscriptionId): TestResponse
    {
        return $this->postJson('/api/v1/devices/push-state/release', [
            'subscription_id' => $subscriptionId,
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
