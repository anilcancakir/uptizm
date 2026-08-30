<?php

namespace App\Listeners;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Notifications\Channels\ChannelDeliveryResult;
use App\Notifications\IncidentEscalated;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use Illuminate\Contracts\Events\ShouldBeDiscovered;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use ReflectionClass;
use Throwable;

/**
 * Writes one {@see NotificationDelivery} row per attempted send through a
 * team-scoped {@see NotificationChannel}.
 *
 * It listens on BOTH notification seams because either alone under-records.
 * {@see NotificationSent} fires only once the channel's `send()` has RETURNED,
 * so a transport failure (the one failure a queue retry can fix, and therefore
 * the one the channels rethrow rather than report) never reaches it;
 * {@see NotificationFailed} sees only that case and nothing that came back
 * normally. Registered for both in `AppServiceProvider::boot()`.
 *
 * The `instanceof NotificationChannel` filter is what keeps the user lanes
 * (mail, database, push, sms, broadcast) out of the table: every notification
 * in this application passes through these events, and only a team channel
 * belongs in a channel-delivery record.
 *
 * SYNCHRONOUS on purpose, and not `ShouldQueue`. It already runs on the worker
 * that holds the decrypted channel model, and queueing it would serialise an
 * event carrying a live delivery result for no gain over a single insert.
 *
 * Nothing it writes may carry a credential. `status_code`, `error_code` (the
 * vendor code {@see ChannelDeliveryResult} has already allowlisted) and
 * `exception_class` are the whole vocabulary; an exception MESSAGE is never
 * read, because for two of the four channels the target URL is the credential
 * and Guzzle appends that URL to every cURL message.
 *
 * The table records ATTEMPTED deliveries only. Upstream gates in
 * `IncidentDispatcher` (severity band, the 60s per-incident throttle,
 * maintenance suppression) drop a send before any event fires, so an absent
 * row must never be read as a delivery that should have happened.
 */
class RecordNotificationDelivery implements ShouldBeDiscovered
{
    /**
     * The lifecycle word each known notification records itself under.
     */
    private const array EVENT_BY_NOTIFICATION = [
        IncidentOpened::class => 'opened',
        IncidentResolved::class => 'resolved',
        IncidentEscalated::class => 'escalated',
    ];

    /**
     * The lifecycle word for the throwaway notification behind the test-send
     * endpoint.
     */
    private const string EVENT_TEST = 'test';

    /**
     * Stay out of Laravel's listener auto-discovery.
     *
     * `app/Listeners` is scanned by default and every public `handle*` method
     * is registered against its first parameter's type, which for the union
     * below is BOTH events. That discovery is additive, not instead of, so
     * with the explicit `Event::listen` in `AppServiceProvider` every send
     * wrote TWO rows.
     *
     * Discovery is the wrong half to keep. It binds the registration to a
     * directory name and a method name, so a rename would silently unregister
     * the listener and the table would quietly stop being written, which is the
     * exact failure mode this table exists to remove. The explicit
     * registration says out loud which events are recorded and why both are
     * needed.
     */
    public static function shouldBeDiscovered(): bool
    {
        return false;
    }

    /**
     * Record one attempted delivery, from whichever seam reported it.
     */
    public function handle(NotificationSent|NotificationFailed $event): void
    {
        $channel = $event->notifiable;

        if (! $channel instanceof NotificationChannel) {
            return;
        }

        // `NotificationChannelController::testNotification()` returns an
        // ANONYMOUS notification, so anonymity is the discriminator rather than
        // a payload field. Asked of reflection rather than matched against
        // `@anonymous` in the class string: the marker is an implementation
        // detail of how PHP names such a class, and the question here is what
        // the class IS.
        $isTest = (new ReflectionClass($event->notification))->isAnonymous();

        NotificationDelivery::create([
            'team_id' => $channel->team_id,
            'channel_id' => $channel->getKey(),
            // Denormalised from the channel so the row stays legible after the
            // channel is deleted and `channel_id` goes null.
            'channel_type' => $channel->channel_type->value,
            'notification_type' => $this->storableClassName($event->notification),
            'event' => $this->lifecycleEvent($event->notification, $isTest),
            'is_test' => $isTest,
            ...$this->outcomeColumns($event),
        ]);
    }

