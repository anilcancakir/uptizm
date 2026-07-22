<?php

namespace App\Notifications\Channels;

use App\Support\Monitoring\HostGuard;
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
 * Custom Microsoft Teams notification channel that POSTs an Adaptive Card to a
 * tenant-configured Power Automate (Workflows) incoming webhook.
 *
 * A notification opts into this channel by exposing `toTeams($notifiable)` (the
 * Adaptive Card `content` object) and the notifiable by exposing
 * `routeNotificationForTeams()` returning `{url}`. The channel wraps the card
 * in the Teams message envelope
 * (`{type:"message",attachments:[{contentType:"application/vnd.microsoft.card.adaptive",content:...}]}`)
 * and POSTs it. The legacy O365 connector `MessageCard` format is retired
 * (~May 2026); only the Workflows Adaptive Card envelope is emitted.
 *
 * The Workflows url carries its own SAS token in a `?sig=` query parameter, so
 * there is no separate signing secret and no Authorization header: the url IS
 * the credential. Because the url is tenant-controlled, the target is
 * re-validated at send time through {@see HostGuard::resolveAndAssertAllowed()}
 * and the connection is pinned to the exact IP(s) that validation resolved:
 * the store-time gate alone leaves a DNS-rebinding window, and a second
 * resolution at connect time would reopen it. A blocked target is reported as a
 * delivery failure and skipped (logged with the host only, never the SAS),
 * never POSTed, and never followed through a redirect.
 *
 * A 429 rate-limit is honored with one bounded, Retry-After-aware retry
 * (Teams Workflows documents no Retry-After header, so a small default backoff
 * applies) before the outcome is judged. Every other no-delivery outcome (a
 * blocked target, an unconfigured route, a non-2xx answer including a 3xx
 * redirect or a 429 that survives the retry) is reported to the exception
 * handler without being rethrown: the report keeps the synchronous test-send
 * honest (it reads a reported failure as `delivered:false`) while the no-throw
 * keeps a queued incident send from poisoning the queue.
 */
class TeamsChannel
{
    /**
     * The Adaptive Card content type Teams expects on the attachment.
     */
    private const string CARD_CONTENT_TYPE = 'application/vnd.microsoft.card.adaptive';

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
     * Send the notification to the notifiable's Teams webhook target.
     *
     * @param  object  $notifiable  The entity exposing `routeNotificationForTeams()`.
     * @param  Notification  $notification  The notification exposing `toTeams()`.
     *
     * @throws \JsonException When the Adaptive Card payload cannot be JSON-encoded.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // 1. Skip when the notification cannot build an Adaptive Card.
        if (! is_callable([$notification, 'toTeams'])) {
            return;
        }

        // 2. Resolve the tenant target; a missing url is a non-delivery,
        //    reported (without the url, so the SAS never leaks) so the
        //    test-send reads it as a failure rather than a false success.
        $url = $this->resolveUrl($notifiable);
        if ($url === null) {
            report(new RuntimeException(
                'Teams delivery skipped: no webhook url configured for the target.',
            ));

            return;
        }

        // 3. Re-validate the url at send time and capture the exact resolved
        //    IP(s). A blocked target is skipped deliberately (logged with the
        //    host only, never the SAS), so a queued job does not retry a denied
        //    host forever.
        try {
            $pinnedIps = $this->guard->resolveAndAssertAllowed($url);
        } catch (ValidationException) {
            $host = parse_url($url, PHP_URL_HOST);

            Log::warning('Teams delivery skipped: target rejected by SSRF guard.', [
                'host' => $host,
            ]);

            report(new RuntimeException(sprintf(
                'Teams delivery to %s skipped: target rejected by the SSRF guard.',
                (string) $host,
            )));

            return;
        }

        // 4. Wrap the card in the Teams message envelope and encode it. The
        //    28KB Workflows limit is a provisioning concern, not enforced here.
        $body = json_encode(
            $this->envelope(\call_user_func([$notification, 'toTeams'], $notifiable)),
            JSON_THROW_ON_ERROR,
        );

        // 5. Pin the connection to the validated IP(s) and forbid redirects, so
        //    connect-time cannot drift from validation-time (DNS rebinding) and
        //    a 3xx cannot bounce the POST to an internal host. A 429 is honored
        //    with one bounded, Retry-After-aware retry: the closure is
        //    re-invoked so the IP-pin + no-redirect options are rebuilt intact
        //    on the retry rather than reused.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $response = $this->sendWithRateLimitBackoff(
            fn (): Response => Http::withOptions([
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
                ->post($url),
        );

        // 6. Teams answers a 2xx on success. Anything else is a non-delivery,
        //    including a 3xx redirect (which the pinned connection refuses to
        //    follow) and a 429 rate-limit. Surface it honestly without
        //    poisoning the queue: report the host + status only, never the SAS.
        if (! $response->successful()) {
            report(new RuntimeException(
                "Teams delivery to {$host} failed with status {$response->status()}.",
            ));
        }
    }

    /**
     * Run the given send closure, retrying a 429 once after honoring its
     * Retry-After hint, and return the final response.
     *
     * The closure is invoked afresh on every attempt so the full request (its
     * SSRF IP-pin + no-redirect options and body) is rebuilt on the retry
     * rather than reused. The backoff is bounded on both axes (at most one
     * retry, capped sleep) and never throws: an exhausted budget returns the
     * last 429, which the caller reports without rethrowing.
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
     * Wrap an Adaptive Card `content` object in the Teams message envelope.
     *
     * @param  array<string, mixed>  $card  The Adaptive Card content.
     * @return array<string, mixed>
     */
    protected function envelope(array $card): array
    {
        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => self::CARD_CONTENT_TYPE,
                    'content' => $card,
                ],
            ],
        ];
    }

    /**
     * Resolve the notifiable's Teams webhook url, or null when absent/empty.
     *
     * Returns null when the notifiable exposes no route or the route is missing
     * the url, so the empty case is a deliberate no-send.
     */
    protected function resolveUrl(object $notifiable): ?string
    {
        if (! is_callable([$notifiable, 'routeNotificationForTeams'])) {
            return null;
        }

        $route = (array) $notifiable->routeNotificationForTeams();
        $url = $route['url'] ?? null;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }
}
