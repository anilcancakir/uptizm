<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\CheckResult;
use App\Support\Monitoring\RelaySigner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the relay wire contract: HMAC-SHA256 over `"{timestamp}.{body}"`,
 * constant-time verification, a bounded replay window, and the
 * `probe_run_id` idempotency key on the parsed worker payload.
 */
class RelaySignerTest extends TestCase
{
    private const SECRET = 'relay-shared-secret';

    private const TTL_SECONDS = 300;

    /** A freshly signed payload verifies within the TTL window. */
    public function test_sign_then_verify_returns_true(): void
    {
        $signer = new RelaySigner(self::SECRET, self::TTL_SECONDS);
        $timestamp = time();
        $body = '{"monitor_id":"m1","region":"us-east"}';

        $signature = $signer->sign($timestamp, $body);

        $this->assertTrue($signer->verify($timestamp, $body, $signature));
    }

    /** A one-character change to the body fails verification. */
    public function test_verify_returns_false_when_body_is_tampered(): void
    {
        $signer = new RelaySigner(self::SECRET, self::TTL_SECONDS);
        $timestamp = time();
        $body = '{"monitor_id":"m1","region":"us-east"}';

        $signature = $signer->sign($timestamp, $body);
        $tampered = $body.' ';

        $this->assertFalse($signer->verify($timestamp, $tampered, $signature));
    }

    /** The digest is a 64-character lowercase hex string. */
    public function test_sign_produces_64_char_lowercase_hex(): void
    {
        $signer = new RelaySigner(self::SECRET, self::TTL_SECONDS);

        $signature = $signer->sign(time(), 'payload');

        $this->assertSame(64, strlen($signature));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    /** A timestamp older than the TTL window is rejected as a replay. */
    public function test_verify_returns_false_for_expired_timestamp(): void
    {
        $signer = new RelaySigner(self::SECRET, self::TTL_SECONDS);
        $body = 'payload';
        $expiredTimestamp = time() - self::TTL_SECONDS - 10;

        $signature = $signer->sign($expiredTimestamp, $body);

        $this->assertFalse($signer->verify($expiredTimestamp, $body, $signature));
    }

    /** fromWorkerPayload reads the probe_run_id idempotency key. */
    public function test_from_worker_payload_reads_probe_run_id(): void
    {
        $result = CheckResult::fromWorkerPayload([
            'probe_run_id' => 'x',
            'monitor_id' => 'm1',
            'region' => 'us-east',
            'checked_at' => '2026-07-11T00:00:00Z',
            'status' => 'up',
            'status_code' => 200,
            'response_ms' => 42,
        ]);

        $this->assertSame('x', $result->probeRunId);
    }
}
