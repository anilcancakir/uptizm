<?php

namespace App\Jobs;

use App\Models\EscalationStep;
use App\Services\OnCall\EscalationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fires a single {@see EscalationStep} of an incident's escalation
 * ladder after its cumulative delay has elapsed. The job carries only the
 * incident and step ids so the handler re-reads live state: a resolved incident
 * is cancelled at run time and the per-(incident, step) idempotency guard keeps
 * a re-dispatch from double-paging.
 */
class DispatchEscalationStep implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $incidentId  The opened incident this step pages for.
     * @param  string  $stepId  The escalation step to fire.
     */
    public function __construct(
        public readonly string $incidentId,
        public readonly string $stepId,
        /**
         * Which pass over this step this job is. 0 is the ladder's own single
         * firing; 1 and above are repeats of a policy's last rung, scheduled by
         * the dispatcher while the incident stays unacknowledged. Defaulting to
         * 0 keeps every existing dispatch site and every job already sitting on
         * the queue across a deploy valid and unchanged.
         */
        public readonly int $attempt = 0,
    ) {}

    /**
     * Delegate to the dispatcher, which owns the cancel/idempotency/target logic.
     */
    public function handle(EscalationDispatcher $dispatcher): void
    {
        $dispatcher->pageStep($this->incidentId, $this->stepId, $this->attempt);
    }
}
