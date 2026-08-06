<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\BodyShape;
use App\Services\Ai\AnalysisPayload;
use App\Services\Monitoring\MetricExtractor;
use App\Services\Monitoring\ResponseDigest;
use App\Services\Monitoring\ResponseDigestResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins what the setup model is allowed to learn from a response body: the
 * STRUCTURE, in the dialect a metric proposal is written in, under a budget it
 * cannot be talked out of.
 *
 * Three properties carry the weight and each is asserted rather than assumed.
 * A JSON leaf's path is emitted in the dot notation
 * {@see MetricExtractor::extractJsonPath()} evaluates,
 * so a proposal built from the digest is directly expressible. Page prose never
 * reaches the digest, because the digest is a shape and the body is
 * attacker-authored. And the budget binds on whole lines only, so a bound
 * budget costs subtrees rather than leaving the model half a token to reason
 * from.
 */
class ResponseDigestTest extends TestCase
{
    /** The budget the tests force when they want truncation to happen. */
    protected const int SMALL_BUDGET = 300;

    /**
     * A nested health payload's deep leaf is the whole point of the JSON
     * skeleton: it is the path a metric proposal has to name, and it has to be
     * named in the dialect the extractor evaluates.
     */
    public function test_a_json_health_body_names_a_deep_leaf_in_dot_notation_with_its_type_and_sample(): void
    {
        $result = $this->digest('health-endpoint.json');

        $this->assertSame(BodyShape::Json, $result->shape);
        $this->assertFalse($result->truncated);
        $this->assertContains('checks.database.details.latency_ms: number 12.4', $this->lines($result));
        $this->assertContains('checks.queue.details.workers_online: boolean true', $this->lines($result));
        $this->assertContains('status: string "pass"', $this->lines($result));
    }

    /**
     * A zero is the most common value on a health payload (`errors`,
     * `evictions`, `pending`) and the one a falsy check swallows, so the sample
     * a metric proposal would be built from has to survive being zero.
     */
    public function test_a_zero_valued_json_leaf_keeps_its_value(): void
    {
        $result = $this->digest('health-endpoint.json');

        $this->assertContains('checks.cache.details.evictions: number 0', $this->lines($result));
    }

    /**
     * An array is a repetition: one element plus a count says everything a
     * hundred near-identical rows would, and costs a hundredth of the budget.
     */
    public function test_a_json_array_collapses_to_its_first_element_plus_a_count(): void
    {
        $result = $this->digest('health-endpoint.json');

        $this->assertContains('notes: array(3)', $this->lines($result));
        $this->assertContains('notes.0: string "queue backlog rising"', $this->lines($result));
        $this->assertStringNotContainsString('notes.1', $result->digest);
        $this->assertStringNotContainsString('read replica lag nominal', $result->digest);
    }

    /**
     * The head is where a page says what it is; the body is where it says
     * whatever its author wants the model to read.
     */
    public function test_an_html_body_yields_head_evidence_and_never_body_prose(): void
    {
        $result = $this->digest('wordpress-page.html');

        $this->assertSame(BodyShape::Html, $result->shape);
        $this->assertStringContainsString('meta.generator: "WordPress 6.5.2"', $result->digest);
        $this->assertStringContainsString('title: "Acme Coffee Roasters', $result->digest);
        $this->assertStringContainsString('meta.og:site_name: "Acme Coffee"', $result->digest);
        $this->assertStringNotContainsString('washed Ethiopian lot', $result->digest);
        $this->assertStringNotContainsString('cupping table', $result->digest);
    }

    /**
     * A cache footer is the strongest evidence of what actually served the
     * response; a tag-manager banner is noise, and everything unlisted is noise
     * by default.
     */
    public function test_an_html_body_yields_a_cache_footer_comment_and_drops_an_unrelated_one(): void
    {
        $result = $this->digest('wordpress-page.html');

        $this->assertStringContainsString('LiteSpeed Cache 6.2', $result->digest);
        $this->assertStringNotContainsString('Google Tag Manager', $result->digest);
    }

    public function test_an_html_body_yields_the_heading_skeleton_and_the_form_and_script_counts(): void
    {
        $result = $this->digest('wordpress-page.html');

        $this->assertContains('h1: "Acme Coffee Roasters"', $this->lines($result));
        $this->assertContains('h2: "Fresh this week"', $this->lines($result));
        $this->assertContains('h3: "How the subscription works"', $this->lines($result));
        $this->assertContains('forms: 2', $this->lines($result));
        $this->assertContains('scripts: 4', $this->lines($result));
    }

