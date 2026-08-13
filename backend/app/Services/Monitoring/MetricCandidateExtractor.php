<?php

namespace App\Services\Monitoring;

use App\Enums\MetricSource;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Http\Requests\StoreMonitorRequest;
use App\Support\Monitoring\MetricCandidate;
use App\Support\Monitoring\ProbeHeaderAllowList;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Illuminate\Support\Arr;

/**
 * Deterministic generator of metric extraction candidates from a captured
 * response body.
 *
 * This class is the security boundary of AI metric discovery. The model is only
 * ever shown {@see digest()} and only ever answers with a candidate `ref`, so
 * every extraction path that can reach monitor configuration was generated
 * here. That holds on two conditions, and both are enforced below rather than
 * assumed: nothing is emitted before it has been proved to resolve to exactly
 * one node holding exactly the candidate's full sample value, and no model, no
 * network call and no configuration influences the output. The same body always
 * yields the same digest.
 *
 * A candidate comes from one of two places, and the second one is narrower on
 * purpose. The response BODY is mined structurally, and the response HEADERS are
 * mined from the ALLOWLISTED map only ({@see ProbeHeaderAllowList::filter()}'s
 * output, never a raw header set): the probe that produced those headers may
 * have run with the operator's own credential, so `Set-Cookie` on the raw set is
 * an authenticated session token and `Authorization` echoes the credential
 * itself. This class never sees either, because it is never handed the raw set.
 *
 * Two properties of the paths matter as much as their correctness:
 *
 *   - They are in the dialect {@see MetricExtractor} evaluates. The document is
 *     parsed with the same flags, the same one-node-then-trim read is what a
 *     path is proved against, and the JSON dialect is dot notation with no
 *     leading `$`. A path that only resolves here would produce a metric that
 *     extracts nothing on every check, and a failed extraction records nothing
 *     rather than raising anything, so the user would see a metric that is
 *     silently always empty.
 *   - They avoid the parts of a page that change without the content changing.
 *     Utility classes are a restyle away from gone and a hashed or per-render
 *     token is a deploy away, so both are refused as selector components and
 *     the path falls through to a positional one instead.
 */
class MetricCandidateExtractor
{
    /**
     * Text nodes worth measuring: non-blank, and outside the two element types
     * whose text is markup mechanics rather than page content.
     */
    protected const string TEXT_NODE_QUERY = '//text()[normalize-space()][not(ancestor::script)][not(ancestor::style)]';

    /**
     * A value shaped like a measurement: an optional sign, digits, an optional
     * fraction, and an optional unit of at most 8 letters (`120ms`, `98%`,
     * `1.2GB`, `4200`).
     *
     * A whitelist rather than a "contains a digit" test, because everything it
     * lets through is offered to the model as a metric.
     */
    protected const string NUMERIC_LIKE = '/^[+-]?\d+(?:[.,]\d+)?\s?(?:%|[a-zA-Z\/µ°]{1,8})?$/u';

    /** The same shape with the unit REQUIRED, which is the strongest metric signal. */
    protected const string UNIT_SUFFIXED = '/^[+-]?\d+(?:[.,]\d+)?\s?(?:%|[a-zA-Z\/µ°]{1,8})$/u';

    /**
     * Hard cap on the returned list, whatever the page throws at it.
     *
     * Public because {@see StoreMonitorRequest} caps its bulk `metrics[]`
     * submission at this same bound: a request may not accept more metric rows
     * than this extractor could ever propose.
     */
    public const int MAX_CANDIDATES = 40;

    /**
     * Work bounds. Every one of them is reached only by a body far outside what
     * a monitored endpoint returns, and hitting one costs candidates rather than
     * correctness: the list is still ranked, capped and proved.
     */
    protected const int MAX_JSON_LEAVES = 2000;

    protected const int MAX_JSON_DEPTH = 16;

    protected const int MAX_TEXT_NODES = 20000;

    /**
     * Path generation runs several whole-document XPath queries per candidate,
     * so the number of collapsed representatives that reach it is bounded too.
     */
    protected const int MAX_HTML_REPRESENTATIVES = 120;

    protected const int MAX_ANCESTOR_CLIMB = 8;

    protected const int MAX_CLASS_TOKENS = 8;

    protected const int MAX_ANCHOR_VALUE_LENGTH = 64;

    protected const int LABEL_HINT_MAX_LENGTH = 48;

    protected const int LABEL_ANCESTOR_LEVELS = 3;

    /**
     * Ranking weights. Anchor stability dominates, because a path that stops
     * resolving is worse than a path that is merely long; the value signals then
     * separate a measurement from list numbering.
     */
    protected const int WEIGHT_ID = 100;

    protected const int WEIGHT_ID_DESCENDED = 70;

    protected const int WEIGHT_CLASS = 60;

    protected const int WEIGHT_ATTRIBUTE = 50;

    protected const int WEIGHT_TAG = 20;

    protected const int WEIGHT_POSITIONAL = 0;

    /**
     * A header name IS the path, so no markup edit can ever break it: as an
     * anchor it is the most stable one here. It still sits at the tag anchor's
     * weight rather than the id's, because a perfectly stable path to
     * `content-type` is a stable path to something that is not a measurement.
     * The value signals below are what should decide whether `x-runtime: 0.024`
     * outranks a number on the page, and this weight is deliberately low enough
     * that a metadata string does not crowd real page candidates out of the cap.
     */
    protected const int WEIGHT_HEADER = 20;

