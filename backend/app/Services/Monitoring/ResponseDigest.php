<?php

namespace App\Services\Monitoring;

use App\Enums\BodyShape;
use App\Services\Ai\AnalysisPayload;
use App\Support\Monitoring\MetricCandidate;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Generator;

/**
 * Renders a captured response body into a compact, structure-preserving digest
 * for the monitor-setup prompt.
 *
 * The body is attacker-authored, up to 1 MiB of it, so this class emits SHAPE
 * and never a copy: paths, types, counts, and short capped sample values. Page
 * prose, script contents and comment bodies that carry no diagnostic signal are
 * dropped rather than truncated, because a digest is what the model reasons
 * from and every character of it is a character an author chose.
 *
 * Three properties are load-bearing:
 *
 *   - JSON paths are emitted in the dot notation
 *     {@see MetricExtractor::extractJsonPath()} evaluates, so a metric proposal
 *     built from the digest is directly expressible. The XML skeleton
 *     deliberately does NOT look like a path: `MetricExtractor` evaluates XML
 *     through `loadHTML`, which lowercases tag names and wraps the document, so
 *     an absolute-looking `/urlset/url/loc` would not resolve there and would
 *     read as a promise this pipeline cannot keep.
 *   - The shape is sniffed from the bytes, never from `content-type`, which the
 *     target controls. {@see MetricCandidateExtractor::decodeJson()} is the
 *     precedent.
 *   - The character budget binds on whole lines. A cut path is worse than a
 *     missing one: the model cannot tell the difference, and a proposal built
 *     from half a path extracts nothing on every check.
 *
 * The bounded-walk discipline is copied from {@see MetricCandidateExtractor},
 * which is deliberately left untouched: it answers a different question (which
 * paths are PROVEN extractable) and is the security boundary of metric
 * proposal, while this class only describes.
 */
class ResponseDigest
{
    /**
     * Appended when the digest describes less than the whole body.
     *
     * It says "subtrees" rather than "budget" because the budget is only one of
     * the two ways a subtree goes missing; the structural caps below are the
     * other, and the model must not read a capped digest as a complete one.
     */
    public const string TRUNCATION_MARKER = '[truncated: subtrees dropped, this digest describes part of the body]';

    /** Appended to a sample value that was cut, so the cut is visible. */
    public const string SAMPLE_TRUNCATION_MARK = '…';

    /**
     * Character ceiling on a single sample value.
     *
     * Matches {@see MetricCandidate::DIGEST_VALUE_MAX_LENGTH},
     * and sits an order of magnitude inside
     * {@see AnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH}, the per-field cap the
     * prompt fence applies anyway: a longer sample could only ever be cut again
     * somewhere it cannot be marked as cut.
     */
    public const int SAMPLE_MAX_LENGTH = 128;

    /**
     * Used when `ai.digest.max_characters` is absent.
     *
     * Public because the CONSUMER of a digest needs the same fallback: two
     * classes reading one key with different defaults would quietly truncate the
     * digest the moment the key went missing.
     * {@see AnalysisPayload::digestBudget()}.
     */
    public const int DEFAULT_BUDGET = 8000;

    /**
     * Work bounds. Each one costs description rather than correctness, and each
     * one that binds sets the truncation flag, so a capped digest never poses as
     * a complete one.
     */
    protected const int MAX_DEPTH = 8;

    /**
     * Distinct children walked per container.
     *
     * This is the bound that keeps the digest USEFUL rather than merely small.
     * A breadth-first walk of a map with a thousand distinct keys spends the
     * whole budget on sibling headers two levels up and never reaches a leaf,
     * and a leaf path is the only thing a metric proposal can be built from.
     *
     * Twenty rather than a rounder ten: a statuspage.io component object (the
     * shape behind a large share of the public status APIs this product is
     * pointed at) carries thirteen keys, and a cap that clips a real API's last
     * field costs evidence for no gain. A 1 MiB body still reaches its fourth
     * level at this width.
     */
    protected const int MAX_CHILDREN_PER_NODE = 20;

    protected const int MAX_NODES = 2000;

    /** Bytes of the body head the shape sniff looks at. */
    protected const int SNIFF_WINDOW = 2048;

    protected const int MAX_HEADINGS = 20;

    protected const int MAX_META = 12;

    protected const int MAX_FOOTER_COMMENTS = 3;

    /** Comment nodes scanned before the search for a footer gives up. */
    protected const int MAX_COMMENTS_SCANNED = 500;

