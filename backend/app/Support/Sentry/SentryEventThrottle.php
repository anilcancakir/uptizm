<?php

namespace App\Support\Sentry;

use Illuminate\Support\Facades\Cache;
use Sentry\Event;
use Throwable;

/**
 * Caps how often one recurring fault may spend the error quota.
 *
 * WHY THIS IS IN CODE AT ALL. Sentry has a per-key rate limit built for exactly
 * this, and it is a Business-plan feature; this org is on Team. The gap is not
 * loud, either: the API accepts a `rateLimit` field on a client key, answers
 * 200, and silently keeps `null`. So the platform control does not exist here
 * and the arithmetic is left unguarded.
 *
 * THE ARITHMETIC. A relay outage fails every check job. `PerformMonitorCheck`
 * carries `$tries = 3` and `RelayClient` throws on both an unreachable worker
 * and a non-2xx, so at ~1000 jobs a minute that is ~3000 events a minute
 * against an allowance of 50,000 a MONTH with `onDemandMaxSpend = 0`. Twenty
 * minutes of a single outage would spend the remaining month of visibility,
 * during precisely the incident class this product exists to detect. And the
 * outage is already reported by its own telemetry (`AlarmDarkProbeRegions`,
 * `ProbeRegionHealth`), so those thousands of events carry nothing the first
 * one did not.
 *
 * WHAT IS LOST. Nothing an operator reads. Sentry groups identical faults into
 * one issue regardless; it simply meters each event on the way in. What the
 * dropped copies would have added is a per-occurrence count, which this product
 * measures far better in its own tables.
 *
 * IT FAILS OPEN, and that is a decision rather than an oversight: see
 * {@see self::allows()}.
 */
class SentryEventThrottle
{
    /**
     * How long one fault holds its slot.
     *
     * A minute, because that is the resolution at which a human reacts to an
     * incident anyway, and because it keeps a sustained outage at ~60 events an
     * hour rather than ~180,000.
     */
    private const int WINDOW_SECONDS = 60;

    /**
     * Cache key prefix, namespaced so it can never collide with the monitoring
     * locks that share this store.
     */
    private const string KEY_PREFIX = 'sentry-throttle:';

    /**
     * Whether this event may be sent.
     *
     * IT FAILS OPEN. Any failure reaching the cache answers `true`, so the event
     * is reported. The throttle protects a quota, not correctness, and an
     * observability layer that goes silent when infrastructure is breaking is
     * silent exactly when it is needed; over-reporting is the cheaper mistake.
     * `Throwable` rather than a narrow catch for the same reason: nothing this
     * class can fail at is worth losing an error report over.
     *
     * @param  Event  $event  The event about to be transmitted.
     */
    public static function allows(Event $event): bool
    {
        try {
            return Cache::add(self::KEY_PREFIX.self::identify($event), 1, self::WINDOW_SECONDS);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * A stable identity for "the same fault happening again".
     *
     * Built from the exception type and message rather than from a stack trace,
     * because a retried queue job produces a slightly different trace each time
     * while being the same fault. A message event falls back to its own text,
     * which matters: without it every `captureMessage` would share one bucket
     * and only the first would ever be reported.
     */
    private static function identify(Event $event): string
    {
        $parts = [];

        foreach ($event->getExceptions() as $exception) {
            $parts[] = $exception->getType().'|'.$exception->getValue();
        }

        if ($parts === []) {
            $parts[] = (string) $event->getMessage();
        }

        return sha1(implode("\n", $parts));
    }
}
