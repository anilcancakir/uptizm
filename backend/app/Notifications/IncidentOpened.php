<?php

namespace App\Notifications;

use App\Enums\NotificationChannelType;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\WebhookChannel;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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

        return self::withoutDisabledChannels($notifiable, self::defaultChannels(), 'incident_opened');
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

        if (Features::hasOnesignalFeatures()) {
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
            'en' => __('notifications.incident_opened_push_heading', ['monitor' => $this->monitorName('en')], 'en'),
            'tr' => __('notifications.incident_opened_push_heading', ['monitor' => $this->monitorName('tr')], 'tr'),
        ]));
        $payload->setContents(new LanguageStringMap([
            // The incident title is user-generated data, not translatable copy.
            'en' => $this->incident->title,
            'tr' => $this->incident->title,
        ]));

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
            'text' => __('notifications.incident_opened_subject', ['monitor' => $monitorName])."\n"
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
            'event' => 'incident.opened',
            'incident_id' => $this->incident->id,
            'monitor_id' => $this->incident->primary_monitor_id,
            'monitor_name' => $this->monitorName(),
            'state' => $this->incident->lifecycle->value,
            'severity' => $this->incident->severity->value,
            'title' => $this->incident->title,
            'incident_url' => $this->incidentUrl(),
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
            ->subject(__('notifications.incident_opened_subject', ['monitor' => $monitorName]))
            ->greeting(__('notifications.incident_opened_greeting'))
            ->line(__('notifications.incident_opened_state_line', [
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
            'type' => 'incident_opened',
            'title' => __('notifications.incident_opened_title', ['monitor' => $monitorName]),
            'body' => $this->incident->title,
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
