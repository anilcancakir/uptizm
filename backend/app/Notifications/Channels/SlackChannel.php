<?php

namespace App\Notifications\Channels;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
 *
 * A TRANSPORT failure is the one case that does propagate, and it is replaced
 * by a host-only error first. Slack's endpoint is a constant, so unlike the two
 * tenant-url channels there is no secret in the URI Guzzle appends to its cURL
 * message; the replacement holds the contract, which is that no raw transport
 * message leaves a channel.
 *
 * What propagation buys is the `NotificationFailed` seam (which records the
 * failed delivery row) and a visible `failed_jobs` entry, NOT a retry:
 * supervisor-1 runs `tries: 1` and no incident notification overrides it. See
 * {@see WebhookChannel} for the full reasoning.
 */
class SlackChannel
{
    use RetriesRateLimitedDelivery;

    /**
     * Slack Web API endpoint for posting a message.
     */
    private const ENDPOINT = 'https://slack.com/api/chat.postMessage';

    /**
     * Send the given notification to Slack using the notifiable's team token.
     *
     * @param  object  $notifiable  Entity exposing `routeNotificationForSlack()`.
     * @param  Notification  $notification  Notification exposing `toSlack()`.
     * @return ChannelDeliveryResult What the attempt amounted to, for the
     *                               `NotificationSent` listener.
     *
     * @throws RuntimeException When Slack could not be reached at all; it names
     *                          the host and nothing else, so the failure is
     *                          recorded without the raw transport message
     *                          reaching the failed-job record.
     */
    public function send(object $notifiable, Notification $notification): ChannelDeliveryResult
    {
        // 1. Skip when the notification cannot render a Slack payload. Nothing
        //    was attempted, and `failed` is the honest half of a two-value
        //    outcome: a null status and a null exception class are what mark it
        //    as a no-send rather than a refusal.
        if (! is_callable([$notification, 'toSlack'])) {
            return ChannelDeliveryResult::failed();
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

            return ChannelDeliveryResult::failed();
        }

        // 3. Build the message body, targeting the team's channel when present.
        $payload = (array) $notification->toSlack($notifiable);
        $channel = $route['channel'] ?? null;

        if (is_string($channel) && trim($channel) !== '') {
            $payload['channel'] = $channel;
        }

        // 4. Post with the team token; a transport error propagates, replaced
        //    by a host-only failure first (see the class docblock). A 429 is
        //    honored with one bounded, Retry-After-aware retry before the
        //    outcome is judged.
        try {
            $response = $this->sendWithRateLimitBackoff(
                fn (): Response => Http::withToken($token)->asJson()->post(self::ENDPOINT, $payload),
            );
        } catch (ConnectionException) {
            // Slack could not be reached. The raw message is dropped rather
            // than wrapped: Guzzle appends the request URI to its cURL text,
            // and while this endpoint is a constant, a channel that passes a
            // transport message through is a channel that leaks one the day
            // the URL stops being constant. The original is not chained as
            // `$previous` either, since every renderer walks the chain.
            //
            // Rethrown rather than reported, unlike the failures below: only a
            // throw reaches `NotificationFailed`, which is what records the
            // failed delivery row, and only a throw leaves a `failed_jobs`
            // entry. It does not buy a retry; see the class docblock.
            throw new RuntimeException(sprintf(
                'Slack delivery to %s failed: the host could not be reached.',
                (string) parse_url(self::ENDPOINT, PHP_URL_HOST),
            ));
        }

        // 5. A non-2xx status or a Slack `{ok:false}` body is a delivery
        //    failure: report it (without the token) and stop, never rethrow.
        //    Slack's `error` is its machine-readable code, so it is the value
        //    the result carries for a listener to record.
        if ($response->failed() || $response->json('ok') !== true) {
            $error = $response->json('error');

            report(new RuntimeException(sprintf(
                'Slack chat.postMessage failed: %s',
                $response->json('error', 'http_'.$response->status()),
            )));

            return ChannelDeliveryResult::failed(
                statusCode: $response->status(),
                errorCode: is_string($error) ? $error : null,
            );
        }

        return ChannelDeliveryResult::delivered(statusCode: $response->status());
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
