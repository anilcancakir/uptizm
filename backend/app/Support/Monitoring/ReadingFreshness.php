<?php

namespace App\Support\Monitoring;

use App\Services\Monitoring\CheckPersistenceService;
use App\Services\Services\ServicePageAssembler;

/**
 * How long a reading speaks for its target before it stops meaning anything.
 *
 * One number with two consumers in different layers, which is why it lives here
 * rather than in either of them:
 *
 * - {@see ServicePageAssembler} withholds a verdict on the public page once the
 *   newest reading is older than this, and says so in as many words rather than
 *   showing the last value it happened to have.
 * - {@see CheckPersistenceService} closes an incident whose target has gone dark
 *   once the newest reading is older than this, because a refused probe writes no
 *   check row and the recovery path only ever runs on a check.
 *
 * It was defined on the page assembler first and read across from the monitoring
 * core, which pointed the dependency from the domain at a read model. Two copies
 * would have been worse than the bad direction: a second definition is how the
 * public page and the incident table come to disagree about what stale means,
 * and that disagreement is exactly the defect the second consumer exists to fix
 * (the page withheld a verdict for four days while the incident asserted one).
 */
final class ReadingFreshness
{
    /**
     * Seconds after which a reading no longer speaks for its target.
     *
     * Ten minutes: long enough that an ordinary 60s cadence survives a few
     * missed ticks without going quiet, short enough that a target which stopped
     * answering is not represented by a ten-minute-old opinion.
     */
    public const int STALE_AFTER_SECONDS = 600;
}
