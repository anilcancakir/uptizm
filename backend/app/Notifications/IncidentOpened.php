<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
     * @param  mixed  $notifiable  The entity receiving the notification.
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [
            'mail',
            'database',
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
        return rtrim((string) config('app.url'), '/') . '/incidents/' . $this->incident->id;
    }
}
