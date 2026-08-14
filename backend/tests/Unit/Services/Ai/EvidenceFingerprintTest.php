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

    public function test_latency_does_not_move_the_fingerprint_at_all(): void
    {
        // Three corrections a live incident forced, ending here. Exact latency
        // never matched, because no two checks answer in the same millisecond.
        // Banding it onto a ladder was the second attempt and this monitor's
        // latency wanders from 436ms to 3389ms on its own, crossing rungs
        // unaided. Latency is a READING, and a reading that matters has a
        // metric band watching it, which is in the fingerprint already.
        $fast = $this->payload([$this->check(ms: 436)]);
        $slow = $this->payload([$this->check(ms: 3389)]);

        $this->assertSame($fast->evidenceFingerprint(), $slow->evidenceFingerprint());
    }

    public function test_a_status_code_change_still_moves_the_fingerprint(): void
    {
        // What dropping latency must not take with it: the verdict itself.
        $gateway = $this->payload([$this->check(code: 503)]);
        $server = $this->payload([$this->check(code: 500)]);

        $this->assertNotSame($gateway->evidenceFingerprint(), $server->evidenceFingerprint());
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

    public function test_a_flapping_metric_does_not_move_the_fingerprint_every_tick(): void
    {
        // MEASURED on production the hour this shipped, on the incident the
        // store was built for. Its trigger is a numeric latency metric that
        // crosses its own bound almost every reading. The series, newest-first:
        // 31.52 critical, 6.94 ok, 9.55 ok, 6.10 ok, 76.90 critical, 4.03 ok,
        // 27.04 critical, 3.88 ok, 25.23 critical. The band list is
        // deduped in FIRST-SEEN order, so as the twelve-reading window slides it
        // alternates `[critical, ok]` and `[ok, critical]`: the same SET, a
        // different order, a different hash.
        //
        // The cost is the whole point of the store. Two responders opening that
        // incident a minute apart each bought an answer, which is the state this
        // table was added to end.
        //
        // Sorting the BAND list is safe in a way that sorting the check list is
        // not, and the difference is what the reader sees. The crossing stays
        // fully visible in the prompt: `triggeringMetric['readings']` reaches the
        // model with its values and timestamps in time order, untouched. Only the
        // hash normalises, and for a metric that alternates every minute the
        // order of two bands is noise rather than a crossing.
        // Both windows are lifted from that series verbatim, newest-first, and
        // the values keep the band they actually had. An earlier draft put `9.55`
        // in the critical slot, which was a fixture that contradicted its own
        // narrative twice: `9.55` measured `ok`, and the same value cannot sit in
        // two bands under one threshold.
        $window = $this->payload([$this->check()], $this->metric([
            ['value' => '31.52', 'band' => 'critical', 'recorded_at' => '2026-08-14T13:05:38+00:00'],
            ['value' => '6.94', 'band' => 'ok', 'recorded_at' => '2026-08-14T13:04:34+00:00'],
        ]));
        // The same window four minutes earlier, before the 31.52 arrived: the
        // newest reading is an `ok` and the one under it is a `critical`, so the
        // first-seen order is reversed while the set is identical.
        $windowSlid = $this->payload([$this->check()], $this->metric([
            ['value' => '6.10', 'band' => 'ok', 'recorded_at' => '2026-08-14T13:01:46+00:00'],
            ['value' => '76.90', 'band' => 'critical', 'recorded_at' => '2026-08-14T13:00:35+00:00'],
        ]));

        $this->assertSame(
            $window->evidenceFingerprint(),
            $windowSlid->evidenceFingerprint(),
            'a metric that alternates every tick must not buy an answer every tick',
        );
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

    public function test_a_number_drifting_inside_a_body_does_not_change_the_fingerprint(): void
    {
        // The third and last source of a fingerprint that never held, read off a
        // live diff block: a monitored service reports live numbers, so
        // `used_percent`, `latency_ms`, `age_seconds` and even the human message
        // that embeds a percentage all move on every check.
        $before = $this->payload([$this->check()], bodies: [
            ['baseline' => false, 'fields' => [
                'checks.storage.details.used_percent' => '82.87 -> 83.14',
                'checks.storage.message' => 'The disk is 82.9% full. -> The disk is 83.1% full.',
                'checks.redis.details.latency_ms' => '59.16 -> 0.18',
            ]],
        ]);
        $later = $this->payload([$this->check()], bodies: [
            ['baseline' => false, 'fields' => [
                'checks.storage.details.used_percent' => '82.87 -> 83.45',
                'checks.storage.message' => 'The disk is 82.9% full. -> The disk is 83.5% full.',
                'checks.redis.details.latency_ms' => '59.16 -> 0.81',
            ]],
        ]);

        $this->assertSame($before->evidenceFingerprint(), $later->evidenceFingerprint());
    }

    public function test_a_body_field_changing_state_still_changes_the_fingerprint(): void
    {
        // What the digit masking must not lose: words are states, and a state
        // flipping is the whole reason to re-ask.
        $healthy = $this->payload([$this->check()], bodies: [
            ['baseline' => false, 'fields' => ['checks.storage.status' => 'ok']],
        ]);
        $degraded = $this->payload([$this->check()], bodies: [
            ['baseline' => false, 'fields' => ['checks.storage.status' => 'ok -> degraded']],
        ]);

        $this->assertNotSame($healthy->evidenceFingerprint(), $degraded->evidenceFingerprint());
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