    /**
     * A sitemap is 50,000 identical subtrees. The digest has to describe the
     * shape once, or the budget is spent on the target's own repetition.
     */
    public function test_an_xml_body_yields_the_root_its_namespace_and_a_collapsed_tag_skeleton(): void
    {
        $result = $this->digest('sitemap.xml');

        $this->assertSame(BodyShape::Xml, $result->shape);
        $this->assertContains('root: urlset', $this->lines($result));
        $this->assertContains('namespace: http://www.sitemaps.org/schemas/sitemap/0.9', $this->lines($result));
        $this->assertContains('urlset > url (6)', $this->lines($result));
        $this->assertContains('urlset > url > changefreq: "daily"', $this->lines($result));
        $this->assertStringNotContainsString('contact-the-roastery-team', $result->digest);
    }

    /**
     * The shape is sniffed from the bytes, never from a content type the
     * monitored target controls: the caller passes no content type at all.
     */
    #[DataProvider('sniffedShapes')]
    public function test_the_shape_is_sniffed_from_the_content(string $content, BodyShape $expected): void
    {
        $this->assertSame($expected, (new ResponseDigest)->digest($content)->shape);
    }

    /**
     * @return array<string, array{0: string, 1: BodyShape}>
     */
    public static function sniffedShapes(): array
    {
        return [
            'json object behind leading whitespace' => ["\n  {\"status\":\"ok\"}", BodyShape::Json],
            'json array' => ['[{"a":1}]', BodyShape::Json],
            'json behind a byte-order mark' => ["\u{FEFF}".'{"status":"ok"}', BodyShape::Json],
            'json scalar is not a json document' => ['"ok"', BodyShape::Unknown],
            'html with a doctype' => ['<!DOCTYPE html><html><head></head></html>', BodyShape::Html],
            'html fragment without a doctype' => ['<div class="x">hi</div>', BodyShape::Html],
            'xml by declaration' => ['<?xml version="1.0"?><status><up>1</up></status>', BodyShape::Xml],
            'xml by namespaced root' => ['<feed xmlns="http://www.w3.org/2005/Atom"><id>1</id></feed>', BodyShape::Xml],
            'plain text' => ['OK', BodyShape::Unknown],
            'empty' => ['', BodyShape::Unknown],
        ];
    }

    /**
     * A 900 KB body is inside what the worker already returns, so the budget is
     * the thing that has to hold, and it has to hold while still reaching the
     * leaves: a digest of nothing but 4,000 sibling object headers names no
     * path a metric proposal could use.
     */
    public function test_a_900_kb_json_body_stays_within_the_budget_and_still_reaches_a_deep_leaf(): void
    {
        $body = $this->largeJsonBody();
        $this->assertGreaterThan(900 * 1024, strlen($body));

        $result = (new ResponseDigest)->digest($body);

        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(
            (int) config('ai.digest.max_characters'),
            mb_strlen($result->digest),
        );
        $this->assertStringContainsString(ResponseDigest::TRUNCATION_MARKER, $result->digest);
        $this->assertStringContainsString('checks.shard_0.details.latency_ms: number 10', $result->digest);
    }

    /**
     * A page that is one enormous inline script is the other way a body eats a
     * budget. The HTML digest reads the head, so it must still say what the
     * page is.
     */
    public function test_a_body_that_is_mostly_one_enormous_script_still_yields_its_head(): void
    {
        $body = '<!DOCTYPE html><html><head><title>Ledger Console</title>'
            .'<meta name="generator" content="Vite 6"></head><body><div id="app"></div>'
            .'<script>var payload = "'.str_repeat('a', 900 * 1024).'";</script></body></html>';

        $result = (new ResponseDigest)->digest($body);

        $this->assertSame(BodyShape::Html, $result->shape);
        $this->assertStringContainsString('title: "Ledger Console"', $result->digest);
        $this->assertStringContainsString('meta.generator: "Vite 6"', $result->digest);
        $this->assertStringNotContainsString('aaaaaaaa', $result->digest);
    }

