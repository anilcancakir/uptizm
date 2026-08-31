<?php

namespace App\Services\Services;

use App\Enums\ServiceStatusSource;
use App\Jobs\IngestServiceFeed;
use App\Jobs\IngestServiceFeeds;
use App\Models\Service;
use App\Models\ServiceFeedSnapshot;
use App\Notifications\Channels\WebhookChannel;
use App\Services\StatusPages\StatusPageCache;
use App\Support\Monitoring\HostGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fetches one catalog service's official status feed and records what came back.
 *
 * Called once per service per tick by {@see IngestServiceFeed}, which
 * {@see IngestServiceFeeds} fans out. This class owns every constraint the
 * outbound side of this catalog committed to, and each is a contractual
 * commitment to the provider on the other end rather than a preference:
 *
 *  1. An identifying User-Agent carrying a contact URL, from
 *     `config('uptizm.bot_user_agent')`. A provider's operator must be able to
 *     reach us instead of having to block us.
 *  2. A HARD floor of {@see self::MIN_INTERVAL_SECONDS} seconds per service,
 *     enforced against the newest `service_feed_snapshots.fetched_at`, so a
 *     re-dispatch, a duplicate schedule tick or a queue retry cannot poll
 *     faster. This is why the fetch carries no `->retry()`: the floor has to
 *     hold under EVERY code path, and a transport-level retry is a second
 *     request inside the same second.
 *  3. `If-None-Match` replayed from the previous snapshot's ETag, with a `304`
 *     treated as "unchanged": the existing row is touched and no duplicate is
 *     written. Atlassian's own status pages poll `status.json` on a 60-second
 *     cadence and honour `304`, so a conditional request is the documented
 *     friendly behaviour here, not a micro-optimisation.
 *  4. {@see HostGuard::resolveAndAssertAllowed()} re-run immediately before the
 *     request and the connection PINNED to the addresses it validated. A DNS
 *     record can change between the write and the fetch, and a second
 *     resolution at connect time would reopen the rebinding window that pinning
 *     closes. Same shape as {@see WebhookChannel}, for the same reason.
 *  5. A `429` or a `403` DISABLES the feed (`feed_disabled_at` +
 *     `feed_disabled_reason`) and stops polling that service until an operator
 *     clears it. Never retried: retrying into a rate limit is how a bot earns a
 *     permanent block.
 *
 * A fetch failure is DATA, not an exception to swallow: every failure path
 * writes a snapshot row carrying `http_status` and `error` so the public page
 * can honestly say the feed is unreachable, which is the same
 * failure-as-a-recorded-row shape `app/Jobs/PerformSslCheck.php` uses.
 *
 * ## The one thing to get right: `content_changed_at`
 *
 * `services.content_changed_at` is the SOLE input to the public sitemap's
 * `lastmod`, and Google discounts an untrustworthy `lastmod` sitewide. So it
 * moves on exactly one condition: the newly parsed reading's normalized hash
 * differs from the previous snapshot's. NOT on a poll, not on a `304`, not on a
 * failure snapshot (whose hash is null, which would otherwise read as "differs"
 * and bump `lastmod` on every provider outage), and not on a provider
 * reordering its own arrays (see {@see FeedReading::normalizedHash()}). The
 * public page cache is busted under the same single condition, because a bust on
 * every poll defeats the cache and tempts exactly the wrong `lastmod` write.
 *
 * Pinned by `tests/Feature/Services/IngestServiceFeedsTest.php`, which asserts
 * each refusal branch in isolation (with the other four satisfied) so a green
 * suite cannot mean "something refused" without naming which thing.
 */
class FeedFetcher
{
    /**
     * Shortest interval between two fetches of the SAME service, in seconds.
     *
     * Public because `routes/console.php` explains the schedule's cadence in
     * terms of it and the test suite asserts against it, and because the number
     * is a commitment rather than a tuning knob.
     */
    public const int MIN_INTERVAL_SECONDS = 60;

