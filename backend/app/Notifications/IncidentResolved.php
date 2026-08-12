<?php

namespace App\Notifications;

use App\Enums\NotificationChannelType;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Notifications\Channels\PagerDutyChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Services\Monitoring\IncidentTitle;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use FlutterSdk\MagicStarter\Support\OneSignalSubscriptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use onesignal\client\model\LanguageStringMap;
use onesignal\client\model\Notification as OneSignalNotification;

/**
 * Notification sent to a team's users when a monitor incident resolves.
 *
 * Dispatched by the incident-detection pipeline (a later step); this class
 * only carries the incident-owned fields needed to render the mail and the
 * in-app feed entry the Flutter client maps to `AppNotificationKind.resolved`.
 */
class IncidentResolved extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  Incident  $incident  The incident that just resolved.
     * @return void
     */
    public function __construct(
        public readonly Incident $incident,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * A team-scoped {@see NotificationChannel} notifiable resolves to its single
     * custom channel class (Slack or webhook), or to nothing when the required
     * credential is absent. Any other notifiable (a person) gets the base set:
     * `mail`/`database`, plus `onesignal` when the push feature is active, minus
     * any channel it disabled in its {@see NotificationSetting} rows.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        if ($notifiable instanceof NotificationChannel) {
            return self::channelVia($notifiable);
        }

        $channels = self::withoutDisabledChannels($notifiable, self::defaultChannels(), 'incident_resolved');

        return array_merge($channels, self::smsChannel($notifiable, 'incident_resolved'));
    }

    /**
     * Resolve the delivery channel for a team-scoped {@see NotificationChannel}:
     * the matching custom channel class, or an empty array (a deliberate
     * no-send that Laravel silently skips) when the required credential is empty.
     *
     * @return array<int, string>
     */
    private static function channelVia(NotificationChannel $channel): array
    {
        return match ($channel->channel_type) {
            NotificationChannelType::Slack => self::hasCredential($channel, 'token')
                ? [SlackChannel::class]
                : [],
            NotificationChannelType::Webhook => self::hasCredential($channel, 'url')
                ? [WebhookChannel::class]
                : [],
            NotificationChannelType::PagerDuty => self::hasCredential($channel, 'routing_key')
                ? [PagerDutyChannel::class]
                : [],
            NotificationChannelType::Teams => self::hasCredential($channel, 'url')
                ? [TeamsChannel::class]
                : [],
        };
    }

    /**
     * Whether the channel carries a non-empty value for the given credential key.
     */
    private static function hasCredential(NotificationChannel $channel, string $key): bool
    {
        $value = $channel->credentials[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /**
     * The default channel set: mail + database, plus OneSignal push when the
     * `onesignal` feature is enabled (else the driver is unregistered).
     *
     * @return array<int, string>
     */
    private static function defaultChannels(): array
    {
        $channels = [
            'mail',
            'database',
        ];

        // Only advertise the onesignal driver when the push app is actually
        // provisioned: OneSignalChannel::send() throws on an empty app_id, so
        // advertising it unprovisioned would dead-letter a push job per
        // recipient on every incident. The logical `push` preference toggle
        // stays visible regardless (it is registry-driven, independent of this
        // driver-name list).
        if (Features::hasOnesignalFeatures() && filled(config('magic-starter.onesignal.app_id'))) {
            $channels[] = 'onesignal';
        }

        return $channels;
    }

    /**
     * Drop any channel the notifiable explicitly disabled for `$type` via a
     * {@see NotificationSetting} override.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    private static function withoutDisabledChannels(mixed $notifiable, array $channels, string $type): array
    {
        if (! method_exists($notifiable, 'notificationSettings')) {
            return $channels;
        }

        $disabled = $notifiable->notificationSettings()
            ->where('type', $type)
            ->where('is_enabled', false)
            ->pluck('channel')
            ->all();

        return array_values(array_diff($channels, $disabled));
    }

    /**
     * Advertise the OneSignal SMS driver for a person, but only as an explicit
     * opt-in (oracle C3b): the notifiable enabled an `sms` preference row for
     * this type, the push app is provisioned, and the notifiable carries a phone.
     * SMS is never in {@see defaultChannels()}, so this is the sole path that adds
     * it; a default-on sms would text every member on every incident.
     *
     * @return array<int, string>
     */
    private static function smsChannel(mixed $notifiable, string $type): array
    {
        // 1. Require the push app provisioned. OneSignalChannel::send() throws on
        //    an empty app_id, so an unprovisioned advertise would dead-letter an
        //    sms job per recipient (mirrors defaultChannels()).
        if (! Features::hasOnesignalFeatures() || blank(config('magic-starter.onesignal.app_id'))) {
            return [];
        }

        // 2. Require a phone to text.
        if (blank($notifiable->phone ?? null)) {
            return [];
        }

        // 3. Require an explicit enabled `sms` preference row (the opt-in gate).
        if (! method_exists($notifiable, 'notificationSettings')) {
            return [];
        }

        $optedIn = $notifiable->notificationSettings()
            ->where('type', $type)
            ->where('channel', 'sms')
            ->where('is_enabled', true)
            ->exists();

        if (! $optedIn) {
            return [];
        }

        // 4. Resolve the driver name behind the logical `sms` channel (registered
        //    as 'onesignal-sms' by MagicStarterServiceProvider). via() narrows by
        //    driver name; GateNotificationChannels re-maps it to the logical `sms`
        //    name and re-checks the preference at send.
        return [NotificationPreferenceRegistry::resolveDriverChannel('sms')];
    }

    /**
     * Build the OneSignal push payload. The channel forces `app_id` and applies
     * the notifiable's `external_id` (`user_{id}`) alias, so this only carries
     * the localized heading and body.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     */
    public function toOneSignal(mixed $notifiable): OneSignalNotification
    {
        $payload = new OneSignalNotification([
            'app_id' => config('magic-starter.onesignal.app_id'),
        ]);
        $payload->setHeadings(new LanguageStringMap([
            'en' => __('notifications.incident_resolved_push_heading', ['monitor' => $this->monitorName('en')], 'en'),
            'tr' => __('notifications.incident_resolved_push_heading', ['monitor' => $this->monitorName('tr')], 'tr'),
        ]));
        $payload->setContents(new LanguageStringMap([
            // Two kinds of title, one map: an operator-authored one is
            // user-generated text and crosses unchanged in both entries, an
            // automatically composed one renders per locale from its key. See
            // {@see IncidentOpened::toOneSignal()} for the full reasoning; the
            // locale is explicit because this payload carries both languages.
            'en' => IncidentTitle::render($this->incident, 'en'),
            'tr' => IncidentTitle::render($this->incident, 'tr'),
        ]));

        return $payload;
    }

    /**
     * Build the OneSignal SMS payload for an opted-in person. Unlike the push
     * builder this targets the `sms` channel explicitly and sets its own alias,
     * so {@see OneSignalChannel} keeps them (it only injects defaults when none
     * are set). The `sms_from` sender is read defensively: when unprovisioned it
     * is omitted so OneSignal falls back to the app's default sender.
     *
     * Registration is on demand: the SMS subscription is created the first time a
     * person is about to be texted, idempotently (Step 4's shared helper, guarded
     * by `users.sms_registered_at`). The phone is PII and is never logged.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     */
    public function toSms(mixed $notifiable): OneSignalNotification
    {
        // 1. Ensure the OneSignal SMS subscription exists before we target it.
        app(OneSignalSubscriptions::class)->ensureSmsSubscription($notifiable);

        // 2. Build the SMS-targeted payload (own alias + sms channel).
        $payload = new OneSignalNotification([
            'app_id' => config('magic-starter.onesignal.app_id'),
        ]);
        $payload->setTargetChannel('sms');
        $payload->setIncludeAliases($notifiable->routeNotificationForOneSignal());
        $payload->setContents(new LanguageStringMap([
            'en' => __('notifications.incident_resolved_subject', ['monitor' => $this->monitorName('en')], 'en'),
            'tr' => __('notifications.incident_resolved_subject', ['monitor' => $this->monitorName('tr')], 'tr'),
        ]));

        // 3. Defensive sms_from: omit when the sender is not provisioned.
        $smsFrom = config('magic-starter.onesignal.sms_from');

        if (is_string($smsFrom) && trim($smsFrom) !== '') {
            $payload->setSmsFrom($smsFrom);
        }

        return $payload;
    }

    /**
     * Build the Slack `chat.postMessage` text payload for a team channel; the
     * channel merges in the target `channel` from the route. The copy reuses the
     * same localized incident lines as {@see toMail()}.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<string, mixed>
     */
    public function toSlack(mixed $notifiable): array
    {
        $monitorName = $this->monitorName();

        return [
            'text' => __('notifications.incident_resolved_subject', ['monitor' => $monitorName])."\n"
                .__('notifications.severity_line', ['severity' => $this->incident->severity->value])."\n"
                .$this->incidentUrl(),
        ];
    }

    /**
     * Build the machine-readable webhook payload: monitor name, state, severity,
     * and the incident URL, HMAC-signed by the channel.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<string, mixed>
     */
    public function toWebhook(mixed $notifiable): array
    {
        return [
            'event' => 'incident.resolved',
            'incident_id' => $this->incident->id,
            'monitor_id' => $this->incident->primary_monitor_id,
            'monitor_name' => $this->monitorName(),
            'state' => $this->incident->lifecycle->value,
            'severity' => $this->incident->severity->value,
            // The stored English, deliberately NOT IncidentTitle::render(): a
            // webhook is machine-to-machine, and a title whose language varied
            // with whichever operator happened to be notified would be a field an
            // integrator cannot parse. It also has to match the title the opening
            // webhook posted for the same incident.
            'title' => $this->incident->title,
            'incident_url' => $this->incidentUrl(),
        ];
    }

    /**
     * Build the PagerDuty Events API v2 payload for a `resolve` event. The
     * channel injects the routing key; a resolve carries only the deduplication
     * key (identical to {@see IncidentOpened::toPagerDuty()} so it closes the
     * alert the trigger opened) and no payload, per the Events API v2 contract.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<string, mixed>
     */
    public function toPagerDuty(mixed $notifiable): array
    {
        return [
            'event_action' => 'resolve',
            'dedup_key' => $this->pagerDutyDedupKey(),
        ];
    }

    /**
     * Deterministic PagerDuty deduplication key for this incident, shared by the
     * trigger and the resolve so PagerDuty correlates them into one alert.
     */
    private function pagerDutyDedupKey(): string
    {
        return 'uptizm-incident-'.$this->incident->id;
    }

    /**
     * Build the Microsoft Teams Adaptive Card `content` for a team channel. The
     * channel wraps this in the Teams message envelope. Mirrors
     * {@see IncidentOpened::toTeams()} with the resolve-side copy: a bold title
     * line, a FactSet of the monitor/severity/state, and a single
     * `Action.OpenUrl` linking to the incident.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<string, mixed>
     */
    public function toTeams(mixed $notifiable): array
    {
        $monitorName = $this->monitorName();

        return [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.2',
            'body' => [
                [
                    'type' => 'TextBlock',
                    'text' => __('notifications.incident_resolved_subject', ['monitor' => $monitorName]),
                    'weight' => 'Bolder',
                    'size' => 'Large',
                    'wrap' => true,
                ],
                [
                    'type' => 'FactSet',
                    'facts' => [
                        [
                            'title' => 'Monitor',
                            'value' => $monitorName,
                        ],
                        [
                            'title' => 'Severity',
                            'value' => $this->incident->severity->value,
                        ],
                        [
                            'title' => 'State',
                            'value' => $this->incident->lifecycle->value,
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'Action.OpenUrl',
                    'title' => __('notifications.view_incident_action'),
                    'url' => $this->incidentUrl(),
                ],
            ],
        ];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $monitorName = $this->monitorName();

        return (new MailMessage)
            ->subject(__('notifications.incident_resolved_subject', ['monitor' => $monitorName]))
            ->greeting(__('notifications.incident_resolved_greeting'))
            ->line(__('notifications.incident_resolved_line', ['monitor' => $monitorName]))
            ->line(__('notifications.severity_line', ['severity' => $this->incident->severity->value]))
            ->action(__('notifications.view_incident_action'), $this->incidentUrl());
    }

    /**
     * Get the array representation for the database channel.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * Build the payload shape consumed by the Flutter client's
     * `DatabaseNotification` -> `NotificationItem` mapping.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        $monitorName = $this->monitorName();

        return [
            'type' => 'incident_resolved',
            'title' => __('notifications.incident_resolved_title', ['monitor' => $monitorName]),
            // Ambient locale on purpose: the database channel builds inside
            // Laravel's `withLocale(preferredLocale(...))` wrap, so this resolves
            // per recipient. Rendering it before the send (a constructor
            // argument, a property) would serialize one language into the queued
            // payload for everybody.
            'body' => IncidentTitle::render($this->incident),
            'incident_id' => $this->incident->id,
            'monitor_id' => $this->incident->primary_monitor_id,
            'monitor_name' => $monitorName,
            'severity' => $this->incident->severity->value,
            'kind' => 'resolved',
        ];
    }

    /**
     * Resolve the primary monitor's name for the incident.
     *
     * @param  string|null  $locale  Explicit locale for the "unnamed monitor"
     *                               fallback (needed by {@see toOneSignal()}, which
     *                               renders both `en` and `tr` outside the app locale).
     */
    private function monitorName(?string $locale = null): string
    {
        return $this->incident->primaryMonitor?->name ?? __('notifications.unnamed_monitor', [], $locale);
    }

    /**
     * Build the client-facing URL for this incident.
     */
    private function incidentUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/incidents/'.$this->incident->id;
    }
}
