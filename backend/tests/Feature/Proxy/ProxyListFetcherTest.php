<?php

namespace Tests\Feature\Proxy;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use App\Services\Proxy\ProxyListFetcher;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks {@see ProxyListFetcher}'s raw-body contract: it fetches, it does not
 * parse and it does not write anything, and its two "empty" outcomes must
 * never be confused with each other.
 *
 * A non-2xx (including Webshare's own 400 for a revoked download token) must
 * THROW rather than resolve to an empty string, because Step 5's refusal to
 * sweep a region's pool on a zero-row parse only works if a genuine auth
 * failure never masquerades as "the provider listed nothing". A 200 with a
 * literal empty body is the opposite case: that IS a legitimate (if useless)
 * answer, and judging it is Step 5's job, not this class's.
 */
class ProxyListFetcherTest extends TestCase
{
    private const string SOURCE_URL = 'https://proxy-provider.test/download/list.txt';

    protected function setUp(): void
    {
        parent::setUp();

        // Record sleeps for assertions instead of blocking the test thread.
        Sleep::fake();
    }

    /**
     * A 200 with a body returns it verbatim, and the outbound request carries
     * an explicit empty `proxy` option: this call downloads the proxy list
     * and must never itself be routed through a proxy. Without the explicit
     * override, Guzzle's curl handler would adopt an ambient `http_proxy` /
     * `https_proxy` / `all_proxy` from the process environment.
     */
    public function test_a_200_response_returns_the_body_with_an_explicit_empty_proxy_option(): void
    {
        $recordedOptions = [];

        Http::fake(function (Request $request, array $options) use (&$recordedOptions) {
            $recordedOptions[] = $options;

            return Http::response("1.1.1.1:8080:user:pass\n", 200);
        });

        $body = (new ProxyListFetcher)->fetch('url', self::SOURCE_URL);

        $this->assertSame("1.1.1.1:8080:user:pass\n", $body);
        $this->assertCount(1, $recordedOptions);
        $this->assertSame(
            '',
            $recordedOptions[0]['proxy'] ?? null,
            'The download must pass an explicit empty proxy option, or a stray http_proxy env var on the '
            .'server would route the proxy-list download (and only this one) through a proxy.',
        );
    }

    /**
     * A 429 carrying a Retry-After header buys exactly one bounded retry
     * (via the shared {@see RetriesRateLimitedDelivery}
     * concern) before the request succeeds.
     */
    public function test_a_429_with_retry_after_retries_exactly_once_then_succeeds(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('', 429, ['Retry-After' => '1'])
                ->push("2.2.2.2:9090:user:pass\n", 200),
        ]);

        $body = (new ProxyListFetcher)->fetch('url', self::SOURCE_URL);

        $this->assertSame("2.2.2.2:9090:user:pass\n", $body);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 1, 1);
    }

    /**
     * Webshare answers a revoked download token with HTTP 400 and a JSON
     * error body, not an empty 200. That must surface as a thrown exception:
     * silently returning an empty string here would read, to Step 5, exactly
     * like "the provider listed zero proxies", which would sweep the whole
     * region's pool on an auth failure instead of an operator's stale token.
     */
    public function test_a_400_with_webshares_revoked_token_body_throws(): void
    {
        Http::fake([
            '*' => Http::response(
                [
                    'download_token' => [
                        [
                            'message' => 'Invalid download token',
                        ],
                    ],
                ],
                400,
            ),
        ]);

        $this->expectException(RequestException::class);

        (new ProxyListFetcher)->fetch('url', self::SOURCE_URL);
    }

    /**
     * A 200 with a literal empty body is a legitimate, if useless, answer: it
     * must be returned as-is and must NOT throw. Judging whether an empty
     * result means "refuse to sweep" belongs to Step 5, not to this fetcher.
     */
    public function test_a_200_with_an_empty_body_returns_the_empty_string_without_throwing(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $body = (new ProxyListFetcher)->fetch('url', self::SOURCE_URL);

        $this->assertSame('', $body);
    }

    /**
     * An empty file path is rejected up front: `file_get_contents('')`
     * resolves to the current working directory, not to a named file, and
     * that is a config bug (an unset env key), not a "read the current
     * directory" instruction.
     */
    public function test_an_empty_file_path_throws_before_opening_anything(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');

        (new ProxyListFetcher)->fetch('file', '');
    }
}
