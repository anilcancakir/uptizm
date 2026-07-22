<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Custom PagerDuty notification channel firing through the Events API v2.
 *
 * The channel resolves the target from the notifiable's
 * `routeNotificationForPagerduty()` (a per-team `{routing_key}` for a PagerDuty
 * service integration), renders the event via the notification's
 * `toPagerDuty($notifiable)` builder, injects the routing key into the body,
 * and POSTs it to https://events.pagerduty.com/v2/enqueue. Unlike Slack, the
 * secret rides in the JSON body (there is no Authorization header for Events
 * API v2), so it is never carried in a header, a log line, or a report.
 *
 * Two deliberate behaviors mirror {@see SlackChannel}:
 *
 * - An empty/absent routing key is a non-delivery: it is reported to the
 *   exception handler (never carrying the key) and the send is skipped, so the
 *   synchronous test-send reads it as `delivered:false` instead of a false
 *   success.
 * - PagerDuty answers HTTP 202 Accepted on success. Anything else (a 400 bad
 *   event, a 429 rate-limit, a 5xx) is a delivery failure: reported to the
 *   exception handler but NOT rethrown, so a permanent failure does not poison
 *   the queue with retries. A 429 backoff is layered on in a later step; the
 *   report keeps the outcome honest until then. The report never carries the
 *   routing key.
 */
class PagerDutyChannel
{
    /**
     * PagerDuty Events API v2 enqueue endpoint. The routing key travels in the
     * body, so a single URL serves every team's integration.
     */
    private const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

    /**
     * Send the given notification to PagerDuty using the notifiable's routing key.
     *
     * @param  object  $notifiable  Entity exposing `routeNotificationForPagerduty()`.
     * @param  Notification  $notification  Notification exposing `toPagerDuty()`.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // 1. Skip when the notification cannot render a PagerDuty event.
        if (! is_callable([$notification, 'toPagerDuty'])) {
            return;
        }

        // 2. Resolve the per-team routing key; a missing key is a non-delivery,
        //    reported (without the key) so the test-send reads it as a failure.
        $routingKey = $this->resolveRoutingKey($notifiable);

        if ($routingKey === null) {
            report(new RuntimeException(
                'PagerDuty delivery skipped: no routing key configured for the channel.',
            ));

            return;
        }

        // 3. Build the event body and inject the routing key. The body carries
        //    the event_action + dedup_key (trigger/resolve share the key) so the
        //    same incident correlates across open and resolve.
        $payload = (array) $notification->toPagerDuty($notifiable);
        $payload['routing_key'] = $routingKey;

        // 4. POST to the Events API v2; a transport error propagates so the
        //    queue can retry a transient failure.
        $response = Http::asJson()->post(self::ENDPOINT, $payload);

        // 5. Only a 202 Accepted is a success. Any other status (400, 429, 5xx)
        //    is a delivery failure: report it (without the routing key) and
        //    stop, never rethrow into the queue.
        if ($response->status() !== HttpResponse::HTTP_ACCEPTED) {
            report(new RuntimeException(sprintf(
                'PagerDuty enqueue failed with status %d.',
                $response->status(),
            )));
        }
    }

    /**
     * Resolve the per-team PagerDuty routing key, or null when absent/empty.
     */
    private function resolveRoutingKey(object $notifiable): ?string
    {
        if (! is_callable([$notifiable, 'routeNotificationForPagerduty'])) {
            return null;
        }

        $route = (array) $notifiable->routeNotificationForPagerduty();
        $routingKey = $route['routing_key'] ?? null;

        return is_string($routingKey) && trim($routingKey) !== '' ? $routingKey : null;
    }
}
