<?php

namespace App\Support\Monitoring;

use App\Enums\MonitorStatus;
use DateTimeImmutable;

/**
 * Immutable value object describing the outcome of a single probe
 * executed by a Cloudflare relay worker.
 *
 * This is the wire contract between the worker's `/run` response and the
 * job that persists check results. Field names must stay aligned with
 * `workers/regional-checker/src/index.ts`. `probeRunId` is the idempotency
 * key: it lets the processing job de-duplicate a worker payload that is
 * delivered more than once.
 */
readonly class CheckResult
{
    /**
     * @param  array<string, string>  $responseHeaders
     */
    public function __construct(
        public string $monitorId,
        public string $region,
        public DateTimeImmutable $checkedAt,
        public MonitorStatus $status,
        public ?int $statusCode,
        public ?int $responseMs,
        public ?string $errorMessage,
        public int $timingDnsMs,
        public int $timingConnectMs,
        public int $timingTlsMs,
        public int $timingTtfbMs,
        public int $timingDownloadMs,
        public array $responseHeaders,
        public ?string $responseBodyPreview,
        public string $probeRunId,
    ) {}

    /**
     * Build a CheckResult from the JSON payload returned by the worker.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromWorkerPayload(array $payload): self
    {
        return new self(
            monitorId: (string) $payload['monitor_id'],
            region: (string) $payload['region'],
            checkedAt: new DateTimeImmutable((string) $payload['checked_at']),
            status: MonitorStatus::from((string) $payload['status']),
            statusCode: isset($payload['status_code']) ? (int) $payload['status_code'] : null,
            responseMs: isset($payload['response_ms']) ? (int) $payload['response_ms'] : null,
            errorMessage: $payload['error_message'] ?? null,
            timingDnsMs: (int) ($payload['timing']['dns_ms'] ?? 0),
            timingConnectMs: (int) ($payload['timing']['connect_ms'] ?? 0),
            timingTlsMs: (int) ($payload['timing']['tls_ms'] ?? 0),
            timingTtfbMs: (int) ($payload['timing']['ttfb_ms'] ?? 0),
            timingDownloadMs: (int) ($payload['timing']['download_ms'] ?? 0),
            responseHeaders: (array) ($payload['response_headers'] ?? []),
            responseBodyPreview: $payload['response_body_preview'] ?? null,
            probeRunId: (string) $payload['probe_run_id'],
        );
    }

    /**
     * Serialize back to the worker wire shape.
     *
     * The output round-trips through {@see fromWorkerPayload()}; the
     * processing job uses it to hand the parsed result forward without
     * re-deriving the individual fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monitor_id' => $this->monitorId,
            'region' => $this->region,
            'checked_at' => $this->checkedAt->format(DateTimeImmutable::ATOM),
            'status' => $this->status->value,
            'status_code' => $this->statusCode,
            'response_ms' => $this->responseMs,
            'error_message' => $this->errorMessage,
            'timing' => [
                'dns_ms' => $this->timingDnsMs,
                'connect_ms' => $this->timingConnectMs,
                'tls_ms' => $this->timingTlsMs,
                'ttfb_ms' => $this->timingTtfbMs,
                'download_ms' => $this->timingDownloadMs,
            ],
            'response_headers' => $this->responseHeaders,
            'response_body_preview' => $this->responseBodyPreview,
            'probe_run_id' => $this->probeRunId,
        ];
    }
}
