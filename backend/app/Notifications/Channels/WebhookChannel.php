<?php

namespace App\Notifications\Channels;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use App\Support\Monitoring\HostGuard;
use App\Support\Monitoring\RelaySigner;
use App\Support\Sentry\SentryScrubber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
 * would reopen it. A blocked target is reported as a delivery failure and
 * skipped (logged without the secret), never POSTed, and never followed
 * through a redirect.
 *
 * Every no-delivery outcome (a blocked target, an unconfigured route, a
 * non-2xx answer including a 3xx redirect) is reported to the exception
 * handler without being rethrown: the report keeps the synchronous test-send
 * honest (it reads a reported failure as `delivered:false`) while the
 * no-throw keeps a queued incident send from poisoning the queue.
 *
 * A TRANSPORT failure is the one case that does propagate, and it is replaced
 * first: Guzzle appends the full request URI to a cURL error message, so the
 * tenant URL would otherwise reach `failed_jobs.exception` and Sentry with its
 * path and query intact.
 *
 * That rethrow closes ONE of the two routes the url had, and only one. The
 * second is `sentry-laravel`'s HTTP-client breadcrumb, which attaches the url
 * and the raw query to every outbound request independently of any exception,
 * on success as well as on failure. Nothing in this class can reach it; it is
 * closed in {@see SentryScrubber}, which reduces an HTTP
 * breadcrumb to its origin. Both halves are needed, and a claim that the
 * credential is contained is only true while both hold.
 *
 * What propagation buys here is the `NotificationFailed` seam (which is what
 * records the failed delivery row) and a visible `failed_jobs` entry. It does
 * NOT buy a retry on this deployment: `config/horizon.php` runs supervisor-1
 * with `tries: 1`, the production block overrides only `maxProcesses`, and none
 * of the incident notifications declares its own `$tries`, so the job gets one
 * attempt. Wanting a real retry is a separate decision, and not one to make by
 * adding `$tries` to a notification: the mail and escalation lanes send to
 * several notifiables per job, which is the sibling-resend hazard
 * {@see RetriesRateLimitedDelivery} already names.
 */
class WebhookChannel
{
    use RetriesRateLimitedDelivery;

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
     * @return ChannelDeliveryResult What the attempt amounted to, for the
     *                               `NotificationSent` listener.
     *
     * @throws \JsonException When the webhook payload cannot be JSON-encoded.
     * @throws RuntimeException When the target could not be reached at all; it
     *                          names the host and nothing else, so the failure
     *                          reaches `NotificationFailed` and `failed_jobs`
     *                          without the URL riding along.
     */
    public function send(object $notifiable, Notification $notification): ChannelDeliveryResult
    {
        // 1. Skip when the notification cannot build a webhook payload. Nothing
        //    was attempted, and `failed` is the honest half of a two-value
        //    outcome: a null status and a null exception class are what mark it
        //    as a no-send rather than a refusal.
        if (! is_callable([$notification, 'toWebhook'])) {
            return ChannelDeliveryResult::failed();
        }

        // 2. Resolve the tenant target; a missing url + secret is a non-delivery,
        //    reported (without any credential) so the test-send reads it as a
        //    failure rather than a false success.
        $route = $this->resolveRoute($notifiable);
        if ($route === null) {
            report(new RuntimeException(
                'Webhook delivery skipped: no url and secret configured for the target.',
            ));

            return ChannelDeliveryResult::failed();
        }

        [$url, $secret] = $route;

        // 3. Re-validate the URL at send time and capture the exact resolved
        //    IP(s). A blocked target is skipped deliberately (logged without
        //    the secret), so a queued job does not retry a denied host forever.
        try {
            $pinnedIps = $this->guard->resolveAndAssertAllowed($url);
        } catch (ValidationException) {
            $host = parse_url($url, PHP_URL_HOST);

            Log::warning('Webhook delivery skipped: target rejected by SSRF guard.', [
                'host' => $host,
            ]);

            // Report the skip (host only, never the secret) so the test-send
            // reads a blocked target as a failure, not a silent success.
            report(new RuntimeException(sprintf(
                'Webhook delivery to %s skipped: target rejected by the SSRF guard.',
                (string) $host,
            )));

            return ChannelDeliveryResult::failed(exceptionClass: ValidationException::class);
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
        //    and a 3xx cannot bounce the POST to an internal host. A 429 is
        //    honored with one bounded, Retry-After-aware retry: the closure is
        //    re-invoked so the IP-pin + no-redirect options are rebuilt intact
        //    on the retry rather than reused.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        try {
            $response = $this->sendWithRateLimitBackoff(
                fn (): Response => Http::withHeaders([
                    self::SIGNATURE_HEADER => $signature,
                    self::TIMESTAMP_HEADER => (string) $timestamp,
                ])
                    ->withOptions([
                        'allow_redirects' => false,
                        // CURLOPT_RESOLVE pins the connection to the validated IP(s),
                        // so connect-time cannot drift from validation-time. It is a
                        // cURL-handler option and assumes Guzzle uses the curl
                        // handler (true under frankenphp/Octane, where ext-curl is
                        // present); if Guzzle ever fell back to the PHP stream
                        // handler the pin would be silently ignored and the
                        // rebinding window would reopen.
                        'curl' => [
                            CURLOPT_RESOLVE => [$host.':443:'.implode(',', $pinnedIps)],
                        ],
                    ])
                    ->withBody($body, 'application/json')
                    ->post($url),
            );
        } catch (ConnectionException) {
            // 6. The target could not be reached. Guzzle appends the full
            //    request URI to its cURL message and PSR-7 redacts only the
            //    `user:pass@` component, so the tenant's path and query would
            //    ride this exception into `failed_jobs.exception` and Sentry,
            //    where SentryScrubber's key matching cannot reach a secret that
            //    sits inside a URL. Name the host and nothing else, and do NOT
            //    pass the original as `$previous`: every renderer walks the
            //    chain, which would print the URL again one link down.
            //
            //    Rethrown rather than reported, unlike every other failure
            //    here. Reporting would swallow it into a `delivered`-shaped
            //    silence: only a throw reaches `NotificationFailed`, which is
            //    what records the failed delivery row, and only a throw leaves
            //    a `failed_jobs` entry an operator can find. It does not buy a
            //    retry; see the class docblock for why.
            throw new RuntimeException(sprintf(
                'Webhook delivery to %s failed: the target could not be reached.',
                $host,
            ));
        }

        // 7. Anything that is not a 2xx is a non-delivery, including a 3xx
        //    redirect (which the pinned connection refuses to follow). Surface
        //    it honestly without poisoning the queue: report the host + status
        //    only, never the secret or the signed body.
        if (! $response->successful()) {
            report(new RuntimeException(
                "Webhook delivery to {$host} failed with status {$response->status()}.",
            ));

            return ChannelDeliveryResult::failed(statusCode: $response->status());
        }

        return ChannelDeliveryResult::delivered(statusCode: $response->status());
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
