<?php

namespace App\Services\Ai;

use App\Enums\IncidentDraftKind;

/**
 * A deterministic drafting gateway for tests and offline runs.
 *
 * Bound in place of {@see LaravelAiIncidentDraftGateway} so no real provider
 * call is ever made in CI. It returns a fixed draft per kind, long enough to
 * clear the production gateway's own length floor so a test that swaps the two
 * is testing the same contract.
 */
class FakeIncidentDraftGateway implements IncidentDraftGateway
{
    /**
     * Return a fixed draft for any incident.
     */
    public function draft(IncidentDraftPayload $payload): IncidentDraftResult
    {
        return new IncidentDraftResult(match ($payload->kind) {
            IncidentDraftKind::Update => 'Deterministic draft stub: we are investigating an issue '
                .'affecting this service and will post another update shortly.',
            IncidentDraftKind::Postmortem => "What happened\nDeterministic draft stub: the service "
                ."was unavailable for a period and has recovered.\n\nStill unknown\nThe internal "
                .'root cause is for the operator to add.',
        });
    }
}