    /** `<meta name>` values worth reading: what built the page, and what it says it is. */
    protected const array DIAGNOSTIC_META_NAMES = [
        'generator',
        'description',
    ];

    /**
     * Substrings that mark an HTML comment as a debug or cache footer.
     *
     * A cache footer is often the only place a page names the stack that served
     * it and how long it took, which is exactly the evidence the setup prompt
     * cannot get anywhere else. Everything unlisted is dropped: a comment is
     * author-controlled text, so this is an allowlist of signals rather than a
     * denylist of noise.
     */
    protected const array FOOTER_COMMENT_HINTS = [
        'cache',
        'cached',
        'generated',
        'generation',
        'served by',
        'render time',
        'debug',
        'queries',
        'execution time',
        'litespeed',
        'varnish',
        'w3tc',
    ];

    /**
     * The digest of a captured response body, at or under the configured budget.
     */
    public function digest(string $content): ResponseDigestResult
    {
        // Sniff the structure instead of trusting a content type the monitored
        // target controls, and decode once: the JSON walk needs the decoded
        // value, and a 1 MiB body is not worth decoding twice.
        $decoded = $this->decodeJson($content);
        if ($decoded !== null) {
            return $this->render(BodyShape::Json, $this->jsonLines($decoded));
        }

        return match ($this->sniffMarkup($content)) {
            BodyShape::Html => $this->render(BodyShape::Html, $this->htmlLines($content)),
            BodyShape::Xml => $this->render(BodyShape::Xml, $this->xmlLines($content)),
            default => $this->render(BodyShape::Unknown, $this->textLines($content)),
        };
    }

    /**
     * Consume a line stream until the budget binds, then close the digest.
     *
     * Lines arrive breadth-first, so the ones dropped when the budget binds are
     * the deepest and last-discovered subtrees, and a line is only ever taken
     * whole. The shape line is unconditional: it is sixteen characters and it is
     * what the rest of the digest is read against.
     *
     * @param  Generator<int, string, mixed, bool>  $lines  Returns true when it stopped short of the whole body.
     */
    protected function render(BodyShape $shape, Generator $lines): ResponseDigestResult
    {
        $budget = $this->budget();

        // The marker's own room is reserved up front, so appending it can never
        // be what pushes the digest over the budget.
        $reserved = mb_strlen(self::TRUNCATION_MARKER) + 1;

        $rendered = ['shape: '.$shape->value];
        $used = mb_strlen($rendered[0]);
        $budgetBound = false;

        foreach ($lines as $line) {
            $cost = mb_strlen($line) + 1;

            if ($used + $cost + $reserved > $budget) {
                $budgetBound = true;

                break;
            }

            $rendered[] = $line;
            $used += $cost;
        }

        // `getReturn()` is only legal once the generator finished, which it has
        // not when the budget broke the loop; in that case the digest is already
        // known to be partial.
        $truncated = $budgetBound || ($lines->valid() === false && $lines->getReturn() === true);

        if ($truncated) {
            $rendered[] = self::TRUNCATION_MARKER;
        }

        return new ResponseDigestResult(
            digest: implode("\n", $rendered),
            shape: $shape,
            truncated: $truncated,
        );
    }

    /**
     * The hard character budget for one digest.
     */
    protected function budget(): int
    {
        return (int) config('ai.digest.max_characters', self::DEFAULT_BUDGET);
    }

