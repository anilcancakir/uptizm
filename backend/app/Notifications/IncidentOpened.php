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
     * @param  array<int, string>|null  $onlyChannels  When set (an escalation
     *                                                 channel-target step), restricts delivery to this subset of the default
     *                                                 channels; null pages on every enabled channel.
     * @return void
     */
    public function __construct(
        public readonly Incident $incident,
        public readonly ?array $onlyChannels = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * The base set is `mail`/`database`, plus `onesignal` when the push feature
     * is active. A channel-target step narrows that to `$onlyChannels`, and any
     * channel the notifiable disabled in its {@see NotificationSetting}
     * rows is dropped so a disabled channel is never paged.
     *
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        $requested = $this->onlyChannels ?? self::defaultChannels();
        $channels = array_values(array_intersect(self::defaultChannels(), $requested));

        return self::withoutDisabledChannels($notifiable, $channels, 'incident_opened');
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
        $monitorName = $this->monitorName();

        $payload = new OneSignalNotification([
            'app_id' => config('magic-starter.onesignal.app_id'),
        ]);
        $payload->setHeadings(new LanguageStringMap([
            'en' => "{$monitorName} is down",
        ]));
        $payload->setContents(new LanguageStringMap([
            'en' => $this->incident->title,
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
            ->subject("[Uptizm] {$monitorName} is down")
            ->greeting('Incident opened')
            ->line("{$monitorName} has entered the \"{$this->incident->lifecycle->value}\" state.")
            ->line("Severity: {$this->incident->severity->value}.")
            ->action('View incident', $this->incidentUrl());
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
            'title' => "{$monitorName} is down",
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
     */
    private function monitorName(): string
    {
        return $this->incident->primaryMonitor?->name ?? 'A monitor';
    }

    /**
     * Build the client-facing URL for this incident.
     */
    private function incidentUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/incidents/'.$this->incident->id;
    }
}
