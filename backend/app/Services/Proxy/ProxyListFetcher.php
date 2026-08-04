<?php

namespace App\Services\Proxy;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use App\Support\Proxy\ProxyListParser;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches the raw proxy-list body for one region's configured source.
 *
 * This class does exactly one job: turn a `config('proxy.sources')` entry
 * (`kind` + `location`) into a raw body string. It never parses that body
 * ({@see ProxyListParser} does) and it never writes
 * anything (the refresher does): a fetch failure and a parse failure are
 * different events and must stay distinguishable to whoever composes them.
 *
 * `kind = 'url'` GETs the location with a 30s timeout and reuses
 * {@see RetriesRateLimitedDelivery} for the single bounded, Retry-After-aware
 * retry a provider's 429 buys. Every other non-2xx (including Webshare's own
 * 400 JSON error body for a revoked download token) THROWS: it must never
 * resolve to an empty string, because the refresher treats an empty PARSE
 * result as "the provider listed nothing right now" and refuses to sweep the
 * pool on it. A genuine auth failure masquerading as an empty list would
 * defeat that guard and could sweep an entire region's exits on a stale
 * token. A 200 with a literal empty body is the opposite case: that is a
 * legitimate (if useless) answer and is returned as-is, leaving the
 * empty-versus-refusal judgment to the refresher.
 *
 * `kind = 'file'` reads the location from disk. An empty path is rejected up
 * front rather than handed to `file_get_contents('')`, which resolves to the
 * current working directory rather than failing loudly: an unset env key
 * must read as a config bug, not as "list this directory".
 *
 * The outbound GET passes an explicit `'proxy' => ''` option. This call is
 * how the pool learns which exits exist, so it must never itself be routed
 * through one of those exits (or any other proxy): without the explicit
 * override, Guzzle's curl handler adopts an ambient `http_proxy` /
 * `https_proxy` / `all_proxy` from the process environment. Passing `false`
 * instead throws a CURL type error ("CURLOPT_PROXY must be a string"), so the
 * empty string is the only correct opt-out.
 */
class ProxyListFetcher
{
    use RetriesRateLimitedDelivery;

    /**
     * Request timeout in seconds, including header read and body stream.
     * Matches the upper bound Webshare documents for a proxy-list download.
     */
    protected const int TIMEOUT_SECONDS = 30;

    /**
     * Fetch the raw body for a configured proxy source.
     *
     * @param  string  $kind  Either `url` (fetched over HTTP) or `file` (read from disk).
     * @param  string  $location  The URL or file path to fetch, per `$kind`.
     *
     * @throws RuntimeException When `$kind` is not one of `url`/`file`, or `$kind` is
     *                          `file` and `$location` is empty.
     * @throws RequestException When `$kind` is `url` and the
     *                          final response is not a 2xx.
     */
    public function fetch(string $kind, string $location): string
    {
        return match ($kind) {
            'url' => $this->fetchUrl($location),
            'file' => $this->fetchFile($location),
            default => throw new RuntimeException("Proxy list fetch refused: unknown source kind \"{$kind}\"."),
        };
    }

    /**
     * GET the source URL and return its body, retrying a single 429 and
     * throwing on any other non-2xx.
     */
    protected function fetchUrl(string $url): string
    {
        $response = $this->sendWithRateLimitBackoff(
            fn (): Response => Http::withOptions([
                // See the class docblock: this download must never itself go
                // through a proxy, and the empty string is the only opt-out
                // Guzzle's curl handler honors (`false` throws instead).
                'proxy' => '',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url),
        );

        // Not swallowed into an empty string: a non-2xx here (including
        // Webshare's 400 on a revoked token) must be distinguishable from a
        // 200 that genuinely lists nothing, or the refresher's empty-result
        // sweep guard would misread an auth failure as an empty pool.
        $response->throw();

        return $response->body();
    }

    /**
     * Read the source file's raw contents from disk.
     */
    protected function fetchFile(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException(
                'Proxy list fetch refused: file path is empty. An unset source location must fail loudly '
                .'instead of file_get_contents(\'\') silently reading the current working directory.',
            );
        }

        if (! is_readable($path)) {
            throw new RuntimeException("Proxy list fetch failed: file is not readable at \"{$path}\".");
        }

        $contents = file_get_contents($path);

        // is_readable() above narrows the common failure (missing/unreadable
        // file), but a TOCTOU race (deleted between the two calls) still
        // returns false here; that must throw rather than coerce into "".
        if ($contents === false) {
            throw new RuntimeException("Proxy list fetch failed: unable to read file at \"{$path}\".");
        }

        return $contents;
    }
}