    /**
     * The budget is read from config rather than compiled in, and a bound
     * budget drops whole lines: the surviving digest is a line-exact prefix of
     * the unbounded one, so the model never gets half a path.
     */
    public function test_a_bound_budget_drops_whole_lines_and_never_cuts_one_in_half(): void
    {
        $full = $this->lines($this->digest('health-endpoint.json'));

        config(['ai.digest.max_characters' => self::SMALL_BUDGET]);
        $bound = $this->digest('health-endpoint.json');
        $boundLines = $this->lines($bound);

        $this->assertTrue($bound->truncated);
        $this->assertLessThanOrEqual(self::SMALL_BUDGET, mb_strlen($bound->digest));
        $this->assertSame(ResponseDigest::TRUNCATION_MARKER, end($boundLines));

        array_pop($boundLines);
        $this->assertNotEmpty($boundLines);
        $this->assertSame(array_slice($full, 0, count($boundLines)), $boundLines);
    }

    /**
     * The digest is rendered into the prompt through
     * {@see AnalysisPayload}'s fence, which caps every untrusted field. A
     * sample value longer than that cap could only ever be cut again, so it is
     * cut here where the cut can be marked.
     */
    public function test_a_long_sample_value_is_capped_well_inside_the_prompt_fence(): void
    {
        $body = (string) json_encode(['message' => str_repeat('n', 4000)]);

        $result = (new ResponseDigest)->digest($body);

        $sample = $this->lineStartingWith($this->lines($result), 'message: ');
        $this->assertLessThan(AnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH, mb_strlen($sample));
        $this->assertStringContainsString(ResponseDigest::SAMPLE_TRUNCATION_MARK, $sample);
    }

    /**
     * A malformed body is the normal case, not the exception: it must produce a
     * digest, raise nothing, and leave nothing on stdout, because libxml writes
     * its complaints there by default and a warning inside a JSON API response
     * corrupts it.
     */
    public function test_a_malformed_html_body_neither_throws_nor_writes_a_libxml_warning_to_output(): void
    {
        $body = '<html><head><title>Half a page</title><meta name="generator" content="Bespoke 1.0">'
            .'</head><body><p>unclosed<div><x:weird attr=></body>';

        ob_start();
        $result = (new ResponseDigest)->digest($body);
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
        $this->assertSame(BodyShape::Html, $result->shape);
        $this->assertStringContainsString('title: "Half a page"', $result->digest);
    }

    /**
     * A plain-text `OK` is a real health endpoint. The shape is unknown, and
     * saying so plus a short sample is more use to the model than saying
     * nothing.
     */
    public function test_an_unrecognised_body_yields_its_size_and_a_short_sample(): void
    {
        $result = (new ResponseDigest)->digest("OK\nqueue: draining\n");

        $this->assertSame(BodyShape::Unknown, $result->shape);
        $this->assertContains('shape: unknown', $this->lines($result));
        $this->assertContains('bytes: 19', $this->lines($result));
        $this->assertContains('sample: "OK\nqueue: draining"', $this->lines($result));
    }

    public function test_an_empty_body_yields_an_unknown_shape_without_a_sample(): void
    {
        $result = (new ResponseDigest)->digest('');

        $this->assertSame(BodyShape::Unknown, $result->shape);
        $this->assertFalse($result->truncated);
        $this->assertStringNotContainsString('sample:', $result->digest);
    }

    /**
     * A ~1 MB JSON body shaped like a fanned-out health payload: wide at the
     * second level, with the leaves that matter at the fourth.
     */
    protected function largeJsonBody(): string
    {
        $shards = [];
        for ($index = 0; $index < 1000; $index++) {
            $shards['shard_'.$index] = [
                'status' => 'pass',
                'componentType' => 'datastore',
                'details' => [
                    'latency_ms' => 10 + $index,
                    'note' => str_repeat('x', 900),
                ],
            ];
        }

        return (string) json_encode(['status' => 'pass', 'checks' => $shards]);
    }

    protected function digest(string $fixture): ResponseDigestResult
    {
        return (new ResponseDigest)->digest(
            (string) file_get_contents(base_path('tests/fixtures/content/'.$fixture)),
        );
    }

    /**
     * @return list<string>
     */
    protected function lines(ResponseDigestResult $result): array
    {
        return explode("\n", $result->digest);
    }

    /**
     * The single digest line opening with `$prefix`, failing the test when it
     * is absent.
     *
     * @param  list<string>  $lines
     */
    protected function lineStartingWith(array $lines, string $prefix): string
    {
        $matches = array_values(array_filter($lines, fn (string $line) => str_starts_with($line, $prefix)));

        $this->assertCount(1, $matches, "Expected exactly one digest line opening with `{$prefix}`.");

        return $matches[0];
    }
}
