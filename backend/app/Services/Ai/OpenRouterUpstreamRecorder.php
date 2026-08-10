<?php

namespace App\Services\Ai;

use App\Services\Ai\Concerns\RoutesOpenRouterByLatency;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Records WHICH OpenRouter upstream served each AI call, and how long it took.
 *
 * Without this, {@see RoutesOpenRouterByLatency} is unfalsifiable: the routing
 * change reorders about twenty upstreams whose median latencies span 8x, and
 * nothing in the system says which one answered. OpenRouter will say, but only
 * when asked: `X-OpenRouter-Metadata` defaults to `disabled`, and with it
 * `enabled` the response body carries an `openrouter_metadata` object naming the
 * selected endpoint, the attempt number, the routing strategy and the serving
 * region.
 *
 * WHY THIS LIVES AT THE HTTP LAYER
 *
 * `laravel/ai` offers no seam above it, verified against the installed package
 * rather than assumed:
 * - {@see Laravel\Ai\Gateway\OpenRouter\Concerns\CreatesOpenRouterClient::client()}
 *   maps exactly two headers, `HTTP-Referer` and `X-OpenRouter-Title`, from
 *   provider config. There is no arbitrary-header hook and no per-request one.
 * - {@see Laravel\Ai\Gateway\OpenRouter\Concerns\ParsesTextResponses::parseTextResponse()}
 *   keeps `choices`, `usage`, `model` and annotations, and drops the rest, so
 *   `openrouter_metadata` never reaches an application object even if it arrives.
 * - The `GET /api/v1/generation?id=` endpoint carries `provider_name` and
 *   `latency` too, but it is a SECOND billed round trip per call for a fact the
 *   first response can already carry.
 *
 * Registered once as a global HTTP client middleware in
 * {@see App\Providers\AppServiceProvider::boot()}. Being global is the point:
 * six gateways prompt through OpenRouter, and this project has shipped the same
 * correct fix to one of them three times. A seam under all six cannot be half
 * applied.
 *
 * Being global also means every relay probe, proxy fetch and outbound webhook in
 * the application passes through it, which is why the host gate is the first
 * thing it does: a vendor header on a customer's own target would be a request
 * fingerprint nobody agreed to send, and reading a probe response body here
 * would parse up to a megabyte of target-authored JSON on every check.
 *
 * WHY ITS OWN LOG CHANNEL
 *
 * It writes to {@see self::LOG_CHANNEL} (`storage/logs/ai-routing-<date>.log`),
 * never the application default, and that is the difference between an
 * instrument and a decoration. MEASURED on the production box: `LOG_LEVEL` is
 * `warning` there, so `Log::info()` on any channel that reads that variable is
 * discarded and this class would have shipped silent, with a green test suite
 * saying otherwise. The two alternatives are both worse: filing a successful AI
 * call as a `warning` teaches an operator to ignore warnings, and lowering the
 * global level to `info` floods `laravel.log` with every other info line in the
 * application to buy one time series. `config/logging.php` carries the driver
 * and retention reasoning; the invariant here is that the channel's level is
 * never derived from `LOG_LEVEL`, and that an unset `AI_ROUTING_LOG_LEVEL`
 * leaves it recording.
 */
class OpenRouterUpstreamRecorder
{
    /**
     * The header that asks OpenRouter to describe its own routing decision.
     */
    public const string METADATA_HEADER = 'X-OpenRouter-Metadata';

    /**
     * The log channel this instrument writes to, configured in `config/logging.php`.
     */
    public const string LOG_CHANNEL = 'ai-routing';

    /**
     * Wrap the next handler in the Guzzle stack.
     *
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @return callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            // 1. Everything that is not OpenRouter leaves untouched and unread.
            if (! $this->isOpenRouter($request)) {
                return $handler($request, $options);
            }

            // 2. Ask for the routing metadata, then time the round trip. The
            //    duration is measured HERE rather than inside a gateway because
            //    the queueing an overloaded upstream adds is precisely what the
            //    latency sort is meant to avoid, and it happens on this side of
            //    the call.
            $startedAt = microtime(true);

            return $handler($request->withHeader(self::METADATA_HEADER, 'enabled'), $options)
                ->then(function (ResponseInterface $response) use ($request, $startedAt): ResponseInterface {
                    // 3. A rejected promise never arrives here, deliberately: a
                    //    transport failure is the calling gateway's to report,
                    //    and it already does, with the budget it had spent.
                    $this->record($request, $response, microtime(true) - $startedAt);

                    return $response;
                });
        };
    }

    /**
     * Whether the request is bound for the configured OpenRouter API.
     *
     * Reads the same config key the package's own client reads
     * ({@see Laravel\Ai\Providers\Provider::additionalConfiguration()}), with the
     * package's default, so a deployment that points OpenRouter at a gateway of
     * its own keeps the instrument instead of silently losing it.
     */
    private function isOpenRouter(RequestInterface $request): bool
    {
        $url = config('ai.providers.openrouter.url', 'https://openrouter.ai/api/v1');

        if (! is_string($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $request->getUri()->getHost() === $host;
    }

    /**
     * Log the routing decision OpenRouter reported, and what it cost.
     *
     * One line per call, always, including when `provider` comes back null. The
     * null is not noise: it is the only signal that the metadata header stopped
     * being honoured, and a suppressed line would leave the latency routing
     * exactly as unmeasurable as it was before this class existed.
     */
    private function record(RequestInterface $request, ResponseInterface $response, float $elapsedSeconds): void
    {
        $body = $this->decodeBody($response);

        if ($body === null) {
            return;
        }

        $metadata = is_array($body['openrouter_metadata'] ?? null) ? $body['openrouter_metadata'] : [];

        Log::channel(self::LOG_CHANNEL)->info('OpenRouter answered an AI request.', [
            'provider' => $this->selectedProvider($metadata),
            'model' => is_string($body['model'] ?? null) ? $body['model'] : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round($elapsedSeconds * 1000),
            'attempt' => $metadata['attempt'] ?? null,
            'strategy' => $metadata['strategy'] ?? null,
            'region' => $metadata['region'] ?? null,
            'path' => $request->getUri()->getPath(),
        ]);
    }

    /**
     * The upstream that served this request, or null when nothing said.
     *
     * OpenRouter reports every endpoint it considered and flags the one it used,
     * so the flag is what is read: taking the first entry would name a candidate
     * that never ran.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function selectedProvider(array $metadata): ?string
    {
        $available = $metadata['endpoints']['available'] ?? [];

        if (! is_array($available)) {
            return null;
        }

        foreach ($available as $endpoint) {
            if (! is_array($endpoint) || ($endpoint['selected'] ?? false) !== true) {
                continue;
            }

            return is_string($endpoint['provider'] ?? null) ? $endpoint['provider'] : null;
        }

        return null;
    }

    /**
     * Decode the response body without spending it.
     *
     * The caller parses this same body afterwards, so the stream is rewound: a
     * PSR-7 body left at EOF would hand the gateway an empty answer, which reads
     * downstream as a non-conforming model rather than as an instrument fault.
     *
     * A body that cannot be rewound is a STREAM (a streamed completion arrives
     * over a socket), and reading one here would both block until the last token
     * and consume the tokens the caller came for. Those calls go unrecorded,
     * which is the correct trade: no gateway in this application streams.
     *
     * @return array<string, mixed>|null
     */
    private function decodeBody(ResponseInterface $response): ?array
    {
        $stream = $response->getBody();

        if (! $stream->isSeekable()) {
            return null;
        }

        $contents = (string) $stream;
        $stream->rewind();

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }
}