    /**
     * The body decoded as JSON, or null when it is not a JSON document.
     *
     * A bare scalar document is not one as far as this pipeline is concerned:
     * `MetricExtractor::extractJsonPath()` refuses anything that does not decode
     * to an array, so no path over it could ever resolve.
     *
     * @return array<array-key, mixed>|null
     */
    protected function decodeJson(string $content): ?array
    {
        $trimmed = ltrim($this->stripBom($content));
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether a non-JSON body is markup, and which dialect of it.
     */
    protected function sniffMarkup(string $content): ?BodyShape
    {
        $trimmed = ltrim($this->stripBom($content));
        if (! str_starts_with($trimmed, '<')) {
            return null;
        }

        // Byte-wise lowercasing on purpose: every token this looks for is ASCII,
        // and a window cut mid-character must not be able to affect the sniff.
        $window = strtolower(substr($trimmed, 0, self::SNIFF_WINDOW));

        // A page that announces itself as HTML is HTML, XHTML's XML declaration
        // included.
        if (str_contains($window, '<!doctype html') || preg_match('/<html[\s>]/', $window) === 1) {
            return BodyShape::Html;
        }

        if (str_starts_with($window, '<?xml')) {
            return BodyShape::Xml;
        }

        // A prefixed or namespaced root is the remaining XML signal. Everything
        // else falls to HTML deliberately: `loadHTML` degrades gracefully on
        // XML-ish input while `loadXML` refuses malformed markup outright, so
        // the forgiving parser is the safer default for an ambiguous body.
        $namespaced = preg_match('/^<[a-z0-9_.-]+:/', $window) === 1
            || preg_match('/^<[^>]*\sxmlns[:=]/', $window) === 1;

        return $namespaced ? BodyShape::Xml : BodyShape::Html;
    }

    /**
     * Drop a UTF-8 byte-order mark, which `json_decode` rejects outright and
     * which would otherwise make a JSON body sniff as unstructured text.
     */
    protected function stripBom(string $content): string
    {
        return str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
    }

    /**
     * The key skeleton of a decoded JSON body, breadth-first.
     *
     * An array collapses to its first element plus a count: a repetition is
     * fully described by one member and how many there are, and the alternative
     * is spending the budget on a target's own pagination.
     *
     * @param  array<array-key, mixed>  $decoded
     * @return Generator<int, string, mixed, bool>
     */
    protected function jsonLines(array $decoded): Generator
    {
        yield 'root: '.$this->containerLabel($decoded);

        /** @var list<array{0: string, 1: array<array-key, mixed>, 2: int}> $queue */
        $queue = [['', $decoded, 0]];
        $emitted = 0;
        $dropped = false;

        while ($queue !== []) {
            [$prefix, $node, $depth] = array_shift($queue);

            if ($depth >= self::MAX_DEPTH) {
                $dropped = true;

                continue;
            }

            $children = $this->jsonChildren($node);
            $dropped = $dropped || $this->isBreadthCapped($node);

            foreach ($children as $key => $value) {
                if ($emitted >= self::MAX_NODES) {
                    return true;
                }

                $emitted++;
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                if (is_array($value)) {
                    yield $path.': '.$this->containerLabel($value);
                    $queue[] = [$path, $value, $depth + 1];

                    continue;
                }

                yield $path.': '.$this->leafLabel($value);
            }
        }

        return $dropped;
    }

    /**
     * The children of a JSON container that are worth walking: the first element
     * of a list, or the first capped run of a map's keys.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    protected function jsonChildren(array $node): array
    {
        $keep = array_is_list($node) ? 1 : self::MAX_CHILDREN_PER_NODE;

        return array_slice($node, 0, $keep, preserve_keys: true);
    }

    /**
     * Whether a container lost DISTINCT children to the breadth cap.
     *
     * A collapsed list has not: its count states exactly what was left out, and
     * its members repeat one shape. A map with more keys than the cap has, and
     * those keys are evidence the digest no longer carries.
     *
     * @param  array<array-key, mixed>  $node
     */
    protected function isBreadthCapped(array $node): bool
    {
        return ! array_is_list($node) && count($node) > self::MAX_CHILDREN_PER_NODE;
    }

    /**
     * How a JSON container announces itself: its kind, its size, and whether the
     * walk below it is showing all of it.
     *
     * @param  array<array-key, mixed>  $node
     */
    protected function containerLabel(array $node): string
    {
        $count = count($node);

        if (array_is_list($node)) {
            return 'array('.$count.')';
        }

        return $count > self::MAX_CHILDREN_PER_NODE
            ? 'object('.$count.', first '.self::MAX_CHILDREN_PER_NODE.' shown)'
            : 'object('.$count.')';
    }

    /**
     * A JSON leaf as its type plus a short sample.
     *
     * The type is what a metric proposal has to pick, so it is stated rather
     * than left to be inferred from the sample's quoting.
     */
    protected function leafLabel(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean '.($value ? 'true' : 'false'),
            is_int($value) || is_float($value) => 'number '.$this->encode($value),
            default => 'string '.$this->quoted((string) $value),
        };
    }

