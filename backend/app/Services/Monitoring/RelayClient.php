<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorRegion;
use App\Models\Monitor;
use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\RelaySigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synchronous relay to the Cloudflare regional-checker worker.
 *
 * A single worker URL (`relay.url`) fronts region-pinned Durable Objects;
 * the worker routes a probe into the DO that matches the payload's
 * `region` field. Each request is signed with HMAC-SHA256 over
 * `"{timestamp}.{body}"` via {@see RelaySigner} so the worker can
 * authenticate the API before running anything.
 *
 * Unlike an async callback model, the worker executes the probe inline and
 * returns the {@see CheckResult} wire payload in the `/run` response body,
 * so {@see dispatch()} parses the outcome synchronously. A non-2xx response
 * (e.g. a 401 on a secret mismatch) is surfaced as a thrown exception, never
 * swallowed as a fake "up": the caller must see the failure and retry.
 *
 * This is the DEFAULT {@see ProbeTransport}, and the only one a customer
 * monitor ever travels through. It already satisfied the contract verbatim, so
 * the `implements` adds no behaviour here.
 */
class RelayClient implements ProbeTransport
{
    /**
     * Dispatch a probe to the given region and return the parsed result.
     *
     * The freshly generated `probe_run_id` is echoed back by the worker and
     * carried on the returned {@see CheckResult} as the idempotency key.
     *
     * @param  Monitor  $monitor  The monitor whose probe spec is executed.
     * @param  string  $region  Target region value (see {@see MonitorRegion}).
     * @return CheckResult Parsed outcome from the worker `/run` response.
     *
     * @throws RuntimeException When the region is not one the relay supports.
     * @throws ConnectionException When the worker is unreachable after retries.
     * @throws RequestException When the worker
     *                          responds with a non-2xx status (e.g. 401 on a secret mismatch).
     */
    public function dispatch(Monitor $monitor, string $region): CheckResult
    {
        // 1. Reject unknown regions up front instead of leaning on the
        //    worker's 400, so a misconfigured caller fails fast and locally.
        if (MonitorRegion::tryFrom($region) === null) {
            throw new RuntimeException("Relay region '{$region}' is not supported.");
        }

        // 2. Encode the probe spec exactly once: the same byte string is both
        //    signed and sent, so the worker recomputes an identical HMAC.
        $body = json_encode($this->buildSpec($monitor, $region), JSON_THROW_ON_ERROR);

        // 3. Sign "{timestamp}.{body}" with the shared secret; the plain
        //    integer timestamp travels alongside so the worker can verify.
        $timestamp = time();
        $signature = (new RelaySigner(
            config('relay.secret'),
            config('relay.signature_ttl_seconds'),
        ))->sign($timestamp, $body);

        // 4. POST the signed body to `/run`, retrying only transient
        //    connection failures, and throw on any non-2xx response.
        $response = Http::withHeaders([
            config('relay.signature_header') => $signature,
            config('relay.timestamp_header') => (string) $timestamp,
            // Every HTTP probe now returns the full body, so this hop carries
            // roughly 8x what it used to. The worker honours the header and
            // Guzzle turns it into CURLOPT_ENCODING, so curl decompresses
            // transparently and the parsed payload is unaffected.
            'Accept-Encoding' => 'gzip',
        ])
            ->retry(2, 200, fn ($e): bool => $e instanceof ConnectionException)
            ->timeout(config('relay.timeout_seconds'))
            ->withBody($body, 'application/json')
            ->post(config('relay.url').'/run')
            ->throw();

        // 5. Parse the inline result payload from the worker response.
        return CheckResult::fromWorkerPayload($response->json());
    }

    /**
     * Build the probe spec the worker consumes.
     *
     * A fresh `probe_run_id` is minted per dispatch and echoed by the worker
     * so the processing job can de-duplicate a payload delivered more than once.
     *
     * @param  Monitor  $monitor  The monitor supplying the request definition.
     * @param  string  $region  The already-validated target region value.
     * @return array<string, mixed>
     */
    protected function buildSpec(Monitor $monitor, string $region): array
    {
        return [
            'monitor_id' => $monitor->id,
            'region' => $region,
            'type' => $monitor->type->value,
            'method' => $monitor->method->value,
            'url' => $monitor->url,
            'request_headers' => $monitor->request_headers,
            'request_body' => $monitor->request_body,
            'timeout_seconds' => $monitor->timeout_sec,
            'expected_status_code' => $monitor->expected_status_code,
            'auth_config' => $monitor->auth_config,
            'assertion_rules' => $monitor->assertion_rules,
            'probe_run_id' => (string) Str::uuid(),
            // The body ceiling and the content-type allowlist travel on the
            // signed spec because the worker cannot read Laravel config. A
            // worker-owned copy of either would mean raising
            // CONTENT_ARCHIVE_MAX_BYTES, or widening the allowlist, silently
            // does nothing: exactly the two-language drift this routing avoids.
            // The worker reimplements the matcher from
            // {@see \App\Support\Monitoring\ContentTypeAllowList}, so the list
            // is sent verbatim, order and shape intact.
            'max_bytes' => (int) config('content-archive.max_bytes'),
            'allowed_content_types' => config('content-archive.allowed_content_types'),
            // Our identity, on the spec for the same reason the two above are:
            // `resources/legal/bot.en.md` renders this exact config value and
            // tells every operator that blocking it stops us, so a copy held in
            // the worker would be the one place that page could quietly become
            // untrue. The monitor's own `request_headers` still override it at
            // the edge; this is the default, not a signature.
            'user_agent' => (string) config('uptizm.bot_user_agent'),
        ];
    }
}