    protected const int WEIGHT_UNIT_SUFFIX = 40;

    protected const int WEIGHT_NUMERIC = 25;

    protected const int PENALTY_LONG_TEXT = 25;

    /**
     * What a JSON key is worth, on top of what its value is worth.
     *
     * These three exist because scoring the VALUE alone cannot rank a health
     * payload. `"degraded"` and `"8.5.6"` are both short strings that score
     * zero, so the sort fell through to document order, and document order is
     * an authoring accident: a real 82-leaf response (kept as
     * `tests/fixtures/content/health-endpoint-wide.json`) declares `storage`
     * last, so the 40-candidate cap offered the model `php_version`,
     * `commit_subject` and `branch` while dropping `checks.storage.status`,
     * which was reading `degraded` at that moment. The model can only ever
     * select among what it is shown, so a cap that drops the verdicts decides
     * the product's answer long before any prompt does.
     *
     * The three weights are ordered against the value weights on purpose:
     *
     * - A VERDICT outranks everything, including a unit-bearing measurement
     *   ({@see self::WEIGHT_UNIT_SUFFIX}), because a service that publishes its
     *   own health per subsystem has already done the inference we would
     *   otherwise ask a model to do from numbers. There are only ever a handful
     *   of these, so they cannot crowd a payload out.
     * - A SIGNAL sits just above a unit-bearing measurement, so a count of jobs
     *   that failed today outranks how long a health check took to run. Both are
     *   bare integers, so nothing about the value can separate them.
     * - IDENTITY is penalised BELOW a plain number rather than excluded, because
     *   the words are heuristics and a small payload should still offer them
     *   rather than nothing. A version, a commit or a driver name is constant
     *   between checks, so a metric built on one records the same value forever
     *   and can never band.
     */
    protected const int WEIGHT_VERDICT_KEY = 90;

    protected const int WEIGHT_SIGNAL_KEY = 45;

    protected const int PENALTY_IDENTITY_KEY = 35;

    /**
     * Key tokens naming a service's own verdict about itself.
     *
     * Deliberately tiny. Every entry here outranks every measurement, so a word
     * that is only sometimes a verdict would quietly evict real numbers from the
     * cap on every payload that happens to use it.
     */
    protected const array VERDICT_KEY_TOKENS = [
        'status',
        'state',
        'health',
        'healthy',
    ];

    /**
     * Key tokens naming something that goes wrong, fills up, or falls behind.
     *
     * The test for membership is "does a rising or falling reading here mean
     * the operator has a problem". `latency` and `duration` are deliberately
     * ABSENT: they are the measurements this list is meant to outrank, and a
     * sub-millisecond health-check duration is the single least actionable
     * number a health payload carries.
     */
    protected const array SIGNAL_KEY_TOKENS = [
        'failed',
        'failures',
        'error',
        'errors',
        'evicted',
        'rejected',
        'dropped',
        'timeout',
        'timeouts',
        'pending',
        'backlog',
        'queued',
        'lag',
        'wait',
        'age',
        'percent',
        'usage',
        'used',
        'free',
        'remaining',
        'unwritable',
    ];

    /**
     * Key tokens naming what a deployment IS rather than how it is doing.
     *
     * `name` and `id` are deliberately ABSENT even though they are identity too:
     * they are also the commonest key in a metrics payload (`{"name": "rps",
     * "value": 12}`), so penalising them would demote the value beside them on
     * every payload shaped that way.
     */
    protected const array IDENTITY_KEY_TOKENS = [
        'version',
        'commit',
        'branch',
        'revision',
        'sha',
        'driver',
        'connection',
        'client',
        'server',
        'store',
        'path',
        'url',
        'endpoint',
        'hostname',
    ];

    /**
     * Elements that are never an anchor: their subtree is the whole document, so
     * their text equals a value only in a trivially small body and the path
     * stops meaning the value the moment anything else is added to the page.
     */
    protected const array STRUCTURAL_TAGS = [
        'html',
        'head',
        'body',
    ];

    /** Attributes that identify an element on purpose, so anchoring on one is safe. */
    protected const array ANCHOR_ATTRIBUTES = [
        'data-testid',
        'data-test-id',
        'data-test',
        'data-metric',
        'data-key',
        'itemprop',
        'name',
        'aria-label',
    ];

