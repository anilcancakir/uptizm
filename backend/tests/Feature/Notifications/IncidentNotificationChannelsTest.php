<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannelType;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\IncidentEscalated;
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

        $this->assertSame(['mail', 'database', 'onesignal', 'broadcast'], $channels);
    }

    public function test_via_excludes_onesignal_when_the_push_app_is_unprovisioned(): void
    {
        // Feature on but no app_id: OneSignalChannel::send() would throw, so the
        // driver must not be advertised (it would dead-letter a push job per
        // recipient on every incident).
        config(['magic-starter.onesignal.app_id' => null]);

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $this->assertSame(['mail', 'database', 'broadcast'], (new IncidentOpened($incident))->via($user));
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
            ['mail', 'database', 'onesignal', 'broadcast'],
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
        $this->assertSame(['mail', 'database', 'onesignal', 'broadcast'], (new IncidentOpened($incident))->via($user));
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
            ['mail', 'database', 'onesignal', 'broadcast', 'onesignal-sms'],
            $channels,
        );
    }

    /**
     * Create a person who opted into SMS for the given incident type: a phone
     * plus an explicit enabled `sms` {@see NotificationSetting} row.
     */
    // ---------------------------------------------------------------------
    // The broadcast channel: live delivery of the in-app row
    // ---------------------------------------------------------------------

    public function test_via_adds_broadcast_alongside_database(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $channels = (new IncidentOpened($incident))->via($user);

        // `broadcast` is the live delivery of the row `database` stores, so it
        // rides alongside and never instead: a client that was closed when the
        // incident fired reads the row over the API on its next start.
        $this->assertContains('broadcast', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_incident_resolved_via_adds_broadcast_too(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $this->assertContains('broadcast', (new IncidentResolved($incident))->via($user));
    }

    public function test_broadcast_is_dropped_when_the_user_disabled_the_in_app_channel(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $user->notificationSettings()->create([
            'type' => 'incident_opened',
            'channel' => 'database',
            'is_enabled' => false,
        ]);

        $channels = (new IncidentOpened($incident))->via($user);

        // Broadcast FOLLOWS database rather than standing on its own. Surviving
        // here would push a frame for a notification no row was ever written for,
        // so the bell would show an entry that vanished on the next fetch. The
        // logical-channel gate cannot catch this: `GateNotificationChannels`
        // allows a driver channel it cannot map back, so broadcast would sail
        // through it fail-open.
        $this->assertNotContains('database', $channels);
        $this->assertNotContains('broadcast', $channels);
    }

    public function test_a_notification_channel_notifiable_never_gets_broadcast(): void
    {
        $incident = $this->makeIncident();
        $channel = NotificationChannel::query()->create([
            'team_id' => $incident->team_id,
            'channel_type' => NotificationChannelType::Slack,
            'name' => 'Ops room',
            'credentials' => ['webhook_url' => 'https://hooks.slack.test/x'],
            'is_enabled' => true,
        ]);

        $channels = (new IncidentOpened($incident))->via($channel);

        // A team webhook is not a person with a socket. Its `via()` arm returns
        // one driver and must stay that way.
        $this->assertNotContains('broadcast', $channels);
    }

    public function test_broadcast_event_name_is_the_short_contract_name(): void
    {
        $incident = $this->makeIncident();

        // Laravel's default would be the fully-qualified
        // BroadcastNotificationCreated. Magic's Reverb channel matches a listener
        // by exact string, so the client would have to hardcode a framework
        // internal; `magic_notifications` listens for this short name instead.
        $this->assertSame('notification.created', (new IncidentOpened($incident))->broadcastAs());
        $this->assertSame('notification.created', (new IncidentResolved($incident))->broadcastAs());
    }

    public function test_broadcast_payload_matches_the_api_row_shape(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $notification = new IncidentOpened($incident);
        $notification->id = 'fixed-notification-id';

        $message = $notification->toBroadcast($user);

        // The client's `DatabaseNotification.fromMap` reads `data.title`,
        // `data.body` and `data.action_url` from a NESTED data key, plus a
        // top-level id/type/created_at/read_at. Laravel's default broadcast
        // payload FLATTENS the data instead, which decodes to nothing usable, so
        // the shape here is the contract and this test is what pins it.
        $payload = $message->data;
        $this->assertSame('fixed-notification-id', $payload['id']);
        // The EVENT TOKEN, not the class name, and this assertion used to say
        // the opposite. `NotificationResource` serves `data['type']` as the
        // row's type, the client reads this top-level key in
        // `DatabaseNotification.fromMap`, and uptizm's icon lookup keys on the
        // token. A class name here made one notification arrive as two
        // different types depending on its transport: a row delivered over the
        // socket missed the lookup and rendered the generic fallback icon,
        // then silently changed to the right one on the next fetch.
        $this->assertSame('incident_opened', $payload['type']);
        $this->assertSame(
            $payload['data']['type'],
            $payload['type'],
            'the frame and the row it carries must name one type',
        );
        $this->assertNull($payload['read_at']);
        $this->assertNotNull($payload['created_at']);
        $this->assertIsArray($payload['data']);
        $this->assertSame(
            $notification->toArray($user),
            $payload['data'],
            'the socket and the API row must come from one serializer',
        );
        $this->assertArrayHasKey('title', $payload['data']);
        $this->assertArrayHasKey('body', $payload['data']);
    }

    public function test_the_resolved_frame_names_the_event_token_too(): void
    {
        // Its own case because `IncidentResolved` declares its own
        // `toBroadcast()` rather than inheriting: the same line was wrong in
        // two files, and a test covering only the first would have let the
        // recovery notification keep arriving as a class name.
        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $notification = new IncidentResolved($incident);
        $notification->id = 'fixed-resolved-id';

        $payload = $notification->toBroadcast($user)->data;

        $this->assertSame('incident_resolved', $payload['type']);
        $this->assertSame($payload['data']['type'], $payload['type']);
    }

    public function test_the_escalated_frame_names_its_own_event_token(): void
    {
        // `IncidentEscalated` extends `IncidentOpened` and overrides only
        // `eventType()`, so this proves the inherited `toBroadcast()` reads the
        // subclass's token rather than the parent's. `static::class` happened to
        // get this right; a hardcoded parent token would not have.
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentEscalated($incident))->toBroadcast($user)->data;

        $this->assertSame('incident_escalated', $payload['type']);
        $this->assertSame($payload['data']['type'], $payload['type']);
    }

    public function test_the_broadcast_frame_goes_to_the_notifiable_own_channel(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $channels = $user->receivesBroadcastNotificationsOn(new IncidentOpened($incident));

        // A notification is personal, so it must never ride the team channel every
        // teammate is subscribed to.
        $this->assertSame('App.Models.User.'.$user->id, $channels);
    }

    public function test_the_channel_resolves_with_no_argument_at_all(): void
    {
        $user = User::factory()->create();

        // Filament's notification component calls this method with NO arguments,
        // guarded only by `method_exists`
        // (filament/notifications/src/Livewire/Notifications.php:103). A required
        // parameter threw ArgumentCountError out of a Blade render and 500'd every
        // admin panel page. Three admin tests caught it; this one names the reason
        // so the next person does not tighten the signature back.
        $this->assertSame(
            'App.Models.User.'.$user->id,
            $user->receivesBroadcastNotificationsOn(),
        );
    }

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
