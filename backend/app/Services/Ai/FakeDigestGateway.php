<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;

/**
 * A deterministic weekly-digest gateway for tests and offline runs.
 *
 * Bound in place of {@see LaravelAiDigestGateway} so no real Anthropic call
 * is ever made in CI. It returns a fixed {@see DigestResult} independent of
 * the payload contents, so assertions stay byte-stable.
 *
 * It still honors the boundary contract: it only NARRATES, and its summary
 * cites nothing that is not in every payload's catalog.
 */
class FakeDigestGateway implements DigestGateway
{
    /**
     * Return a fixed, boundary-safe weekly narration for any team.
     */
    public function summarize(DigestPayload $payload): DigestResult
    {
        return new DigestResult(
            summary: 'Deterministic digest stub: uptime held steady with no notable regressions this week.',
            confidence: AiConfidence::Medium,
            highlights: [
                'Uptime tracked close to the prior week with no sustained regression.',
            ],
            strippedCitations: [],
        );
    }
}
