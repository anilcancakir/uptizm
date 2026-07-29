<?php

namespace App\Services\Monitoring;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MonitorStatus;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Arr;

/**
 * Pure-function extraction of a metric value from an HTTP response body
 * (or response headers) for a given {@see MetricSource} rule.
 *
 * Used by the preview endpoint to power the form's live-preview card and
 * by the relay worker when persisting metric samples. Returns a
 * structured {@see ExtractResult} so callers can surface specific error
 * messages (malformed regex, missing header, invalid JSON, etc.) instead
 * of a generic failure.
 */
class MetricExtractor
{
    /**
     * Apply the extraction rule and validate the result against the
     * declared metric type.
     *
     * @param  array<string, string>  $headers  Case-insensitive header map.
     */
    public function extract(
        MetricSource $source,
        string $extractionPath,
        MetricType $type,
        string $body,
        array $headers = [],
        ?int $statusCode = null,
    ): ExtractResult {
        $raw = match ($source) {
            MetricSource::JsonPath => $this->extractJsonPath($body, $extractionPath),
            MetricSource::Regex => $this->extractRegex($body, $extractionPath),
            MetricSource::Xpath => $this->extractXpath($body, $extractionPath),
            MetricSource::Header => $this->extractHeader($headers, $extractionPath),
            MetricSource::HttpStatus => $this->extractHttpStatus($statusCode),
        };

        if ($raw->error !== null) {
            return $raw;
        }

        return $this->validateType($raw->value, $type);
    }

    /**
     * Pull a value out of a JSON body using dot-notation path.
     */
    protected function extractJsonPath(string $body, string $path): ExtractResult
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return ExtractResult::error('Response body is not valid JSON.');
        }

        $value = Arr::get($decoded, $this->normalizeJsonPath($path));
        if ($value === null) {
            return ExtractResult::error("No value at path `{$path}`.");
        }

        return ExtractResult::ok($this->stringify($value));
    }

    /**
     * Drop the conventional JSONPath root so `$.a.b`, `$a.b` and `a.b` all
     * address the same value.
     *
     * {@see Arr::get()} treats every dot-separated segment as a literal key, so
     * a leading `$` was looked up as a key named `$` and never matched. That
     * mattered because the whole product presents this source as JSONPath: the
     * enum is `json_path`, the picker reads "JSON path", and the metric form's
     * path placeholder is literally `$.system.memory.used_pct`. Following the UI
     * therefore produced a rule that extracted nothing on every check, with no
     * error surfaced anywhere, so the metric simply stayed empty forever.
     *
     * Only the LEADING root is removed, and only when it is the whole first
     * segment, so a payload with its own key named `$` (`meta.$.id`) stays
     * addressable.
     */
    protected function normalizeJsonPath(string $path): string
    {
        $trimmed = ltrim($path);

        if ($trimmed === '$') {
            return '';
        }
        if (str_starts_with($trimmed, '$.')) {
            return substr($trimmed, 2);
        }
        if (str_starts_with($trimmed, '$')) {
            return substr($trimmed, 1);
        }

        return $path;
    }

    /**
     * Convert an arbitrary decoded JSON value into its string form.
     */
    protected function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }

    /**
     * Match a regex pattern against the body and return the first capture
     * group (or the full match when no group is present).
     */
    protected function extractRegex(string $body, string $pattern): ExtractResult
    {
        // 1. Wrap raw patterns in slashes so users can type `\d+` instead of `/\d+/`.
        $normalized = $this->normalizeRegex($pattern);

        // 2. `@` suppresses the PHP warning; a malformed pattern returns false.
        $matched = @preg_match($normalized, $body, $matches);
        if ($matched === false) {
            return ExtractResult::error('Invalid regex pattern.');
        }
        if ($matched === 0) {
            return ExtractResult::error('Regex did not match.');
        }

        // 3. Prefer the first capture group; fall back to the full match.
        $value = $matches[1] ?? $matches[0];

        return ExtractResult::ok((string) $value);
    }

    /**
     * Wrap a raw pattern in delimiters when the user typed a bare regex.
     */
    protected function normalizeRegex(string $pattern): string
    {
        if ($pattern === '') {
            return '//';
        }

        $first = $pattern[0];
        $last = substr($pattern, -1);
        if ($first === $last && in_array($first, ['/', '#', '~'], true)) {
            return $pattern;
        }

        return '/'.str_replace('/', '\/', $pattern).'/';
    }

    /**
     * Query an HTML/XML body via XPath and return the first matched node's
     * text content.
     */
    protected function extractXpath(string $body, string $query): ExtractResult
    {
        $document = new DOMDocument;
        $loaded = @$document->loadHTML($body, LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $loaded) {
            return ExtractResult::error('Could not parse response as HTML/XML.');
        }

        $xpath = new DOMXPath($document);
        $nodes = @$xpath->query($query);
        if ($nodes === false) {
            return ExtractResult::error('Invalid XPath query.');
        }
        if ($nodes->length === 0) {
            return ExtractResult::error('XPath query returned no nodes.');
        }

        return ExtractResult::ok(trim($nodes->item(0)->textContent));
    }

    /**
     * Look up a response header by name, case-insensitively.
     *
     * @param  array<string, string>  $headers
     */
    protected function extractHeader(array $headers, string $name): ExtractResult
    {
        // 1. Flatten to lower-case keys so `Content-Type` and `content-type` match.
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }

        $value = $normalized[strtolower($name)] ?? null;
        if ($value === null) {
            return ExtractResult::error("Header `{$name}` not present.");
        }

        return ExtractResult::ok((string) $value);
    }

    /**
     * Surface the HTTP status code of the check response as the metric value.
     */
    protected function extractHttpStatus(?int $statusCode): ExtractResult
    {
        if ($statusCode === null) {
            return ExtractResult::error('No HTTP status code available.');
        }

        return ExtractResult::ok((string) $statusCode);
    }

    /**
     * Validate the extracted value against the declared metric type.
     */
    protected function validateType(string $value, MetricType $type): ExtractResult
    {
        $typeValid = match ($type) {
            MetricType::Numeric => is_numeric($value),
            MetricType::Status => MonitorStatus::tryFrom($value) !== null,
            MetricType::String => true,
        };

        return ExtractResult::okTyped($value, $typeValid);
    }
}
