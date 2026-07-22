<?php

namespace App\Notifications\Channels;

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
 * - An empty/absent team token means "do not send". The channel never falls
 *   back to the shared `services.slack.notifications.bot_user_oauth_token`
 *   config value, so one team's token can never leak into another workspace.
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

        // 2. Resolve the per-team route; a missing token means "do not send"
        //    and must never fall back to the shared config token.
        $route = $this->resolveRoute($notifiable);
        $token = $route['token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            return;
        }

        // 3. Build the message body, targeting the team's channel when present.
        $payload = (array) $notification->toSlack($notifiable);
        $channel = $route['channel'] ?? null;

        if (is_string($channel) && trim($channel) !== '') {
            $payload['channel'] = $channel;
        }

        // 4. Post with the team token; a transport error propagates so the
        //    queue can retry a transient failure.
        $response = Http::withToken($token)
            ->asJson()
            ->post(self::ENDPOINT, $payload);

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
