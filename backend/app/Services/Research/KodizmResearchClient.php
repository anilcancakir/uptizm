<?php

namespace App\Services\Research;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The transport behind the two research tools: one JSON-RPC `tools/call` POST
 * to the kodizm MCP server.
 *
 * The protocol is deliberately this small because it was MEASURED rather than
 * assumed: a bare `tools/call` with a bearer answers HTTP 200 with no
 * `initialize` round trip and no `Mcp-Session-Id`, and the tool payload arrives
 * as a JSON string under `result.content[0].text`. Do not grow a handshake here
 * without re-measuring it first.
 *
 * DORMANT AND SILENT WITHOUT A TOKEN
 *
 * With no `research.kodizm.token` configured this client sends nothing and
 * answers null, which the two research tools turn into a refusal the model can
 * act on. A deployment that never provisions the credential therefore loses
 * research and nothing else. The dependency runs one way on purpose: the tools
 * know this transport, and this transport knows nothing about them.
 *
 * IT NEVER THROWS, BECAUSE OF WHERE IT RUNS
 *
 * Every caller is a `laravel/ai` tool, and the package invokes a tool inside
 * `try { ... } finally` with no catch, so an exception from here would travel
 * past the analyze endpoint's degrade (which catches only `RuntimeException`,
 * `ConnectionException` and `RequestException`) and land on the operator as a
 * 500 or a 422. So every outcome other than a usable payload is null plus a log
 * line: an unreachable server, a non-2xx answer, a JSON-RPC error member, a
 * tool-reported error, and an answer carrying no text content.
 *
 * The log line names the tool and OUR OWN reason string. It never carries the
 * arguments or the response body: a search query is derived from a monitor
 * target and a fetched page is attacker-authored text.
 */
class KodizmResearchClient
{
    use RetriesRateLimitedDelivery;

    /**
     * Determine whether the research surface is configured at all.
     *
     * Callers check this to phrase their refusal honestly: "unavailable in this
     * deployment" is a different message to the model than "the service did not
     * answer", and only the second one is worth it trying again.
     */
    public function configured(): bool
    {
        return filled($this->token());
    }

    /**
     * Call one remote tool and return its decoded payload, or null.
     *
     * @param  string  $tool  The remote tool name, e.g. `web-search`.
     * @param  array<string, mixed>  $arguments  The tool arguments. Never a
     *                                           credential, a request header,
     *                                           or any part of `auth_config`.
     * @return array<array-key, mixed>|null The decoded payload, or null when
     *                                      there is nothing usable to hand back.
     */
    public function call(string $tool, array $arguments): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        // 1. One POST, retrying only a 429 and only once, through the shared
        //    bounded backoff that cannot throw and cannot pin the request
        //    thread beyond its cap.
        try {
            $response = $this->sendWithRateLimitBackoff(fn (): Response => Http::withToken($this->token())
                ->withHeaders([
                    // The measured request advertised both, because an MCP
                    // server may answer a call as a single JSON body or as an
                    // SSE stream. This endpoint answered JSON.
                    'Accept' => 'application/json, text/event-stream',
                ])
                ->timeout((int) config('research.kodizm.timeout_seconds'))
                ->post((string) config('research.kodizm.url'), [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $tool,
                        'arguments' => $arguments,
                    ],
                ]));
        } catch (ConnectionException) {
            return $this->recordFailure($tool, 'the server was unreachable');
        }

        // 2. A non-2xx carries no result member worth reading, whatever it says.
        if ($response->failed()) {
            return $this->recordFailure($tool, 'the server answered '.$response->status());
        }

        return $this->decode($tool, $response);
    }

    /**
     * Pull the tool payload out of a 200 answer, or null when there is none.
     *
     * @return array<array-key, mixed>|null
     */
    protected function decode(string $tool, Response $response): ?array
    {
        // 1. A JSON-RPC error member replaces the result entirely, so there is
        //    nothing to decode. An unknown tool name arrives here.
        if ($response->json('error') !== null) {
            return $this->recordFailure($tool, 'the server returned a JSON-RPC error');
        }

        // 2. An MCP tool reports its own failure in-band rather than as a
        //    JSON-RPC error, and that content is a message about the failure,
        //    not an answer to the call.
        if ($response->json('result.isError') === true) {
            return $this->recordFailure($tool, 'the tool reported an error');
        }

        $text = $response->json('result.content.0.text');

        if (! is_string($text) || trim($text) === '') {
            return $this->recordFailure($tool, 'the answer carried no text content');
        }

        $decoded = json_decode($text, true);

        // 3. The utility tools answer with a JSON document, but a tool that
        //    answers with prose (a fetched page rendered as markdown) has still
        //    answered, so it is wrapped rather than discarded.
        return is_array($decoded) ? $decoded : ['text' => $text];
    }

    /**
     * Write down why a call produced nothing, then answer null.
     *
     * @return null Always, so a caller can `return $this->recordFailure(...)`.
     */
    protected function recordFailure(string $tool, string $reason): mixed
    {
        // The reason is one of this class's own strings and the tool name is
        // ours. Neither the arguments nor the response body is logged: the first
        // is derived from a monitor target and the second is authored by a third
        // party.
        Log::warning('The research service returned no usable result.', [
            'tool' => $tool,
            'reason' => $reason,
        ]);

        return null;
    }

    /**
     * The configured bearer token, if any.
     */
    protected function token(): ?string
    {
        $token = config('research.kodizm.token');

        return is_string($token) ? $token : null;
    }
}
