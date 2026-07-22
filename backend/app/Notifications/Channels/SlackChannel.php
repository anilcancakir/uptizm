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
 * Custom Slack notification channel posting through a per-team bot token.
 *
 * The channel resolves the target from the notifiable's
 * `routeNotificationForSlack()` (a per-team `{token, channel}` pair), renders
 * the message via the notification's `toSlack($notifiable)` builder, and POSTs
 * it to Slack's `chat.postMessage` with an `Authorization: Bearer <team token>`
 * header.
 *
 * Two deliberate behaviors:
 *
 * - An empty/absent team token is a non-delivery: it is reported to the
 *   exception handler (never carrying the token) and the send is skipped, so
 *   the synchronous test-send reads it as `delivered:false` instead of a false
 *   success. The channel never falls back to the shared
 *   `services.slack.notifications.bot_user_oauth_token` config value, so one
 *   team's token can never leak into another workspace.
 * - Slack answers HTTP 200 with `{"ok": false, "error": ...}` on a logical
 *   failure (bad token, unknown channel). That is reported to the exception
 *   handler but NOT rethrown, so a permanent failure does not poison the queue
 *   with retries. The report never carries the token.
 */
class SlackChannel
{
    /**
     * Slack Web API endpoint for posting a message.
     */
    private const ENDPOINT = 'https://slack.com/api/chat.postMessage';

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
     * Send the given notification to Slack using the notifiable's team token.
     *
     * @param  object  $notifiable  Entity exposing `routeNotificationForSlack()`.
     * @param  Notification  $notification  Notification exposing `toSlack()`.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // 1. Skip when the notification cannot render a Slack payload.
        if (! is_callable([$notification, 'toSlack'])) {
            return;
        }

        // 2. Resolve the per-team route; a missing token is a non-delivery,
        //    reported (without the token) so the test-send reads it as a
        //    failure, and must never fall back to the shared config token.
        $route = $this->resolveRoute($notifiable);
        $token = $route['token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            report(new RuntimeException(
                'Slack delivery skipped: no team bot token configured for the channel.',
            ));

            return;
        }

        // 3. Build the message body, targeting the team's channel when present.
        $payload = (array) $notification->toSlack($notifiable);
        $channel = $route['channel'] ?? null;

        if (is_string($channel) && trim($channel) !== '') {
            $payload['channel'] = $channel;
        }

        // 4. Post with the team token; a transport error propagates so the
        //    queue can retry a transient failure. A 429 is honored with one
        //    bounded, Retry-After-aware retry before the outcome is judged.
        $response = $this->sendWithRateLimitBackoff(
            fn (): Response => Http::withToken($token)->asJson()->post(self::ENDPOINT, $payload),
        );

        // 5. A non-2xx status or a Slack `{ok:false}` body is a delivery
        //    failure: report it (without the token) and stop, never rethrow.
        if ($response->failed() || $response->json('ok') !== true) {
            report(new RuntimeException(sprintf(
                'Slack chat.postMessage failed: %s',
                $response->json('error', 'http_'.$response->status()),
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
     * Resolve the per-team Slack route (token + channel) from the notifiable.
     *
     * @return array<string, mixed>
     */
    private function resolveRoute(object $notifiable): array
    {
        if (! is_callable([$notifiable, 'routeNotificationForSlack'])) {
            return [];
        }

        return (array) $notifiable->routeNotificationForSlack();
    }
}
