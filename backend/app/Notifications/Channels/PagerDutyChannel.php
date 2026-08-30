<?php

namespace App\Notifications\Channels;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
 * - PagerDuty answers HTTP 202 Accepted on success. A 429 rate-limit is honored
 *   with one bounded, Retry-After-aware retry (PagerDuty documents no
 *   Retry-After header, so a small default backoff applies) before the outcome
 *   is judged. Anything else that is not a 202 (a 400 bad event, a 5xx, or a
 *   429 that survives the retry) is a delivery failure: reported to the
 *   exception handler but NOT rethrown, so a permanent failure does not poison
 *   the queue with retries. The report never carries the routing key.
 *
 * A TRANSPORT failure is the one case that does propagate, because it is the
 * one case a retry can fix, and it is replaced by a host-only error first.
 * This endpoint is a constant, so unlike the two tenant-url channels there is
 * no secret in the URI Guzzle appends to its cURL message; the replacement
 * holds the contract, which is that no raw transport message leaves a channel.
 */
class PagerDutyChannel
{
    use RetriesRateLimitedDelivery;

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
     * @return ChannelDeliveryResult What the attempt amounted to, for the
     *                               `NotificationSent` listener.
     *
     * @throws RuntimeException When PagerDuty could not be reached at all; it
     *                          names the host so the queue can retry without the
     *                          raw transport message reaching the failed-job record.
     */
    public function send(object $notifiable, Notification $notification): ChannelDeliveryResult
    {
        // 1. Skip when the notification cannot render a PagerDuty event.
        //    Nothing was attempted, and `failed` is the honest half of a
        //    two-value outcome: a null status and a null exception class are
        //    what mark it as a no-send rather than a refusal.
        if (! is_callable([$notification, 'toPagerDuty'])) {
            return ChannelDeliveryResult::failed();
        }

        // 2. Resolve the per-team routing key; a missing key is a non-delivery,
        //    reported (without the key) so the test-send reads it as a failure.
        $routingKey = $this->resolveRoutingKey($notifiable);

        if ($routingKey === null) {
            report(new RuntimeException(
                'PagerDuty delivery skipped: no routing key configured for the channel.',
            ));

            return ChannelDeliveryResult::failed();
        }

        // 3. Build the event body and inject the routing key. The body carries
        //    the event_action + dedup_key (trigger/resolve share the key) so the
        //    same incident correlates across open and resolve.
        $payload = (array) $notification->toPagerDuty($notifiable);
        $payload['routing_key'] = $routingKey;

        // 4. POST to the Events API v2; a transport error propagates so the
        //    queue can retry a transient failure. A 429 is honored with one
        //    bounded, Retry-After-aware retry before the outcome is judged.
        try {
            $response = $this->sendWithRateLimitBackoff(
                fn (): Response => Http::asJson()->post(self::ENDPOINT, $payload),
            );
        } catch (ConnectionException) {
            // PagerDuty could not be reached. The raw message is dropped rather
            // than wrapped: Guzzle appends the request URI to its cURL text,
            // and while this endpoint is a constant, a channel that passes a
            // transport message through is a channel that leaks one the day the
            // URL stops being constant. The original is not chained as
            // `$previous` either, since every renderer walks the chain.
            //
            // Rethrown rather than reported, unlike the failure below: a
            // connect failure is the one a retry can fix, and only propagation
            // out of `send()` gives the queued job that retry.
            throw new RuntimeException(sprintf(
                'PagerDuty delivery to %s failed: the host could not be reached.',
                (string) parse_url(self::ENDPOINT, PHP_URL_HOST),
            ));
        }

        // 5. Only a 202 Accepted is a success. Any other status (400, 5xx, or a
        //    429 that survived the retry) is a delivery failure: report it
        //    (without the routing key) and stop, never rethrow into the queue.
        //    Events API v2 names its own reason in `message`, so that is the
        //    value the result carries for a listener to record.
        if ($response->status() !== HttpResponse::HTTP_ACCEPTED) {
            report(new RuntimeException(sprintf(
                'PagerDuty enqueue failed with status %d.',
                $response->status(),
            )));

            $message = $response->json('message');

            return ChannelDeliveryResult::failed(
                statusCode: $response->status(),
                errorCode: is_string($message) ? $message : null,
            );
        }

        return ChannelDeliveryResult::delivered(statusCode: $response->status());
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
