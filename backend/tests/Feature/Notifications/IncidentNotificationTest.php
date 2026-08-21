<?php

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Monitoring\IncidentTitle;
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

    public function test_incident_opened_notifies_via_mail_database_and_onesignal(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $notification = new IncidentOpened($incident);

        $this->assertSame(['mail', 'database', 'onesignal', 'broadcast'], $notification->via($user));

        $payload = $notification->toArray($user);

        $this->assertSame('incident_opened', $payload['type']);
        $this->assertSame('incident', $payload['kind']);
        $this->assertSame($incident->id, $payload['incident_id']);
        $this->assertSame($incident->primary_monitor_id, $payload['monitor_id']);
        $this->assertSame('API Health', $payload['monitor_name']);
        $this->assertSame('critical', $payload['severity']);
    }

    public function test_incident_resolved_notifies_via_mail_database_and_onesignal(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
        ]);
        $user = User::factory()->create();

        $notification = new IncidentResolved($incident);

        $this->assertSame(['mail', 'database', 'onesignal', 'broadcast'], $notification->via($user));

        $payload = $notification->toArray($user);

        $this->assertSame('incident_resolved', $payload['type']);
        $this->assertSame('resolved', $payload['kind']);
        $this->assertSame($incident->id, $payload['incident_id']);
        $this->assertSame('API Health', $payload['monitor_name']);
    }

    public function test_a_disabled_channel_setting_removes_that_channel_from_via(): void
    {
        $this->enableOnesignal();
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $user->notificationSettings()->create([
            'type' => 'incident_opened',
            'channel' => 'onesignal',
            'is_enabled' => false,
        ]);

        $notification = new IncidentOpened($incident);

        $this->assertSame(['mail', 'database', 'broadcast'], $notification->via($user));
    }

    /**
     * One push payload carries both languages and the device picks, so the BODY
     * has to differ between them exactly as the heading does.
     *
     * This assertion used to say the two entries were EQUAL to `incident->title`,
     * which encoded the defect as the expectation: an automatically composed title
     * is a key plus parameters, and sending its English render to a Turkish device
     * under a Turkish heading is the bug this PR exists to remove. Both halves of
     * the rewrite matter. Asserting a difference is worthless while the fixture
     * carries no `title_key` (both entries then correctly fall back to the stored
     * text), and seeding the key without inverting the assertion would go red on
     * the very fix. {@see self::makeIncident()} now seeds the composed triple.
     */
    public function test_toonesignal_renders_the_body_per_language_not_the_stored_english(): void
    {
        $this->enableOnesignal();
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toOneSignal($user);

        $this->assertInstanceOf(Notification::class, $payload);
        $this->assertSame('API Health is down', $payload->getHeadings()['en']);
        $this->assertSame('API Health kesintide', $payload->getHeadings()['tr']);

        // The English entry is the stored render, which is also what the column
        // holds; the Turkish one is the catalogue's sentence, and the two are
        // different strings.
        $this->assertSame($incident->title, $payload->getContents()['en']);
        $this->assertSame(
            $this->catalogueSentence('tr', 'monitor_down', ['monitor' => 'API Health']),
            $payload->getContents()['tr'],
        );
        $this->assertNotSame(
            $payload->getContents()['en'],
            $payload->getContents()['tr'],
            'A composed title must not cross to a Turkish device in English',
        );
    }

    public function test_incident_resolved_toonesignal_renders_the_body_per_language(): void
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

        // The heading says "resolved" while the body keeps naming the incident:
        // the title is what opened, and resolving does not rewrite it. So the
        // body renders the same composed sentence, per language.
        $this->assertSame($incident->title, $payload->getContents()['en']);
        $this->assertSame(
            $this->catalogueSentence('tr', 'monitor_down', ['monitor' => 'API Health']),
            $payload->getContents()['tr'],
        );
        $this->assertNotSame(
            $payload->getContents()['en'],
            $payload->getContents()['tr'],
        );
    }

    /**
     * The other half of the contract, and the guard against "fixing" the
     * assertion above by always rendering from a catalogue: an OPERATOR-authored
     * title has no key, so a human chose both its words and its language and it
     * crosses to every device unchanged. Two identical entries are the CORRECT
     * answer here, and this is also what every row written before the structured
     * seam looks like.
     */
    public function test_an_authored_title_crosses_both_push_languages_unchanged(): void
    {
        $this->enableOnesignal();
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        $incident = $this->makeIncident([
            'title' => 'Ödeme akışı EU kenarında yavaş',
            'title_key' => null,
            'title_params' => null,
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toOneSignal($user);

        $this->assertSame('Ödeme akışı EU kenarında yavaş', $payload->getContents()['en']);
        $this->assertSame('Ödeme akışı EU kenarında yavaş', $payload->getContents()['tr']);
    }

    /**
     * A metric-bound breach is not the monitor being down, and the page said it
     * was.
     *
     * Measured in the bell on a monitor that answered 200 from all three regions
     * throughout: the row read "API is down" over a body of "HTTP status code
     * breached critical bound". Only the second line was true, and it was the
     * small one. The same false heading reached the mail subject and the push
     * heading, since all three resolve the one copy key.
     *
     * Asserted in BOTH locales, because a hardcoded English literal passes every
     * English assertion by construction and says nothing about the catalogue the
     * other half of the users read.
     */
    public function test_a_metric_incident_never_claims_the_monitor_is_down(): void
    {
        $incident = $this->makeIncident([
            'title' => 'Response time breached critical bound',
            'title_key' => IncidentTitle::METRIC_CRITICAL_BOUND,
            'title_params' => ['metric' => 'Response time'],
            'trigger_metric_key' => 'response_time',
        ]);
        $notification = new IncidentOpened($incident);

        foreach (['en', 'tr'] as $locale) {
            $user = User::factory()->create(['locale' => $locale]);
            App::setLocale($user->preferredLocale());

            $expected = $this->catalogueSentence(
                $locale,
                'metric_critical_bound',
                ['metric' => 'Response time'],
            );

            $this->assertSame($expected, $notification->toArray($user)['title'], $locale);
            $this->assertSame(
                '[Uptizm] '.$expected,
                $notification->toMail($user)->subject,
                $locale,
            );
            $this->assertStringNotContainsString(
                'API Health',
                (string) $notification->toMail($user)->subject,
                'the subject must not name the monitor as the thing that broke',
            );
        }
    }

    /**
     * The other two kinds that were claiming an outage: an AI anomaly and an
     * expiring certificate. Neither means the monitor is down, and both reached
     * the mail subject, the in-app row and the push heading saying it was.
     *
     * The SSL key is the pluralised one, so this also pins that the headline
     * resolves through the same `_one`/`_other` suffix the stored column uses
     * rather than the bare key.
     */
    public function test_the_other_non_outage_kinds_state_what_actually_happened(): void
    {
        $cases = [
            'ai_anomaly' => [IncidentTitle::AI_ANOMALY, ['monitor' => 'API Health'], 'ai_anomaly'],
            'ssl_expiry' => [IncidentTitle::SSL_EXPIRING, ['monitor' => 'API Health', 'days' => 7], 'ssl_expiring_other'],
        ];

        foreach ($cases as $label => [$key, $params, $catalogueKey]) {
            $incident = $this->makeIncident([
                'title' => 'ignored, the key wins',
                'title_key' => $key,
                'title_params' => $params,
            ]);
            $notification = new IncidentOpened($incident);

            foreach (['en', 'tr'] as $locale) {
                $user = User::factory()->create(['locale' => $locale]);
                App::setLocale($user->preferredLocale());

                $expected = $this->catalogueSentence($locale, $catalogueKey, $params);

                $this->assertSame(
                    $expected,
                    $notification->toArray($user)['title'],
                    "{$label} in {$locale}",
                );
                $this->assertSame(
                    '[Uptizm] '.$expected,
                    $notification->toMail($user)->subject,
                    "{$label} in {$locale}",
                );
            }
        }
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

        // `title` IS the incident's own sentence now, rendered with no explicit
        // locale so the ambient `withLocale(preferredLocale(...))` wrap decides.
        // A render captured in the constructor would hand this recipient the
        // dispatcher's language.
        $this->assertSame(
            $this->catalogueSentence('tr', 'monitor_down', ['monitor' => 'API Health']),
            $trPayload['title'],
        );

        // And `body` is the monitor, so the row carries what happened and where
        // without saying either twice. It used to be this same sentence, which
        // for a down incident meant the heading and the body were identical and
        // for a metric breach meant the heading was the only false line on the
        // row ("API is down" over "HTTP status code breached critical bound").
        $this->assertSame('API Health', $trPayload['body']);

        $enUser = User::factory()->create(['locale' => 'en']);
        App::setLocale($enUser->preferredLocale());
        $enMail = $notification->toMail($enUser);
        $enPayload = $notification->toArray($enUser);

        $this->assertSame('[Uptizm] API Health is down', $enMail->subject);
        $this->assertSame('Incident opened', $enMail->greeting);
        $this->assertSame('API Health is down', $enPayload['title']);

        // ONE dispatch, two recipients, two languages in the stored feed entry.
        // Carried by `title` now that it holds the incident's own sentence;
        // `body` is the monitor name, which is the same word in both languages.
        $this->assertNotSame($trPayload['title'], $enPayload['title']);
        $this->assertSame('API Health', $enPayload['body']);
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
        $this->assertSame(
            $this->catalogueSentence('tr', 'monitor_down', ['monitor' => 'API Health']),
            $trPayload['body'],
        );

        $enUser = User::factory()->create(['locale' => 'en']);
        App::setLocale($enUser->preferredLocale());
        $enMail = $notification->toMail($enUser);
        $enPayload = $notification->toArray($enUser);

        $this->assertSame('[Uptizm] API Health is resolved', $enMail->subject);
        $this->assertSame('Incident resolved', $enMail->greeting);
        $this->assertSame('API Health is resolved', $enPayload['title']);
        $this->assertSame('API Health is down', $enPayload['body']);
        $this->assertNotSame($trPayload['body'], $enPayload['body']);
    }

    public function test_both_incident_types_are_registered_with_mail_database_and_push_defaults(): void
    {
        $this->assertTrue(NotificationPreferenceRegistry::has(IncidentOpened::class));
        $this->assertTrue(NotificationPreferenceRegistry::has(IncidentResolved::class));

        // 'sms' is an advertised channel (opt-in toggle) but never a default.
        $this->assertSame(['mail', 'database', 'push', 'sms'], NotificationPreferenceRegistry::channels(IncidentOpened::class));
        $this->assertSame(['mail', 'database', 'push'], NotificationPreferenceRegistry::defaults(IncidentOpened::class));
        $this->assertSame([], NotificationPreferenceRegistry::locked(IncidentOpened::class));

        $this->assertSame(['mail', 'database', 'push', 'sms'], NotificationPreferenceRegistry::channels(IncidentResolved::class));
        $this->assertSame(['mail', 'database', 'push'], NotificationPreferenceRegistry::defaults(IncidentResolved::class));

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
     * The sentence `lang/<locale>/incidents.php` spells for [$key], with its
     * `:placeholder` tokens filled from [$params].
     *
     * Read off the catalogue FILE rather than through `__()`, on purpose. The
     * Must-NOT is a hardcoded Turkish sentence: a copy edit would leave a
     * duplicate here asserting a wording the product no longer ships. But `__()`
     * is the call the notification itself makes, so an expectation built from it
     * would mirror the code under test and could not tell a per-locale render from
     * a locale that silently resolved to the fallback. The file sits one layer
     * away from both.
     *
     * @param  array<string, string|int>  $params
     */
    private function catalogueSentence(string $locale, string $key, array $params): string
    {
        $catalogue = require base_path("lang/{$locale}/incidents.php");

        $sentence = $catalogue[$key];

        foreach ($params as $name => $value) {
            $sentence = str_replace(":{$name}", (string) $value, $sentence);
        }

        return $sentence;
    }

    /**
     * Build a persisted incident with a primary monitor for a fresh team.
     *
     * The incident is an AUTOMATICALLY opened one: it carries the composed triple
     * (`title` holding the English render, plus `title_key` and `title_params`),
     * because that is the shape five of the six writers persist and the only shape
     * under which a localized channel can render anything at all. A fixture with
     * no key made every push assertion in this file vacuous: `IncidentTitle`
     * correctly fell back to the stored text, so `en` and `tr` were equal and an
     * assertion that they were equal passed for the wrong reason. A test wanting
     * the operator-authored path overrides `title_key` and `title_params` with
     * null.
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
            // Spelled out rather than built through `IncidentTitle::compose()`:
            // the composer resolves the English from the same catalogue the render
            // reads, so a fixture built from it would agree with any wording and
            // the `en` push entry could never catch a drift between the stored
            // column and the sentence.
            'title' => 'API Health is down',
            'title_key' => IncidentTitle::MONITOR_DOWN,
            'title_params' => ['monitor' => 'API Health'],
            'impact' => 'critical',
            'severity' => 'critical',
            'signal_source' => 'user_threshold',
            'lifecycle' => 'detected',
            'started_at' => now(),
            ...$overrides,
        ]);
    }
}
