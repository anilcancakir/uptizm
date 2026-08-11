<?php

namespace App\Enums;

use App\Services\Ai\IncidentAnalysisResult;

/**
 * Why an AI surface answered from a deterministic baseline instead of the model.
 *
 * Crosses the wire on {@see IncidentAnalysisResult} so the client can say what
 * happened in the operator's own language; before it existed, the reason was an
 * English clause embedded in the summary prose and a Turkish operator read
 * developer English.
 *
 * The set is CLOSED at three because it is a rendering contract: each case is a
 * sentence the client ships in every locale, so a case only earns its place
 * when an operator would act on the distinction. `laravel/ai` can tell a rate
 * limit from insufficient credits from an overload, and all three fold into
 * {@see self::ServiceUnreachable}: to a caller they are one thing, a provider
 * that did not answer. The finer taxonomy survives in the log line, which is
 * the only place it is useful.
 */
enum AiDegradeReason: string
{
    /**
     * The team's daily AI budget was already spent, so the model was never called.
     */
    case BudgetExhausted = 'budget_exhausted';

    /**
     * The model answered, but its output did not conform past the single retry.
     */
    case OutputUntrusted = 'output_untrusted';

    /**
     * The provider did not answer: an outage, a timeout, a missing key, or an
     * error body delivered in-band on an HTTP 200.
     */
    case ServiceUnreachable = 'service_unreachable';
}
