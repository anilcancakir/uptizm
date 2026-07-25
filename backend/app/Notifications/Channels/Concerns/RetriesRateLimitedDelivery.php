<?php

namespace App\Notifications\Channels\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bounded, Retry-After-aware retry for a rate-limited outbound delivery.
 *
 * Shared by the four team-configured outbound channels (Slack, webhook,
 * PagerDuty, Teams). Each POSTs to a provider that answers HTTP 429 once the
 * team runs over its rate limit, and each needs the same narrow behavior:
 *
 * - A 429 buys exactly ONE retry, never a loop: the budget is spent after the
 *   first backoff, so a persistently throttled provider cannot pin a queue
 *   worker.
 * - The provider's Retry-After hint is honored but clamped into
 *   [0, MAX_RETRY_AFTER_SECONDS], so a hostile or mis-set header can never
 *   block the worker (or the synchronous test-send thread) beyond the cap. An
 *   absent or unparseable header falls back to the small default backoff.
 * - An exhausted budget is REPORTED by the caller, never thrown from here: the
 *   last 429 is returned as-is. These channels run inside a `ShouldQueue`
 *   notification whose `via()` may name several channels in one job, so
 *   throwing would make the queue re-send the already-delivered,
 *   non-idempotent siblings.
 * - The retry re-invokes the caller's closure instead of replaying a built
 *   request. For the two SSRF-guarded channels (webhook, Teams) that closure
 *   captures the IP set resolved once BEFORE the first attempt, so the retry
 *   re-applies the same pre-validated addresses (`CURLOPT_RESOLVE`) with
 *   redirects still disabled. Resolving the host again on the retry would
 *   reopen the DNS-rebinding window that pinning exists to close, so no
 *   resolution ever happens on this path.
 *
 * A non-429 outcome is never interpreted here: it is handed straight back so
 * the channel's own success/report logic decides (Slack reads `{ok:false}`,
 * PagerDuty demands a 202, the webhook and Teams demand any 2xx).
 */
trait RetriesRateLimitedDelivery
{
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
     * Run the given send closure, retrying a 429 once after honoring its
     * Retry-After hint, and return the final response.
     *
     * The closure is invoked afresh on every attempt so the full request (its
     * headers, body, and any SSRF IP-pin + no-redirect options) is rebuilt from
     * the inputs validated before the first attempt rather than reused; nothing
     * on this path resolves a host a second time. The backoff is bounded on
     * both axes (at most one retry, capped sleep) and never throws: an
     * exhausted budget returns the last 429, which the caller reports without
     * rethrowing.
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
}
