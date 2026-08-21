<?php

namespace App\Notifications;

use App\Enums\IncidentSeverity;
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
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use onesignal\client\model\LanguageStringMap;
use onesignal\client\model\Notification as OneSignalNotification;

/**
 * Notification sent to a team's users when a monitor incident opens.
 *
 * Dispatched by the incident-detection pipeline (a later step); this class
 * only carries the incident-owned fields needed to render the mail and the
 * in-app feed entry the Flutter client maps to `AppNotificationKind.incident`.
 */
class IncidentOpened extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  Incident  $incident  The incident that just opened.
     * @return void
     */
    public function __construct(
        public readonly Incident $incident,
    ) {}

    /**
     * The event token this notification is known by.
     *
     * One token drives three things: the notification-preference row a user
     * toggles, the `data.type` the Flutter feed reads, and the prefix of every
     * copy key. A subclass overriding this gets its own preference, its own feed
     * type and its own copy, without a line of channel logic being duplicated.
     */
    protected function eventType(): string
    {
        return 'incident_opened';
    }

    /**
     * The translation key for [$suffix] under this notification's event token.
     */
    protected function copyKey(string $suffix): string
    {
        return 'notifications.'.$this->eventType().'_'.$suffix;
    }

    /**
     * The parameters every string in this notification's copy family can use.
     *
     * Both are always supplied, and each key picks what it needs: the escalation
     * family talks about the monitor, the opened family leads with `:title`.
     *
     * `:title` exists because ":monitor is down" was a claim rather than a fact.
     * A metric-bound breach, an AI anomaly, an expiring certificate and a
     * hand-filed incident all page for a service that is answering normally, and
     * the headline said it was down anyway: measured in the bell on a healthy
     * monitor, "API is down" sitting over a body reading "HTTP status code
     * breached critical bound". `IncidentTitle::render` is the same composer the
     * body and the status page use, so the sentence agrees everywhere, and for a
     * genuine down incident it renders ":monitor is down" out of
     * `incidents.monitor_down` and nothing about that case changes.
     *
     * @param  string|null  $locale  Explicit locale, for the two payloads that
     *                               carry both languages at once (push and SMS).
     *                               Null renders in the ambient locale, which is
     *                               the recipient's own.
     * @return array<string, string>
     */
    protected function copyParams(?string $locale = null): array
    {
        return [
            'monitor' => $this->monitorName($locale),
            'title' => IncidentTitle::render($this->incident, $locale),
        ];
    }

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

        $channels = self::withoutDisabledChannels($notifiable, self::defaultChannels(), $this->eventType());

        return array_merge(
            self::withLiveDelivery($channels),
            self::smsChannel($notifiable, $this->eventType()),
        );
    }

    /**
     * The wire event name for the live in-app delivery.
     *
     * Laravel's default is the fully-qualified
     * `Illuminate\Notifications\Events\BroadcastNotificationCreated`. Magic's Reverb
     * channel matches a listener by EXACT string, so the client would have to
     * hardcode a framework internal; `magic_notifications` listens for this short
     * name (`NotificationManager.realtimeEvent`) instead.
     */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * The frame, in the SAME shape `GET /notifications` returns a row.
     *
     * This is not cosmetic. Laravel's default broadcast payload FLATTENS the
     * notification data to the top level and appends `id` and `type`, while the
     * client's `DatabaseNotification.fromMap` reads `data.title`, `data.body` and
     * `data.action_url` from a NESTED `data` key. The default therefore decodes to
     * nothing usable, and the failure is silent: a frame arrives, the decoder
     * throws, the row is dropped.
     *
     * Building it from {@see toArray()} keeps ONE serializer behind both the API
     * row and the socket frame, so the two cannot drift. `read_at` is null because
     * a notification being delivered has not been read; the authoritative row
     * replaces this one on the next fetch anyway, keyed by the same id.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     */
    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'type' => static::class,
            'data' => $this->toArray($notifiable),
            'created_at' => now()->toIso8601String(),
            'read_at' => null,
        ]);
    }

    /**
     * Append the `broadcast` driver whenever the in-app row is being written.
     *
     * Broadcast FOLLOWS `database`; it is the live delivery of that row, not a
     * channel of its own. Two consequences, both deliberate:
     *
     *  - A notifiable that disabled the in-app channel gets neither. Surviving
     *    alone would push a frame for a notification no row exists for, so the
     *    bell would show an entry that vanished on the next fetch.
     *  - `GateNotificationChannels` cannot enforce this. It maps a DRIVER channel
     *    back to a logical one and allows anything it cannot map, so a `broadcast`
     *    driver sails through it fail-open. The gate has to be here.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    private static function withLiveDelivery(array $channels): array
    {
        if (! in_array('database', $channels, true)) {
            return $channels;
        }

        return [...$channels, 'broadcast'];
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
            'en' => __($this->copyKey('push_heading'), $this->copyParams('en'), 'en'),
            'tr' => __($this->copyKey('push_heading'), $this->copyParams('tr'), 'tr'),
        ]));
        $payload->setContents(new LanguageStringMap([
            // A title is one of two things, and this map has to be right for
            // both. An operator-authored one is user-generated text: a human
            // chose its language, so it crosses unchanged and both entries carry
            // the same string. An automatically composed one is a key plus its
            // parameters, so it renders per locale out of `lang/*/incidents.php`
            // and the two entries differ. {@see IncidentTitle::render()} decides
            // which from `title_key`.
            //
            // The locale is passed explicitly rather than left ambient because
            // one push payload carries both languages and OneSignal picks per
            // device, exactly like the headings two lines above.
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
            'en' => __($this->copyKey('subject'), $this->copyParams('en'), 'en'),
            'tr' => __($this->copyKey('subject'), $this->copyParams('tr'), 'tr'),
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
        return [
            'text' => __($this->copyKey('subject'), $this->copyParams())."\n"
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
            // Derived, not hardcoded: this is a machine-readable field an
            // integrator switches on, and an escalation posting
            // `incident.opened` would be a lie in the one payload nobody reads
            // with their eyes.
            'event' => str_replace('_', '.', $this->eventType()),
            'incident_id' => $this->incident->id,
            'monitor_id' => $this->incident->primary_monitor_id,
            'monitor_name' => $this->monitorName(),
            'state' => $this->incident->lifecycle->value,
            'severity' => $this->incident->severity->value,
            // The stored English, deliberately NOT IncidentTitle::render(): a
            // webhook is machine-to-machine, and a title whose language varied
            // with whichever operator happened to be notified would be a field an
            // integrator cannot parse. A stable language beats a localized one on
            // this side of the wire.
            'title' => $this->incident->title,
            'incident_url' => $this->incidentUrl(),
        ];
    }

    /**
     * Build the PagerDuty Events API v2 payload for a `trigger` event. The
     * channel injects the routing key; this carries the deduplication key
     * (deterministic per incident, shared with {@see IncidentResolved::toPagerDuty()}
     * so the resolve closes the same alert) plus the severity-mapped payload.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<string, mixed>
     */
    public function toPagerDuty(mixed $notifiable): array
    {
        $monitorName = $this->monitorName();

        return [
            'event_action' => 'trigger',
            'dedup_key' => $this->pagerDutyDedupKey(),
            'payload' => [
                'summary' => __($this->copyKey('subject'), $this->copyParams()),
                'source' => $monitorName,
                'severity' => self::pagerDutySeverity($this->incident->severity),
                'custom_details' => [
                    'incident_id' => $this->incident->id,
                    'monitor_id' => $this->incident->primary_monitor_id,
                    'monitor_name' => $monitorName,
                    'state' => $this->incident->lifecycle->value,
                    'severity' => $this->incident->severity->value,
                    // The stored English, for the reason {@see toWebhook()}
                    // gives plus a stronger one: PagerDuty keys its own
                    // deduplication and its search off this text, so a title
                    // that changed language between two sends about one
                    // incident would split an alert in half.
                    'title' => $this->incident->title,
                    'incident_url' => $this->incidentUrl(),
                ],
            ],
        ];
    }

    /**
     * Build the Microsoft Teams Adaptive Card `content` for a team channel. The
     * channel wraps this in the Teams message envelope. The card carries a bold
     * title line, a FactSet of the monitor/severity/state, and a single
     * `Action.OpenUrl` linking to the incident (the only action type Workflows
     * incoming webhooks accept).
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
                    'text' => __($this->copyKey('subject'), $this->copyParams()),
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
     * Map an operator {@see IncidentSeverity} onto a PagerDuty payload severity
     * (`critical|warning|info`, the levels the Events API v2 accepts).
     */
    private static function pagerDutySeverity(IncidentSeverity $severity): string
    {
        return match ($severity) {
            IncidentSeverity::Critical => 'critical',
            IncidentSeverity::Warn => 'warning',
            IncidentSeverity::Info => 'info',
        };
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
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $monitorName = $this->monitorName();

        return (new MailMessage)
            ->subject(__($this->copyKey('subject'), $this->copyParams()))
            ->greeting(__($this->copyKey('greeting')))
            ->line(__($this->copyKey('state_line'), [
                'monitor' => $monitorName,
                'lifecycle' => $this->incident->lifecycle->value,
            ]))
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
            'type' => $this->eventType(),
            'title' => __($this->copyKey('title'), $this->copyParams()),
            // No explicit locale, and that is the whole point: Laravel wraps each
            // recipient's channel build in `withLocale(preferredLocale(...))`, so
            // an ambient render inside this method resolves to THIS recipient's
            // language. Rendering earlier (a constructor argument, a property)
            // would bake the dispatcher's language into the queued payload and
            // hand every recipient the same one.
            // The MONITOR, because `title` above now carries the incident's own
            // sentence. Both facts stay on the row: what happened, and where.
            // The two used to be "API is down" over "HTTP status code breached
            // critical bound", where only the second was true.
            'body' => $monitorName,
            'incident_id' => $this->incident->id,
            'monitor_id' => $this->incident->primary_monitor_id,
            'monitor_name' => $monitorName,
            'severity' => $this->incident->severity->value,
            'kind' => 'incident',
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
