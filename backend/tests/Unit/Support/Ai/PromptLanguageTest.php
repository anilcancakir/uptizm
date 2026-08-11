<?php

namespace Tests\Unit\Support\Ai;

use App\Services\Ai\MetricDiscoveryPayload;
use App\Support\Ai\PromptLanguage;
use Tests\TestCase;

/**
 * The language operator-facing model text is written in.
 *
 * The production symptom: an operator whose entire interface is Turkish received
 * metric labels reading `database_latency_ms` and `total_duration_ms`. Those are
 * the candidate keys the backend had already sent, echoed back as names, in the
 * wrong language and in no language at all.
 */
class PromptLanguageTest extends TestCase
{
    public function test_a_locale_becomes_a_language_a_model_can_follow(): void
    {
        // A NAME and not the code. "write the label in tr" is a token a provider
        // may or may not resolve; "in Turkish" is not ambiguous to any of them.
        $this->assertSame('Turkish', PromptLanguage::nameFor('tr'));
        $this->assertSame('English', PromptLanguage::nameFor('en'));
    }

    public function test_a_region_subtag_resolves_to_its_language(): void
    {
        // Matching RequestLocaleDetector, which negotiates the header the stored
        // locale came from and drops the subtag the same way.
        $this->assertSame('Turkish', PromptLanguage::nameFor('tr-TR'));
        $this->assertSame('Turkish', PromptLanguage::nameFor('TR'));
    }

    public function test_an_unknown_or_absent_locale_falls_back_rather_than_guessing(): void
    {
        // A locale nobody ships must not travel into a prompt as a tag for the
        // provider to interpret: an unsupported language is a worse answer than
        // the fallback, because the operator cannot read either but only one of
        // them is predictable.
        $this->assertSame('English', PromptLanguage::nameFor(null));
        $this->assertSame('English', PromptLanguage::nameFor(''));
        $this->assertSame('English', PromptLanguage::nameFor('de'));
        $this->assertSame('English', PromptLanguage::nameFor('klingon'));
    }

    public function test_the_language_reaches_the_prompt_outside_the_untrusted_fence(): void
    {
        // THE assertion. Two properties in one, and the second is a security
        // property: the language has to be IN the message (or the model has no
        // reason to change anything), and it has to sit BEFORE the fence, because
        // a language name read from inside it would be a probed page choosing
        // what language our dashboard reads in.
        $message = (new MetricDiscoveryPayload(
            url: 'https://example.com/health',
            monitorType: 'http',
            candidateRefs: ['c1'],
            digestRows: [],
            language: 'Turkish',
        ))->buildUserMessage();

        $this->assertStringContainsString('label_language: Turkish', $message);
        $this->assertStringContainsString('Write every label in Turkish', $message);

        $this->assertLessThan(
            strpos($message, MetricDiscoveryPayload::UNTRUSTED_BLOCK_HEADER),
            strpos($message, 'label_language: Turkish'),
            'the language is ours, so it must be stated before the untrusted fence opens',
        );
    }

    public function test_a_payload_built_without_a_language_still_names_one(): void
    {
        // The default exists so a test payload stays cheap to build, but it must
        // not leave the instruction half-written: a prompt saying "write every
        // label in " would be worse than the keys it replaced.
        $message = (new MetricDiscoveryPayload(
            url: 'https://example.com/health',
            monitorType: 'http',
            candidateRefs: ['c1'],
            digestRows: [],
        ))->buildUserMessage();

        $this->assertStringContainsString('label_language: English', $message);
        $this->assertStringContainsString('Write every label in English', $message);
    }
}