    /**
     * The four columns describing how the attempt ended, read from whichever
     * seam this event came from.
     *
     * @return array{
     *     outcome: string,
     *     status_code: int|null,
     *     error_code: string|null,
     *     exception_class: class-string|null,
     * }
     */
    private function outcomeColumns(NotificationSent|NotificationFailed $event): array
    {
        if ($event instanceof NotificationFailed) {
            // There is no `$event->exception`: NotificationFailed's constructor
            // is `($notifiable, $notification, $channel, $data = [])` and
            // NotificationSender passes `['exception' => $exception]`
            // (vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:173).
            $exception = $event->data['exception'] ?? null;

            return [
                'outcome' => ChannelDeliveryResult::OUTCOME_FAILED,
                // Null BY CONSTRUCTION, not by omission: `send()` threw, so no
                // response ever existed for a status to be read from.
                'status_code' => null,
                'error_code' => null,
                // The class, never the message. The message is where the
                // target URL rides. Routed through the same NUL strip as
                // `notification_type`: an anonymous exception class carries the
                // byte PostgreSQL rejects, and throwing a QueryException from
                // inside the failure handler would replace the transport
                // failure it was recording.
                'exception_class' => $exception instanceof Throwable
                    ? $this->storableClassName($exception)
                    : null,
            ];
        }

        // Step 1 gave all four channels a ChannelDeliveryResult return value,
        // which is what `NotificationSent::$response` carries here. The type
        // check is a guard rather than a branch: reaching a channel that
        // returned something else would mean the contract had been broken.
        //
        // The fallback below then records `delivered`, and that is an
        // ASSUMPTION rather than a reading: all `NotificationSent` proves is
        // that `send()` returned without throwing. It is the best available
        // signal and the alternative (`failed`) would be equally invented, but
        // a channel added later that forgets the return type will be recorded
        // as a success it never claimed. Pinned by
        // `test_a_channel_returning_no_result_is_recorded_as_delivered` so the
        // behaviour is a decision on the record rather than a default nobody
        // chose.
        $result = $event->response instanceof ChannelDeliveryResult ? $event->response : null;

        return [
            'outcome' => $result?->outcome ?? ChannelDeliveryResult::OUTCOME_DELIVERED,
            'status_code' => $result?->statusCode,
            'error_code' => $result?->errorCode,
            'exception_class' => $result?->exceptionClass,
        ];
    }

    /**
     * Any object's class, in a form a varchar column can hold.
     *
     * PHP names an anonymous class `Parent@anonymous` + a NUL byte + the
     * absolute file path and line it was declared at. That NUL is not
     * storable: PostgreSQL rejects it outright ("invalid byte sequence for
     * encoding UTF8: 0x00") while SQLite accepts it, so writing the raw name
     * would pass the default suite and fail on the box. Everything after the
     * NUL is a deployment path anyway; the half before it is the only stable
     * half and the only one worth recording.
     *
     * Both class-name columns go through here. `notification_type` is the live
     * case (the test-send notification IS an anonymous class); `exception_class`
     * is latent, since no anonymous exception exists in this codebase today,
     * but an asymmetry between the two reads as an oversight and would fail in
     * the one place it must not: inside the handler recording a failure.
     */
    private function storableClassName(object $subject): string
    {
        $class = $subject::class;
        $marker = strpos($class, "\0");

        return $marker === false ? $class : substr($class, 0, $marker);
    }

    /**
     * The lifecycle word this notification records itself under.
     *
     * An unrecognised class falls back to its short name rather than throwing:
     * the listener is synchronous and inside the send, so a notification added
     * later must record something rather than break the delivery it is
     * recording.
     */
    private function lifecycleEvent(object $notification, bool $isTest): string
    {
        if ($isTest) {
            return self::EVENT_TEST;
        }

        return self::EVENT_BY_NOTIFICATION[$notification::class] ?? class_basename($notification);
    }
}
