<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Services\Monitoring\MetricExtractor;
use App\Support\Monitoring\MetricCandidate;
use App\Support\Monitoring\ProbeHeaderAllowList;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the contract the AI metric-discovery call rests on: every extraction
 * path in the digest was generated HERE and proved evaluable HERE, so the model
 * can only ever select a `ref` and never author a path.
 *
 * Two assertions carry that weight and are made over EVERY candidate rather
 * than a sample: each path fed back through the real
 * {@see MetricExtractor::extract()} returns the candidate's full sample value,
 * and each candidate's `eligibleTypes` excludes `numeric` unless the check-time
 * extractor can actually reduce the sample to a number (a metric typed numeric
 * over a sample it cannot is extracted fine and then discarded on every check,
 * which reads to the user as a metric that never records a sample).
 */
class MetricCandidateExtractorTest extends TestCase
{
    /** The frozen real-page capture; the digest built from it must stay this small. */
    protected const int REAL_PAGE_DIGEST_MAX_BYTES = 2000;

    /** The bound the digest row applies to a value, ellipsis excluded. */
    protected const int DIGEST_VALUE_MAX_LENGTH = 128;

    /** The cap on the returned list, whatever the page throws at it. */
    protected const int MAX_CANDIDATES = 40;

    /**
     * A value living directly inside an id-bearing element is addressed by the
     * id alone, and nothing longer.
     */
    public function test_an_id_bearing_element_yields_an_id_anchored_path(): void
    {
        $candidates = $this->extract('candidates-with-id.html');

        $this->assertNotEmpty($candidates);

        $candidate = $this->candidateFor($candidates, '120ms');

        $this->assertSame('//*[@id="render-time"]', $candidate->extractionPath);
        $this->assertSame('120ms', $candidate->sampleValue);
        $this->assertSame(MetricSource::Xpath, $candidate->source);
        $this->assertSame('Render time', $candidate->labelHint);
    }

    /**
     * When the id sits on a wrapper whose own text is NOT the value, the id
     * anchor alone would round-trip the wrapper's whole subtree text, so the
     * path has to descend to the value-bearing element.
     */
    public function test_a_wrapper_id_descends_to_the_value_bearing_element(): void
    {
        $candidates = $this->extract('candidates-with-id.html');

        $this->assertNotEmpty($candidates);

        $candidate = $this->candidateFor($candidates, '340ms');

        $this->assertSame('//*[@id="latency-panel"]/span[2]', $candidate->extractionPath);
    }

    /**
     * `numeric` is offered for a sample the check-time extractor can reduce to
     * a number, which is now two shapes and not one.
     *
     * This test used to pin `120ms` to `[String]`. That expectation was correct
     * only while {@see MetricExtractor} had no way to strip a unit: the value
     * failed `is_numeric()` at check time, so proposing it as numeric would
     * have produced a metric that records nothing forever. The extractor now
     * strips a MAPPED suffix ahead of the type gate, so the same sample
     * sustains `numeric` and carries the unit that number is expressed in.
     */
    public function test_a_unit_bearing_sample_is_numeric_eligible_and_carries_its_unit(): void
    {
        $candidates = $this->extract('candidates-with-id.html');

        $this->assertNotEmpty($candidates);

        $unitBearing = $this->candidateFor($candidates, '120ms');
        $bareNumber = $this->candidateFor($candidates, '4200');

        $this->assertSame([MetricType::Numeric, MetricType::String], $unitBearing->eligibleTypes);
        $this->assertSame(MetricUnit::Millisecond, $unitBearing->unit);

        // The sample itself is never rewritten: the round-trip proof compares
        // against it verbatim, so the strip belongs at check time and the unit
        // travels beside the value rather than inside it.
        $this->assertSame('120ms', $unitBearing->sampleValue);

        $this->assertSame([MetricType::Numeric, MetricType::String], $bareNumber->eligibleTypes);
        $this->assertNull($bareNumber->unit);
    }

