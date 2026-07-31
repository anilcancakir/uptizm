<?php

namespace Tests\Unit\Support\Monitoring;

use App\Enums\MonitorStatus;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Pins the body half of the worker wire contract: the three content fields
 * parse, a payload from an older worker that omits all three still parses, and
 * `toArray()` deliberately carries the content METADATA without the body.
 *
 * That asymmetry is the assertion that matters most here. `PerformMonitorCheck`
 * dispatches `toArray()` onto the Redis `processing` queue, so a `content` key
 * would push every HTTP check's full page into Redis.
 */
class CheckResultContentTest extends TestCase
{
    /**
     * The byte size and SHA-256 of the two frozen research-time captures of
     * https://fluttersdk.com, both pinned here rather than recomputed.
     *
     * The pair is load-bearing for the normalizer: the two bodies differ ONLY
     * by the per-request CSRF token, which is what proves normalized dedupe
     * works on a real page. Re-fetching the live site would silently invalidate
     * that, so these constants make any replacement of the fixtures a failing
     * test rather than a quiet change of subject.
     */
    protected const int FIXTURE_BYTES = 182349;

    protected const string FIXTURE_ONE_SHA256 = '7727796b4e709c87a6c8e07657e2ea31c2222c74c0936915a081699bf964bbef';

    protected const string FIXTURE_TWO_SHA256 = 'd0297349669dd765c5b9cd38defd28a5c4d29372371668d80f76406bf4afb5aa';

    /** The 10 KiB `response_body_preview` ceiling, unchanged by the archive work. */
    protected const int PREVIEW_BYTES = 10240;

    /** A current worker payload carries the decoded body, its type and the truncation flag. */
    public function test_parses_the_three_content_fields_from_a_worker_payload(): void
    {
        $result = CheckResult::fromWorkerPayload($this->workerPayload([
            'content' => '<html><body>ok</body></html>',
            'content_type' => 'text/html; charset=utf-8',
            'content_truncated' => true,
        ]));

        $this->assertSame('<html><body>ok</body></html>', $result->content);
        $this->assertSame('text/html; charset=utf-8', $result->contentType);
        $this->assertTrue($result->contentTruncated);
    }

    /** An older worker deployment sends none of the three; the payload must still parse. */
    public function test_parses_a_legacy_payload_without_the_content_fields(): void
    {
        $payload = $this->workerPayload();
        $this->assertArrayNotHasKey('content', $payload);

        $result = CheckResult::fromWorkerPayload($payload);

        $this->assertNull($result->content);
        $this->assertNull($result->contentType);
        $this->assertFalse($result->contentTruncated);
    }

    /**
     * The content type is a raw header chosen by the monitored target and it
     * lands in a `string(128)` column, so it is cut at the boundary where it
     * enters rather than at the insert that would throw on PostgreSQL.
     */
    public function test_truncates_an_over_long_content_type_at_the_boundary(): void
    {
        $result = CheckResult::fromWorkerPayload($this->workerPayload([
            'content_type' => 'text/html; charset='.str_repeat('u', 400),
        ]));

        $this->assertSame(128, mb_strlen((string) $result->contentType));
        $this->assertStringStartsWith('text/html; charset=', (string) $result->contentType);
    }

    /** An absent content type is a null field, never an empty string. */
    public function test_parses_an_empty_content_type_as_null(): void
    {
        $result = CheckResult::fromWorkerPayload($this->workerPayload([
            'content_type' => '',
        ]));

        $this->assertNull($result->contentType);
    }

    /**
     * The queue payload carries the content metadata but NEVER the body.
     *
     * This is the Redis guard: `PerformMonitorCheck` dispatches this array onto
     * the `processing` queue.
     */
    public function test_to_array_omits_the_content_but_keeps_its_metadata(): void
    {
        $result = CheckResult::fromWorkerPayload($this->workerPayload([
            'content' => '<html><body>ok</body></html>',
            'content_type' => 'text/html; charset=utf-8',
            'content_truncated' => true,
        ]));

        $array = $result->toArray();

        $this->assertFalse(array_key_exists('content', $array));
        $this->assertArrayHasKey('content_type', $array);
        $this->assertArrayHasKey('content_truncated', $array);
        $this->assertSame('text/html; charset=utf-8', $array['content_type']);
        $this->assertTrue($array['content_truncated']);
    }

