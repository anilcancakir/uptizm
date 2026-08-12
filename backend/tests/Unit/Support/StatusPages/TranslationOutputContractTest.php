<?php

namespace Tests\Unit\Support\StatusPages;

use App\Support\StatusPages\TranslationOutputContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The mechanical gate a machine translation must pass before it is published on
 * a public, indexed, customer-branded page with no human review.
 *
 * Table-driven on purpose: every case asserts BOTH the verdict and the machine
 * reason, because a contract that rejects for the wrong reason records the wrong
 * thing in `status_page_translations.rejection_reason` and sends whoever reads
 * it hunting the wrong defect. The reason is also the only part of a rejection
 * that is ever logged, since the suspect text never is.
 *
 * Each pair below is a real failure mode rather than an illustration:
 * an injected support URL and an injected phone number are the concrete payload
 * this path invites, a length blow-out is what an ignored instruction looks
 * like, and a trailing bidi override is how a rendered line can be made to read
 * backwards on a status page during an outage.
 */
class TranslationOutputContractTest extends TestCase
{
    /**
     * Source/output pairs, the expected verdict, and the expected reason.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string|null}>
     */
    public static function pairs(): array
    {
        return [
            'an injected url the source never carried' => [
                'We are investigating.',
                'İnceliyoruz. Detay: https://evil.example/x',
                false,
                TranslationOutputContract::REASON_FOREIGN_TOKEN,
            ],
            'the source own host carried through' => [
                'See https://status.acme.com for updates.',
                'Güncellemeler için https://status.acme.com adresine bakın.',
                true,
                null,
            ],
            'a phone number whose digits were changed' => [
                'Call 555 0100 123 for help.',
                'Yardım için 555 0100 999 numarasını arayın.',
                false,
                TranslationOutputContract::REASON_FOREIGN_TOKEN,
            ],
            'a short source under the ratio floor' => [
                'Resolved.',
                'Çözüldü.',
                true,
                null,
            ],
            'an output three and a half times its source' => [
                self::filler(200),
                self::filler(700),
                false,
                TranslationOutputContract::REASON_LENGTH_RATIO,
            ],
            'a trailing bidi override' => [
                'Investigating.',
                "İnceleniyor.\u{202E}",
                false,
                TranslationOutputContract::REASON_UNSAFE_CHARACTERS,
            ],
            // Beyond the six the plan names, one case per remaining rule, so no
            // rule is only asserted by the rule that happens to fire first.
            'an injected email address' => [
                'Contact the team.',
                'Ekiple iletişime geçin: support@evil.example',
                false,
                TranslationOutputContract::REASON_FOREIGN_TOKEN,
            ],
            'an injected bare hostname with no scheme' => [
                'We are investigating.',
                'İnceliyoruz. Ayrıntılar evil.example adresinde.',
                false,
                TranslationOutputContract::REASON_FOREIGN_TOKEN,
            ],
            'a zero width character smuggled into the output' => [
                'Investigating.',
                "İnceleniyor\u{200B}.",
                false,
                TranslationOutputContract::REASON_UNSAFE_CHARACTERS,
            ],
            'an output truncated mid codepoint' => [
                'Investigating an elevated error rate on the checkout API.',
                "İnceleniyor: ödeme API'sinde yükselen hata oran\xC4",
                false,
                TranslationOutputContract::REASON_TRUNCATED_TAIL,
            ],
            'an output that lost more than half its source' => [
                self::filler(200),
                self::filler(60),
                false,
                TranslationOutputContract::REASON_LENGTH_RATIO,
            ],
            'an empty output' => [
                'Investigating.',
                "  \n ",
                false,
                TranslationOutputContract::REASON_EMPTY,
            ],
            // The two honest-reformat cases the token rules must NOT reject.
            'the same phone number written with different separators' => [
                'Call (555) 010-0123 for help.',
                'Yardım için 555 010 0123 numarasını arayın.',
                true,
                null,
            ],
            'an unrelated pair of numbers reordered by the translation' => [
                'We saw 12 failures across 34567 checks in the last hour.',
                'Son bir saatte 34567 kontrolde 12 hata gördük.',
                true,
                null,
            ],
        ];
    }

    #[DataProvider('pairs')]
    public function test_the_contract_verdict_and_reason(
        string $source,
        string $output,
        bool $accepted,
        ?string $reason,
    ): void {
        $verdict = TranslationOutputContract::verify($source, $output);

        $this->assertSame($accepted, $verdict['accepted']);
        $this->assertSame($reason, $verdict['reason']);
    }

    public function test_an_accepted_output_carries_the_stripped_and_trimmed_value(): void
    {
        $verdict = TranslationOutputContract::verify('Investigating.', "  İnceleniyor.\n");

        $this->assertTrue($verdict['accepted']);
        $this->assertSame('İnceleniyor.', $verdict['value']);
    }

    public function test_a_rejected_output_never_carries_the_suspect_value(): void
    {
        $verdict = TranslationOutputContract::verify('We are investigating.', 'https://evil.example/x');

        $this->assertFalse($verdict['accepted']);
        $this->assertNull($verdict['value']);
    }

    /**
     * A postmortem body's newlines are layout on the rendered page
     * (`whitespace-pre-line`), so they are content and not control characters to
     * strip. The rule that removes C0 controls must keep tab, newline and
     * carriage return or every multi-paragraph postmortem is rejected as unsafe.
     */
    public function test_newlines_inside_a_body_are_content_and_not_a_rejection(): void
    {
        $source = "First paragraph of the postmortem.\n\nSecond paragraph explaining the cause.";
        $output = "Olayın ilk paragrafı burada.\n\nNedeni açıklayan ikinci paragraf burada.";

        $verdict = TranslationOutputContract::verify($source, $output);

        $this->assertTrue($verdict['accepted']);
        $this->assertSame($output, $verdict['value']);
    }

    /**
     * A source that already carries a host does not license a DIFFERENT one: the
     * comparison is exact set membership, never a suffix match, so an attacker
     * cannot ride the customer's own domain with `acme.com.evil.example`.
     */
    public function test_a_lookalike_subdomain_of_an_allowed_host_is_still_foreign(): void
    {
        $verdict = TranslationOutputContract::verify(
            'See https://acme.com for updates.',
            'Güncellemeler için https://acme.com.evil.example adresine bakın.',
        );

        $this->assertFalse($verdict['accepted']);
        $this->assertSame(TranslationOutputContract::REASON_FOREIGN_TOKEN, $verdict['reason']);
    }

    /**
     * Filler of an exact character length, carrying no token of any class, so a
     * ratio case can only fail on the ratio.
     */
    protected static function filler(int $length): string
    {
        return mb_substr(str_repeat('ab ', (int) ceil($length / 3) + 1), 0, $length);
    }
}
