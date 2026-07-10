<?php

namespace App\Services\Monitoring;

use App\Enums\MetricType;

/**
 * Outcome of a single {@see MetricExtractor::extract()} call.
 *
 * `value` holds the raw string extracted from the response (or null when
 * extraction failed). `typeValid` is true when the extracted value
 * passes the declared {@see MetricType}'s sniff test.
 * `error` carries a human-readable reason on any hard failure so the UI
 * can surface it verbatim.
 */
readonly class ExtractResult
{
    public function __construct(
        public ?string $value,
        public bool $typeValid,
        public ?string $error,
    ) {}

    public static function ok(string $value): self
    {
        return new self(value: $value, typeValid: true, error: null);
    }

    public static function okTyped(string $value, bool $typeValid): self
    {
        return new self(value: $value, typeValid: $typeValid, error: null);
    }

    public static function error(string $message): self
    {
        return new self(value: null, typeValid: false, error: $message);
    }
}
