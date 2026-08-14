<?php

namespace Tests\Unit\Services\Ai;

use App\Enums\AiConfidence;
use App\Enums\EvidenceSource;
use App\Services\Ai\LaravelAiIncidentAnalysisGateway;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Tests\TestCase;

/**
 * The analysis output is bounded, deterministically, not asked to be short.
 *
 * The schema said "One or two sentences" in a DESCRIPTION and enforced nothing.
 * Only a minimum existed ({@see LaravelAiIncidentAnalysisGateway} at 30
 * characters, added after a summary came back as the single word "No."), and
 * every array was unbounded.
 *
 * MEASURED on a live Turkish run: an 800-character summary, six suggested
 * actions, six contributing factors. The good run on the same model, same prompt,
 * one incident earlier: roughly 300 characters and two actions. And the quality
 * collapsed specifically in the tail of the long one, in a way that is obvious
 * once seen: a hallucinated English word dropped mid-sentence ("Boulder"),
 * invented Turkish words ("dönüşcut", "retrivialerini"), a number fused into a
 * word ("de1744ms"), stray English ("Hint", "control"). The opening sentences of
 * the same answer were fine.
 *
 * So this is not a translation problem to solve with a bigger model first. It is
 * length, on a model chosen for being fast and cheap, and length is the one part
 * of a model's behaviour that can be enforced rather than requested.
 * {@see LaravelAiIncidentDraftGateway::capSentences()} already learned this on
 * the draft half ("A length rule is the one part of this that can be enforced
 * deterministically, so it is") and the analysis half never got it.
 *
 * Both halves are asserted here, because a schema is a REQUEST: the provider is
 * free to ignore `maxItems`, and this same provider has already returned three
 * sentences where the instructions said two.
 */
class IncidentAnalysisBoundsTest extends TestCase
{
    public function test_the_schema_bounds_the_summary_and_every_array(): void
    {
        // `maxLength` and `maxItems` are what `StringType::max()` and
        // `ArrayType::max()` emit, read out of the vendor rather than out of the
        // implementation this asserts.
        $properties = $this->properties();

        $this->assertArrayHasKey('maxLength', $properties['summary'], 'the summary has no upper bound');

        foreach (['contributing_factors', 'evidence_for', 'evidence_against', 'suggested_actions'] as $field) {
            $this->assertArrayHasKey('maxItems', $properties[$field], "{$field} has no upper bound");
        }
    }

    public function test_a_long_summary_is_trimmed_rather_than_stored(): void
    {
        // The enforcement half. A schema the provider ignored has to lose to us.
        $gateway = app(LaravelAiIncidentAnalysisGateway::class);
        $long = str_repeat('Bu bir cümledir. ', 200);

        $result = $gateway->capLength($long, 400);

        $this->assertLessThanOrEqual(400, mb_strlen($result));
        $this->assertStringEndsNotWith(' ', $result, 'a trim that leaves trailing space cuts mid-word');
    }

    public function test_trimming_keeps_whole_sentences_where_it_can(): void
    {
        // Cutting mid-word is worse than cutting early: the reader cannot tell a
        // truncation from the model losing coherence, which is the exact failure
        // this bound exists to hide.
        $gateway = app(LaravelAiIncidentAnalysisGateway::class);

        $result = $gateway->capLength('Birinci cümle burada. İkinci cümle burada. Üçüncü cümle burada.', 45);

        $this->assertSame('Birinci cümle burada. İkinci cümle burada.', $result);
    }

    public function test_a_short_summary_is_returned_untouched(): void
    {
        $gateway = app(LaravelAiIncidentAnalysisGateway::class);
        $short = 'Depolama katmanı degraded bildirdi.';

        $this->assertSame($short, $gateway->capLength($short, 400));
    }

    public function test_the_item_cap_keeps_the_first_entries(): void
    {
        // First rather than last, matching the draft's sentence cap and for the
        // same measured reason: the answer front-loads and pads afterwards, so
        // what a cap drops is what was added to fill space.
        $gateway = app(LaravelAiIncidentAnalysisGateway::class);

        $this->assertSame(
            ['a', 'b', 'c'],
            $gateway->capItems(['a', 'b', 'c', 'd', 'e', 'f'], 3),
        );
    }

    public function test_the_bounds_leave_room_for_a_real_answer(): void
    {
        // The other direction, which a purely defensive edit would break. Every
        // good answer measured on this provider sits between 250 and 400
        // characters with two or three actions, so a cap that cut into that band
        // would trade broken Turkish for truncated Turkish.
        $this->assertGreaterThanOrEqual(400, LaravelAiIncidentAnalysisGateway::MAX_SUMMARY_LENGTH);
        $this->assertGreaterThanOrEqual(3, LaravelAiIncidentAnalysisGateway::MAX_ITEMS);
    }

    public function test_the_confidence_and_source_enums_are_still_closed(): void
    {
        // A guard on the edit itself: adding bounds to this schema means touching
        // every field in it, and the enum on `confidence` is what keeps a
        // fabricated value off the wire.
        $this->assertSame(
            [
                AiConfidence::High->value,
                AiConfidence::Medium->value,
                AiConfidence::Low->value,
            ],
            $this->properties()['confidence']['enum'],
        );

        $this->assertNotEmpty(EvidenceSource::cases(), 'the evidence source enum must stay closed');
    }

    /**
     * The gateway's declared output schema, as the properties array.
     *
     * Built the way `AnalysisGatewayTest` builds its own: the contract is not
     * instantiable, so the concrete factory is passed directly.
     */
    protected function properties(): array
    {
        return (new ObjectSchema(
            (new LaravelAiIncidentAnalysisGateway)->schema(new JsonSchemaTypeFactory)
        ))->toSchema()['properties'];
    }
}