    /**
     * Leading segments of the Tailwind/Wind utility vocabulary.
     *
     * A utility class reads like a stable name and is not one: the pages this
     * runs against are styled entirely in utilities, so `font-bold` would give a
     * selector that silently stops resolving after any restyle. Tokens carrying
     * a digit, a colon or a bracket (`text-blue-400`, `md:flex`, `w-[32px]`) are
     * already refused by the charset gate, so this list only needs the families
     * whose tokens are letters and dashes.
     */
    protected const array UTILITY_CLASS_HEADS = [
        'absolute', 'accent', 'align', 'animate', 'antialiased', 'appearance', 'aspect', 'backdrop', 'basis', 'bg',
        'blur', 'border', 'bottom', 'break', 'brightness', 'capitalize', 'caret', 'clear', 'col', 'columns',
        'container', 'content', 'contents', 'contrast', 'cursor', 'dark', 'decoration', 'delay', 'disabled',
        'divide', 'drop', 'duration', 'ease', 'even', 'filter', 'first', 'fixed', 'flex', 'float', 'font', 'from',
        'gap', 'grayscale', 'grid', 'group', 'grow', 'hidden', 'hover', 'hyphens', 'indent', 'inline', 'inset',
        'invert', 'invisible', 'isolate', 'italic', 'items', 'justify', 'last', 'leading', 'left', 'list',
        'lowercase', 'max', 'min', 'motion', 'object', 'odd', 'opacity', 'order', 'origin', 'outline', 'overflow',
        'overscroll', 'peer', 'place', 'pointer', 'print', 'prose', 'relative', 'resize', 'right', 'ring',
        'rotate', 'rounded', 'row', 'saturate', 'scale', 'scroll', 'select', 'sepia', 'shadow', 'shrink', 'size',
        'skew', 'snap', 'space', 'static', 'sticky', 'table', 'text', 'top', 'touch', 'tracking', 'transform',
        'transition', 'translate', 'truncate', 'underline', 'uppercase', 'visible', 'whitespace', 'will',
    ];

    /**
     * Every extraction target in `$body` and `$allowedHeaders` that this class
     * has proved evaluable, ranked best first and capped.
     *
     * `$allowedHeaders` defaults to empty and that default is load-bearing: the
     * candidate-browser endpoint mines an archived body that arrives with no
     * headers at all, and it must keep behaving exactly as it did. A caller
     * that HAS headers passes {@see ProbeHeaderAllowList::filter()}'s output and
     * never the raw set; see the class docblock for why that is a security
     * boundary rather than a preference.
     *
     * @param  array<string, mixed>  $allowedHeaders  Allowlisted response headers,
     *                                                lowercase names to values.
     * @return list<MetricCandidate>
     */
    public function extract(string $body, array $allowedHeaders = []): array
    {
        // 1. The headers first, and independently of the body: a probe can
        //    return a `content-length` worth measuring with a body the edge
        //    filtered out entirely.
        $found = $this->headerCandidates($allowedHeaders);

        if (trim($body) !== '') {
            // 2. Sniff the structure instead of trusting a content-type header
            //    the monitored target controls: a decodable JSON document is
            //    the stronger and cheaper source, and it never needs the DOM.
            $decoded = $this->decodeJson($body);

            $found = [
                ...$found,
                ...($decoded !== null ? $this->jsonCandidates($decoded) : $this->htmlCandidates($body)),
            ];
        }

        // 3. Rank before capping, so the single worthwhile value on a page full
        //    of list numbering is not the one the cap discards.
        return $this->rank($found);
    }

    /**
     * One candidate per allowlisted header worth measuring.
     *
     * The path is the header NAME, which is the whole of the dialect
     * {@see MetricExtractor::extractHeader()} evaluates: it lowercases both
     * sides of a map lookup, so a name taken from the filtered map resolves
     * against the raw-cased map the check pipeline holds. There is no
     * re-extraction proof here because there is no expression to prove; what
     * has to be proved instead is that the SAMPLE this candidate advertises is
     * the value a check would really read, and the two refusals below are that
     * proof:
     *
     *   - A value at {@see ProbeHeaderAllowList::VALUE_MAX_LENGTH} is refused as
     *     suspect. `filter()` caps a value at 256 characters and joins a
     *     repeated header with `', '`, while `extractHeader()` does neither and
     *     returns what the target sent. A candidate proposed from a cut sample
     *     therefore advertises a value no check will ever produce, and it breaks
     *     {@see MetricCandidate}'s contract that `sampleValue` is the full
     *     extracted value. Deliberately conservative: a legitimate header of
     *     exactly 256 characters is indistinguishable from a cut one here, so it
     *     is dropped too.
     *   - A non-string value is refused. `extractHeader()` casts with
     *     `(string) $value`, and on an array that raises the warning Laravel
     *     rethrows as an `ErrorException` inside the check job.
     *
     * @param  array<string, mixed>  $allowedHeaders
     * @return list<array{source: MetricSource, path: string, value: string, label: ?string, score: int}>
     */
    protected function headerCandidates(array $allowedHeaders): array
    {
        $found = [];

        foreach ($allowedHeaders as $rawName => $value) {
            $name = strtolower(trim((string) $rawName));

            if ($name === '' || ! is_string($value) || trim($value) === '') {
                continue;
            }

            if (mb_strlen($value) >= ProbeHeaderAllowList::VALUE_MAX_LENGTH) {
                continue;
            }

            $found[] = [
                'source' => MetricSource::Header,
                'path' => $name,
                // The UNtrimmed value: `extractHeader()` returns the header
                // verbatim, so a trimmed sample would fail the round trip the
                // candidate promises.
                'value' => $value,
                'label' => $this->normalizeLabel($name),
                'score' => self::WEIGHT_HEADER + $this->valueScore($value),
            ];
        }

        return $found;
    }

