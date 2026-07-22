<?php

namespace App\Notifications\Channels;

use App\Support\Monitoring\HostGuard;
use App\Support\Monitoring\RelaySigner;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
     * Maximum number of retries attempted after an initial 429 response.
     */
    private const int MAX_RATE_LIMIT_RETRIES = 1;

    /**
     * Backoff (seconds) applied when a 429 carries no usable Retry-After header.
     */
    private const int DEFAULT_RETRY_AFTER_SECONDS = 1;

    /**
     * Hard cap (seconds) on any single backoff sleep, so a hostile or mis-set
     * Retry-After can never block the worker or request thread beyond it.
     */
    private const int MAX_RETRY_AFTER_SECONDS = 5;

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

        // 2. Resolve the tenant target; a missing url + secret is a non-delivery,
        //    reported (without any credential) so the test-send reads it as a
        //    failure rather than a false success.
        $route = $this->resolveRoute($notifiable);
        if ($route === null) {
            report(new RuntimeException(
                'Webhook delivery skipped: no url and secret configured for the target.',
            ));

            return;
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
        //    and a 3xx cannot bounce the POST to an internal host. A 429 is
        //    honored with one bounded, Retry-After-aware retry: the closure is
        //    re-invoked so the IP-pin + no-redirect options are rebuilt intact
        //    on the retry rather than reused.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

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

        // 6. Anything that is not a 2xx is a non-delivery, including a 3xx
        //    redirect (which the pinned connection refuses to follow). Surface
        //    it honestly without poisoning the queue: report the host + status
        //    only, never the secret or the signed body.
        if (! $response->successful()) {
            report(new RuntimeException(
                "Webhook delivery to {$host} failed with status {$response->status()}.",
            ));
        }
    }

    /**
     * Run the given send closure, retrying a 429 once after honoring its
     * Retry-After hint, and return the final response.
     *
     * The closure is invoked afresh on every attempt so the full request (its
     * headers, signed body, and the SSRF IP-pin + no-redirect options) is
     * rebuilt on the retry rather than reused. The backoff is bounded on both
     * axes (at most one retry, capped sleep) and never throws: an exhausted
     * budget returns the last 429, which the caller reports without rethrowing.
     *
     * @param  Closure(): Response  $send  Produces one outbound HTTP response.
     */
    protected function sendWithRateLimitBackoff(Closure $send): Response
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
    protected function retryAfterSeconds(Response $response): int
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
