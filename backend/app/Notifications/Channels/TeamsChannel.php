<?php

namespace App\Notifications\Channels;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use App\Support\Monitoring\HostGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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
 *
 * A TRANSPORT failure is the one case that does propagate, because it is the
 * one case a retry can fix. It is replaced first: Guzzle appends the full
 * request URI to a cURL error message, and here that URI carries the SAS.
 */
class TeamsChannel
{
    use RetriesRateLimitedDelivery;

    /**
     * The Adaptive Card content type Teams expects on the attachment.
     */
    private const string CARD_CONTENT_TYPE = 'application/vnd.microsoft.card.adaptive';

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
     * @return ChannelDeliveryResult What the attempt amounted to, for the
     *                               `NotificationSent` listener.
     *
     * @throws \JsonException When the Adaptive Card payload cannot be JSON-encoded.
     * @throws RuntimeException When the target could not be reached at all; it
     *                          names the host so the queue can retry without
     *                          the SAS-bearing url reaching the failed-job record.
     */
    public function send(object $notifiable, Notification $notification): ChannelDeliveryResult
    {
        // 1. Skip when the notification cannot build an Adaptive Card. Nothing
        //    was attempted, and `failed` is the honest half of a two-value
        //    outcome: a null status and a null exception class are what mark it
        //    as a no-send rather than a refusal.
        if (! is_callable([$notification, 'toTeams'])) {
            return ChannelDeliveryResult::failed();
        }

        // 2. Resolve the tenant target; a missing url is a non-delivery,
        //    reported (without the url, so the SAS never leaks) so the
        //    test-send reads it as a failure rather than a false success.
        $url = $this->resolveUrl($notifiable);
        if ($url === null) {
            report(new RuntimeException(
                'Teams delivery skipped: no webhook url configured for the target.',
            ));

            return ChannelDeliveryResult::failed();
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

            return ChannelDeliveryResult::failed(exceptionClass: ValidationException::class);
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

        try {
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
        } catch (ConnectionException) {
            // 6. The target could not be reached. Guzzle appends the full
            //    request URI to its cURL message and PSR-7 redacts only the
            //    `user:pass@` component, so the `?sig=` SAS would ride this
            //    exception into `failed_jobs.exception` and Sentry, where
            //    SentryScrubber's key matching cannot reach a secret that sits
            //    inside a URL. Name the host and nothing else, and do NOT pass
            //    the original as `$previous`: every renderer walks the chain,
            //    which would print the url again one link down.
            //
            //    Rethrown rather than reported, unlike every other failure
            //    here: a connect failure is the one that a retry can fix, and
            //    only propagation out of `send()` gives the queued job that
            //    retry.
            throw new RuntimeException(sprintf(
                'Teams delivery to %s failed: the target could not be reached.',
                $host,
            ));
        }

        // 7. Teams answers a 2xx on success. Anything else is a non-delivery,
        //    including a 3xx redirect (which the pinned connection refuses to
        //    follow) and a 429 rate-limit. Surface it honestly without
        //    poisoning the queue: report the host + status only, never the SAS.
        if (! $response->successful()) {
            report(new RuntimeException(
                "Teams delivery to {$host} failed with status {$response->status()}.",
            ));

            return ChannelDeliveryResult::failed(statusCode: $response->status());
        }

        return ChannelDeliveryResult::delivered(statusCode: $response->status());
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