    /** A real 182 KB page body must not inflate the job payload that rides Redis. */
    public function test_queue_payload_stays_small_for_a_real_page_body(): void
    {
        $body = (string) file_get_contents($this->fixturePath('fluttersdk-home-1.html'));
        $result = CheckResult::fromWorkerPayload($this->workerPayload([
            'response_body_preview' => substr($body, 0, self::PREVIEW_BYTES),
            'content' => $body,
            'content_type' => 'text/html; charset=utf-8',
        ]));

        $this->assertSame(self::FIXTURE_BYTES, strlen((string) $result->content));
        $this->assertLessThan(12000, strlen(serialize($result->toArray())));

        // The preview legitimately still carries markup (`<html` sits at byte 16
        // of it), so it is excluded: the claim under test is that the FULL body
        // is absent, not that no markup survives anywhere.
        $this->assertStringNotContainsString(
            '<html',
            serialize(Arr::except($result->toArray(), [
                'response_body_preview',
            ])),
        );
    }

    /**
     * A body the edge filtered out on the content-type allowlist arrives as a
     * null content beside a preview that is still populated, so the existing
     * preview consumers are untouched by the filter.
     */
    public function test_a_filtered_body_parses_as_null_content_with_the_preview_intact(): void
    {
        $result = CheckResult::fromWorkerPayload($this->workerPayload([
            'response_body_preview' => '%PDF-1.4 ...',
            'content' => null,
            'content_type' => 'application/pdf',
            'content_truncated' => false,
        ]));

        $this->assertNull($result->content);
        $this->assertSame('application/pdf', $result->contentType);
        $this->assertSame('%PDF-1.4 ...', $result->responseBodyPreview);
        $this->assertFalse($result->contentTruncated);
    }

    /** Both real-page fixtures are installed verbatim and are genuinely two versions. */
    public function test_the_two_page_fixtures_are_installed_verbatim(): void
    {
        $one = $this->fixturePath('fluttersdk-home-1.html');
        $two = $this->fixturePath('fluttersdk-home-2.html');

        $this->assertFileExists($one);
        $this->assertFileExists($two);
        $this->assertSame(self::FIXTURE_BYTES, filesize($one));
        $this->assertSame(self::FIXTURE_BYTES, filesize($two));
        $this->assertSame(self::FIXTURE_ONE_SHA256, hash_file('sha256', $one));
        $this->assertSame(self::FIXTURE_TWO_SHA256, hash_file('sha256', $two));
        $this->assertNotSame(hash_file('sha256', $one), hash_file('sha256', $two));
    }

    /**
     * Absolute path of a committed content fixture.
     */
    protected function fixturePath(string $name): string
    {
        return base_path('tests/fixtures/content/'.$name);
    }

    /**
     * A complete worker payload, with `$overrides` merged over it.
     *
     * The base deliberately omits `content`, `content_type` and
     * `content_truncated` so it doubles as the legacy shape an older worker
     * deployment sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function workerPayload(array $overrides = []): array
    {
        return array_merge([
            'monitor_id' => '01J0000000000000000000000',
            'region' => 'eu-central',
            'checked_at' => (new DateTimeImmutable('2026-08-01T10:00:00+00:00'))->format(DateTimeImmutable::ATOM),
            'status' => MonitorStatus::Up->value,
            'status_code' => 200,
            'response_ms' => 128,
            'error_message' => null,
            'timing' => [
                'dns_ms' => 0,
                'connect_ms' => 0,
                'tls_ms' => 0,
                'ttfb_ms' => 90,
                'download_ms' => 38,
            ],
            'response_headers' => [
                'content-type' => 'text/html; charset=utf-8',
            ],
            'response_body_preview' => '<!DOCTYPE html>',
            'probe_run_id' => 'run-0001',
            'colo' => 'FRA',
            'probe_refused' => false,
        ], $overrides);
    }
}
