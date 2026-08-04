<?php

namespace App\Services\Proxy;

use App\Models\Proxy;
use App\Models\ProxySource;
use App\Support\Monitoring\HostGuard;
use App\Support\Proxy\ProxyListParser;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes one region's proxy pool from its configured source: fetch, parse,
 * guard, upsert, sweep, in that exact order.
 *
 * Ported from the design reference's `Refresh()`
 * (/Users/anilcan/Code/package-booster/internal/proxysrc/service.go, read-only,
 * not a dependency) and its repository's `Upsert()`
 * (internal/db/proxy_repo.go), adapted to the region-keyed schema Step 2
 * introduced (at most one source per region, `region` denormalised onto
 * every proxy row) and to a threat the Go original never faced: a PARSED
 * endpoint can itself be an internal address, because the list is fetched
 * from a URL a third party controls, not supplied by an operator.
 *
 * THE ORDER IS THE DESIGN, and each step protects a different failure:
 *
 * 1. Stamp `importStartedAt` BEFORE any IO. A slow fetch must never let the
 *    sweep predicate (`last_refreshed_at < importStartedAt`) shadow rows that
 *    already existed when this refresh began; stamping after the fetch would
 *    narrow that window on every slow request instead of closing it.
 * 2. Fetch, then parse. {@see ProxyListFetcher} throws on a genuine
 *    transport/auth failure and never resolves that into an empty body;
 *    {@see ProxyListParser} drops malformed lines silently and never throws.
 *    Between the two, the only way to reach an empty parsed list here is a
 *    200 whose body carries no recognisable row.
 * 3. REFUSE ON EMPTY. A maintenance page, a truncated response, or a revoked
 *    token rendered as an empty file all parse to zero rows with no
 *    exception anywhere. Handing that to the upsert-and-sweep below would
 *    disable EVERY proxy of this source in one tick, and nothing in the logs
 *    would say why the region went dark. So an empty parse records
 *    `last_error` on the source and returns before touching a single proxy
 *    row.
 * 4. GUARD every parsed endpoint through {@see HostGuard::isBlockedHost()}.
 *    The monitor-target guard exists because a TENANT supplies the URL;
 *    here the roles are reversed, a proxy provider's fetched list is the
 *    untrusted input. A compromised or typo'd list yielding `127.0.0.1:6379`
 *    would make this server open a connection to its own Redis with the
 *    proxy credentials as the payload. A blocked endpoint is dropped, never
 *    upserted, and the drop count is logged so an operator can tell a
 *    malicious list from a merely small one.
 * 5. UPSERT on `(host, port)`. Read-then-write via `updateOrCreate`, NOT an
 *    `ON CONFLICT` statement: the unique index is a race backstop rather than the
 *    mechanism, and claiming otherwise would send the next reader looking for a
 *    guarantee that is not there. A returning proxy is RESURRECTED (`enabled = true`,
 *    `removed_at = null`, `failed_attempts = 0`): its prior penalty history
 *    does not survive re-appearing in the provider's list, because the
 *    provider re-listing it is itself evidence the exit is live again.
 * 6. SWEEP rows of THIS source only, whose `last_refreshed_at` predates the
 *    import, restricted to `enabled = true`. That last predicate is not
 *    redundant: without it, sweeping the same absence twice would re-count
 *    the same rows and overwrite `removed_at` with a later timestamp,
 *    destroying the record of when the exit FIRST disappeared and making "N
 *    swept" meaningless as a decay signal on a recurring refresh.
 */
class ProxySourceRefresher
{
    public function __construct(
        protected ProxyListFetcher $fetcher,
        protected HostGuard $hostGuard,
    ) {}

