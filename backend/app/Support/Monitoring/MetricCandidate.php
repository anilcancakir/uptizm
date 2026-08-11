<?php

namespace App\Support\Monitoring;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Services\Monitoring\MetricExtractor;

/**
 * Immutable description of one extraction target the backend generated from a
 * captured response body and PROVED evaluable against it.
 *
 * `ref` is the only handle the discovery model ever answers with: the backend
 * sends the digest, the model replies `c3`, and the backend maps that back to
 * the candidate it generated itself. That indirection is what makes the model
 * structurally incapable of authoring an extraction path, so no byte of an
 * untrusted page can become monitor configuration.
 *
 * `sampleValue` is the FULL extracted value and is never truncated:
 * {@see MetricCandidateExtractor} proves a path by comparing what it
 * re-extracts against this exact string, and a truncated copy would make that
 * comparison impossible for any long leaf. Truncation belongs to
 * {@see toDigestRow()} alone, which is the only representation the model sees.
 *
 * `eligibleTypes` is the set of {@see MetricType} cases this sample can
 * actually sustain. It exists because {@see MetricExtractor::validateType()}
 * discards a non-numeric value under `MetricType::Numeric`, so a candidate
 * accepted as numeric on a sample the extractor cannot reduce to a number
 * would extract on every check and record nothing, which reads to the user as
 * a metric that is silently always empty.
 *
 * `unit` is that rule's other half. A sample like `120ms` IS numeric-eligible,
 * because {@see MetricExtractor::splitUnit()} strips the suffix at check time,
 * and the unit it stripped is the only one under which the recorded `120`
 * means anything. Carrying it here is what lets an accepted suggestion arrive
 * with the measurement it was read in. Null for a bare number or for a sample
 * whose suffix the map does not name.
 */
readonly class MetricCandidate
{
    /**
     * Character ceiling a digest row applies to a sample value.
     *
     * The digest is what the model is billed for, and free text on a page is
     * unbounded, so the row keeps the head of the value (where a unit or a
     * label prefix lives) and drops the rest.
     */
    public const int DIGEST_VALUE_MAX_LENGTH = 128;

    /** Appended to a digest value that was cut, so the model can see it was cut. */
    public const string DIGEST_TRUNCATION_MARK = '…';

    /**
     * @param  list<MetricType>  $eligibleTypes
     */
    public function __construct(
        public string $ref,
        public MetricSource $source,
        public string $extractionPath,
        public string $sampleValue,
        public ?string $labelHint,
        public array $eligibleTypes,
        public ?MetricUnit $unit = null,
    ) {}

    /**
     * The compact row this candidate contributes to the model's digest.
     *
     * Keys are abbreviated and a null label hint is omitted entirely: this
     * array is serialized once per candidate into a prompt, so every key name
     * is paid for on every discovery call.
     *
     * @return array<string, mixed>
     */
    public function toDigestRow(): array
    {
        $row = [
            'ref' => $this->ref,
            'src' => $this->source->value,
            'path' => $this->extractionPath,
            'value' => $this->digestValue(),
        ];

        if ($this->labelHint !== null) {
            $row['label'] = $this->labelHint;
        }

        $row['types'] = array_map(fn (MetricType $type) => $type->value, $this->eligibleTypes);

        return $row;
    }

    /**
     * The sample value cut to the digest ceiling, head preserved.
     */
    protected function digestValue(): string
    {
        if (mb_strlen($this->sampleValue) <= self::DIGEST_VALUE_MAX_LENGTH) {
            return $this->sampleValue;
        }

        return mb_substr($this->sampleValue, 0, self::DIGEST_VALUE_MAX_LENGTH).self::DIGEST_TRUNCATION_MARK;
    }
}
