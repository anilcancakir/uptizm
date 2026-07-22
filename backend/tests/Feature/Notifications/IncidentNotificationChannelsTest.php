<?php

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use onesignal\client\api\DefaultApi;
use onesignal\client\model\CreateNotificationSuccessResponse;
use Tests\TestCase;

/**
 * Covers the OneSignal push registration wired in this step: the `onesignal`
 * feature flag, the `push` logical channel in the `NotificationPreferenceRegistry`,
 * and the split between `via()` (driver-name narrowing) and
 * `GateNotificationChannels` (logical-name preference gating).
 */
class IncidentNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_opened_via_includes_onesignal_mail_and_database(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $channels = (new IncidentOpened($incident))->via($user);

        $this->assertSame(['mail', 'database', 'onesignal'], $channels);
    }

    public function test_via_excludes_onesignal_when_the_push_app_is_unprovisioned(): void
    {
        // Feature on but no app_id: OneSignalChannel::send() would throw, so the
        // driver must not be advertised (it would dead-letter a push job per
        // recipient on every incident).
        config(['magic-starter.onesignal.app_id' => null]);

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $this->assertSame(['mail', 'database'], (new IncidentOpened($incident))->via($user));
    }

    public function test_push_is_registered_as_a_logical_channel_for_both_incident_types(): void
    {
        $this->assertContains('push', NotificationPreferenceRegistry::channels(IncidentOpened::class));
        $this->assertContains('push', NotificationPreferenceRegistry::defaults(IncidentOpened::class));
        $this->assertContains('push', NotificationPreferenceRegistry::channels(IncidentResolved::class));
        $this->assertContains('push', NotificationPreferenceRegistry::defaults(IncidentResolved::class));
    }

    public function test_a_user_with_no_disabling_setting_receives_the_onesignal_push(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        Mail::fake();

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $client = Mockery::mock(DefaultApi::class);
        $client->shouldReceive('createNotification')->once()->andReturn(new CreateNotificationSuccessResponse);
        $this->app->instance(DefaultApi::class, $client);

        // Dispatches through the real channel manager (not Notification::fake,
        // which never fires NotificationSending and so never runs
        // GateNotificationChannels), so the mocked client only receives the
        // call if the onesignal channel actually delivers.
        Notification::send($user, new IncidentOpened($incident));
    }

    public function test_a_user_who_disabled_push_does_not_receive_the_onesignal_channel(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        Mail::fake();

        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $user->notificationSettings()->create([
            'type' => 'incident_opened',
            'channel' => 'push',
            'is_enabled' => false,
        ]);

        $client = Mockery::mock(DefaultApi::class);
        $client->shouldNotReceive('createNotification');
        $this->app->instance(DefaultApi::class, $client);

        // via() still returns 'onesignal' (it narrows by driver name, not the
        // logical 'push' name), so suppression must come from
        // GateNotificationChannels resolving 'onesignal' -> 'push' and
        // consulting the disabled NotificationSetting row, not from via().
        $this->assertSame(
            ['mail', 'database', 'onesignal'],
            (new IncidentOpened($incident))->via($user),
        );

        Notification::send($user, new IncidentOpened($incident));
    }

    public function test_sms_is_registered_as_available_but_not_default_for_both_incident_types(): void
    {
        // SMS is opt-in: it must be an advertised channel (so the preference
        // matrix surfaces a toggle) but MUST NOT be default-enabled (a default-on
        // sms would text every member on every incident).
        $this->assertContains('sms', NotificationPreferenceRegistry::channels(IncidentOpened::class));
        $this->assertNotContains('sms', NotificationPreferenceRegistry::defaults(IncidentOpened::class));
        $this->assertContains('sms', NotificationPreferenceRegistry::channels(IncidentResolved::class));
        $this->assertNotContains('sms', NotificationPreferenceRegistry::defaults(IncidentResolved::class));
    }

    public function test_via_excludes_sms_for_a_user_without_an_explicit_sms_preference(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = User::factory()->create(['phone' => '+15551234567']);

        // A phone and a provisioned app are not enough: without an explicit
        // enabled sms row the channel stays off (opt-out default preserved).
        $this->assertSame(['mail', 'database', 'onesignal'], (new IncidentOpened($incident))->via($user));
    }

    public function test_via_includes_sms_for_a_user_who_enabled_sms_with_phone_and_app_id(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = $this->userOptedIntoSms('incident_opened');

        $this->assertContains('onesignal-sms', (new IncidentOpened($incident))->via($user));
    }

    public function test_incident_resolved_via_includes_sms_for_an_opted_in_user(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = $this->userOptedIntoSms('incident_resolved');

        $this->assertContains('onesignal-sms', (new IncidentResolved($incident))->via($user));
    }

    public function test_via_excludes_sms_when_the_push_app_is_unprovisioned(): void
    {
        config(['magic-starter.onesignal.app_id' => null]);

        $incident = $this->makeIncident();
        $user = $this->userOptedIntoSms('incident_opened');

        $this->assertNotContains('onesignal-sms', (new IncidentOpened($incident))->via($user));
    }

    public function test_via_excludes_sms_when_the_user_has_no_phone(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $user->notificationSettings()->create([
            'type' => 'incident_opened',
            'channel' => 'sms',
            'is_enabled' => true,
        ]);

        $this->assertNotContains('onesignal-sms', (new IncidentOpened($incident))->via($user));
    }

    public function test_sms_send_registers_the_subscription_on_demand_once(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        Mail::fake();

        $incident = $this->makeIncident();
        $user = $this->userOptedIntoSms('incident_opened');

        // Step 7's contract on the SMS path is to trigger the on-demand
        // registration exactly once before building the payload. The
        // idempotency/persistence itself is Step 4's (magic-starter-laravel)
        // tested responsibility, so mock the helper at the wiring seam.
        $subscriptions = Mockery::mock(OneSignalSubscriptions::class);
        $subscriptions->shouldReceive('ensureSmsSubscription')->once()->andReturn(true);
        $this->app->instance(OneSignalSubscriptions::class, $subscriptions);

        $client = Mockery::mock(DefaultApi::class);
        // Both the push and the sms driver deliver through createNotification.
        $client->shouldReceive('createNotification')->andReturn(new CreateNotificationSuccessResponse);
        $this->app->instance(DefaultApi::class, $client);

        Notification::send($user, new IncidentOpened($incident));
    }

    public function test_person_mail_and_database_channels_are_unchanged_by_the_sms_path(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = $this->userOptedIntoSms('incident_opened');

        // Opting into sms adds the sms driver without disturbing the base set.
        $channels = (new IncidentOpened($incident))->via($user);

        $this->assertSame(
            ['mail', 'database', 'onesignal', 'onesignal-sms'],
            $channels,
        );
    }

    /**
     * Create a person who opted into SMS for the given incident type: a phone
     * plus an explicit enabled `sms` {@see NotificationSetting} row.
     */
    private function userOptedIntoSms(string $type): User
    {
        $user = User::factory()->create(['phone' => '+15551234567']);
        $user->notificationSettings()->create([
            'type' => $type,
            'channel' => 'sms',
            'is_enabled' => true,
        ]);

        return $user;
    }

    /**
     * Build a persisted incident with a primary monitor for a fresh team.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeIncident(array $overrides = []): Incident
    {
        $owner = User::factory()->create();

        $team = Team::create([
            'user_id' => $owner->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $monitor = Monitor::create([
            'team_id' => $team->id,
            'name' => 'API Health',
            'type' => 'http',
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
        ]);

        return Incident::create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Health is down',
            'impact' => 'critical',
            'severity' => 'critical',
            'signal_source' => 'user_threshold',
            'lifecycle' => 'detected',
            'started_at' => now(),
            ...$overrides,
        ]);
    }
}