    /**
     * The map is a MAP and not a pattern.
     *
     * `12 widgets` has the exact shape of a unit-suffixed number and stays
     * string-only, because `widgets` names no {@see MetricUnit} case and a
     * stripped `12` would then be stored under no unit at all. `1,2 MB` is the
     * second refusal: the comma could be a decimal separator or a thousands
     * separator depending on the page's locale, which the body does not carry.
     */
    public function test_only_a_mapped_suffix_makes_a_sample_numeric_eligible(): void
    {
        $candidates = (new MetricCandidateExtractor)->extract((string) json_encode([
            'transfer_size' => '1.2 MB',
            'inventory' => '12 widgets',
            'european_size' => '1,2 MB',
        ]));

        $mapped = $this->candidateFor($candidates, '1.2 MB');
        $unmapped = $this->candidateFor($candidates, '12 widgets');
        $commaDecimal = $this->candidateFor($candidates, '1,2 MB');

        $this->assertSame([MetricType::Numeric, MetricType::String], $mapped->eligibleTypes);
        $this->assertSame(MetricUnit::Megabyte, $mapped->unit);

        $this->assertSame([MetricType::String], $unmapped->eligibleTypes);
        $this->assertNull($unmapped->unit);

        $this->assertSame([MetricType::String], $commaDecimal->eligibleTypes);
        $this->assertNull($commaDecimal->unit);
    }

    /**
     * The load-bearing proof, asserted for every candidate of every fixture:
     * the emitted path fed back through the REAL extractor returns exactly the
     * candidate's full sample value.
     */
    #[DataProvider('bodyFixtureProvider')]
    public function test_every_candidate_round_trips_through_the_metric_extractor(string $fixture): void
    {
        $body = $this->fixture($fixture);
        $candidates = (new MetricCandidateExtractor)->extract($body);
        $extractor = new MetricExtractor;

        $this->assertNotEmpty($candidates, "{$fixture} produced no candidates to verify.");

        foreach ($candidates as $candidate) {
            $this->assertContains(
                $candidate->source,
                [MetricSource::JsonPath, MetricSource::Xpath],
                "{$candidate->ref} uses a source outside the two structured ones.",
            );

            $result = $extractor->extract(
                $candidate->source,
                $candidate->extractionPath,
                MetricType::String,
                $body,
            );

            $this->assertNull($result->error, "{$fixture} {$candidate->ref}: {$result->error}");
            $this->assertSame(
                $candidate->sampleValue,
                $result->value,
                "{$fixture} {$candidate->ref} path `{$candidate->extractionPath}` did not round-trip.",
            );
        }
    }

    /** Every candidate carries a unique, well-shaped ref. */
    #[DataProvider('bodyFixtureProvider')]
    public function test_refs_are_unique_and_shaped(string $fixture): void
    {
        $candidates = (new MetricCandidateExtractor)->extract($this->fixture($fixture));

        $this->assertNotEmpty($candidates);

        $refs = array_map(fn (MetricCandidate $candidate) => $candidate->ref, $candidates);

        foreach ($refs as $ref) {
            $this->assertMatchesRegularExpression('/^c[0-9]+$/', $ref);
        }

        $this->assertSame($refs, array_values(array_unique($refs)));
    }

    /** No digest row ever carries more than the ceiling plus one ellipsis. */
    #[DataProvider('bodyFixtureProvider')]
    public function test_no_digest_row_value_exceeds_the_ceiling(string $fixture): void
    {
        $candidates = (new MetricCandidateExtractor)->extract($this->fixture($fixture));

        $this->assertNotEmpty($candidates);

        foreach ($candidates as $candidate) {
            $row = $candidate->toDigestRow();

            $this->assertLessThanOrEqual(
                self::DIGEST_VALUE_MAX_LENGTH + 1,
                mb_strlen((string) $row['value']),
                "{$fixture} {$candidate->ref} digest value is over the ceiling.",
            );
        }
    }

    /** The real 182 KB page yields candidates, and its digest stays affordable. */
    public function test_the_real_page_yields_candidates_within_the_digest_budget(): void
    {
        $extractor = new MetricCandidateExtractor;
        $candidates = $extractor->extract($this->fixture('fluttersdk-home-1.html'));

        $this->assertNotEmpty($candidates);
        $this->assertLessThanOrEqual(self::MAX_CANDIDATES, count($candidates));

        $digest = $extractor->digest($candidates);

        $this->assertLessThan(self::REAL_PAGE_DIGEST_MAX_BYTES, strlen($digest));

        // The page is styled with Wind utilities end to end, so a utility token
        // reaching a path would mean the stability gate is not running at all.
        foreach ($candidates as $candidate) {
            $this->assertStringNotContainsString('font-bold', $candidate->extractionPath);
            $this->assertStringNotContainsString('text-wind', $candidate->extractionPath);
            $this->assertStringNotContainsString('text-green', $candidate->extractionPath);
        }
    }