    /**
     * The compact digest of a candidate list, as the model receives it.
     *
     * @param  list<MetricCandidate>  $candidates
     */
    public function digest(array $candidates): string
    {
        return (string) json_encode(
            array_map(fn (MetricCandidate $candidate) => $candidate->toDigestRow(), $candidates),
            // Unescaped slashes matter: a positional path is mostly slashes and
            // escaping them would inflate the digest by a third. The substitute
            // flag matters more: the sample values are attacker-controlled text,
            // and one invalid byte would otherwise collapse the whole digest to
            // `false` and leave discovery with an empty candidate table.
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * Sort by score, cap, and only then assign refs.
     *
     * Refs are contiguous from `c1` in the order the model reads them, and the
     * sort is stable, so an identical body always yields an identical digest.
     * The discovery step maps a returned ref back to a candidate by position,
     * which only holds while that determinism does.
     *
     * @param  list<array{source: MetricSource, path: string, value: string, label: ?string, score: int}>  $found
     * @return list<MetricCandidate>
     */
    protected function rank(array $found): array
    {
        usort($found, fn (array $left, array $right) => $right['score'] <=> $left['score']);

        $candidates = [];
        foreach (array_slice($found, 0, self::MAX_CANDIDATES) as $index => $row) {
            $candidates[] = new MetricCandidate(
                ref: 'c'.($index + 1),
                source: $row['source'],
                extractionPath: $row['path'],
                sampleValue: $row['value'],
                labelHint: $row['label'],
                eligibleTypes: $this->eligibleTypes($row['value']),
                // The sample stays FULL and unmodified (the round-trip proof
                // compares against it verbatim); the unit rides alongside it.
                //
                // The value's own suffix wins, and the KEY is the fallback. A
                // suffix is proof (`120ms` records 120 in milliseconds); a key
                // is evidence, and it is the only evidence there is for the
                // shape that actually occurs in a JSON payload: `used_percent:
                // 81.39` writes the scale in the key and nowhere else. Without
                // it the bound derivation had no ceiling to respect and put the
                // warn bound for a disk at 81% at 244%.
                unit: MetricExtractor::splitUnit($row['value'])[1]
                    ?? $this->unitFromKey($row['source'], $row['path']),
            );
        }

        return $candidates;
    }

    /**
     * The metric types this sample can actually sustain.
     *
     * `numeric` is offered for a sample the check-time extractor can actually
     * reduce to a number, and for no other. {@see MetricExtractor::validateType()}
     * discards a non-numeric value under `MetricType::Numeric`, so a candidate
     * accepted as numeric on a sample that survives extraction as text would
     * extract on every check and record nothing.
     *
     * That is now two shapes rather than one. A bare `4200` qualifies, and so
     * does `120ms`, because {@see MetricExtractor::splitUnit()} strips a MAPPED
     * unit suffix ahead of the type gate. The condition is deliberately the
     * same function the check path calls: a suffix the map does not name (`12
     * widgets`) still leaves the candidate string-only, and the two sides
     * cannot drift into disagreeing about which is which.
     *
     * @return list<MetricType>
     */
    protected function eligibleTypes(string $value): array
    {
        return is_numeric($value) || MetricExtractor::splitUnit($value) !== null
            ? [MetricType::Numeric, MetricType::String]
            : [MetricType::String];
    }

    /**
     * The body decoded as JSON, or null when it is not a JSON document.
     *
     * @return array<array-key, mixed>|null
     */
    protected function decodeJson(string $body): ?array
    {
        $trimmed = ltrim($body);
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        // A bare scalar document is not a JSON body as far as this pipeline is
        // concerned: `MetricExtractor::extractJsonPath()` refuses anything that
        // does not decode to an array, so no path over it could ever resolve.
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Every scalar leaf of a decoded JSON body, collapsed and proved.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return list<array{source: MetricSource, path: string, value: string, label: ?string, score: int}>
     */
    protected function jsonCandidates(array $decoded): array
    {
        $leaves = [];
        $this->walkJson($decoded, '', 0, $leaves);

        $found = [];
        $seenGroups = [];

        foreach ($leaves as [$path, $value]) {
            // An array's elements are siblings under the same masked path, so
            // a 500-row collection contributes one representative per value
            // shape rather than 500 near-identical rows.
            //
            // The WHOLE path is masked, not just the parent. Under an array
            // that changes nothing (`items.0.latency` and `items.1.latency`
            // both reduce to `items.#.latency`), and under an OBJECT it is the
            // difference between a collapse and a silent loss: grouping on the
            // parent alone made two different keys that happen to read the same
            // number one row, and every failure counter on a healthy service
            // reads zero. Measured on `health-endpoint-wide.json`, six of its
            // ten zero-valued leaves were dropped and all six were counters, so
            // the healthier the service the fewer of its warning lights the
            // model was allowed to see.
            $group = $this->groupKey($this->maskDigits($path), $value);
            if (isset($seenGroups[$group])) {
                continue;
            }

            // A key holding a dot, or a leading `$` that the extractor's own
            // path normalization strips, addresses something else entirely once
            // the path is evaluated. The proof is what keeps such a path from
            // ever reaching the model.
            if (! $this->provesJson($decoded, $path, $value)) {
                continue;
            }

            $seenGroups[$group] = true;
            $found[] = [
                'source' => MetricSource::JsonPath,
                'path' => $path,
                'value' => $value,
                'label' => $this->jsonLabelHint($path),
                // JSON is the one source where a key travels with the value, so
                // it is the one source that can rank on meaning rather than on
                // shape. HTML and headers keep scoring the value alone: a text
                // node has no key, and a header name is already its own anchor
                // weight.
                'score' => $this->valueScore($value) + $this->pathScore($path),
            ];
        }

        return $found;
    }

    /**
     * Depth-first walk collecting `[dot path, stringified value]` for every
     * non-null scalar leaf.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<array{0: string, 1: string}>  $leaves
     */
    protected function walkJson(array $node, string $prefix, int $depth, array &$leaves): void
    {
        if ($depth > self::MAX_JSON_DEPTH) {
            return;
        }

        foreach ($node as $key => $value) {
            if (count($leaves) >= self::MAX_JSON_LEAVES) {
                return;
            }

            // Array indices become integer segments (`items.0.latency`), which
            // is exactly what `Arr::get()` addresses.
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $this->walkJson($value, $path, $depth + 1, $leaves);

                continue;
            }

            // A null leaf is indistinguishable from a missing path once
            // `Arr::get()` runs, so it can never be proved and is not offered.
            if ($value === null) {
                continue;
            }

            $stringified = $this->stringify($value);
            if (trim($stringified) === '') {
                continue;
            }

            $leaves[] = [$path, $stringified];
        }
    }

    /**
     * Re-read the path and require the same value back.
     *
     * @param  array<array-key, mixed>  $decoded
     */
    protected function provesJson(array $decoded, string $path, string $value): bool
    {
        $resolved = Arr::get($decoded, $this->normalizeJsonPath($path));

        return $resolved !== null
            && ! is_array($resolved)
            && $this->stringify($resolved) === $value;
    }

    /**
     * Drop a leading JSONPath root exactly as {@see MetricExtractor} does.
     *
     * Duplicated rather than called, because the extractor's copy is protected
     * and this proof has to run in the extractor's dialect: a body whose first
     * key is literally `$` would otherwise pass a proof performed on the raw
     * path and then resolve to a different key at check time.
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
     * The string form of a decoded JSON scalar, matching what the extractor
     * returns for the same leaf.
     */
    protected function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Everything above the last segment of a dot path, or `''` at the root.
     */
    protected function jsonParentPath(string $path): string
    {
        $lastDot = strrpos($path, '.');

        return $lastDot === false ? '' : substr($path, 0, $lastDot);
    }

    /**
     * The naming hint for a JSON leaf: its own key, or the nearest key above it
     * when the leaf sits directly in an array.
     */
    protected function jsonLabelHint(string $path): ?string
    {
        $segments = array_reverse(explode('.', $path));

        foreach ($segments as $segment) {
            if ($segment !== '' && ! ctype_digit($segment)) {
                return $this->normalizeLabel($segment);
            }
        }

        return null;
    }

    /**
     * Every proved XPath candidate in an HTML body.
     *
     * @return list<array{source: MetricSource, path: string, value: string, label: ?string, score: int}>
     */
    protected function htmlCandidates(string $body): array
    {
        // 1. Parse exactly as `MetricExtractor::extractXpath()` does. Any
        //    divergence in the flags or in the document construction would make
        //    a path that resolves here resolve to something else there.
        $document = new DOMDocument;
        $loaded = @$document->loadHTML($body, LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $texts = @$xpath->query(self::TEXT_NODE_QUERY);
        if ($texts === false) {
            return [];
        }

        // 2. Collapse before generating paths, not after: generation runs
        //    several whole-document queries per candidate, so it must not run
        //    once per member of a seven-item enumeration.
        $found = [];
        foreach ($this->htmlRepresentatives($texts) as [$element, $value]) {
            $anchor = $this->generateXpath($xpath, $element, $value);
            if ($anchor === null) {
                continue;
            }

            $found[] = [
                'source' => MetricSource::Xpath,
                'path' => $anchor['expression'],
                'value' => $value,
                'label' => $this->labelHint($element),
                'score' => $anchor['weight'] + $this->valueScore($value),
            ];
        }

        return $found;
    }

    /**
     * One representative element per (parent, digit-masked value shape) group.
     *
     * @param  DOMNodeList<DOMNode>  $texts
     * @return list<array{0: DOMElement, 1: string}>
     */
    protected function htmlRepresentatives(DOMNodeList $texts): array
    {
        $representatives = [];
        $seenGroups = [];
        $scanned = 0;

        foreach ($texts as $text) {
            if ($scanned++ >= self::MAX_TEXT_NODES) {
                break;
            }
            if (count($representatives) >= self::MAX_HTML_REPRESENTATIVES) {
                break;
            }

            $value = trim((string) $text->nodeValue);
            if (! $this->isNumericLike($value)) {
                continue;
            }

            $element = $text->parentNode;
            if (! $element instanceof DOMElement) {
                continue;
            }

            // The extractor reads a matched node's WHOLE subtree text, so a
            // value that shares its element with other text is not addressable
            // at all and must not be offered as if it were.
            if (trim($element->textContent) !== $value) {
                continue;
            }

            // `getNodePath()` is used here as a GROUPING key only, never as an
            // emitted path: unconditional indices make it stable enough to
            // compare siblings by and too brittle to hand to a monitor.
            $group = $this->groupKey($element->parentNode?->getNodePath() ?? '', $value);
            if (isset($seenGroups[$group])) {
                continue;
            }

            $seenGroups[$group] = true;
            $representatives[] = [$element, $value];
        }

        return $representatives;
    }

    /**
     * The shortest expression that provably resolves to this element's value,
     * with the ranking weight of the anchor it used.
     *
     * Preference is id, then id plus a descent, then class, attribute, tag and
     * finally positional. Every branch is PROVED before it is returned, so a
     * shorter expression is never preferred over a correct one.
     *
     * @return array{expression: string, weight: int}|null
     */
    protected function generateXpath(DOMXPath $xpath, DOMElement $element, string $value): ?array
    {
        // 1. An id on an element whose OWN text is the value. Shortest possible,
        //    and anchored on the one attribute a restyle does not touch.
        $anchorId = $this->valueBearingId($element, $value);
        if ($anchorId !== null) {
            $expression = '//*[@id="'.$anchorId.'"]';
            if ($this->proves($xpath, $expression, $value)) {
                return ['expression' => $expression, 'weight' => self::WEIGHT_ID];
            }
        }

        // 2. An id further up plus positional steps down to the value. This is
        //    the branch the id qualification above makes necessary: an id on a
        //    wrapper reads back the wrapper's whole subtree text, so the
        //    expression has to descend to the element that holds the value
        //    alone.
        $descended = $this->idDescendedExpression($element);
        if ($descended !== null && $this->proves($xpath, $descended, $value)) {
            return ['expression' => $descended, 'weight' => self::WEIGHT_ID_DESCENDED];
        }

        // 3. A class token, but only one the stability gate accepts.
        foreach ($this->stableClassTokens($element) as $token) {
            $expression = '//'.$element->nodeName
                .'[contains(concat(" ", normalize-space(@class), " "), " '.$token.' ")]';
            if ($this->proves($xpath, $expression, $value)) {
                return ['expression' => $expression, 'weight' => self::WEIGHT_CLASS];
            }
        }

        // 4. An attribute whose whole purpose is identifying the element.
        foreach ($this->anchorAttributes($element) as $name => $attributeValue) {
            $expression = '//'.$element->nodeName.'[@'.$name.'="'.$attributeValue.'"]';
            if ($this->proves($xpath, $expression, $value)) {
                return ['expression' => $expression, 'weight' => self::WEIGHT_ATTRIBUTE];
            }
        }

        // 5. The tag alone, when the document holds exactly one of it.
        if (! in_array($element->nodeName, self::STRUCTURAL_TAGS, true)) {
            $expression = '//'.$element->nodeName;
            if ($this->proves($xpath, $expression, $value)) {
                return ['expression' => $expression, 'weight' => self::WEIGHT_TAG];
            }
        }

        // 6. Positional, indexed only where a same-tag sibling actually exists.
        $positional = $this->positionalExpression($element);
        if ($this->proves($xpath, $positional, $value)) {
            return ['expression' => $positional, 'weight' => self::WEIGHT_POSITIONAL];
        }

        // 7. Last resort only. `getNodePath()` indexes every step
        //    unconditionally, which is the brittle long chain every branch above
        //    exists to avoid, so it is reached only when nothing shorter proved.
        $fallback = $element->getNodePath();
        if ($fallback !== null && $this->proves($xpath, $fallback, $value)) {
            return ['expression' => $fallback, 'weight' => self::WEIGHT_POSITIONAL];
        }

        return null;
    }

    /**
     * Query the expression and require exactly one node holding exactly the
     * value.
     *
     * Both halves are the contract {@see MetricExtractor::extractXpath()} reads:
     * it takes `item(0)` and trims its whole subtree text. A second match would
     * make the extracted value depend on document order, and a subtree holding
     * more than the value would extract a different string on every check.
     */
    protected function proves(DOMXPath $xpath, string $expression, string $value): bool
    {
        $nodes = @$xpath->query($expression);
        if ($nodes === false || $nodes->length !== 1) {
            return false;
        }

        $node = $nodes->item(0);

        return $node !== null && trim($node->textContent) === $value;
    }

    /**
     * The nearest id, at or above this element, whose own subtree text is still
     * exactly the value.
     */
    protected function valueBearingId(DOMElement $element, string $value): ?string
    {
        $node = $element;

        for ($level = 0; $level < self::MAX_ANCESTOR_CLIMB && $node instanceof DOMElement; $level++) {
            if (in_array($node->nodeName, self::STRUCTURAL_TAGS, true)) {
                return null;
            }

            // Above this point the subtree carries more than the value, so an id
            // here would read back a different string.
            if (trim($node->textContent) !== $value) {
                return null;
            }

            $id = $this->stableId($node);
            if ($id !== null) {
                return $id;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * An id anchor on the nearest id-bearing ancestor, plus the positional steps
     * from it down to this element.
     */
    protected function idDescendedExpression(DOMElement $element): ?string
    {
        $steps = [];
        $node = $element;

        for ($level = 0; $level < self::MAX_ANCESTOR_CLIMB; $level++) {
            $parent = $node->parentNode;
            if (! $parent instanceof DOMElement) {
                return null;
            }

            $steps[] = $this->positionalStep($node);

            $id = in_array($parent->nodeName, self::STRUCTURAL_TAGS, true) ? null : $this->stableId($parent);
            if ($id !== null) {
                return '//*[@id="'.$id.'"]/'.implode('/', array_reverse($steps));
            }

            $node = $parent;
        }

        return null;
    }

    /**
     * The full positional path of an element from the document root.
     */
    protected function positionalExpression(DOMElement $element): string
    {
        $steps = [];
        $node = $element;

        while ($node instanceof DOMElement) {
            $steps[] = $this->positionalStep($node);
            $node = $node->parentNode;
        }

        return '/'.implode('/', array_reverse($steps));
    }

    /**
     * One step of a positional path, indexed ONLY where a same-tag sibling
     * actually exists.
     *
     * An unconditional index is what makes `getNodePath()`'s output break on any
     * markup edit above the value; omitting it where it carries no information
     * keeps the path both shorter and more durable.
     */
    protected function positionalStep(DOMElement $element): string
    {
        $tag = $element->nodeName;
        $parent = $element->parentNode;
        if ($parent === null) {
            return $tag;
        }

        $sameTag = 0;
        $ownIndex = 0;
        foreach ($parent->childNodes as $sibling) {
            if ($sibling instanceof DOMElement && $sibling->nodeName === $tag) {
                $sameTag++;
                if ($sibling === $element) {
                    $ownIndex = $sameTag;
                }
            }
        }

        return $sameTag > 1 ? $tag.'['.$ownIndex.']' : $tag;
    }

    /**
     * This element's id when it is safe to anchor on, null otherwise.
     */
    protected function stableId(DOMElement $element): ?string
    {
        $id = trim($element->getAttribute('id'));
        if ($id === '' || mb_strlen($id) > self::MAX_ANCHOR_VALUE_LENGTH) {
            return null;
        }

        // The charset gate does double duty: it rules out framework-generated
        // forms such as `:r1:`, and it guarantees no quote can reach the
        // generated expression.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_:.-]*$/', $id) !== 1) {
            return null;
        }

        // A per-render or hashed id is exactly as brittle as a utility class:
        // it stops resolving on the next deploy, and the metric then records
        // nothing rather than failing visibly.
        return $this->looksGenerated($id) ? null : $id;
    }

    /**
     * The class tokens on this element that may become selector components.
     *
     * @return list<string>
     */
    protected function stableClassTokens(DOMElement $element): array
    {
        $tokens = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stable = [];
        foreach (array_slice($tokens, 0, self::MAX_CLASS_TOKENS) as $token) {
            if ($this->isStableClassToken($token)) {
                $stable[] = $token;
            }
        }

        return $stable;
    }

    /**
     * The stability gate: does this class token describe the element, or the way
     * it currently happens to look?
     */
    protected function isStableClassToken(string $token): bool
    {
        // 1. Letters and single dashes only. Digits, colons, slashes and
        //    brackets are Tailwind's own vocabulary (`text-blue-400`,
        //    `md:flex`, `w-1/2`, `w-[32px]`), so the charset alone removes most
        //    of the utility space, and it keeps the token quotable in the
        //    generated expression.
        if (preg_match('/^[A-Za-z][A-Za-z-]{2,}$/', $token) !== 1) {
            return false;
        }

        // 2. Every dash- or camel-separated part must read like a word, which is
        //    what rejects a hashed build-time class (`kzXbqR`, `tw-xkcdfgh`)
        //    that no rebuild preserves.
        foreach (preg_split('/-|(?=[A-Z])/', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) <= 2 || $this->looksGenerated($word)) {
                return false;
            }
        }

        // 3. A utility family head reads like a name and is not one.
        return ! in_array(strtolower(explode('-', $token)[0]), self::UTILITY_CLASS_HEADS, true);
    }

    /**
     * Identifying attributes present on this element, in preference order.
     *
     * @return array<string, string>
     */
    protected function anchorAttributes(DOMElement $element): array
    {
        $anchors = [];

        foreach (self::ANCHOR_ATTRIBUTES as $name) {
            $value = trim($element->getAttribute($name));
            if ($value === '' || mb_strlen($value) > self::MAX_ANCHOR_VALUE_LENGTH) {
                continue;
            }

            // No quote may reach the generated expression, and a value carrying
            // anything beyond identifier punctuation is prose rather than an
            // identifier.
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:-]*$/', $value) !== 1) {
                continue;
            }

            $anchors[$name] = $value;
        }

        return $anchors;
    }

    /**
     * A short naming hint for the value, so the model has something to name the
     * metric after without being shown the page.
     */
    protected function labelHint(DOMElement $element): ?string
    {
        // 1. An explicit accessible name beats any guess from surrounding text.
        foreach (['aria-label', 'title'] as $attribute) {
            $explicit = $this->normalizeLabel($element->getAttribute($attribute));
            if ($explicit !== null) {
                return $explicit;
            }
        }

        // 2. Otherwise the nearest preceding text that is not itself a number:
        //    on a metric tile the label sits immediately before the value.
        $node = $element;
        for ($level = 0; $level < self::LABEL_ANCESTOR_LEVELS && $node instanceof DOMElement; $level++) {
            $sibling = $node->previousSibling;
            while ($sibling !== null) {
                $text = $this->normalizeLabel($sibling->textContent);
                if ($text !== null && ! $this->isNumericLike($text)) {
                    return $text;
                }
                $sibling = $sibling->previousSibling;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * Collapse whitespace, bound the length, and treat blank as absent.
     *
     * A hint longer than the bound is a block of prose rather than a label, so
     * it is dropped instead of truncated: half a paragraph would cost digest
     * bytes and name nothing.
     */
    protected function normalizeLabel(?string $text): ?string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        if ($normalized === '' || mb_strlen($normalized) > self::LABEL_HINT_MAX_LENGTH) {
            return null;
        }

        return $normalized;
    }

    /**
     * How much this value looks like a metric rather than a page number or a
     * paragraph.
     */
    protected function valueScore(string $value): int
    {
        $score = 0;

        // A unit suffix is the strongest signal that a human put a MEASUREMENT
        // on the page rather than a count, an index or a year.
        if (preg_match(self::UNIT_SUFFIXED, $value) === 1) {
            $score += self::WEIGHT_UNIT_SUFFIX;
        } elseif (is_numeric($value)) {
            $score += self::WEIGHT_NUMERIC;
        }

        if (mb_strlen($value) > MetricCandidate::DIGEST_VALUE_MAX_LENGTH) {
            $score -= self::PENALTY_LONG_TEXT;
        }

        return $score;
    }

    /**
     * What this leaf's KEY says about how much the reading under it is worth
     * watching.
     *
     * Read off the last path segment only. A parent segment is a grouping
     * (`checks`, `details`, `queue`) rather than a claim about the leaf, and
     * letting it contribute would give `checks.status.duration_ms` a verdict's
     * weight for being filed under one.
     *
     * The segment is split on the separators a JSON key actually uses, so
     * `failed_recent`, `usedPercent` and `longest-wait-seconds` all reduce to
     * tokens the tables can match. Matching is exact per token and never a
     * substring: `clients` is not `client`, which is what keeps
     * `connected_clients` (a real gauge) out of the identity penalty that
     * `client: "phpredis"` earns.
     *
     * The three tables are mutually exclusive by construction, but the order is
     * still fixed rather than summed. A key matching two tables is a key we do
     * not understand, and taking the first match keeps the outcome readable
     * instead of landing on an arithmetic middle nobody chose.
     */
    protected function pathScore(string $path): int
    {
        $tokens = self::keyTokens($path);

        if (array_intersect($tokens, self::VERDICT_KEY_TOKENS) !== []) {
            return self::WEIGHT_VERDICT_KEY;
        }

        if (array_intersect($tokens, self::SIGNAL_KEY_TOKENS) !== []) {
            return self::WEIGHT_SIGNAL_KEY;
        }

        if (array_intersect($tokens, self::IDENTITY_KEY_TOKENS) !== []) {
            return -self::PENALTY_IDENTITY_KEY;
        }

        return 0;
    }

    /**
     * The unit a JSON key names outright, or null when it names none.
     *
     * Deliberately only the two BOUNDED scales. They are the ones where the
     * unit changes an outcome rather than a label: {@see MetricDiscoveryService}
     * derives a bound toward a ceiling when it knows there is one, and a
     * multiplied bound on a percentage lands somewhere no reading can go. Byte
     * and duration keys (`latency_ms`, `used_memory_mb`) are left alone on
     * purpose: naming them would change how a value is FORMATTED without the
     * value having been parsed that way, and `81.39` is not `81.39 MB` just
     * because a key says so.
     *
     * Restricted to a JSON path, where a key is a real key. An HTML candidate's
     * path is an XPath expression and a header's is a header name; neither is a
     * place a service writes a unit down.
     */
    protected function unitFromKey(MetricSource $source, string $path): ?MetricUnit
    {
        if ($source !== MetricSource::JsonPath) {
            return null;
        }

        $tokens = self::keyTokens($path);

        if (array_intersect($tokens, ['percent', 'pct']) !== []) {
            return MetricUnit::Percent;
        }

        if (in_array('ratio', $tokens, true)) {
            return MetricUnit::Ratio;
        }

        return null;
    }

    /**
     * Whether this JSON key names a service's own verdict about itself.
     *
     * Public and static because the ranking is not the only thing that needs the
     * answer: {@see MetricDiscoveryService::verdictRows()} proposes a metric on
     * a verdict field directly, and the two have to agree about what a verdict
     * IS or the field that leads the candidate table is not the field that gets
     * proposed.
     */
    public static function namesAVerdict(string $path): bool
    {
        return array_intersect(self::keyTokens($path), self::VERDICT_KEY_TOKENS) !== [];
    }

    /**
     * The lowercased word tokens of a dot path's last segment.
     *
     * @return list<string>
     */
    protected static function keyTokens(string $path): array
    {
        $segments = explode('.', $path);
        $leaf = (string) end($segments);

        // camelCase before the split, so `usedPercent` yields two tokens rather
        // than one that matches nothing.
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $leaf);

        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($spaced)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));
    }

    /**
     * Does this value have the shape of a measurement?
     */
    protected function isNumericLike(string $value): bool
    {
        return preg_match(self::NUMERIC_LIKE, $value) === 1;
    }

    /**
     * Does this token look machine-generated rather than authored?
     *
     * Modelled on finder's `wordLike()` vowel-density heuristic: a hex run, a
     * long digit run, or a consonant run no language produces are all signs of
     * a token that changes on the next build.
     */
    protected function looksGenerated(string $token): bool
    {
        return preg_match('/^[0-9a-f]{8,}$/i', $token) === 1
            || preg_match('/\d{5,}/', $token) === 1
            || preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/i', $token) === 1;
    }

    /**
     * The collapse key: same parent and same digit-masked value shape means the
     * same enumeration.
     */
    protected function groupKey(string $parent, string $value): string
    {
        return $parent.'|'.$this->maskDigits($value);
    }

    /**
     * Replace every digit run with a single marker, so `1`, `2` and `25` share
     * one shape while `120ms` and `98%` do not.
     */
    protected function maskDigits(string $value): string
    {
        return (string) preg_replace('/\d+/', '#', $value);
    }
}
