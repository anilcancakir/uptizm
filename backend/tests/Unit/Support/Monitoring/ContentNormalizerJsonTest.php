<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\ContentNormalizer;
use Tests\TestCase;

/**
 * The JSON half of the normalizer, driven by the real document that exposed the
 * gap: the FlutterSDK status endpoint, the only monitor producing archive
 * traffic (627 versions, 129.5 a day, one per check).
 */
class ContentNormalizerJsonTest extends TestCase
{
    /**
     * Two samples of the same healthy endpoint, taken three minutes apart:
     * every measurement differs, nothing about the state does.
     */
    protected function sample(string $checkedAt, int $ts, float $duration, float $latency, int $age, float $mem): string
    {
        return json_encode([
            'status' => 'ok',
            'checked_at' => $checkedAt,
            'timestamp' => $ts,
            'duration_ms' => $duration,
            'checks' => [
                'database' => [
                    'status' => 'ok',
                    'message' => null,
                    'duration_ms' => $duration,
                    'details' => ['connection' => 'mysql', 'latency_ms' => $latency, 'pending_migrations' => 0],
                ],
                'redis' => [
                    'status' => 'ok',
                    'details' => ['used_memory_mb' => $mem, 'connected_clients' => 466, 'evicted_keys' => 0],
                ],
                'scheduler' => [
                    'status' => 'ok',
                    'details' => ['last_run_at' => $checkedAt, 'age_seconds' => $age],
                ],
                'application' => [
                    'status' => 'ok',
                    'details' => ['maintenance' => false, 'commit' => '03b79b026a8f', 'branch' => 'main'],
                ],
            ],
        ]);
    }

    public function test_two_samples_of_an_unchanged_endpoint_hash_the_same(): void
    {
        $a = ContentNormalizer::normalize($this->sample('2026-08-04T21:48:28+00:00', 1785880108, 12.73, 0.73, 22, 139.99));
        $b = ContentNormalizer::normalize($this->sample('2026-08-04T21:51:31+00:00', 1785880291, 15.02, 0.91, 5, 141.20));

        $this->assertNotSame($a->rawHash, $b->rawHash, 'the raw bytes really do differ, or this proves nothing');
        $this->assertSame($a->normalizedHash, $b->normalizedHash);
        $this->assertFalse($a->normalizationFailed);
    }

    public function test_a_status_flip_still_changes_the_hash(): void
    {
        $ok = $this->sample('2026-08-04T21:48:28+00:00', 1785880108, 12.73, 0.73, 22, 139.99);
        $degraded = str_replace('"status":"ok","message":null', '"status":"degraded","message":null', $ok);

        $this->assertNotSame($ok, $degraded, 'the fixture edit landed');
        $this->assertNotSame(
            ContentNormalizer::normalize($ok)->normalizedHash,
            ContentNormalizer::normalize($degraded)->normalizedHash,
        );
    }

    public function test_a_deploy_still_changes_the_hash(): void
    {
        $before = $this->sample('2026-08-04T21:48:28+00:00', 1785880108, 12.73, 0.73, 22, 139.99);
        $after = str_replace('03b79b026a8f', 'ffffffffffff', $before);

        $this->assertNotSame(
            ContentNormalizer::normalize($before)->normalizedHash,
            ContentNormalizer::normalize($after)->normalizedHash,
            'a commit sha is a real change, not a measurement',
        );
    }

    public function test_a_check_disappearing_still_changes_the_hash(): void
    {
        $full = $this->sample('2026-08-04T21:48:28+00:00', 1785880108, 12.73, 0.73, 22, 139.99);
        $decoded = json_decode($full, true);
        unset($decoded['checks']['redis']);

        $this->assertNotSame(
            ContentNormalizer::normalize($full)->normalizedHash,
            ContentNormalizer::normalize(json_encode($decoded))->normalizedHash,
            'the shape of the tree is state, not noise',
        );
    }

    public function test_a_boolean_flip_still_changes_the_hash(): void
    {
        $off = $this->sample('2026-08-04T21:48:28+00:00', 1785880108, 12.73, 0.73, 22, 139.99);
        $on = str_replace('"maintenance":false', '"maintenance":true', $off);

        $this->assertNotSame(
            ContentNormalizer::normalize($off)->normalizedHash,
            ContentNormalizer::normalize($on)->normalizedHash,
        );
    }

    /**
     * The accepted cost, pinned so nobody discovers it by surprise: a purely
     * numeric state change no longer marks the content as changed. Numeric
     * thresholds belong to custom metrics, which read the LIVE body.
     */
    public function test_a_purely_numeric_change_is_deliberately_invisible(): void
    {
        $calm = $this->sample('2026-08-04T21:48:28+00:00', 1785880108, 12.73, 0.73, 22, 139.99);
        $busy = str_replace('"pending_migrations":0', '"pending_migrations":9', $calm);

        $this->assertNotSame($calm, $busy);
        $this->assertSame(
            ContentNormalizer::normalize($calm)->normalizedHash,
            ContentNormalizer::normalize($busy)->normalizedHash,
        );
    }

    public function test_an_html_body_still_takes_the_html_rules(): void
    {
        $html = '<html><meta name="csrf-token" content="'.str_repeat('a', 40).'"><body>hi</body></html>';
        $html2 = '<html><meta name="csrf-token" content="'.str_repeat('b', 40).'"><body>hi</body></html>';

        $this->assertSame(
            ContentNormalizer::normalize($html)->normalizedHash,
            ContentNormalizer::normalize($html2)->normalizedHash,
            'the JSON branch must not have stolen the HTML path',
        );
    }

    public function test_a_bare_json_scalar_is_left_to_the_html_path(): void
    {
        // Normalizing a scalar would collapse every numeric body to one hash, so
        // an endpoint answering a bare number would look unchanged forever.
        $this->assertNotSame(
            ContentNormalizer::normalize('123')->normalizedHash,
            ContentNormalizer::normalize('456')->normalizedHash,
        );
    }
}
