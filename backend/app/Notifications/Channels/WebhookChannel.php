<?php

namespace App\Notifications\Channels;

use App\Support\Monitoring\HostGuard;
use App\Support\Monitoring\RelaySigner;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Custom notification channel that POSTs a signed JSON payload to a
 * tenant-configured webhook endpoint.
 *
 * A notification opts into this channel by exposing `toWebhook($notifiable)`
 * (the JSON payload) and the notifiable by exposing
 * `routeNotificationForWebhook()` returning `{url, secret}`. The body is signed
 * with HMAC-SHA256 over `"{timestamp}.{body}"` (the same scheme the relay
 * worker uses, see {@see RelaySigner}) and carried in the
 * `X-Uptizm-Signature` + `X-Uptizm-Timestamp` headers so the receiver can
 * authenticate the payload.
 *
 * Because the URL is tenant-controlled, the target is re-validated at send
 * time through {@see HostGuard::resolveAndAssertAllowed()} and the connection
 * is pinned to the exact IP(s) that validation resolved: validate-at-store
 * alone leaves a DNS-rebinding window, and a second resolution at connect time
 * would reopen it. A blocked target is skipped (logged without the secret),
 * never POSTed, and never followed through a redirect.
 */
class WebhookChannel
{
    /**
     * Header carrying the hex HMAC-SHA256 signature of "{timestamp}.{body}".
     */
    protected const string SIGNATURE_HEADER = 'X-Uptizm-Signature';

    /**
     * Header carrying the unix-seconds timestamp the body was signed at.
     */
    protected const string TIMESTAMP_HEADER = 'X-Uptizm-Timestamp';

    /**
     * @param  HostGuard  $guard  Shared SSRF guard, reused for send-time checks.
     */
    public function __construct(
        protected HostGuard $guard,
    ) {}

    /**
     * Send the notification to the notifiable's webhook target.
     *
     * @param  object  $notifiable  The entity exposing `routeNotificationForWebhook()`.
     * @param  Notification  $notification  The notification exposing `toWebhook()`.
     *
     * @throws \JsonException When the webhook payload cannot be JSON-encoded.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // 1. Skip when the notification cannot build a webhook payload.
        if (! is_callable([$notification, 'toWebhook'])) {
            return;
        }

        // 2. Resolve the tenant target; nothing to do without a url + secret.
        $route = $this->resolveRoute($notifiable);
        if ($route === null) {
            return;
        }

        [$url, $secret] = $route;

        // 3. Re-validate the URL at send time and capture the exact resolved
        //    IP(s). A blocked target is skipped deliberately (logged without
        //    the secret), so a queued job does not retry a denied host forever.
        try {
            $pinnedIps = $this->guard->resolveAndAssertAllowed($url);
        } catch (ValidationException) {
            Log::warning('Webhook delivery skipped: target rejected by SSRF guard.', [
                'host' => parse_url($url, PHP_URL_HOST),
            ]);

            return;
        }

        // 4. Encode once: the same bytes are both signed and sent, so the
        //    receiver recomputes an identical HMAC.
        $body = json_encode(
            \call_user_func([$notification, 'toWebhook'], $notifiable),
            JSON_THROW_ON_ERROR,
        );

        $timestamp = time();
        $signature = (new RelaySigner($secret))->sign($timestamp, $body);

        // 5. Pin the connection to the validated IP(s) and forbid redirects,
        //    so connect-time cannot drift from validation-time (DNS rebinding)
        //    and a 3xx cannot bounce the POST to an internal host.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $response = Http::withHeaders([
            self::SIGNATURE_HEADER => $signature,
            self::TIMESTAMP_HEADER => (string) $timestamp,
        ])
            ->withOptions([
                'allow_redirects' => false,
                // CURLOPT_RESOLVE pins the connection to the validated IP(s), so
                // connect-time cannot drift from validation-time. It is a
                // cURL-handler option and assumes Guzzle uses the curl handler
                // (true under frankenphp/Octane, where ext-curl is present); if
                // Guzzle ever fell back to the PHP stream handler the pin would
                // be silently ignored and the rebinding window would reopen.
                'curl' => [
                    CURLOPT_RESOLVE => [$host.':443:'.implode(',', $pinnedIps)],
                ],
            ])
            ->withBody($body, 'application/json')
            ->post($url);

        // 6. Surface a non-2xx honestly without poisoning the queue: report the
        //    host + status only, never the secret or the signed body.
        if ($response->failed()) {
            report(new RuntimeException(
                "Webhook delivery to {$host} failed with status {$response->status()}.",
            ));
        }
    }

    /**
     * Resolve the notifiable's webhook route to a [url, secret] pair, or null.
     *
     * Returns null when the notifiable exposes no route or the route is missing
     * either the url or the secret, so the empty case is a deliberate no-send.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolveRoute(object $notifiable): ?array
    {
        if (! method_exists($notifiable, 'routeNotificationForWebhook')) {
            return null;
        }

        $route = $notifiable->routeNotificationForWebhook();

        if (! is_array($route)) {
            return null;
        }

        $url = $route['url'] ?? null;
        $secret = $route['secret'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        return [
            $url,
            $secret,
        ];
    }
}
