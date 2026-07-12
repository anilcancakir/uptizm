<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Stringable;

/**
 * The production weekly-digest gateway: a thin, replaceable wrapper over
 * `laravel/ai`.
 *
 * Cloned from {@see LaravelAiIncidentAnalysisGateway}: it is simultaneously
 * the app-facing {@see DigestGateway} and a `laravel/ai` Agent (via the
 * {@see Promptable} trait), so the entire pre-1.0 package surface it touches
 * is confined to this one file.
 *
 * The honest-AI-boundary is enforced in three places (there is no fencing
 * step: see {@see DigestPayload}'s docblock for why this payload carries no
 * probe-controlled fields):
 * - GROUNDING: {@see instructions()} tells the model it only sees the team's
 *   own aggregate uptime numbers and incident records for the week, never
 *   any deploy/git/logs/APM context.
 * - STRUCTURED OUTPUT: {@see schema()} pins a flat JSON shape with a
 *   bounded/enum confidence field; non-conforming output is retried once,
 *   then rejected.
 * - ALLOWLIST: {@see sanitizeText()} strips any incident_id/monitor_id
 *   citation the model invented that the payload's owned catalog does not
 *   vouch for.
 *
 * No tools, no function-calling, no DB access are ever exposed to the model.
 */
class LaravelAiDigestGateway implements Agent, Conversational, DigestGateway, HasStructuredOutput
{
    use Promptable;

    /**
     * Narrate a team's week from its aggregate uptime and incidents.
     *
     * @throws RuntimeException When the model returns non-conforming output twice.
     */
    public function summarize(DigestPayload $payload): DigestResult
    {
        $message = $payload->buildUserMessage();
        // The digest gateway shares the triage model config: both are
        // structured-output narration tasks a cheap, fast model handles
        // well, and config/ai.php is not in this step's file scope to extend.
        $model = config('ai.triage.model');
        $model = is_string($model) ? $model : null;

        // 1. First attempt.
        // verify-at-execute: confirm against installed vendor/laravel/ai.
        $data = $this->parse($this->prompt($message, model: $model));

        // 2. Validate-then-retry once on non-conforming structured output.
        if ($data === null) {
            // verify-at-execute: confirm against installed vendor/laravel/ai.
            $data = $this->parse($this->prompt($message, model: $model));
        }

        if ($data === null) {
            throw new RuntimeException('Digest gateway received non-conforming structured output.');
        }

        // 3. Enforce the owned-citation allowlist on the free-text summary
        //    and each highlight bullet.
        $cleanedSummary = $this->sanitizeText($data['summary'], $payload);
        $cleanedHighlights = [];
        $stripped = $cleanedSummary['stripped'];
        foreach ($data['highlights'] as $highlight) {
            $cleanedHighlight = $this->sanitizeText($highlight, $payload);
            $cleanedHighlights[] = $cleanedHighlight['text'];
            $stripped = [...$stripped, ...$cleanedHighlight['stripped']];
        }

        return new DigestResult(
            summary: $cleanedSummary['text'],
            confidence: $data['confidence'],
            highlights: $cleanedHighlights,
            strippedCitations: $stripped,
        );
    }

    /**
     * The system grounding: the honest-AI-boundary stated as standing rules.
     */
    public function instructions(): Stringable|string
    {
        return implode(' ', [
            'You are the weekly-digest narrator for an uptime-monitoring product.',
            'Reason ONLY from the evidence provided in the user message.',
            "You have NO access to the customer's deploys, git history, logs, or APM;",
            'never assume those exist and never reference them.',
            'You are summarizing one team\'s own aggregate uptime numbers and',
            'incidents for the week, not diagnosing any monitored application.',
            'Cite an incident_id or monitor_id only when it appears in the known catalog.',
            'Respond only with the requested structured fields.',
        ]);
    }

    /**
     * No prior conversation: a digest is a single stateless narration turn.
     *
     * @return iterable<Message>
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * The FLAT structured-output schema: three scalar/array fields, no
     * nesting, no oneOf. A flat shape is the most reliable to constrain
     * across models.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('One or two sentences narrating the team\'s week for an operator.')
                ->required(),
            'confidence' => $schema->string()
                ->enum([
                    AiConfidence::High->value,
                    AiConfidence::Medium->value,
                    AiConfidence::Low->value,
                ])
                ->description('How strongly the summary is supported by the evidence.')
                ->required(),
            'highlights' => $schema->array()
                ->items($schema->string())
                ->description('Short bullets naming the week\'s notable trends or incidents.')
                ->required(),
        ];
    }

    /**
     * Strip any owned-citation the model invented from a free-text field.
     *
     * Deterministic and I/O-free: it scans for `incident_id:`/`monitor_id:`
     * tokens and nulls out every value the payload's owned catalog does not
     * vouch for, so the model can narrate but cannot fabricate references.
     * Public so the boundary is unit-testable without a real prompt.
     *
     * @return array{text: string, stripped: list<string>}
     */
    public function sanitizeText(string $text, DigestPayload $payload): array
    {
        $stripped = [];

        $cleaned = preg_replace_callback(
            '/\b(incident_id|monitor_id):([A-Za-z0-9_\-]+)/',
            function (array $match) use ($payload, &$stripped): string {
                [$token, $type, $value] = $match;

                if ($payload->isKnownCitation($type, $value)) {
                    return $token;
                }

                $stripped[] = $token;

                return '';
            },
            $text,
        ) ?? $text;

        // Collapse the whitespace left where a citation was removed.
        $cleaned = trim(preg_replace('/\s{2,}/', ' ', $cleaned) ?? $cleaned);

        return [
            'text' => $cleaned,
            'stripped' => $stripped,
        ];
    }

    /**
     * Validate the structured response against the flat schema.
     *
     * Returns the normalized fields, or null to signal a retry / hard failure.
     *
     * @return array{summary: string, confidence: AiConfidence, highlights: list<string>}|null
     */
    private function parse(AgentResponse $response): ?array
    {
        if (! $response instanceof StructuredAgentResponse) {
            return null;
        }

        $data = $response->toArray();

        $summary = $data['summary'] ?? null;
        if (! is_string($summary) || $summary === '') {
            return null;
        }

        $confidence = is_string($data['confidence'] ?? null)
            ? AiConfidence::tryFrom($data['confidence'])
            : null;
        if ($confidence === null) {
            return null;
        }

        $highlights = $data['highlights'] ?? null;
        if (! is_array($highlights)) {
            return null;
        }

        foreach ($highlights as $highlight) {
            if (! is_string($highlight)) {
                return null;
            }
        }

        return [
            'summary' => $summary,
            'confidence' => $confidence,
            'highlights' => $highlights,
        ];
    }
}
