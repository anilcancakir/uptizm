<?php

namespace App\Support\Monitoring;

use App\Enums\ComponentStatus;
use App\Enums\MetricBand;
use App\Services\Ai\MetricDiscoveryService;
use App\Services\Monitoring\ThresholdEvaluator;

/**
 * The health words whose severity is settled enough to act on without asking
 * anyone.
 *
 * This exists for exactly one caller and one decision. When AI metric discovery
 * proposes a string metric, {@see MetricDiscoveryService::bandsFor()}
 * refuses any row that puts the value the probe just OBSERVED into a paging
 * band, because a model that copies the sample it was shown into
 * `critical_values` hands the operator a metric that pages on its first check.
 * That refusal is correct far more often than not, and it also refused the one
 * configuration a health endpoint actually needs: a field reading `degraded`
 * belongs in `warn_values` and nowhere else. Measured on a production analyze of
 * a Laravel health endpoint, that is precisely the metric the operator lost.
 *
 * So this class answers the narrow question the refusal could not: is the word
 * itself unambiguous enough that the model's placement of it can be checked
 * rather than trusted?
 *
 * THREE PROPERTIES ARE LOAD-BEARING, and the value of the class is in all three
 * holding at once:
 *
 *   1. It only ever VALIDATES a placement, never authors one. Nothing here can
 *      add a value to a band, invent a synonym, or change a list. The model
 *      still chooses the field and still chooses the band; this decides whether
 *      that choice is allowed to stand. "Propose, do not decide" survives
 *      intact, because the proposal is still entirely the model's.
 *   2. An unknown word agrees with nothing, so it takes the refusal it took
 *      before this class existed. Adding an entry can only ever LIFT a refusal,
 *      never create one, which is what bounds the blast radius of a bad entry
 *      to the words actually written down here.
 *   3. Ambiguity is resolved by omission, not by a best guess. A word that
 *      means different things in different fields is left out, and the caller
 *      keeps refusing. One missing word costs a suggestion the operator can add
 *      by hand; one wrong word publishes a metric that either reads a sick
 *      service as healthy or pages on a well one, which is the failure this
 *      whole path exists to prevent.
 *
 * WHAT IS DELIBERATELY ABSENT, since the omissions carry more meaning than the
 * entries and a future reader will otherwise "fix" them:
 *
 *   - `maintenance`: neither healthy nor an outage, and a service publishing it
 *     usually means "expected", so any severity here is a guess. It is also the
 *     word an existing discovery test transcribes into `ok_values`.
 *   - `true` / `false`: no health meaning of their own. `debug: false` is
 *     healthy and `writable: false` is not, and only the field knows which.
 *   - `pending` / `unknown` / `starting` / `none`: these describe a service that
 *     has not answered yet, which is a different axis from well or unwell.
 *   - `green` / `amber` / `yellow` / `red`: a rendering convention rather than a
 *     state, and the identical words name a theme colour, so a payload field
 *     holding a brand colour would band and page on its first check.
 *   - `active` / `enabled`: a configuration fact, not a verdict.
 *
 * The two-word forms are the statuspage.io component vocabulary, which is the
 * single most common health-payload dialect in the wild. Note this is NOT the
 * same list as {@see ComponentStatus}: that enum is the four-case
 * shape uptizm PUBLISHES a catalog component in, closed by its own schema, and
 * borrowing it here would tie an open free-text dictionary to a closed
 * publishing contract.
 */
class HealthVocabulary
{
    /**
     * The band a health word denotes, or null when this class does not vouch
     * for it.
     *
     * A `default` arm rather than an exhaustive match, and unusually for this
     * codebase that is the point: the input is free text off a third party's
     * response, so the set is open by construction and "I do not know this word"
     * is a real and frequent answer rather than a case somebody forgot.
     */
    public static function bandFor(string $value): ?MetricBand
    {
        return match (self::normalize($value)) {
            'ok', 'up', 'healthy', 'pass', 'passing', 'operational',
            'available', 'online', 'running', 'ready', 'normal' => MetricBand::Ok,

            'degraded', 'degraded performance', 'warn', 'warning', 'partial',
            'partial outage', 'slow', 'elevated', 'minor', 'minor outage' => MetricBand::Warn,

            'down', 'critical', 'unhealthy', 'fail', 'failed', 'failing',
            'error', 'outage', 'major', 'major outage', 'unavailable',
            'offline', 'stopped', 'dead' => MetricBand::Critical,

            default => null,
        };
    }

    /**
     * Whether this class vouches for `$value` carrying exactly `$band`.
     *
     * The predicate the discovery path calls. False for a word this class does
     * not know, which is what leaves the caller's refusal in place.
     */
    public static function agrees(string $value, MetricBand $band): bool
    {
        return self::bandFor($value) === $band;
    }

    /**
     * The lookup form of a health word.
     *
     * Callers hand this values that have already been through
     * {@see ThresholdEvaluator::normalizeMatchValue()},
     * which trims and lowercases but leaves separators alone. Every dialect
     * writes its two-word states differently (`degraded_performance` on the
     * wire, `Degraded Performance` on a dashboard), so folding the separator is
     * this class's own job: the alternative is three spellings of one entry in
     * the table above, which is how a table drifts.
     *
     * Deliberately no stemming or fuzzy match. A near-miss that resolves to a
     * band is the one outcome worse than a miss that resolves to null.
     */
    protected static function normalize(string $value): string
    {
        $collapsed = preg_replace('/[\s\x{00A0}_\-]+/u', ' ', mb_strtolower($value));

        return trim((string) $collapsed);
    }
}
