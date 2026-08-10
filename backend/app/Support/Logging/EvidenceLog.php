<?php

namespace App\Support\Logging;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Jobs\AnnounceScheduledMaintenance;
use App\Jobs\RefreshProxySources;
use App\Services\Monitoring\IncidentDispatcher;
use App\Services\OnCall\EscalationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * The channel for lines that are EVIDENCE: a record of something the system
 * deliberately did not do, or of a capability a tenant exercised.
 *
 * WHY IT IS NOT THE DEFAULT CHANNEL. MEASURED on the production box:
 * `LOG_CHANNEL=stack`, `LOG_STACK=single`, `LOG_LEVEL=warning`. Every
 * `Log::info()` on the default channel is therefore discarded before it reaches
 * a file, and the three lines below had never once been written in production
 * while tests asserting them passed against a faked logger. The two obvious
 * alternatives are both worse: promoting the lines to `warning` teaches an
 * operator to ignore warnings (nothing here is a fault), and lowering the global
 * level to `info` buys three lines by flooding `laravel.log` with every other
 * info line in the application.
 *
 * `config/logging.php` carries the driver and retention reasoning. The invariant
 * here is that the channel's level is never derived from `LOG_LEVEL`, and that an
 * unset `EVIDENCE_LOG_LEVEL` leaves it recording. The same reasoning produced
 * `ai-routing`, which stays separate: that one is a latency instrument read by
 * grepping a provider name out of a time series, this one is read once, after an
 * incident or a question about a credential.
 *
 * WHAT IS ON IT, and the list is closed rather than a category anyone may join:
 * - {@see MonitorController::auditCredentialledProbe()}: a tenant sent an
 *   operator-supplied credential to a host through the analyze endpoint. Backed
 *   by a persisted row, which is the system of record; this line is derived from
 *   it.
 * - {@see IncidentDispatcher::logSuppression()}: an incident page was withheld
 *   by an open maintenance window.
 * - {@see EscalationDispatcher::logSuppression()}: an escalation step was
 *   withheld by the same.
 *
 * The last two are the load-bearing case: "why did nobody get paged" is asked
 * after the fact, and this project has already shipped a suppression bug that
 * paged an on-call engineer for thirty minutes, where that trail was what the
 * review needed.
 *
 * WHAT IS DELIBERATELY NOT ON IT. Three other `Log::info()` calls stay on the
 * default channel and therefore stay silent in production, by decision:
 * {@see AnnounceScheduledMaintenance} (two lines, a spent claim and a fan-out
 * count, both of which leave a database row behind) and
 * {@see RefreshProxySources} (a per-region refresh count, roughly 120 lines a
 * day across five regions). Keeping the proxy line out is what stops it burying
 * a credential line that fires maybe ten times a day. Each site carries the same
 * note where a future reader will be standing when they consider moving it.
 */
final class EvidenceLog
{
    /**
     * The log channel this evidence is written to, configured in `config/logging.php`.
     */
    public const string CHANNEL = 'evidence';

    /**
     * Record one line of evidence.
     *
     * The level is fixed here rather than at each call site, for the reason in
     * this class's docblock: `info` is the honest level for a deliberate act, and
     * this channel is the only one in the application that keeps it.
     *
     * @param  string  $message  The fact being recorded, as a sentence.
     * @param  array<string, mixed>  $context  The identifiers a reader needs to follow it up.
     */
    public static function record(string $message, array $context): void
    {
        Log::channel(self::CHANNEL)->info($message, $context);
    }
}