    /**
     * Prefix of the public service-page cache key this fetcher forgets.
     *
     * The page itself lands in a later step of this plan; it MUST build its
     * cache key from this constant (`self::PAGE_CACHE_KEY_PREFIX.$slug`,
     * optionally suffixed with `:{locale}`), because a page that invents its own
     * key would serve a stale provider status forever and nothing would fail.
     * Tag-free plain-key forget on purpose, mirroring
     * {@see StatusPageCache}: cache tags are unsupported on the drivers this app
     * runs.
     */
    public const string PAGE_CACHE_KEY_PREFIX = 'service-page:';

    /**
     * Request timeout in seconds. A feed that cannot answer in ten seconds is
     * recorded as unreachable; the tick has other services to fetch and the
     * worker's own timeout is 60 (`config/horizon.php`, the `background`
     * supervisor, which is where `feeds` is drained).
     */
    protected const int TIMEOUT_SECONDS = 10;

    /**
     * Truncation bound for the recorded error, matching the width of
     * `service_feed_snapshots.error` (a plain `string`, so 255).
     */
    protected const int MAX_ERROR_LENGTH = 250;

    /**
     * @param  HostGuard  $guard  Shared SSRF guard, re-run before every request.
     */
    public function __construct(
        protected HostGuard $guard,
    ) {}

    /**
     * Fetch this service's feed, or refuse for a named reason.
     *
     * Every early return here is a refusal that carries a commitment: an absent
     * feed, an unreviewed terms register, an operator-visible disable, and the
     * polling floor. Publication (`is_published`) is deliberately NOT one of
     * them: that is a display decision, and it is the fan-out
     * ({@see Service::scopeDueForFeedIngest()}) that owns it. The two selections
     * may only diverge in this direction, with this class the stricter of the
     * pair.
     */
    public function fetch(Service $service): void
    {
        // 1. No feed, no adapter, nothing to do. `none` is an honest state (the
        //    service still publishes on uptizm's own measurement), not an error.
        $adapter = $this->adapterFor($service->status_source);
        if ($adapter === null) {
            return;
        }

        $url = (string) $service->status_source_url;
        if ($url === '') {
            return;
        }

        // 2. Terms unreviewed means no human has signed off on republishing this
        //    provider's status yet, and this catalog does not fetch first and ask
        //    later.
        if ($service->terms_reviewed_at === null) {
            return;
        }

        // 3. An operator (or rule 5 below) turned this feed off. It stays off
        //    until they clear the column by hand.
        if ($service->feed_disabled_at !== null) {
            return;
        }

        // 4. The polling floor, enforced against the newest recorded fetch
        //    rather than an in-memory timer, so it survives a re-dispatch, a
        //    second scheduler tick and a queue retry alike.
        $previous = $this->previousSnapshot($service);
        if ($previous !== null
            && $previous->fetched_at !== null
            && $previous->fetched_at->gt(now()->subSeconds(self::MIN_INTERVAL_SECONDS))) {
            return;
        }

        // 5. Re-validate the host and capture the exact addresses to pin to.
        //    A rejection is recorded as data: the page must be able to say this
        //    feed is unreachable rather than quietly showing nothing.
        try {
            $pinnedIps = $this->guard->resolveAndAssertAllowed($url);
        } catch (ValidationException $exception) {
            $message = 'The feed url was rejected by the SSRF guard: '
                .implode(' ', $exception->errors()['url'] ?? []);

            Log::warning('Service feed fetch skipped: url rejected by the SSRF guard.', [
                'service_id' => $service->id,
                'host' => parse_url($url, PHP_URL_HOST),
            ]);

            $this->recordFailure($service, null, $message);

            return;
        }

        $this->request($service, $adapter, $url, $previous, $pinnedIps);
    }

