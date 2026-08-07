<?php

namespace App\Exceptions;

use App\Services\Ai\AiDeadline;
use RuntimeException;

/**
 * A model call that was never made, because {@see AiDeadline} had too little of
 * the request's shared provider budget left to fund it.
 *
 * It exists to keep one degrade from wearing another's name. Both gateways
 * already treat "the model could not be trusted" as a distinct failure from
 * "the provider could not be reached", each with its own log line, and the
 * budget refusal is a third thing again: nothing was sent, so there is no
 * output to distrust and no service that failed to answer. Returning null from
 * the raw-call seam had put it in the first bucket, and production logged a
 * refused metric-discovery call as `the model output could not be trusted.`,
 * which sends whoever reads it looking at a prompt that was never issued.
 *
 * It also removes a retry that could not help. Both gateways retry once when
 * the raw seam answers null, so a spent budget produced a second refusal at the
 * same instant before raising. Throwing leaves the retry for the case it was
 * written for, which is a model that answered badly.
 *
 * It extends RuntimeException deliberately. Every existing call site already
 * catches that class and degrades, so a site that has not yet grown its own
 * branch keeps degrading with the older label rather than 500ing the request.
 * That is the failure this whole surface was built to stop, and a new exception
 * class must not reintroduce it. Catch this one FIRST where the label matters.
 */
class AiBudgetExhaustedException extends RuntimeException
{
    /**
     * @param  string  $turn  Which model call was refused, as an operator would name it.
     */
    final protected function __construct(public readonly string $turn, string $message)
    {
        parent::__construct($message);
    }

    /**
     * The named constructor every call site uses, so the message shape stays
     * identical across the gateways and the turn is never left unsaid.
     */
    public static function before(string $turn): static
    {
        return new static($turn, sprintf(
            "The request's shared AI budget was already spent, so the %s turn was not started.",
            $turn,
        ));
    }
}
