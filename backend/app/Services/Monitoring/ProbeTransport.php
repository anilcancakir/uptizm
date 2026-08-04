<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Support\Monitoring\CheckResult;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * The single seam a probe leaves this application through.
 *
 * There are two networks a check can run on and they are not interchangeable.
 * {@see RelayClient} pushes the spec to the Cloudflare worker, which probes from
 * somewhere that is not our infrastructure; {@see LocalProbeEngine} probes from
 * this server through the proxy pool, and is reserved for the internal system
 * team's catalog monitors. Which one runs is decided per monitor inside
 * `PerformMonitorCheck::transportFor()`, so no caller has to know that two
 * networks exist.
 *
 * Both implementations return the SAME {@see CheckResult} wire shape, parsed by
 * the same code, because both write into one `monitor_checks` table: a field one
 * transport populates differently from the other would surface as a status
 * change on a public page rather than as a test failure.
 *
 * ## An outage and a refusal are return values; a broken transport is a throw
 *
 * Three outcomes, and conflating any two of them pages somebody for a service
 * that is up (or hides one that is down):
 *
 *  1. The target answered: a {@see CheckResult} carrying the verdict.
 *  2. The probe never ran, and that says nothing about the target: a
 *     {@see CheckResult} with `probeRefused = true`, which
 *     {@see CheckPersistenceService::persist()} turns into no check row, no
 *     `consecutive_fails` change and no notification.
 *  3. The transport itself failed (an unreachable worker, a 401 on a secret
 *     mismatch): a thrown exception, so the queue retries. Never a fabricated
 *     `down`.
 */
interface ProbeTransport
{
    /**
     * Execute one probe for a (monitor, region) pair and return the outcome.
     *
     * @param  Monitor  $monitor  The monitor whose probe spec is executed.
     * @param  string  $region  Target region, an `App\Enums\MonitorRegion` value.
     *
     * @throws RuntimeException When the transport cannot serve this monitor at
     *                          all: an unsupported region, or a monitor this
     *                          transport is not allowed to probe.
     * @throws ConnectionException When the transport's own hop is unreachable.
     * @throws Throwable For any other transport-level failure. A failure to
     *                   REACH the target is not one of these: that is a verdict
     *                   and it is returned.
     */
    public function dispatch(Monitor $monitor, string $region): CheckResult;
}