    /**
     * The newest existing snapshot for this service, or null on a first fetch.
     *
     * Queried explicitly rather than through `Service::latestFeedSnapshot()`
     * because the fetcher wants the row without hydrating or caching a relation on
     * a model it is about to write to. The two must agree on what "latest" means,
     * and both now order by `fetched_at` alone.
     *
     * That agreement was not free. The relation originally used
     * `latestOfMany('fetched_at')`, which always appends a tie-breaker aggregate
     * over the PRIMARY KEY, compiling to `MAX("service_feed_snapshots"."id")`.
     * These keys are UUIDs and PostgreSQL has no `max(uuid)`, so the relation
     * threw `SQLSTATE[42883]` on every call while the SQLite test suite stayed
     * green, and this explicit query is what kept ingestion working during that
     * window. The relation is repaired now (see its own docblock); this stays
     * explicit, and the shape is pinned on both sides.
     */
    protected function previousSnapshot(Service $service): ?ServiceFeedSnapshot
    {
        return ServiceFeedSnapshot::query()
            ->where('service_id', $service->getKey())
            ->orderByDesc('fetched_at')
            ->first();
    }

    /**
     * Resolve the adapter for a status source.
     *
     * A `match` IS the whole resolver: two implementations behind a four-case
     * enum do not earn a registry class, and an exhaustive `match` with no
     * `default` is what makes adding a fifth source a compile-time failure here
     * instead of a silent no-op in production.
     */
    protected function adapterFor(ServiceStatusSource $source): ?ServiceStatusAdapter
    {
        return match ($source) {
            ServiceStatusSource::StatuspageV2 => new StatuspageV2Adapter,
            ServiceStatusSource::GoogleCloud, ServiceStatusSource::GoogleWorkspace => new GoogleStatusAdapter,
            ServiceStatusSource::None => null,
        };
    }