    /**
     * A utility-only element falls through to a positional path, while a
     * semantic class is still allowed to anchor.
     */
    public function test_a_utility_class_falls_through_to_a_positional_path(): void
    {
        $candidates = $this->extract('candidates-utility-class.html');

        $this->assertNotEmpty($candidates);

        $utility = $this->candidateFor($candidates, '98%');

        $this->assertSame('/html/body/main/div[1]/p[2]', $utility->extractionPath);
        $this->assertStringNotContainsString('@class', $utility->extractionPath);
        $this->assertStringNotContainsString('font-bold', $utility->extractionPath);
        $this->assertStringNotContainsString('text-blue-400', $utility->extractionPath);

        $semantic = $this->candidateFor($candidates, '88ms');

        $this->assertStringContainsString('@class', $semantic->extractionPath);
        $this->assertStringContainsString('latency-value', $semantic->extractionPath);
    }

    /** Seven counting siblings collapse to one row; a different shape survives. */
    public function test_a_sibling_enumeration_collapses_to_one_representative(): void
    {
        $candidates = $this->extract('candidates-enumeration.html');

        $this->assertNotEmpty($candidates);
        $this->assertCount(2, $candidates);
        $this->assertSame('/html/body/main/ol/li[1]', $this->candidateFor($candidates, '1')->extractionPath);
        $this->assertSame('/html/body/main/ul/li[1]', $this->candidateFor($candidates, '42ms')->extractionPath);
    }

    /** JSON paths are dot-notation in the dialect the extractor evaluates. */
    public function test_json_paths_are_dot_notation_with_an_integer_array_segment(): void
    {
        $candidates = $this->extract('candidates-json.json');

        $this->assertNotEmpty($candidates);

        $paths = $this->paths($candidates);

        foreach ($paths as $path) {
            $this->assertStringStartsNotWith('$', $path);
        }

        $this->assertContains('items.0.latency', $paths);
        $this->assertContains('metrics.latency_ms', $paths);

        // The second array element carries the same digit-masked shape under the
        // same masked parent, so it is the collapsed sibling.
        $this->assertNotContains('items.1.latency', $paths);

        $this->assertSame(
            [MetricType::Numeric, MetricType::String],
            $this->candidateFor($candidates, '4200')->eligibleTypes,
        );
    }

    /**
     * The full value and the digest value are two separate representations: a
     * 176-character leaf round-trips in full and is truncated only in the row.
     */
    public function test_a_long_json_leaf_round_trips_in_full_but_truncates_in_the_digest(): void
    {
        $body = $this->fixture('candidates-json.json');
        $candidates = (new MetricCandidateExtractor)->extract($body);

        $this->assertNotEmpty($candidates);

        $decoded = json_decode($body, true);
        $summary = $decoded['summary'];
        $this->assertGreaterThan(self::DIGEST_VALUE_MAX_LENGTH, mb_strlen($summary));

        $candidate = $this->candidateFor($candidates, $summary);

        $this->assertSame('summary', $candidate->extractionPath);

        $result = (new MetricExtractor)->extract(
            MetricSource::JsonPath,
            $candidate->extractionPath,
            MetricType::String,
            $body,
        );
        $this->assertSame($summary, $result->value);

        $row = $candidate->toDigestRow();
        $this->assertSame(
            mb_substr($summary, 0, self::DIGEST_VALUE_MAX_LENGTH).MetricCandidate::DIGEST_TRUNCATION_MARK,
            $row['value'],
        );
        $this->assertStringEndsWith(MetricCandidate::DIGEST_TRUNCATION_MARK, (string) $row['value']);
    }

