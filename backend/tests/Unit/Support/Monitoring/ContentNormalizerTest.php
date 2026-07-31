<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\ContentNormalizer;
use Tests\TestCase;

/**
 * Pins `ContentNormalizer`'s two contracts: token-shaped noise must stop
 * churning the change signal, and a normalization that cannot run must fail
 * OPEN to the raw bytes.
 *
 * The dedupe claim is proven against the two frozen captures of
 * `https://fluttersdk.com`, which differ ONLY by a per-request 40-character
 * CSRF token appearing twice. Raw-byte hashing dedupes 0% of them; the
 * measured rules take that to 100%. `edited-heading.html` is the same page
 * with one `<h1>` reworded and the SAME token as capture 1, so a moving
 * normalized hash there can only come from the real content edit.
 *
 * The fail-open half matters more than it looks: if a failed substitution
 * chain collapsed to the empty string, `sha256('')` would become a monitor's
 * permanent normalized hash, every later check would read "unchanged", and the
 * archive would silently stop after one version with nothing in the logs.
 */
class ContentNormalizerTest extends TestCase
{
    /** Byte size of both frozen captures; a guard against a truncated fixture. */
    protected const int FIXTURE_BYTES = 182349;

    /** The CSRF token carried by capture 1 and by `edited-heading.html`. */
    protected const string FIXTURE_ONE_TOKEN = 'GGFg7tNEUnbSMQ22uUnWuGK5FVAejMlhVv2Wid2x';

    /** The CSRF token carried by capture 2, the only difference from capture 1. */
    protected const string FIXTURE_TWO_TOKEN = 'jScUHRHoAlcCDCjkGNPXj0Ayj20T73OGQeIVNAmO';

    /**
     * Two fetches of the same unchanged page hash differently raw and
     * identically normalized. This is the 0%-to-100% dedupe claim.
     */
    public function test_two_captures_of_one_page_differ_raw_and_agree_normalized(): void
    {
        $bodyOne = $this->fixture('fluttersdk-home-1.html');
        $bodyTwo = $this->fixture('fluttersdk-home-2.html');

        // Guard: an empty or truncated fixture would make the claims below
        // measure nothing.
        $this->assertSame(self::FIXTURE_BYTES, strlen($bodyOne));
        $this->assertSame(self::FIXTURE_BYTES, strlen($bodyTwo));

        $one = ContentNormalizer::normalize($bodyOne);
        $two = ContentNormalizer::normalize($bodyTwo);

        $this->assertNotSame($one->rawHash, $two->rawHash);
        $this->assertSame($one->normalizedHash, $two->normalizedHash);
        $this->assertFalse($one->normalizationFailed);
        $this->assertFalse($two->normalizationFailed);

        // The raw hash addresses the bytes that were served, so it must NOT be
        // the hash of the normalized subject.
        $this->assertSame(hash('sha256', $bodyOne), $one->rawHash);
        $this->assertNotSame($one->rawHash, $one->normalizedHash);
    }

    /**
     * A real content edit moves the normalized hash, proving the rules are not
     * broad enough to hide a change.
     */
    public function test_an_edited_heading_moves_the_normalized_hash(): void
    {
        $bodyOne = $this->fixture('fluttersdk-home-1.html');
        $edited = $this->fixture('edited-heading.html');

        // The edited fixture carries capture 1's token verbatim, so the only
        // thing that can move the normalized hash is the heading itself.
        $this->assertStringContainsString(self::FIXTURE_ONE_TOKEN, $bodyOne);
        $this->assertStringContainsString(self::FIXTURE_ONE_TOKEN, $edited);
        $this->assertStringNotContainsString(self::FIXTURE_TWO_TOKEN, $edited);
        $this->assertStringContainsString('Your concept is', $edited);
        $this->assertStringNotContainsString('Your concept is', $bodyOne);

        $one = ContentNormalizer::normalize($bodyOne);
        $editedResult = ContentNormalizer::normalize($edited);

        $this->assertNotSame($one->normalizedHash, $editedResult->normalizedHash);
        $this->assertNotSame($one->rawHash, $editedResult->rawHash);
        $this->assertFalse($editedResult->normalizationFailed);
    }