    /**
     * What an HTML page says about itself: the head evidence, any diagnostic
     * footer comment, the form and script counts, then the heading skeleton.
     *
     * The order IS the priority order, which is what a bound budget drops from
     * the back of: a page's own counts survive a heading-heavy document, and
     * nothing from the body's prose is in the stream at all.
     *
     * @return Generator<int, string, mixed, bool>
     */
    protected function htmlLines(string $content): Generator
    {
        // Parsed exactly as `MetricExtractor::extractXpath()` parses: an HTML4
        // parser, errors and warnings suppressed at both levels, because libxml
        // writes its complaints to output and a warning inside a JSON API
        // response corrupts it. `LIBXML_NOENT | LIBXML_DTDLOAD` is never passed:
        // PHP is XXE-safe by default only while that pair stays absent.
        $document = new DOMDocument;
        $loaded = @$document->loadHTML($content, LIBXML_NOERROR | LIBXML_NOWARNING);
        if (! $loaded) {
            yield 'parse: failed';

            return true;
        }

        $xpath = new DOMXPath($document);

        $title = $this->firstText($xpath, '//title');
        if ($title !== null) {
            yield 'title: '.$this->collapsedQuoted($title);
        }

        $meta = $this->metaValues($xpath);
        foreach (array_slice($meta, 0, self::MAX_META, preserve_keys: true) as $name => $value) {
            yield 'meta.'.$name.': '.$this->collapsedQuoted($value);
        }

        foreach ($this->footerComments($xpath) as $comment) {
            yield 'comment: '.$this->collapsedQuoted($comment);
        }

        yield 'forms: '.$this->countNodes($xpath, '//form');
        yield 'scripts: '.$this->countNodes($xpath, '//script');

        $headings = $this->headings($xpath);
        foreach (array_slice($headings, 0, self::MAX_HEADINGS) as [$tag, $text]) {
            yield $tag.': '.$this->collapsedQuoted($text);
        }

        return count($meta) > self::MAX_META || count($headings) > self::MAX_HEADINGS;
    }

    /**
     * The diagnostic `<meta>` values, keyed by their lower-cased name.
     *
     * `og:*` is included because a page's OpenGraph block names the site and its
     * type in words its author chose for a machine, which is the closest thing a
     * marketing page has to a self-description.
     *
     * @return array<string, string>
     */
    protected function metaValues(DOMXPath $xpath): array
    {
        $nodes = @$xpath->query('//meta[@name or @property][@content]');
        if ($nodes === false) {
            return [];
        }

        $values = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $name = strtolower(trim($node->getAttribute('name') ?: $node->getAttribute('property')));
            $content = trim($node->getAttribute('content'));
            if ($name === '' || $content === '') {
                continue;
            }

            if (! in_array($name, self::DIAGNOSTIC_META_NAMES, true) && ! str_starts_with($name, 'og:')) {
                continue;
            }

            $values[$name] = $content;
        }

