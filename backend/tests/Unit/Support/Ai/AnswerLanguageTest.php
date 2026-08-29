<?php

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\AnswerLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the answer-language detector against REAL production prose.
 *
 * Every string below was read out of this product's own database or out of a
 * live model response on 2026-08-29, rather than written to make a classifier
 * look good. That matters most for the mixed rows: the system templates
 * interpolate an operator's Turkish metric name into an English sentence, and a
 * character-class classifier calls those Turkish. It did, in an audit that
 * reported 91 missing translations where 3 were missing.
 *
 * The `null` cases are as load-bearing as the verdicts. The caller spends a
 * model call on a wrong-language answer, so an over-confident detector costs
 * money on prose it should have left alone.
 */
class AnswerLanguageTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function corpus(): array
    {
        return [
            // Live model rationale, analyze run, 2026-08-29.
            'model rationale, English' => [
                "Probe returned HTML with title 'NeverSSL - Connecting ...', a static "
                .'Apache-served page (no CDN detected, server Apache/2.4.66), so this is '
                .'an ordinary web page check. Response was slow at 3364ms with most time '
                .'in TTFB, but a single sample does not establish a baseline.',
                'en',
            ],
            'model rationale, Turkish' => [
                'Tek deneme yanıtı, içerik türü application/json olan ve JSON gövde dönen '
                .'bir REST API uç noktası olduğunu gösteriyor; 779 ms gözlemlendiği için '
                .'uyarı ve kritik eşikleri bunun üzerinde tutuldu.',
                'tr',
            ],

            // The trap. A Turkish metric name inside an English system template.
            'English sentence carrying a Turkish metric name' => [
                'Redis İşlem Süresi (ms) returned to its normal range on fluttersdk.com; '
                .'incident auto-resolved.',
                'en',
            ],
            'English sentence carrying a Turkish metric name, second shape' => [
                'Toplam Yanıt Süresi (ms) returned to its normal range on fluttersdk.com; '
                .'incident auto-resolved.',
                'en',
            ],

            // The mirror: a Turkish sentence carrying English product nouns.
            'Turkish sentence carrying English product nouns' => [
                'fluttersdk.com üzerindeki Redis latency değeri normal aralığına döndü ve '
                .'bu olay otomatik olarak çözüldü.',
                'tr',
            ],

            // Real operator updates.
            'operator update, Turkish' => [
                'Bu olay çözülmüştür. Bu konuda başka bir güncelleme yayınlanmayacaktır ve '
                .'hizmet normal şekilde çalışmaya devam etmektedir.',
                'tr',
            ],
            'operator update, English' => [
                'This incident has been resolved. We are no longer seeing elevated '
                .'response times and the service is operating normally.',
                'en',
            ],

            // Undetermined: too short to be evidence of anything.
            'a fragment is not evidence' => ['Incident resolved.', null],
            'a metric label is not a sentence' => ['Toplam Yanıt Süresi (ms)', null],
            'a bare url' => ['https://api.github.com/meta', null],
            'numbers and a hostname' => ['fluttersdk.com 779 ms 200', null],
        ];
    }

    #[DataProvider('corpus')]
    public function test_it_reads_real_production_prose(string $text, ?string $expected): void
    {
        $this->assertSame($expected, AnswerLanguage::detect($text));
    }

    public function test_it_only_reports_a_difference_it_is_sure_of(): void
    {
        $turkish = 'Bu olay çözülmüştür ve hizmet normal şekilde çalışmaya devam etmektedir.';

        $this->assertTrue(AnswerLanguage::differsFrom($turkish, 'en'));
        $this->assertFalse(AnswerLanguage::differsFrom($turkish, 'tr'));

        // A region subtag is not a different language.
        $this->assertFalse(AnswerLanguage::differsFrom($turkish, 'tr-TR'));
    }

    public function test_prose_it_cannot_read_is_never_a_difference(): void
    {
        // This is the property that keeps a false positive from spending a model
        // call: undetermined answers false, whatever it is compared against.
        foreach (['Incident resolved.', 'Toplam Yanıt Süresi (ms)', ''] as $text) {
            $this->assertFalse(AnswerLanguage::differsFrom($text, 'en'), $text);
            $this->assertFalse(AnswerLanguage::differsFrom($text, 'tr'), $text);
        }
    }

    public function test_a_third_language_reads_as_undetermined_rather_than_as_a_guess(): void
    {
        // German shares enough shape with English to be a tempting wrong answer.
        $this->assertNull(AnswerLanguage::detect(
            'Der Dienst wurde wiederhergestellt und die Antwortzeiten sind wieder normal.'
        ));
    }

    public function test_a_shouted_sentence_still_reads(): void
    {
        // `strtolower` leaves `İŞLEM` alone and a non-lowercased token matches no
        // stopword, so the whole sentence would score zero in both languages.
        $this->assertSame('tr', AnswerLanguage::detect(
            'BU OLAY ÇÖZÜLMÜŞTÜR VE HİZMET NORMAL ŞEKİLDE ÇALIŞMAYA DEVAM ETMEKTEDİR.'
        ));
    }
}
