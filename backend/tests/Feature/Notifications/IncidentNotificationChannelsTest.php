<?php

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
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
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $channels = (new IncidentOpened($incident))->via($user);

        $this->assertSame(['mail', 'database', 'onesignal'], $channels);
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
