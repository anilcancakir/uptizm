<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\IncidentAnalysisPayload;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see IncidentAnalysisPayload::evidenceFingerprint()}: what counts as
 * a change in the evidence, and what is only the clock moving.
 *
 * The fingerprint is what decides whether the endpoint spends an AI budget unit
 * or serves what is on file, so both directions matter equally. A fingerprint
 * that never changes re-serves a stale answer; one that always changes is the
 * unstored behaviour this replaced.
 */
class EvidenceFingerprintTest extends TestCase
{
    public function test_repeating_the_same_failure_does_not_change_the_fingerprint(): void
    {
        $once = $this->payload([$this->check()]);
        $thrice = $this->payload([$this->check(), $this->check(), $this->check()]);

        $this->assertSame($once->evidenceFingerprint(), $thrice->evidenceFingerprint());
    }

    public function test_the_check_clock_does_not_change_the_fingerprint(): void
    {
        $early = $this->payload([$this->check(at: '2026-08-13T09:00:00+00:00')]);
        $late = $this->payload([$this->check(at: '2026-08-13T11:45:31+00:00')]);

        $this->assertSame($early->evidenceFingerprint(), $late->evidenceFingerprint());
    }

    public function test_a_different_status_code_changes_the_fingerprint(): void
    {
        $gateway = $this->payload([$this->check(code: 503)]);
        $server = $this->payload([$this->check(code: 500)]);

        $this->assertNotSame($gateway->evidenceFingerprint(), $server->evidenceFingerprint());
    }

    public function test_latency_jitter_does_not_change_the_fingerprint(): void
    {
        // The correction the running system forced. Exact latency in the hash
        // meant no two ticks ever matched, because no two checks answer in the
        // same millisecond: one open incident produced three fingerprints and
        // three paid answers inside ten minutes.
        $first = $this->payload([$this->check(ms: 436)]);
        $second = $this->payload([$this->check(ms: 445)]);

        $this->assertSame($first->evidenceFingerprint(), $second->evidenceFingerprint());
    }

    public function test_a_real_latency_move_still_changes_the_fingerprint(): void
    {
        // The other half, and the one the banding could have quietly lost:
        // half a second and three seconds are not the same fact.
        $slow = $this->payload([$this->check(ms: 436)]);
        $slower = $this->payload([$this->check(ms: 3389)]);

        $this->assertNotSame($slow->evidenceFingerprint(), $slower->evidenceFingerprint());
    }

    public function test_a_recovery_reads_differently_from_an_onset(): void
    {
        // The check list is newest-first, so an `up` on top of a `down` is a
        // recovery and the reverse is the failure starting. The distinct set is
        // identical either way; only the order separates them, which is why the
        // fingerprint keeps first-appearance order instead of sorting.
        $recovery = $this->payload([
            $this->check(status: 'up', code: 200, ms: 180),
            $this->check(),
        ]);
        $onset = $this->payload([
            $this->check(),
            $this->check(status: 'up', code: 200, ms: 180),
        ]);

        $this->assertNotSame($recovery->evidenceFingerprint(), $onset->evidenceFingerprint());
    }

    public function test_a_metric_reading_the_same_band_does_not_change_the_fingerprint(): void
    {
        // A numeric metric never reports the same number twice. Hashing values
        // grew and shifted the hash on every tick, exactly as the timestamps
        // did, so what is hashed is the band.
        $one = $this->payload([$this->check()], $this->metric([
            ['value' => '83.7', 'band' => 'warn', 'recorded_at' => '2026-08-13T09:00:00+00:00'],
        ]));
        $later = $this->payload([$this->check()], $this->metric([
            ['value' => '83.8', 'band' => 'warn', 'recorded_at' => '2026-08-13T09:01:00+00:00'],
            ['value' => '83.7', 'band' => 'warn', 'recorded_at' => '2026-08-13T09:00:00+00:00'],
        ]));

        $this->assertSame($one->evidenceFingerprint(), $later->evidenceFingerprint());
    }

    public function test_a_metric_crossing_a_band_changes_the_fingerprint(): void
    {
        // What the analysis actually narrates, and the thing banding must not
        // lose: the crossing.
        $warn = $this->payload([$this->check()], $this->metric([
            ['value' => '83.7', 'band' => 'warn', 'recorded_at' => '2026-08-13T09:00:00+00:00'],
        ]));
        $critical = $this->payload([$this->check()], $this->metric([
            ['value' => '95.2', 'band' => 'critical', 'recorded_at' => '2026-08-13T09:00:00+00:00'],
        ]));

        $this->assertNotSame($warn->evidenceFingerprint(), $critical->evidenceFingerprint());
    }

    public function test_resolving_the_incident_changes_the_fingerprint(): void
    {
        $open = $this->payload([$this->check()]);
        $resolved = $this->payload([$this->check()], resolvedAt: '2026-08-13T10:00:00+00:00');

        $this->assertNotSame($open->evidenceFingerprint(), $resolved->evidenceFingerprint());
    }

    public function test_a_new_response_body_changes_the_fingerprint(): void
    {
        $before = $this->payload([$this->check()], bodies: [
            ['at' => '2026-08-13T09:00:00+00:00', 'repeat' => 4, 'baseline' => true, 'fields' => ['status' => 'ok']],
        ]);
        // Same block, more repeats, later stamp: the body itself did not change.
        $repeated = $this->payload([$this->check()], bodies: [
            ['at' => '2026-08-13T10:30:00+00:00', 'repeat' => 19, 'baseline' => true, 'fields' => ['status' => 'ok']],
        ]);
        $changed = $this->payload([$this->check()], bodies: [
            ['at' => '2026-08-13T09:00:00+00:00', 'repeat' => 4, 'baseline' => true, 'fields' => ['status' => 'degraded']],
        ]);

        $this->assertSame($before->evidenceFingerprint(), $repeated->evidenceFingerprint());
        $this->assertNotSame($before->evidenceFingerprint(), $changed->evidenceFingerprint());
    }

    /**
     * @return array<string, mixed>
     */
    protected function check(
        string $status = 'down',
        int $code = 503,
        int $ms = 4100,
        string $at = '2026-08-13T09:00:00+00:00',
    ): array {
        return [
            'n' => 1,
            'checked_at' => $at,
            'region' => 'eu-central',
            'status' => $status,
            'status_code' => $code,
            'response_ms' => $ms,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $readings
     * @return array<string, mixed>
     */
    protected function metric(array $readings): array
    {
        return [
            'label' => 'Available seats',
            'path' => 'data.seats',
            'direction' => 'below',
            'warn' => '5',
            'critical' => '1',
            'ok_values' => [],
            'warn_values' => [],
            'critical_values' => [],
            'readings' => $readings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>|null  $triggeringMetric
     * @param  list<array<string, mixed>>  $bodies
     */
    protected function payload(
        array $checks,
        ?array $triggeringMetric = null,
        ?string $resolvedAt = null,
        array $bodies = [],
    ): IncidentAnalysisPayload {
        return new IncidentAnalysisPayload(
            incidentId: 'inc-1',
            severity: 'critical',
            impact: 'critical',
            lifecycle: 'detected',
            signalSource: 'user_threshold',
            aiOwned: false,
            startedAt: '2026-08-13T08:55:00+00:00',
            resolvedAt: $resolvedAt,
            timeline: [],
            checks: $checks,
            bodies: $bodies,
            knownCheckIds: [],
            knownMonitorIds: ['mon-1'],
            monitors: [['name' => 'API Uptime', 'monitor_id' => 'mon-1']],
            triggeringMetric: $triggeringMetric,
        );
    }
}
