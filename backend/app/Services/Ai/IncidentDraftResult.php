<?php

namespace App\Services\Ai;

use App\Enums\AiDegradeReason;

/**
 * A drafted status update or postmortem, or the honest absence of one.
 *
 * `draft` is null on every degrade path rather than carrying a fallback
 * sentence, and that is deliberate. The client already owns a localized
 * template for both surfaces (`draftUpdate` and `postmortemDraft` in the
 * Flutter app), it has owned them since before this gateway existed, and they
 * are better than anything a degraded backend could compose: they are written
 * by a person in both locales. So the failure path hands back a null and a
 * reason, the client fills its own template, and the operator is told which one
 * they are looking at.
 *
 * That is also why there is no `summary`-style baseline here. Returning
 * machine-composed English on a Turkish screen is the defect the analysis
 * surface already had once.
 */
readonly class IncidentDraftResult
{
    /**
     * @param  string|null  $draft  The drafted text, or null when the model did not answer.
     * @param  AiDegradeReason|null  $degradeReason  Why there is no draft, or null when there is one.
     */
    public function __construct(
        public ?string $draft,
        public ?AiDegradeReason $degradeReason = null,
    ) {}

    /**
     * The snake_case wire shape.
     *
     * Both keys are always present, following `degrade_reason`'s rule on the
     * analysis endpoint: the client tells a null ("no draft, here is why") from
     * an absent key ("this is a shape I do not know").
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draft' => $this->draft,
            'degrade_reason' => $this->degradeReason?->value,
        ];
    }
}