    /**
     * Refresh one region's pool from its configured source.
     *
     * @return array{upserted: int, dropped: int, swept: int} Counts for
     *                                                        observability;
     *                                                        an empty-parse
     *                                                        refusal returns
     *                                                        all zeros.
     *
     * @throws \RuntimeException When the fetch's `kind`/`location` contract is violated.
     * @throws RequestException When the source answers a non-2xx.
     */
    public function refresh(ProxySource $source): array
    {
        // 1. Stamp before any IO; see the class docblock for why the order is load-bearing.
        $importStartedAt = Carbon::now();

        // 2. Fetch + parse. The fetcher throws on a real failure; the parser
        //    never throws and drops what it cannot read.
        $body = $this->fetcher->fetch($source->kind, $source->location);
        $parsedProxies = ProxyListParser::parse($body);

        // 3. An empty parse is a failed refresh in disguise: refuse to sweep on it.
        if ($parsedProxies === []) {
            $source->update([
                'last_error' => "Refresh for region \"{$source->region}\" parsed 0 proxies; "
                    .'refusing to sweep the existing pool.',
            ]);

            return [
                'upserted' => 0,
                'dropped' => 0,
                'swept' => 0,
            ];
        }

        // 4. Drop any parsed endpoint that resolves into blocked address space. These
        //    addresses arrive from a fetched URL, not from an operator, so they are
        //    exactly the untrusted input HostGuard exists to reject.
        $droppedCount = 0;
        $allowedProxies = [];

        foreach ($parsedProxies as $parsedProxy) {
            if ($this->hostGuard->isBlockedHost($parsedProxy->host)) {
                $droppedCount++;

                continue;
            }

            $allowedProxies[] = $parsedProxy;
        }

        if ($droppedCount > 0) {
            Log::warning('Proxy source refresh dropped endpoints resolving into blocked address space.', [
                'region' => $source->region,
                'dropped' => $droppedCount,
            ]);
        }

        // 5. Upsert on (host, port): resurrect a returning proxy's enabled/removed/failure
        //    state rather than preserving whatever penalty history it carried before.
        //
        //    The conflict target is GLOBAL, not per-source, because one host:port is one
        //    physical exit and claiming it sits in two regions at once would be exactly the
        //    fabricated geography this engine exists to avoid. So when another source
        //    already owns a listed endpoint, this refresh TAKES it: a provider moving an
        //    exit between its country pools is a real event and the newest list is the
        //    better evidence. What must not happen is that silently. An endpoint listed by
        //    two regions at once produces this warning on every refresh, which is how an
        //    operator tells a genuine move (one warning) from two regions permanently
        //    fighting over the same exit (a warning every hour, and a pool that oscillates).
        foreach ($allowedProxies as $allowedProxy) {
            $incumbent = Proxy::query()
                ->where('host', $allowedProxy->host)
                ->where('port', $allowedProxy->port)
                ->where('proxy_source_id', '!=', $source->id)
                ->first();

            if ($incumbent !== null) {
                Log::warning('Proxy endpoint reassigned between regions; the newest list wins.', [
                    'host' => $allowedProxy->host,
                    'port' => $allowedProxy->port,
                    'from_region' => $incumbent->region,
                    'to_region' => $source->region,
                ]);
            }

            Proxy::query()->updateOrCreate(
                [
                    'host' => $allowedProxy->host,
                    'port' => $allowedProxy->port,
                ],
                [
                    'proxy_source_id' => $source->id,
                    'region' => $source->region,
                    'credentials' => [
                        'username' => $allowedProxy->username,
                        'password' => $allowedProxy->password,
                    ],
                    'enabled' => true,
                    'removed_at' => null,
                    'failed_attempts' => 0,
                    'last_refreshed_at' => $importStartedAt,
                ],
            );
        }

        // 6. Sweep rows of THIS source untouched by the upsert above. `enabled = true`
        //    is what makes a second sweep of the same absence a no-op; see the class
        //    docblock for why that predicate is load-bearing rather than redundant.
        $sweptCount = Proxy::query()
            ->where('proxy_source_id', $source->id)
            ->where('last_refreshed_at', '<', $importStartedAt)
            ->where('enabled', true)
            ->update([
                'enabled' => false,
                'removed_at' => $importStartedAt,
            ]);

        $source->update([
            'last_refreshed_at' => $importStartedAt,
            'last_error' => null,
        ]);

        return [
            'upserted' => count($allowedProxies),
            'dropped' => $droppedCount,
            'swept' => $sweptCount,
        ];
    }
}