    /**
     * Issue the conditional GET and route the response to its outcome.
     *
     * @param  ServiceFeedSnapshot|null  $previous  The newest existing snapshot, source of both the replayed ETag and the hash to compare against.
     * @param  list<string>  $pinnedIps  The addresses {@see HostGuard} just validated.
     */
    protected function request(
        Service $service,
        ServiceStatusAdapter $adapter,
        string $url,
        ?ServiceFeedSnapshot $previous,
        array $pinnedIps,
    ): void {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        $headers = [
            'User-Agent' => (string) config('uptizm.bot_user_agent'),
        ];

        if (is_string($previous?->etag) && $previous->etag !== '') {
            $headers['If-None-Match'] = $previous->etag;
        }

        try {
            $response = Http::withHeaders($headers)
                ->withOptions([
                    // A redirect is never followed. Feed URLs move
                    // (`status.anthropic.com` now 302s to `status.claude.com`),
                    // and following one would fetch a host whose terms nobody
                    // reviewed, from an address the guard above never validated.
                    // It is recorded below so an operator can update the row.
                    'allow_redirects' => false,
                    // CURLOPT_RESOLVE pins the connection to the validated
                    // address set, so connect-time cannot drift from
                    // validate-time. Same caveat as WebhookChannel: it assumes
                    // Guzzle's curl handler, which is present under
                    // frankenphp/Octane.
                    'curl' => [
                        CURLOPT_RESOLVE => [$host.':443:'.implode(',', $pinnedIps)],
                    ],
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $exception) {
            // Not swallowed: recorded on a row so the page can say the feed is
            // unreachable, and logged so an operator can see the cause. Not
            // rethrown, because a provider being down is not this job failing.
            Log::warning('Service feed fetch failed to connect.', [
                'service_id' => $service->id,
                'host' => $host,
                'error' => $exception->getMessage(),
            ]);

            $this->recordFailure($service, null, $exception->getMessage());

            return;
        }

        $status = $response->status();

        // 1. Rate limited or refused: disable and stop. Never retried.
        if ($status === 429 || $status === 403) {
            $this->disableFeed($service, $host, $status);

            return;
        }

        // 2. Unchanged. Touch the existing row rather than writing a duplicate:
        //    a 304 asserts that what we already parsed is still what they serve,
        //    so the reading's freshness moves and its content does not.
        if ($status === 304 && $previous !== null) {
            $previous->forceFill([
                'fetched_at' => now(),
            ])->save();

            return;
        }

        // 3. Any other 3xx is an unfollowed redirect. Recorded with its target
        //    so the page shows unknown and an operator can repoint the row.
        if ($response->redirect()) {
            $location = (string) $response->header('Location');

            $this->recordFailure($service, $status, sprintf(
                'The feed responded %d redirecting to %s, which was not followed.',
                $status,
                $location === '' ? 'an unnamed location' : $location,
            ));

            return;
        }

        if (! $response->successful()) {
            $this->recordFailure($service, $status, "The feed responded with HTTP {$status}.");

            return;
        }

        // 4. A body that is not a JSON document is a failure, not an empty
        //    reading: `json()` returns null on undecodable bytes.
        $payload = $response->json();
        if (! is_array($payload)) {
            $this->recordFailure($service, $status, 'The feed response was not a JSON document.');

            return;
        }

        $etag = (string) $response->header('ETag');

        $this->recordReading(
            $service,
            $adapter->read($payload, $url),
            $previous,
            $status,
            $etag === '' ? null : $etag,
        );
    }

    /**
     * Persist a parsed reading, and move `content_changed_at` only when it
     * actually changed anything.
     *
     * The hash comparison is the whole method. A first-ever reading counts as a
     * change (the page gains a provider block); an identical re-read does not,
     * and neither the sitemap's `lastmod` nor the page cache is touched then.
     */
    protected function recordReading(
        Service $service,
        FeedReading $reading,
        ?ServiceFeedSnapshot $previous,
        int $status,
        ?string $etag,
    ): void {
        $hash = $reading->normalizedHash();

        ServiceFeedSnapshot::query()->create([
            'service_id' => $service->getKey(),
            'fetched_at' => now(),
            'http_status' => $status,
            'indicator' => $reading->indicator,
            'components' => $reading->componentsToArray(),
            'incidents' => $reading->incidentsToArray(),
            'content_hash_normalized' => $hash,
            // Stored so the NEXT fetch of this service can send If-None-Match.
            'etag' => $etag,
            'error' => null,
        ]);

        if ($previous?->content_hash_normalized === $hash) {
            return;
        }

        $service->markContentChanged();
        $this->forgetPageCache($service);
    }

    /**
     * Record a fetch failure as a row.
     *
     * Deliberately does NOT touch `content_changed_at` or the page cache. A
     * failure snapshot has a null hash, so treating it as a content change would
     * bump the sitemap's `lastmod` every time a provider had a bad minute, which
     * is precisely how a `lastmod` becomes untrustworthy.
     */
    protected function recordFailure(Service $service, ?int $httpStatus, string $error): void
    {
        ServiceFeedSnapshot::query()->create([
            'service_id' => $service->getKey(),
            'fetched_at' => now(),
            'http_status' => $httpStatus,
            'indicator' => null,
            'components' => [],
            'incidents' => [],
            'content_hash_normalized' => null,
            'etag' => null,
            'error' => Str::limit($error, self::MAX_ERROR_LENGTH, ''),
        ]);
    }

    /**
     * Turn this service's feed off after a `429` or a `403`, and record why.
     *
     * Logged at warning rather than reported as an exception: it is an expected
     * outcome of talking to somebody else's rate limiter, and the operator's
     * signal is the row plus the panel, not the exception handler.
     */
    protected function disableFeed(Service $service, string $host, int $status): void
    {
        $reason = "Disabled automatically after HTTP {$status} from {$host}.";

        Log::warning('Service feed disabled automatically.', [
            'service_id' => $service->id,
            'host' => $host,
            'http_status' => $status,
        ]);

        $service->disableFeed($reason);
        $this->recordFailure($service, $status, $reason);
    }

    /**
     * Forget the cached public read model of this service's page.
     *
     * Both the bare key and one per supported locale, because the page is
     * localized and a per-locale key is the shape it will use. Plain-key forget,
     * no tags (see {@see self::PAGE_CACHE_KEY_PREFIX}).
     */
    protected function forgetPageCache(Service $service): void
    {
        Cache::forget(self::PAGE_CACHE_KEY_PREFIX.$service->slug);

        foreach ((array) config('magic-starter.supported_locales', []) as $locale) {
            Cache::forget(self::PAGE_CACHE_KEY_PREFIX.$service->slug.':'.$locale);
        }
    }
}
