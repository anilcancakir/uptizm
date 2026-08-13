<?php

namespace Tests\Unit\Support\Monitoring;

use App\Enums\MetricBand;
use App\Support\Monitoring\HealthVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dictionary that decides whether a health word's severity is settled
 * enough for the discovery path to keep the model's placement of it.
 *
 * Its whole contract is "known and unambiguous, or nothing", so the tests that
 * matter most are the ones asserting a word is ABSENT: an entry added here
 * lifts a refusal, and a wrong entry publishes a metric that reads a sick
 * service as healthy or pages on a well one.
 */
class HealthVocabularyTest extends TestCase
{
    #[DataProvider('healthyWords')]
    public function test_a_healthy_word_resolves_to_ok(string $word): void
    {
        $this->assertSame(MetricBand::Ok, HealthVocabulary::bandFor($word));
    }

    #[DataProvider('degradedWords')]
    public function test_a_degraded_word_resolves_to_warn(string $word): void
    {
        $this->assertSame(MetricBand::Warn, HealthVocabulary::bandFor($word));
    }

    #[DataProvider('failingWords')]
    public function test_a_failing_word_resolves_to_critical(string $word): void
    {
        $this->assertSame(MetricBand::Critical, HealthVocabulary::bandFor($word));
    }

    /**
     * @return list<list<string>>
     */
    public static function healthyWords(): array
    {
        return [['ok'], ['up'], ['healthy'], ['passing'], ['operational'], ['available']];
    }

    /**
     * @return list<list<string>>
     */
    public static function degradedWords(): array
    {
        return [['degraded'], ['warning'], ['partial'], ['slow'], ['elevated']];
    }

    /**
     * @return list<list<string>>
     */
    public static function failingWords(): array
    {
        return [['down'], ['critical'], ['unhealthy'], ['failed'], ['error'], ['outage']];
    }

    #[DataProvider('separatorForms')]
    public function test_case_and_separators_do_not_change_the_verdict(string $written): void
    {
        // The dictionary is consulted with a value that has already been through
        // `ThresholdEvaluator::normalizeMatchValue()`, which only trims and
        // lowercases. Every real health payload writes its two-word states
        // differently (statuspage.io ships `degraded_performance`, a dashboard
        // shows `Degraded Performance`), so folding the separator is this
        // class's own job and not the caller's.
        $this->assertSame(MetricBand::Warn, HealthVocabulary::bandFor($written));
    }

    /**
     * @return list<list<string>>
     */
    public static function separatorForms(): array
    {
        return [
            ['degraded_performance'],
            ['degraded-performance'],
            ['Degraded Performance'],
            ['  degraded   performance  '],
        ];
    }

    #[DataProvider('wordsDeliberatelyAbsent')]
    public function test_an_ambiguous_or_unknown_word_resolves_to_null(string $word): void
    {
        // Null is what keeps today's refusal in place, so each of these is a
        // deliberate omission rather than a gap:
        //
        // - `maintenance` is neither healthy nor an outage, and a service that
        //   publishes it usually means "expected", so a severity here would be a
        //   guess. It is also the value an existing discovery test transcribes
        //   into `ok_values`.
        // - `true` and `false` carry no health meaning of their own: `debug:
        //   false` is healthy and `writable: false` is not, and only the field
        //   knows which.
        // - `pending`, `unknown` and `starting` describe a service that has not
        //   answered yet, which is a different axis from well or unwell.
        // - `amber` and `red` are a rendering convention, not a state, and the
        //   same words name a theme colour.
        $this->assertNull(HealthVocabulary::bandFor($word));
    }

    /**
     * @return list<list<string>>
     */
    public static function wordsDeliberatelyAbsent(): array
    {
        return [
            ['maintenance'],
            ['true'],
            ['false'],
            ['pending'],
            ['unknown'],
            ['starting'],
            ['amber'],
            ['red'],
            ['green'],
            ['fluttersdk'],
            [''],
        ];
    }

    public function test_agreement_requires_a_known_word_and_a_matching_band(): void
    {
        // The predicate the discovery path actually calls. Three cases, and the
        // third is the one that preserves the old behaviour: an unknown word
        // agrees with nothing, so it can never lift the refusal.
        $this->assertTrue(HealthVocabulary::agrees('degraded', MetricBand::Warn));
        $this->assertFalse(HealthVocabulary::agrees('ok', MetricBand::Critical));
        $this->assertFalse(HealthVocabulary::agrees('amber', MetricBand::Warn));
    }
}
