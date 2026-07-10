<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Services\Monitoring\MetricExtractor;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Locks the extraction contract the preview endpoint exposes to the
 * Flutter form: each {@see MetricSource} rule either returns a stringy
 * value that round-trips to the client, or a user-facing error message
 * explaining why the rule failed.
 */
class MetricExtractorTest extends TestCase
{
    public function test_json_path_pulls_nested_numeric_value(): void
    {
        $extractor = new MetricExtractor;

        $result = $extractor->extract(
            MetricSource::JsonPath,
            'data.metrics.latency',
            MetricType::Numeric,
            json_encode(['data' => ['metrics' => ['latency' => 42.7]]]),
        );

        $this->assertSame('42.7', $result->value);
        $this->assertTrue($result->typeValid);
        $this->assertNull($result->error);
    }

    public function test_json_path_errors_when_body_is_not_json(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::JsonPath,
            'foo',
            MetricType::String,
            '<html>hi</html>',
        );

        $this->assertNull($result->value);
        $this->assertSame('Response body is not valid JSON.', $result->error);
    }

    public function test_json_path_errors_when_key_missing(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::JsonPath,
            'missing.key',
            MetricType::Numeric,
            '{"other":1}',
        );

        $this->assertStringContainsString('missing.key', $result->error);
    }

    public function test_regex_returns_first_capture_group(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::Regex,
            'took (\d+)ms',
            MetricType::Numeric,
            'Request took 128ms total',
        );

        $this->assertSame('128', $result->value);
        $this->assertTrue($result->typeValid);
    }

    #[WithoutErrorHandler]
    public function test_regex_surfaces_invalid_pattern(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::Regex,
            '/(unterminated',
            MetricType::String,
            'hello',
        );

        $this->assertSame('Invalid regex pattern.', $result->error);
    }

    public function test_regex_reports_no_match(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::Regex,
            'nope',
            MetricType::String,
            'hello world',
        );

        $this->assertSame('Regex did not match.', $result->error);
    }

    public function test_xpath_reads_text_content(): void
    {
        $html = '<html><body><span class="count">17</span></body></html>';
        $result = (new MetricExtractor)->extract(
            MetricSource::Xpath,
            '//span[@class="count"]',
            MetricType::Numeric,
            $html,
        );

        $this->assertSame('17', $result->value);
        $this->assertTrue($result->typeValid);
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::Header,
            'X-Response-Time',
            MetricType::Numeric,
            '',
            ['x-response-time' => '88'],
        );

        $this->assertSame('88', $result->value);
        $this->assertTrue($result->typeValid);
    }

    public function test_header_missing_surfaces_error(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::Header,
            'X-Missing',
            MetricType::String,
            '',
            ['other' => 'value'],
        );

        $this->assertStringContainsString('X-Missing', $result->error);
    }

    public function test_numeric_type_rejects_non_numeric_value(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::JsonPath,
            'status',
            MetricType::Numeric,
            '{"status":"ok"}',
        );

        $this->assertSame('ok', $result->value);
        $this->assertFalse($result->typeValid);
        $this->assertNull($result->error);
    }

    public function test_http_status_source_returns_response_status_code(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::HttpStatus,
            '',
            MetricType::Numeric,
            body: 'ignored',
            statusCode: 200,
        );

        $this->assertSame('200', $result->value);
        $this->assertTrue($result->typeValid);
        $this->assertNull($result->error);
    }

    public function test_http_status_source_errors_when_status_missing(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::HttpStatus,
            '',
            MetricType::Numeric,
            body: '',
        );

        $this->assertSame('No HTTP status code available.', $result->error);
    }

    public function test_status_type_matches_monitor_status_enum(): void
    {
        $result = (new MetricExtractor)->extract(
            MetricSource::JsonPath,
            'state',
            MetricType::Status,
            '{"state":"down"}',
        );

        $this->assertSame('down', $result->value);
        $this->assertTrue($result->typeValid);
    }
}