    /** The list is capped however many leaves the body carries. */
    public function test_the_candidate_list_is_capped(): void
    {
        $leaves = [];
        for ($index = 0; $index < 60; $index++) {
            // Distinct non-numeric values, so the enumeration collapse (which
            // groups on the digit-masked shape) cannot be what caps the list.
            $leaves['field'.$index] = 'value-'.chr(97 + intdiv($index, 26)).chr(97 + $index % 26);
        }

        $candidates = (new MetricCandidateExtractor)->extract((string) json_encode($leaves));

        $this->assertCount(self::MAX_CANDIDATES, $candidates);
        $this->assertSame('c1', $candidates[0]->ref);
        $this->assertSame('c'.self::MAX_CANDIDATES, $candidates[self::MAX_CANDIDATES - 1]->ref);
    }

    /**
     * The cap is applied AFTER ranking, so the one worthwhile candidate on a
     * page full of counting survives it and leads the digest.
     */
    public function test_ranking_puts_the_id_anchored_unit_bearing_candidate_first(): void
    {
        $noise = '';
        for ($index = 1; $index <= 60; $index++) {
            $noise .= '<div><span>'.($index * 3).'</span></div>';
        }

        $candidates = (new MetricCandidateExtractor)->extract(
            '<html><body><div id="render-time">120ms</div>'.$noise.'</body></html>',
        );

        $this->assertCount(self::MAX_CANDIDATES, $candidates);
        $this->assertSame('120ms', $candidates[0]->sampleValue);
        $this->assertSame('c1', $candidates[0]->ref);
        $this->assertSame('//*[@id="render-time"]', $candidates[0]->extractionPath);
    }

    /**
     * Numbers inside `script` and `style` are markup mechanics, never metrics.
     *
     * The two blocks hold a bare number on purpose: a script whose text is a
     * whole statement is rejected by the numeric-shape filter anyway, so it
     * would make this assertion pass without the ancestor exclusion running.
     */
    public function test_script_and_style_numbers_are_never_candidates(): void
    {
        $candidates = (new MetricCandidateExtractor)->extract(
            "<html><head><style>\n12\n</style></head>".
            "<body><script>\n4200\n</script><p id=\"uptime\">99.9%</p></body></html>",
        );

        $this->assertCount(1, $candidates);
        $this->assertSame('99.9%', $candidates[0]->sampleValue);
        $this->assertSame('//*[@id="uptime"]', $candidates[0]->extractionPath);
    }

    /**
     * An allowlisted header becomes a candidate whose path resolves through the
     * REAL extractor against the raw-cased header map a check actually holds.
     */
    public function test_an_allowlisted_header_yields_a_candidate_that_round_trips(): void
    {
        $raw = [
            'X-Runtime' => '0.024',
            'Set-Cookie' => 'session=SUPERSECRET; HttpOnly',
        ];

        $candidates = (new MetricCandidateExtractor)->extract(
            '<html><body><p id="uptime">99.9%</p></body></html>',
            ProbeHeaderAllowList::filter($raw),
        );

        $header = $this->candidateFor($candidates, '0.024');

        $this->assertSame(MetricSource::Header, $header->source);
        $this->assertSame('x-runtime', $header->extractionPath);
        $this->assertSame('x-runtime', $header->labelHint);

        // The check job passes the raw set with the target's own casing, so the
        // emitted path has to resolve against THAT and not only against the
        // filtered map this candidate was built from.
        $result = (new MetricExtractor)->extract(
            $header->source,
            $header->extractionPath,
            MetricType::String,
            '',
            $raw,
        );

        $this->assertNull($result->error);
        $this->assertSame($header->sampleValue, $result->value);
    }

    /**
     * The allowlist is the only header source, so a credential-bearing name is
     * unreachable from a suggestion no matter what the target sent.
     */
    public function test_an_unlisted_header_never_becomes_a_candidate(): void
    {
        $candidates = (new MetricCandidateExtractor)->extract(
            '<html><body><p id="uptime">99.9%</p></body></html>',
            ProbeHeaderAllowList::filter([
                'Set-Cookie' => 'session=SUPERSECRET',
                'Authorization' => 'Basic dXNlcjpwYXNz',
                'X-Runtime' => '0.024',
            ]),
        );

        $headerPaths = array_map(
            fn (MetricCandidate $candidate): string => $candidate->extractionPath,
            array_filter($candidates, fn (MetricCandidate $candidate): bool => $candidate->source === MetricSource::Header),
        );

        $this->assertSame(['x-runtime'], array_values($headerPaths));

        foreach ($this->values($candidates) as $value) {
            $this->assertStringNotContainsString('SUPERSECRET', $value);
            $this->assertStringNotContainsString('dXNlcjpwYXNz', $value);
        }
    }

