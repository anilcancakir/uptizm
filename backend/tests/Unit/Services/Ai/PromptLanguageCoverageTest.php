<?php

namespace Tests\Unit\Services\Ai;

use App\Support\Ai\PromptLanguage;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every payload that asks a model for prose names the language to write it in.
 *
 * `PromptLanguage` was added for one surface: metric discovery had returned
 * labels like `database_latency_ms` to an operator whose entire interface was
 * Turkish. The fix was right and it was applied to that surface alone, so two of
 * eight payloads localized their output and six did not, and the seam was
 * available to all of them the whole time.
 *
 * That is the shape this project has shipped before, and the reason this test is
 * a SWEEP rather than eight assertions: a correct fix applied to one of several
 * identical sites, four times over. A ninth payload added next month inherits the
 * gap silently unless something counts them, and a reviewer reading one file
 * cannot see the other seven.
 *
 * The mirror image is worth naming too: it does NOT assert that a payload passes
 * the right locale, only that it names a language at all. What the source is per
 * caller (a request user, a team, a monitor's team) is a per-surface decision and
 * lives in the tests for those surfaces.
 */
class PromptLanguageCoverageTest extends TestCase
{
    /**
     * The payloads that hand a model free text to write.
     *
     * `TranslationPayload` is deliberately absent: it names a source and a target
     * language by construction, so it cannot regress the way these can.
     *
     * @var list<string>
     */
    protected const PROSE_PAYLOADS = [
        'AnalysisPayload',
        'AssistantPayload',
        'DigestPayload',
        'IncidentAnalysisPayload',
        'IncidentDraftPayload',
        'MetricDiscoveryPayload',
        'TriagePayload',
    ];

    public function test_every_prose_payload_names_a_language(): void
    {
        $missing = [];

        foreach (self::PROSE_PAYLOADS as $payload) {
            $source = (string) file_get_contents(app_path("Services/Ai/{$payload}.php"));

            if (! str_contains($source, 'PromptLanguage')) {
                $missing[] = $payload;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'these payloads ask a model for prose without saying what language to write it in',
        );
    }

    public function test_the_sweep_covers_every_payload_that_exists(): void
    {
        // The guard on the guard. A new payload file is exactly the case the
        // sweep is for, and a list maintained by hand cannot see one, so the
        // directory is the source of truth and this test fails when the two
        // disagree rather than quietly covering six of eight again.
        $onDisk = [];

        foreach (File::files(app_path('Services/Ai')) as $file) {
            if (str_ends_with($file->getFilename(), 'Payload.php')) {
                $onDisk[] = str_replace('.php', '', $file->getFilename());
            }
        }

        sort($onDisk);
        $known = [...self::PROSE_PAYLOADS, 'TranslationPayload'];
        sort($known);

        $this->assertSame(
            $known,
            $onDisk,
            'a payload exists that this sweep does not classify: add it to PROSE_PAYLOADS, or to the exemption above with its reason',
        );
    }

    public function test_an_unshipped_language_resolves_to_the_fallback(): void
    {
        // The property every call site relies on: a locale the product does not
        // ship must never reach a prompt as a tag. Asserted here rather than only
        // in a helper's own test because seven payloads now depend on it.
        $this->assertSame(PromptLanguage::FALLBACK, PromptLanguage::nameFor('de'));
        $this->assertSame(PromptLanguage::FALLBACK, PromptLanguage::nameFor(''));
        $this->assertSame(PromptLanguage::FALLBACK, PromptLanguage::nameFor(null));
        $this->assertSame('Turkish', PromptLanguage::nameFor('tr-TR'));
    }
}
