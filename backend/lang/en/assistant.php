<?php

return [

    /*
     * What the assistant says when the team has spent its daily AI budget.
     *
     * It reaches the operator as the assistant's own reply in the chat, so it is
     * written in the first person and says WHY rather than apologising. It used
     * to be a hardcoded English literal in the controller, which a Turkish
     * operator read as a sentence Uptizm AI had chosen to say to them in English.
     *
     * The response also carries `degrade_reason`, so the client can draw this as
     * a system note rather than as an answer; the sentence still has to read well
     * on its own, because a client that has not adopted that field yet renders it
     * as a reply.
     */
    'degraded_budget' => "Today's AI allowance for your team is used up. I cannot answer from the model until it resets, so the monitors and incidents lists are the place to look in the meantime.",

    /*
     * The body of the 503 when the provider could not be reached or answered
     * with an error.
     *
     * Sent as the response `message`, which the client puts straight into its
     * failure toast. Before this, the same failure left as a 500 carrying
     * Laravel's own "Server Error", so a Turkish operator read an English
     * framework string for an outage that had nothing to do with them.
     *
     * It says the service and not the cause. A rate limit, a timeout and a
     * provider 503 are three things an operator cannot act on differently, and
     * the finer distinction survives in the log where an engineer can use it.
     */
    'unavailable' => 'The AI service could not be reached right now. Please try again in a moment.',

];
