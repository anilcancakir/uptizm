<?php

namespace App\Notifications\Channels;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
 */
class PagerDutyChannel
{
    /**
     * PagerDuty Events API v2 enqueue endpoint. The routing key travels in the
     * body, so a single URL serves every team's integration.
     */
    private const ENDPOINT = 'https://events.pagerduty.com/v2/enqueue';

    /**
     * Maximum number of retries attempted after an initial 429 response.
     */
    private const MAX_RATE_LIMIT_RETRIES = 1;

    /**
     * Backoff (seconds) applied when a 429 carries no usable Retry-After header.
     */
    private const DEFAULT_RETRY_AFTER_SECONDS = 1;

    /**
     * Hard cap (seconds) on any single backoff sleep, so a hostile or mis-set
     * Retry-After can never block the worker or request thread beyond it.
     */
    private const MAX_RETRY_AFTER_SECONDS = 5;

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
        //    queue can retry a transient failure. A 429 is honored with one
        //    bounded, Retry-After-aware retry before the outcome is judged.
        $response = $this->sendWithRateLimitBackoff(
            fn (): Response => Http::asJson()->post(self::ENDPOINT, $payload),
        );

        // 5. Only a 202 Accepted is a success. Any other status (400, 5xx, or a
        //    429 that survived the retry) is a delivery failure: report it
        //    (without the routing key) and stop, never rethrow into the queue.
        if ($response->status() !== HttpResponse::HTTP_ACCEPTED) {
            report(new RuntimeException(sprintf(
                'PagerDuty enqueue failed with status %d.',
                $response->status(),
            )));
        }
    }

    /**
     * Run the given send closure, retrying a 429 once after honoring its
     * Retry-After hint, and return the final response.
     *
     * The closure is invoked afresh on every attempt so the full request is
     * rebuilt on the retry rather than reused. The backoff is bounded on both
     * axes (at most one retry, capped sleep) and never throws: an exhausted
     * budget returns the last 429, which the caller reports without rethrowing.
     *
     * @param  Closure(): Response  $send  Produces one outbound HTTP response.
     */
    private function sendWithRateLimitBackoff(Closure $send): Response
    {
        $attempt = 0;

        while (true) {
            $response = $send();

            // 1. Only a 429 is retryable here; any other outcome is returned
            //    untouched so the caller's own success/report logic decides.
            if ($response->status() !== HttpResponse::HTTP_TOO_MANY_REQUESTS) {
                return $response;
            }

            // 2. A spent budget returns the last 429 as-is for the caller to
            //    report, so an exhausted retry stays honest and never throws.
            if ($attempt >= self::MAX_RATE_LIMIT_RETRIES) {
                return $response;
            }

            // 3. Honor the provider's Retry-After hint (bounded) before the
            //    single retry. Sleep runs through the facade so tests fake it.
            Sleep::for($this->retryAfterSeconds($response))->seconds();
            $attempt++;
        }
    }

    /**
     * Resolve the bounded backoff (seconds) for a 429 from its Retry-After
     * header, supporting the delay-seconds and HTTP-date forms and falling back
     * to the default when the header is absent or unparseable.
     */
    private function retryAfterSeconds(Response $response): int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return self::DEFAULT_RETRY_AFTER_SECONDS;
        }

        // 1. Delay-seconds form (e.g. "120"): a plain non-negative integer.
        if (ctype_digit($header)) {
            return max(0, min((int) $header, self::MAX_RETRY_AFTER_SECONDS));
        }

        // 2. HTTP-date form (e.g. "Wed, 21 Oct 2015 07:28:00 GMT"): the delay is
        //    the distance from now; an unparseable value falls back to default.
        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return self::DEFAULT_RETRY_AFTER_SECONDS;
        }

        return max(0, min($timestamp - time(), self::MAX_RETRY_AFTER_SECONDS));
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