    /**
     * Invalid UTF-8 makes `preg_replace` return null under `/u`, and that must
     * surface as a flagged failure over the RAW bytes, never as an empty
     * subject.
     */
    public function test_invalid_utf8_fails_open_to_the_raw_bytes(): void
    {
        $valid = '<html><meta name="csrf-token" content="'.str_repeat('a', 40).'"></html>';
        $invalid = '<html><meta name="csrf-token" content="'.str_repeat('a', 40).'">'
            .chr(0xFF).chr(0xFE).'</html>';

        $failed = ContentNormalizer::normalize($invalid);

        $this->assertTrue($failed->normalizationFailed);
        $this->assertSame(hash('sha256', $invalid), $failed->rawHash);
        $this->assertSame(hash('sha256', $invalid), $failed->normalizedHash);
        $this->assertNotSame(hash('sha256', ''), $failed->normalizedHash);

        // Control: the same body WITHOUT the invalid bytes normalizes cleanly
        // and moves off its raw hash, so the assertions above are reading a
        // real failure rather than a normalizer that never does anything.
        $clean = ContentNormalizer::normalize($valid);

        $this->assertFalse($clean->normalizationFailed);
        $this->assertNotSame($clean->rawHash, $clean->normalizedHash);
    }

    /** A token value below the 16-character floor is left alone. */
    public function test_a_short_attribute_value_is_not_treated_as_a_token(): void
    {
        $first = ContentNormalizer::normalize('<div data-csrf="short-value-01"></div>');
        $second = ContentNormalizer::normalize('<div data-csrf="short-value-02"></div>');

        $this->assertNotSame($first->normalizedHash, $second->normalizedHash);
    }

    /** A prose `content="..."` value is left alone; only token-shaped values go. */
    public function test_a_prose_meta_description_is_not_treated_as_a_token(): void
    {
        $first = ContentNormalizer::normalize(
            '<meta name="description" content="Uptime monitoring for calm operators.">',
        );
        $second = ContentNormalizer::normalize(
            '<meta name="description" content="Uptime monitoring for calmer operators.">',
        );

        $this->assertNotSame($first->normalizedHash, $second->normalizedHash);
    }

    /**
     * A 1 MB unterminated attribute value is a real hostile input; it must
     * return a usable hash promptly rather than backtrack catastrophically.
     */
    public function test_a_hostile_unterminated_attribute_value_returns_promptly(): void
    {
        $hostile = '<html data-csrf="'.str_repeat('A', 1_000_000);

        $startedAt = microtime(true);
        $result = ContentNormalizer::normalize($hostile);
        $elapsedSeconds = microtime(true) - $startedAt;

        // The measured cost is ~2 ms; the budget is generous on purpose, since
        // the failure being guarded against is a hang, not a slow run.
        $this->assertLessThan(2.0, $elapsedSeconds);
        $this->assertSame(hash('sha256', $hostile), $result->rawHash);
        $this->assertNotSame(hash('sha256', ''), $result->normalizedHash);
    }

    /**
     * No non-empty body may ever normalize to the empty-string hash, whatever
     * the substitution chain does. That value becoming a monitor's normalized
     * hash is what would silently freeze its archive.
     */
    public function test_no_non_empty_body_normalizes_to_the_empty_string_hash(): void
    {
        $bodies = [
            'capture-one' => $this->fixture('fluttersdk-home-1.html'),
            'capture-two' => $this->fixture('fluttersdk-home-2.html'),
            'edited-heading' => $this->fixture('edited-heading.html'),
            'invalid-utf8' => chr(0xFF).chr(0xFE).'<div data-csrf="'.str_repeat('b', 40).'"></div>',
            'hostile' => '<html data-csrf="'.str_repeat('A', 200_000),
            'tiny' => 'x',
            'token-only' => '<meta name="csrf-token" content="'.str_repeat('c', 40).'">',
        ];

        foreach ($bodies as $label => $body) {
            $result = ContentNormalizer::normalize($body);

            $this->assertNotSame(hash('sha256', ''), $result->normalizedHash, $label);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->normalizedHash, $label);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->rawHash, $label);
        }
    }

    /** The version travels from config, so bumping a rule starts a fresh chain. */
    public function test_the_normalizer_version_comes_from_config(): void
    {
        $this->assertSame(
            (int) config('content-archive.normalizer_version'),
            ContentNormalizer::normalize('<html></html>')->normalizerVersion,
        );

        config()->set('content-archive.normalizer_version', 7);

        $this->assertSame(7, ContentNormalizer::normalize('<html></html>')->normalizerVersion);
    }

    /**
     * Contents of a committed content fixture.
     */
    protected function fixture(string $name): string
    {
        $path = base_path('tests/fixtures/content/'.$name);

        $this->assertFileExists($path);

        return file_get_contents($path);
    }
}