        return $values;
    }

    /**
     * HTML comments that read like a debug or cache footer.
     *
     * @return list<string>
     */
    protected function footerComments(DOMXPath $xpath): array
    {
        $nodes = @$xpath->query('//comment()');
        if ($nodes === false) {
            return [];
        }

        $found = [];
        $scanned = 0;
        foreach ($nodes as $node) {
            if ($scanned++ >= self::MAX_COMMENTS_SCANNED || count($found) >= self::MAX_FOOTER_COMMENTS) {
                break;
            }
            if (! $node instanceof DOMComment) {
                continue;
            }

            $text = trim($node->textContent);
            if ($text === '' || ! $this->looksLikeFooter($text)) {
                continue;
            }

            $found[] = $text;
        }

        return $found;
    }

    /**
     * Does this comment carry a diagnostic signal, or is it authored noise?
     */
    protected function looksLikeFooter(string $text): bool
    {
        $lowered = strtolower($text);

        foreach (self::FOOTER_COMMENT_HINTS as $hint) {
            if (str_contains($lowered, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The heading skeleton in document order, as `[tag, text]` pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function headings(DOMXPath $xpath): array
    {
        $nodes = @$xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        if ($nodes === false) {
            return [];
        }

        $headings = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = trim($node->textContent);
            if ($text === '') {
                continue;
            }

            $headings[] = [$node->nodeName, $text];
        }

        return $headings;
    }

    /**
     * The tag skeleton of an XML body, breadth-first, with same-named siblings
     * collapsed to one entry plus their count.
     *
     * The separator is ` > ` rather than a slash on purpose: this is a shape,
     * not a selector, and an XPath-looking string would read as a promise that
     * {@see MetricExtractor::extractXpath()} cannot keep, since it parses XML
     * through `loadHTML` and would see different tag names.
     *
     * @return Generator<int, string, mixed, bool>
     */
    protected function xmlLines(string $content): Generator
    {
        // `loadXML` with errors and warnings suppressed, and never
        // `LIBXML_NOENT | LIBXML_DTDLOAD`: that pair is what turns PHP's
        // XXE-safe default off, and this body came from an arbitrary target.
        $document = new DOMDocument;
        $loaded = @$document->loadXML($content, LIBXML_NOERROR | LIBXML_NOWARNING);
        $root = $loaded ? $document->documentElement : null;
        if ($root === null) {
            yield 'parse: failed';

            return true;
        }

        yield 'root: '.$root->nodeName;
        yield 'namespace: '.($root->namespaceURI ?? 'none');

        /** @var list<array{0: string, 1: DOMElement, 2: int}> $queue */
        $queue = [[$root->nodeName, $root, 0]];
        $emitted = 0;
        $dropped = false;

        while ($queue !== []) {
            [$path, $element, $depth] = array_shift($queue);

            if ($depth >= self::MAX_DEPTH) {
                $dropped = true;

                continue;
            }

            $groups = $this->collapsedChildren($element);
            $dropped = $dropped || count($groups) > self::MAX_CHILDREN_PER_NODE;

            foreach (array_slice($groups, 0, self::MAX_CHILDREN_PER_NODE) as [$child, $count]) {
                if ($emitted >= self::MAX_NODES) {
                    return true;
                }

                $emitted++;
                $childPath = $path.' > '.$child->nodeName;
                $line = $count > 1 ? $childPath.' ('.$count.')' : $childPath;

                if ($this->hasElementChildren($child)) {
                    yield $line;
                    $queue[] = [$childPath, $child, $depth + 1];

                    continue;
                }

                $text = trim($child->textContent);

                yield $text === '' ? $line : $line.': '.$this->collapsedQuoted($text);
            }
        }

        return $dropped;
    }

    /**
     * One representative child element per distinct tag name, with how many
     * siblings share that name.
     *
     * @return list<array{0: DOMElement, 1: int}>
     */
    protected function collapsedChildren(DOMElement $element): array
    {
        $groups = [];

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (isset($groups[$child->nodeName])) {
                $groups[$child->nodeName][1]++;

                continue;
            }

            $groups[$child->nodeName] = [$child, 1];
        }

        return array_values($groups);
    }

    protected function hasElementChildren(DOMNode $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    /**
     * What can honestly be said about a body with no structure to walk: how big
     * it is, and a short sample.
     *
     * A plain-text `OK` is a real health endpoint, so this is the difference
     * between the model knowing what the service answered and knowing nothing.
     *
     * @return Generator<int, string, mixed, bool>
     */
    protected function textLines(string $content): Generator
    {
        yield 'bytes: '.strlen($content);

        $trimmed = trim($content);
        if ($trimmed !== '') {
            yield 'sample: '.$this->quoted($trimmed);
        }

        return false;
    }

    /**
     * The text of the first node matching `$query`, or null when there is none.
     */
    protected function firstText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = @$xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim((string) $nodes->item(0)?->textContent);

        return $text === '' ? null : $text;
    }

    protected function countNodes(DOMXPath $xpath, string $query): int
    {
        $nodes = @$xpath->query($query);

        return $nodes === false ? 0 : $nodes->length;
    }

    /**
     * A markup-sourced value as a quoted sample, with its layout whitespace
     * collapsed: a heading in an indented document carries newlines that say
     * nothing about the page and would cost a third of the value's cap.
     */
    protected function collapsedQuoted(string $value): string
    {
        return $this->quoted(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /**
     * A sample value, capped and rendered as a JSON string.
     *
     * JSON-encoded rather than merely quoted, for the reason
     * {@see AnalysisPayload} encodes its whole untrusted half: an escaped
     * newline cannot start a line, so a value cannot pose as a digest line, and
     * one invalid byte cannot collapse the value to nothing.
     */
    protected function quoted(string $value): string
    {
        return $this->encode($this->truncate($value));
    }

    /**
     * Cut a sample to the per-value cap, marking the cut.
     */
    protected function truncate(string $value): string
    {
        return mb_strlen($value) > self::SAMPLE_MAX_LENGTH
            ? mb_substr($value, 0, self::SAMPLE_MAX_LENGTH).self::SAMPLE_TRUNCATION_MARK
            : $value;
    }

    /**
     * Compactly encode a scalar for a single digest line.
     */
    protected function encode(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        // Compared against `false` rather than coerced: `json_encode(0)` returns
        // the string `"0"`, which is falsy, and a truthiness check would render
        // every zero on a health payload as an empty value. Zero is the most
        // common reading on one.
        return $encoded === false ? '""' : $encoded;
    }
}