    /**
     * A value at the filter's cap is refused, because it is indistinguishable
     * from one the filter cut.
     *
     * `filter()` caps at 256 characters; `MetricExtractor::extractHeader()`
     * caps at nothing and returns what the target sent. A candidate proposed
     * from a cut sample advertises a value no check will ever extract, so it
     * would be silently wrong forever. Deliberately conservative: a legitimate
     * 256-character header is dropped along with the cut ones.
     */
    public function test_a_header_value_at_the_filter_cap_is_refused(): void
    {
        $filtered = ProbeHeaderAllowList::filter([
            'Link' => str_repeat('a', ProbeHeaderAllowList::VALUE_MAX_LENGTH + 40),
            'X-Cache' => 'HIT',
        ]);

        $this->assertSame(ProbeHeaderAllowList::VALUE_MAX_LENGTH, mb_strlen($filtered['link']));

        $candidates = (new MetricCandidateExtractor)->extract('', $filtered);

        $this->assertSame(['x-cache'], $this->paths($candidates));
    }

    /**
     * A non-string header value is refused rather than cast.
     *
     * `extractHeader()` casts with `(string) $value`, and on an array that
     * raises the warning Laravel rethrows as an `ErrorException`, inside the
     * check job. Unreachable through `ProbeHeaderAllowList::filter()`, which
     * joins a list first, and refused here anyway because this parameter is
     * what a future caller hands over.
     */
    public function test_a_non_string_header_value_is_refused(): void
    {
        $candidates = (new MetricCandidateExtractor)->extract('', [
            'x-cache' => ['HIT', 'MISS'],
            'age' => '300',
        ]);

        $this->assertSame(['age'], $this->paths($candidates));
    }

    /** A body with nothing to measure yields an empty list, never an error. */
    public function test_a_body_without_measurable_content_yields_no_candidates(): void
    {
        $extractor = new MetricCandidateExtractor;

        $this->assertSame([], $extractor->extract(''));
        $this->assertSame([], $extractor->extract('   '));
        $this->assertSame([], $extractor->extract('<html><body><p>no numbers here</p></body></html>'));
        $this->assertSame('[]', $extractor->digest([]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bodyFixtureProvider(): array
    {
        return [
            'real page' => ['fluttersdk-home-1.html'],
            'id anchors' => ['candidates-with-id.html'],
            'utility classes' => ['candidates-utility-class.html'],
            'sibling enumeration' => ['candidates-enumeration.html'],
            'json leaves' => ['candidates-json.json'],
        ];
    }

    /**
     * @return list<MetricCandidate>
     */
    protected function extract(string $fixture): array
    {
        return (new MetricCandidateExtractor)->extract($this->fixture($fixture));
    }

    /**
     * The single candidate carrying `$value`, failing the test when absent.
     *
     * @param  list<MetricCandidate>  $candidates
     */
    protected function candidateFor(array $candidates, string $value): MetricCandidate
    {
        $matches = array_values(array_filter(
            $candidates,
            fn (MetricCandidate $candidate) => $candidate->sampleValue === $value,
        ));

        $this->assertCount(1, $matches, "Expected exactly one candidate valued `{$value}`.");

        return $matches[0];
    }

    /**
     * @param  list<MetricCandidate>  $candidates
     * @return list<string>
     */
    protected function paths(array $candidates): array
    {
        return array_map(fn (MetricCandidate $candidate) => $candidate->extractionPath, $candidates);
    }

    /**
     * @param  list<MetricCandidate>  $candidates
     * @return list<string>
     */
    protected function values(array $candidates): array
    {
        return array_map(fn (MetricCandidate $candidate) => $candidate->sampleValue, $candidates);
    }

    /**
     * Contents of a committed content fixture.
     */
    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/content/'.$name));
    }
}
