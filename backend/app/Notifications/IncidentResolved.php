<?php

namespace App\Notifications;

use App\Models\Incident;
use FlutterSdk\MagicStarter\Features;
use FlutterSdk\MagicStarter\Models\NotificationSetting;
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
     * The base set is `mail`/`database`, plus `onesignal` when the push feature
     * is active. Any channel the notifiable disabled in its
     * {@see NotificationSetting} rows is dropped
     * so a disabled channel is never paged.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return self::withoutDisabledChannels($notifiable, self::defaultChannels(), 'incident_resolved');
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
            'en' => __('notifications.incident_resolved_push_heading', ['monitor' => $this->monitorName('en')], 'en'),
            'tr' => __('notifications.incident_resolved_push_heading', ['monitor' => $this->monitorName('tr')], 'tr'),
        ]));
        $payload->setContents(new LanguageStringMap([
            // The incident title is user-generated data, not translatable copy.
            'en' => $this->incident->title,
            'tr' => $this->incident->title,
        ]));

        return $payload;
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
            'body' => $this->incident->title,
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
