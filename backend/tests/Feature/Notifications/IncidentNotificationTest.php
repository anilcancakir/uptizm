<?php

namespace Tests\Feature\Notifications;

use App\Enums\IncidentSeverity;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Notifications\IncidentEscalated;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Monitoring\IncidentTitle;
use App\Support\Notifications\IncidentBody;
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
     *
     * The body is no longer the title at all, and the last assertion is why: the
     * heading was already the incident's sentence, so a content built from the
     * same render produced a real push reading "Local web push test 08:41:39"
     * over itself (measured on a device on 2026-09-08). It now carries what the
     * heading cannot, composed by `IncidentBody`.
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

        $this->assertSame('Critical · example.com', $payload->getContents()['en']);
        $this->assertSame('Kritik · example.com', $payload->getContents()['tr']);
        $this->assertNotSame(
            $payload->getContents()['en'],
            $payload->getContents()['tr'],
            'A composed body must not cross to a Turkish device in English',
        );
        $this->assertNotSame(
            $payload->getHeadings()['en'],
            $payload->getContents()['en'],
            'The two lines of a push must not be the same sentence twice',
        );
    }

    public function test_incident_resolved_toonesignal_renders_the_body_per_language(): void
    {
        $this->enableOnesignal();
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
            'started_at' => now()->subMinutes(47),
            'resolved_at' => now(),
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentResolved($incident))->toOneSignal($user);

        $this->assertInstanceOf(Notification::class, $payload);
        $this->assertSame('API Health is resolved', $payload->getHeadings()['en']);
        $this->assertSame('API Health sorunu giderildi', $payload->getHeadings()['tr']);

        // The heading says the incident closed; the body says how long it ran.
        // It used to repeat the incident's own title, which is the sentence about
        // it BREAKING, so a resolved push read "API Health is resolved" over "API
        // Health is down" on one notification.
        $this->assertSame('Lasted 47 minutes · example.com', $payload->getContents()['en']);
        $this->assertSame('47 dakika sürdü · example.com', $payload->getContents()['tr']);
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
     *
     * It asserts on the HEADING rather than the content, because the heading is
     * where the incident's title lives now. The body is composed from the
     * severity and the target, which are catalogue and column, never authored.
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

        $this->assertSame('Ödeme akışı EU kenarında yavaş', $payload->getHeadings()['en']);
        $this->assertSame('Ödeme akışı EU kenarında yavaş', $payload->getHeadings()['tr']);
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

        // And `body` carries what the title cannot: how serious, and which host.
        // It follows the same ambient locale, so a Turkish recipient gets a
        // Turkish severity while the host stays a host.
        $this->assertSame('Kritik · example.com', $trPayload['body']);

        $enUser = User::factory()->create(['locale' => 'en']);
        App::setLocale($enUser->preferredLocale());
        $enMail = $notification->toMail($enUser);
        $enPayload = $notification->toArray($enUser);

        $this->assertSame('[Uptizm] API Health is down', $enMail->subject);
        $this->assertSame('Incident opened', $enMail->greeting);
        $this->assertSame('API Health is down', $enPayload['title']);

        // ONE dispatch, two recipients, two languages in the stored feed entry,
        // on both lines of the row.
        $this->assertNotSame($trPayload['title'], $enPayload['title']);
        $this->assertSame('Critical · example.com', $enPayload['body']);
        $this->assertNotSame($trPayload['body'], $enPayload['body']);
    }

    public function test_incident_resolved_mail_and_database_render_in_the_notifiables_preferred_locale(): void
    {
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
            'started_at' => now()->subMinutes(47),
            'resolved_at' => now(),
        ]);
        $notification = new IncidentResolved($incident);

        $trUser = User::factory()->create(['locale' => 'tr']);
        App::setLocale($trUser->preferredLocale());
        $trMail = $notification->toMail($trUser);
        $trPayload = $notification->toArray($trUser);

        $this->assertSame('[Uptizm] API Health sorunu giderildi', $trMail->subject);
        $this->assertSame('Olay çözüldü', $trMail->greeting);
        $this->assertSame('API Health sorunu giderildi', $trPayload['title']);
        $this->assertSame('47 dakika sürdü · example.com', $trPayload['body']);

        $enUser = User::factory()->create(['locale' => 'en']);
        App::setLocale($enUser->preferredLocale());
        $enMail = $notification->toMail($enUser);
        $enPayload = $notification->toArray($enUser);

        $this->assertSame('[Uptizm] API Health is resolved', $enMail->subject);
        $this->assertSame('Incident resolved', $enMail->greeting);
        $this->assertSame('API Health is resolved', $enPayload['title']);
        $this->assertSame('Lasted 47 minutes · example.com', $enPayload['body']);
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

    public function test_incident_opened_body_names_the_severity_and_the_target(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toArray($user);

        $this->assertSame('Critical · example.com', $payload['body']);
    }

    /**
     * A title that does not name the monitor makes the body name it.
     *
     * A third of the incident catalogue is metric-derived, and those sentences
     * carry the METRIC and no monitor at all (`lang/*\/incidents.php`:
     * `:metric breached critical bound`). An operator-authored title names
     * whatever the human typed. On both, a body that spent its second part on
     * the host left the row with no monitor name anywhere the client renders:
     * it shows `title` over `body` and reads `monitor_name` only for routing.
     */
    public function test_a_title_that_does_not_name_the_monitor_puts_it_in_the_body(): void
    {
        $incident = $this->makeIncident([
            'title' => 'p95 latency breached critical bound',
            'title_key' => 'incidents.metric_critical_bound',
            'title_params' => ['metric' => 'p95 latency'],
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toArray($user);

        $this->assertSame('Critical · API Health', $payload['body']);
    }

    public function test_an_operator_authored_title_also_puts_the_monitor_in_the_body(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident([
            'title' => 'Ödeme akışı EU kenarında yavaş',
            'title_key' => null,
            'title_params' => null,
        ]);
        $user = User::factory()->create();
        $notification = new IncidentOpened($incident);

        $this->assertSame('Critical · API Health', $notification->toArray($user)['body']);

        // The push half too. The heading is the authored sentence and crosses
        // both languages unchanged; the CONTENT is composed, so it differs.
        $payload = $notification->toOneSignal($user);
        $this->assertSame('Critical · API Health', $payload->getContents()['en']);
        $this->assertSame('Kritik · API Health', $payload->getContents()['tr']);
    }

    /**
     * The resolved and escalated bodies name the HOST even when the incident's
     * own title named nothing.
     *
     * Their titles are `:monitor is resolved` and `:monitor got worse`, composed
     * from the monitor rather than from the incident's stored sentence, so the
     * monitor is named whatever that sentence says. Reading `title_params` here
     * put the monitor name on both lines of an authored incident, measured live
     * on 2026-09-08: "API sorunu giderildi" over "30 dakika 33 saniye sürdü ·
     * API". Every fixture in this file carried `title_params`, so the suite could
     * not see it.
     */
    public function test_the_resolved_and_escalated_bodies_name_the_host_on_an_authored_incident(): void
    {
        $resolved = $this->makeIncident([
            'title' => 'Ödeme akışı EU kenarında yavaş',
            'title_key' => null,
            'title_params' => null,
            'lifecycle' => 'resolved',
            'started_at' => now()->subMinutes(47),
            'resolved_at' => now(),
        ]);
        $escalated = $this->makeIncident([
            'title' => 'Ödeme akışı EU kenarında yavaş',
            'title_key' => null,
            'title_params' => null,
        ]);
        $user = User::factory()->create();

        $this->assertSame(
            'Lasted 47 minutes · example.com',
            (new IncidentResolved($resolved))->toArray($user)['body'],
        );
        $this->assertSame(
            'Raised to Critical · example.com',
            (new IncidentEscalated($escalated))->toArray($user)['body'],
        );
    }

    /**
     * A TCP monitor stores its target with no scheme, and the label has to
     * survive that.
     */
    public function test_the_body_labels_a_scheme_less_target(): void
    {
        $incident = $this->makeIncident();
        $incident->primaryMonitor->update([
            'type' => 'tcp',
            'url' => 'db.internal:5432',
        ]);
        $incident->load('primaryMonitor');
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toArray($user);

        $this->assertSame('Critical · db.internal:5432', $payload['body']);
    }

    /**
     * Every severity tier has a name in both languages.
     *
     * The lookup used to build its key by concatenating the stored token, and
     * Laravel's translator answers a miss with the key itself, so a fourth enum
     * case would have shipped `notifications.severity_major · example.com` to a
     * lock screen with nothing failing.
     */
    public function test_every_severity_tier_is_named_in_both_languages(): void
    {
        foreach (IncidentSeverity::cases() as $severity) {
            foreach (['en', 'tr'] as $locale) {
                $name = IncidentBody::severityName(
                    $this->makeIncident(['severity' => $severity->value]),
                    $locale,
                );

                $this->assertStringNotContainsString('notifications.', $name, "{$severity->value} in {$locale}");
                $this->assertNotSame($severity->value, $name, "{$severity->value} in {$locale}");
            }
        }
    }

    public function test_the_escalated_push_content_names_the_tier_it_reached(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentEscalated($incident))->toOneSignal($user);

        $this->assertSame('Raised to Critical · example.com', $payload->getContents()['en']);
        $this->assertSame('Kritik seviyesine yükseldi · example.com', $payload->getContents()['tr']);
    }

    /**
     * A Teams card names the tier, it does not print the column.
     *
     * Same defect as the severity line, one surface further out: the FactSet is
     * read by a person in a channel, and it showed `warn`.
     */
    public function test_the_teams_card_names_the_severity_tier(): void
    {
        $incident = $this->makeIncident(['severity' => 'warn']);
        $user = User::factory()->create();

        $facts = $this->teamsFacts((new IncidentOpened($incident))->toTeams($user));

        $this->assertSame('Warning', $facts['Severity']);
    }

    /**
     * The `title => value` pairs out of an Adaptive Card's FactSet block.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, string>
     */
    private function teamsFacts(array $card): array
    {
        foreach ($card['body'] as $block) {
            if (($block['type'] ?? null) !== 'FactSet') {
                continue;
            }

            return collect($block['facts'])->pluck('value', 'title')->all();
        }

        return [];
    }

    public function test_incident_opened_body_renders_in_the_recipient_language(): void
    {
        App::setLocale('tr');

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toArray($user);

        $this->assertSame('Kritik · example.com', $payload['body']);
    }

    public function test_incident_resolved_body_names_how_long_the_outage_lasted(): void
    {
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
            'started_at' => now()->subMinutes(47),
            'resolved_at' => now(),
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentResolved($incident))->toArray($user);

        $this->assertSame('Lasted 47 minutes · example.com', $payload['body']);
    }

    /**
     * A two-hour outage says two hours, not "1 hour".
     *
     * Carbon's `diffForHumans` takes a `parts` count and one part rounds 119
     * minutes down to a single unit, which throws away most of what an operator
     * reads this line for.
     */
    public function test_the_duration_does_not_round_a_long_outage_down_to_one_unit(): void
    {
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
            'started_at' => now()->subMinutes(119),
            'resolved_at' => now(),
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentResolved($incident))->toArray($user);

        $this->assertSame('Lasted 1 hour 59 minutes · example.com', $payload['body']);
    }

    /**
     * An incident opened and closed inside one second says nothing about its
     * length, rather than "Lasted 0 seconds".
     */
    public function test_the_duration_part_goes_when_there_is_no_duration(): void
    {
        $now = now();
        $incident = $this->makeIncident([
            'lifecycle' => 'resolved',
            'started_at' => $now,
            'resolved_at' => $now,
        ]);
        $user = User::factory()->create();

        $payload = (new IncidentResolved($incident))->toArray($user);

        $this->assertSame('example.com', $payload['body']);
    }

    public function test_incident_escalated_body_names_the_severity_it_reached(): void
    {
        $incident = $this->makeIncident();
        $user = User::factory()->create();

        $payload = (new IncidentEscalated($incident))->toArray($user);

        $this->assertSame('Raised to Critical · example.com', $payload['body']);
    }

    /**
     * An incident whose monitor is gone still has to produce a body, and the
     * separator has to go with the part it separated.
     *
     * `incidents.primary_monitor_id` is `nullOnDelete`, so deleting a monitor
     * leaves its incidents behind with no target to name. A naive
     * `:severity · :target` template renders "Critical · " with a dangling middot
     * on every one of them, and an incident list is exactly where those pile up.
     */
    public function test_body_drops_the_separator_when_the_incident_has_no_monitor(): void
    {
        $incident = $this->makeIncident();
        $incident->primaryMonitor->delete();
        $incident->refresh()->load('primaryMonitor');
        $user = User::factory()->create();

        $payload = (new IncidentOpened($incident))->toArray($user);

        $this->assertSame('Critical', $payload['body']);
    }

    /**
     * The severity line names the tier, it does not print the column.
     *
     * `severity_line` interpolated `$incident->severity->value` straight into a
     * translated sentence, so a Turkish recipient read "Önem derecesi: critical."
     * and a Slack channel got the same. The stored token is the database's
     * vocabulary; the catalogue has the reader's.
     */
    public function test_the_severity_line_names_the_tier_in_the_recipient_language(): void
    {
        App::setLocale('tr');

        $incident = $this->makeIncident();
        $user = User::factory()->create(['locale' => 'tr']);
        $notification = new IncidentOpened($incident);

        $this->assertContains('Önem derecesi: Kritik.', $notification->toMail($user)->introLines);
        $this->assertStringContainsString('Önem derecesi: Kritik.', $notification->toSlack($user)['text']);
    }

    /**
     * The incident link has to point at the host that actually serves the
     * Flutter client (`app.frontend_url`), not this API's own origin: a
     * Universal Link only opens the installed app for a host in its
     * entitlement, and `app.url` here is the backend, `uptizm.com`.
     *
     * Covers every surface {@see IncidentOpened::incidentUrl()} feeds: the mail
     * action, the Slack text, the Teams `Action.OpenUrl`, the webhook
     * `incident_url` and the PagerDuty `custom_details.incident_url`.
     */
    public function test_incident_opened_urls_use_the_frontend_host(): void
    {
        config(['app.frontend_url' => 'https://app.example.test']);

        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $notification = new IncidentOpened($incident);
        $expected = 'https://app.example.test/incidents/'.$incident->id;

        $this->assertSame($expected, $notification->toMail($user)->actionUrl);
        $this->assertStringContainsString($expected, $notification->toSlack($user)['text']);
        $this->assertSame($expected, $notification->toWebhook($user)['incident_url']);
        $this->assertSame($expected, $notification->toPagerDuty($user)['payload']['custom_details']['incident_url']);
        $this->assertSame($expected, $this->teamsActionUrl($notification->toTeams($user)));
    }

    /**
     * Same five surfaces, on the resolve notification.
     */
    public function test_incident_resolved_urls_use_the_frontend_host(): void
    {
        config(['app.frontend_url' => 'https://app.example.test']);

        $incident = $this->makeIncident(['lifecycle' => 'resolved']);
        $user = User::factory()->create();
        $notification = new IncidentResolved($incident);
        $expected = 'https://app.example.test/incidents/'.$incident->id;

        $this->assertSame($expected, $notification->toMail($user)->actionUrl);
        $this->assertStringContainsString($expected, $notification->toSlack($user)['text']);
        $this->assertSame($expected, $notification->toWebhook($user)['incident_url']);
        $this->assertSame($expected, $this->teamsActionUrl($notification->toTeams($user)));
    }

    /**
     * `backend/.env.example:235` ships `APP_FRONTEND_URL` blank, and a blank
     * `.env` line makes the key PRESENT and EMPTY, so `env()`'s own default
     * inside `config/app.php` never fires. A present-but-empty
     * `app.frontend_url` (the exact runtime shape a stale deploy config
     * produces) has to fall back to `app.url` rather than compose a relative
     * `/incidents/{id}` link that a mail client cannot open at all.
     */
    public function test_incident_urls_fall_back_to_app_url_when_frontend_url_is_empty(): void
    {
        config([
            'app.frontend_url' => '',
            'app.url' => 'https://api.example.test',
        ]);

        $incident = $this->makeIncident();
        $user = User::factory()->create();
        $notification = new IncidentOpened($incident);
        $expected = 'https://api.example.test/incidents/'.$incident->id;

        $this->assertSame($expected, $notification->toMail($user)->actionUrl);
        $this->assertNotSame('/incidents/'.$incident->id, $notification->toMail($user)->actionUrl);
    }

    /**
     * The `url` out of a Teams Adaptive Card's `Action.OpenUrl` block.
     *
     * @param  array<string, mixed>  $card
     */
    private function teamsActionUrl(array $card): string
    {
        foreach ($card['actions'] as $action) {
            if (($action['type'] ?? null) === 'Action.OpenUrl') {
                return $action['url'];
            }
        }

        return '';
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
