<?php

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\Team;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use onesignal\client\model\Notification;
use Tests\TestCase;

/**
 * Covers the `IncidentOpened`/`IncidentResolved` notification classes: their
 * channels, the payload shape the Flutter `NotificationItem` mapping expects,
 * and their registration in the `NotificationPreferenceRegistry` performed by
 * `AppServiceProvider::boot()`.
 */
class IncidentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_opened_notifies_via_mail_and_database(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $notification = new IncidentOpened($incident);

        $this->assertSame(['mail', 'database'], $notification->via($user));

        $payload = $notification->toArray($user);

        $this->assertSame('incident_opened', $payload['type']);
        $this->assertSame('incident', $payload['kind']);
        $this->assertSame($incident->id, $payload['incident_id']);
        $this->assertSame($incident->primary_monitor_id, $payload['monitor_id']);
        $this->assertSame('API Health', $payload['monitor_name']);
        $this->assertSame('critical', $payload['severity']);
    }

    public function test_incident_resolved_notifies_via_mail_and_database(): void
    {
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
        ]);
        $user = User::factory()->create();

        $notification = new IncidentResolved($incident);

        $this->assertSame(['mail', 'database'], $notification->via($user));

        $payload = $notification->toArray($user);

        $this->assertSame('incident_resolved', $payload['type']);
        $this->assertSame('resolved', $payload['kind']);
        $this->assertSame($incident->id, $payload['incident_id']);
        $this->assertSame('API Health', $payload['monitor_name']);
    }

    public function test_incident_opened_adds_the_onesignal_channel_when_the_feature_is_enabled(): void
    {
        $this->enableOnesignal();
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $notification = new IncidentOpened($incident);

        $this->assertSame(['mail', 'database', 'onesignal'], $notification->via($user));
    }

    public function test_incident_resolved_adds_the_onesignal_channel_when_the_feature_is_enabled(): void
    {
        $this->enableOnesignal();
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
        ]);
        $user = User::factory()->create();

        $notification = new IncidentResolved($incident);

        $this->assertSame(['mail', 'database', 'onesignal'], $notification->via($user));
    }

    public function test_a_disabled_channel_setting_removes_that_channel_from_via(): void
    {
        $this->enableOnesignal();
        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $user->notificationSettings()->create([
            'type' => 'incident_opened',
            'channel' => 'onesignal',
            'is_enabled' => false,
        ]);

        $notification = new IncidentOpened($incident);

        $this->assertSame(['mail', 'database'], $notification->via($user));
    }

    public function test_toonesignal_builds_a_localized_push_payload(): void
    {
        $this->enableOnesignal();
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toOneSignal($user);

        $this->assertInstanceOf(Notification::class, $payload);
        $this->assertSame('API Health is down', $payload->getHeadings()['en']);
        $this->assertSame('API Health kesintide', $payload->getHeadings()['tr']);
        $this->assertSame($incident->title, $payload->getContents()['en']);
        $this->assertSame($incident->title, $payload->getContents()['tr']);
    }

    public function test_incident_resolved_toonesignal_builds_a_localized_push_payload(): void
    {
        $this->enableOnesignal();
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentResolved($incident))->toOneSignal($user);

        $this->assertInstanceOf(Notification::class, $payload);
        $this->assertSame('API Health is resolved', $payload->getHeadings()['en']);
        $this->assertSame('API Health sorunu giderildi', $payload->getHeadings()['tr']);
        $this->assertSame($incident->title, $payload->getContents()['en']);
        $this->assertSame($incident->title, $payload->getContents()['tr']);
    }

    public function test_incident_opened_mail_and_database_render_in_the_notifiables_preferred_locale(): void
    {
        $incident = $this->makeIncident();
        $notification = new IncidentOpened($incident);

        $trUser = User::factory()->create(['locale' => 'tr']);
        App::setLocale($trUser->preferredLocale());
        $trMail = $notification->toMail($trUser);
        $trPayload = $notification->toArray($trUser);

        $this->assertSame('[Uptizm] API Health kesintide', $trMail->subject);
        $this->assertSame('Olay açıldı', $trMail->greeting);
        $this->assertSame('API Health kesintide', $trPayload['title']);

        $enUser = User::factory()->create(['locale' => 'en']);
        App::setLocale($enUser->preferredLocale());
        $enMail = $notification->toMail($enUser);
        $enPayload = $notification->toArray($enUser);

        $this->assertSame('[Uptizm] API Health is down', $enMail->subject);
        $this->assertSame('Incident opened', $enMail->greeting);
        $this->assertSame('API Health is down', $enPayload['title']);
    }

    public function test_incident_resolved_mail_and_database_render_in_the_notifiables_preferred_locale(): void
    {
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
        ]);
        $notification = new IncidentResolved($incident);

        $trUser = User::factory()->create(['locale' => 'tr']);
        App::setLocale($trUser->preferredLocale());
        $trMail = $notification->toMail($trUser);
        $trPayload = $notification->toArray($trUser);

        $this->assertSame('[Uptizm] API Health sorunu giderildi', $trMail->subject);
        $this->assertSame('Olay çözüldü', $trMail->greeting);
        $this->assertSame('API Health sorunu giderildi', $trPayload['title']);

        $enUser = User::factory()->create(['locale' => 'en']);
        App::setLocale($enUser->preferredLocale());
        $enMail = $notification->toMail($enUser);
        $enPayload = $notification->toArray($enUser);

        $this->assertSame('[Uptizm] API Health is resolved', $enMail->subject);
        $this->assertSame('Incident resolved', $enMail->greeting);
        $this->assertSame('API Health is resolved', $enPayload['title']);
    }

    public function test_both_incident_types_are_registered_with_mail_and_database_defaults(): void
    {
        $this->assertTrue(NotificationPreferenceRegistry::has(IncidentOpened::class));
        $this->assertTrue(NotificationPreferenceRegistry::has(IncidentResolved::class));

        $this->assertSame(['mail', 'database'], NotificationPreferenceRegistry::channels(IncidentOpened::class));
        $this->assertSame(['mail', 'database'], NotificationPreferenceRegistry::defaults(IncidentOpened::class));
        $this->assertSame([], NotificationPreferenceRegistry::locked(IncidentOpened::class));

        $this->assertSame(['mail', 'database'], NotificationPreferenceRegistry::channels(IncidentResolved::class));
        $this->assertSame(['mail', 'database'], NotificationPreferenceRegistry::defaults(IncidentResolved::class));

        // Also reachable by the slug the client's preference matrix uses.
        $this->assertTrue(NotificationPreferenceRegistry::has('incident_opened'));
        $this->assertTrue(NotificationPreferenceRegistry::has('incident_resolved'));
    }

    /**
     * Enable the OneSignal push feature for the duration of the test so the
     * notifications advertise the `onesignal` channel.
     */
    private function enableOnesignal(): void
    {
        config(['magic-starter.features' => array_values(array_unique([
            ...config('magic-starter.features', []),
            Features::onesignal(),
        ]))]);
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
