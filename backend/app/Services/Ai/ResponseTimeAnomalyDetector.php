<?php

namespace App\Services\Ai;

use App\Enums\IncidentSeverity;
use DateTimeInterface;

/**
 * Pure statistical detector for response-time anomalies.
 *
 * Given a recent `response_ms` window and the monitor's configuration, it
 * returns a single {@see AnomalyCandidate} or null. It reads NO database and
 * NO clock: the caller (the scheduled sweep) supplies the window and its
 * bounds, which keeps every score reproducible and unit-testable.
 *
 * Two NIST-cited detectors run behind a cold-start gate:
 *
 *  - MAD z-score spike (e-Handbook eda35h): `M = 0.6745 * (x - median) / MAD`,
 *    flagged when `|M|` exceeds the MAD threshold. Robust to the spike itself.
 *  - EWMA drift (e-Handbook pmc324): `z_t = lambda*x_t + (1-lambda)*z_{t-1}`
 *    with control limit `L * sigma * sqrt(lambda/(2-lambda) * (1-(1-lambda)^2t))`.
 *    Catches small, sustained shifts a single-point test would miss.
 *
 * The cold-start gate is deliberately conjunctive: the statistical limits are
 * trusted ONLY when the window holds enough checks AND spans enough wall-clock
 * time. A burst of 100 checks in one second must not qualify. When the gate is
 * not met, the monitor's configured warn/critical bounds act as STATIC
 * thresholds so a sparse-cadence monitor is still evaluated instead of sitting
 * in cold-start forever; with neither history nor bounds, the result is null.
 *
 * Both statistical branches then pass through
 * {@see self::raisedAboveBaseline()}, which drops a fire whose latest reading is
 * not actually above its own baseline. A shift is not a reading, and this
 * detector is only ever asked about one direction.
 *
 * EWMA carries a SECOND direction gate inside {@see self::detectEwma()}, because
 * for that branch the two questions come apart: `raisedAboveBaseline()` reads the
 * latest raw sample and the branch's own statistic reads the smoothed centre, so
 * a falling series ending on a sample that grazes the mean passes the first and
 * fails the second. MAD needs no such gate: its score is a monotone function of
 * `observed - median`, the very comparison the shared guard makes.
 */
class ResponseTimeAnomalyDetector
{
    public const SIGNAL_RESPONSE_TIME = 'response_time';

    public const METHOD_EWMA = 'ewma';

    public const METHOD_MAD = 'mad';

    public const METHOD_STATIC = 'static';

    /**
     * Minimum sample count before the statistical limits are trusted. Rooted in
     * the Shewhart 25x4 guidance and NAB's 15% learning fraction (~100 checks).
     */
    private const DEFAULT_MIN_CHECKS = 100;

    /**
     * Minimum wall-clock span (seconds) the window must cover for its limits to
     * be trusted, so a compressed burst cannot fake a stable baseline.
     */
    private const DEFAULT_MIN_WINDOW_AGE = 1800;

    private const DEFAULT_LAMBDA = 0.25;

    private const DEFAULT_EWMA_L = 3.0;

    private const DEFAULT_MAD_CONSTANT = 0.6745;

    private const DEFAULT_MAD_THRESHOLD = 3.5;

    /**
     * Number of trailing samples that must agree (same direction, all breaching)
     * before a statistical anomaly is confirmed, damping single-sample blips.
     */
    private const DEFAULT_CONFIRM_K = 3;

    /**
     * Coarse dedupe bucket width (seconds); one bucket ~ one anomaly episode.
     */
    private const DEFAULT_DEDUPE_BUCKET = 900;

    /**
     * Score band boundary above which a MAD spike is critical rather than warn.
     * Bands: |M| in (threshold, 5] -> warn; |M| > 5 -> critical.
     */
    private const MAD_CRITICAL_SCORE = 5.0;

    /**
     * EWMA is critical when the standardized statistic exceeds 1.5x the control
     * limit multiplier L; between L and 1.5L it is warn.
     */
    private const EWMA_CRITICAL_FACTOR = 1.5;

    /**
     * Run the detector over a response-time window.
     *
     * @param  array<int, int|float>  $responseMsWindow  Oldest-to-newest `response_ms`.
     * @param  array<string, mixed>  $config  Monitor bounds + window metadata.
     */
    public function detect(array $responseMsWindow, array $config): ?AnomalyCandidate
    {
        $values = array_values(array_map(static fn (int|float $value): float => (float) $value, $responseMsWindow));

        if ($values === []) {
            return null;
        }

        // 1. Cold-start gate: trust the statistical limits only when the window
        //    is both deep enough AND spread over enough wall-clock time. Each
        //    branch is filtered on its own so a spike dropped for pointing the
        //    wrong way still falls through to the drift test.
        if ($this->statisticalLimitsTrusted($values, $config)) {
            return $this->raisedAboveBaseline($this->detectMad($values, $config))
                ?? $this->raisedAboveBaseline($this->detectEwma($values, $config));
        }

        // 2. Cold-start fallback: the configured bounds become static thresholds.
        return $this->detectStatic($values, $config);
    }

    /**
     * Drop a statistical candidate whose latest reading is not actually worse
     * than its own baseline.
     *
     * Both statistical branches measure a SHIFT rather than the current reading,
     * so both can fire while the endpoint is answering faster than usual. EWMA
     * carries the decaying tail of a spike that has already passed; MAD can
     * confirm a run of samples breaching in the NEGATIVE direction, which is a
     * service that got faster. Measured on production over 2026-08-15..17, two
     * of five EWMA fires had a latest reading at or below baseline (53ms against
     * 120.8ms, 89ms against 93.7ms), and the model handed that evidence answered
     * "No", "no_action" and "none" because there was nothing to describe.
     *
     * This is a product judgement, not a statistical correction. The signal is
     * `response_time` and every consumer reads it as "this endpoint is slow":
     * the incident title, the severity tier, the operator's card. There is no
     * vocabulary anywhere for "it got faster", so a fire that could only be
     * narrated that way is better not raised than raised and mislabelled.
     *
     * A candidate with no numeric baseline passes through untouched. That is the
     * static branch, which compared the latest sample against a CONFIGURED bound:
     * clearing that bound is itself the statement that the reading is high, and
     * there is no baseline to compare it to.
     */
    private function raisedAboveBaseline(?AnomalyCandidate $candidate): ?AnomalyCandidate
    {
        if ($candidate === null) {
            return null;
        }

        $observed = $candidate->evidence['observed'] ?? null;
        $baseline = $candidate->evidence['baseline'] ?? null;

        if (! is_int($observed) && ! is_float($observed)) {
            return $candidate;
        }

        if (! is_int($baseline) && ! is_float($baseline)) {
            return $candidate;
        }

        return (float) $observed > (float) $baseline ? $candidate : null;
    }

    /**
     * @param  array<int, float>  $values
     * @param  array<string, mixed>  $config
     */
    private function statisticalLimitsTrusted(array $values, array $config): bool
    {
        $minChecks = (int) ($config['min_checks'] ?? self::DEFAULT_MIN_CHECKS);
        $minAge = (int) ($config['min_window_age_seconds'] ?? self::DEFAULT_MIN_WINDOW_AGE);
        $age = $this->windowAgeSeconds($config);

        return count($values) >= $minChecks && $age !== null && $age >= $minAge;
    }

    /**
     * MAD z-score spike branch. Robust to the spike because the median and MAD
     * are unaffected by a small number of extreme samples.
     *
     * @param  array<int, float>  $values
     * @param  array<string, mixed>  $config
     */
    private function detectMad(array $values, array $config): ?AnomalyCandidate
    {
        $constant = (float) ($config['mad_constant'] ?? self::DEFAULT_MAD_CONSTANT);
        $threshold = (float) ($config['mad_threshold'] ?? self::DEFAULT_MAD_THRESHOLD);
        $confirmK = (int) ($config['confirm_k'] ?? self::DEFAULT_CONFIRM_K);

        $median = $this->median($values);
        $mad = $this->median(array_map(static fn (float $value): float => abs($value - $median), $values));

        // Guard MAD == 0: at least half the samples equal the median, so the
        // robust scale is undefined. A flat series has no spike; a drift is
        // EWMA's job, so never manufacture a spike from a zero scale.
        if ($mad === 0.0) {
            return null;
        }

        $scoreFor = static fn (float $value): float => $constant * ($value - $median) / $mad;
        $latestScore = $scoreFor($values[array_key_last($values)]);

        if (! $this->confirmed($values, $confirmK, $threshold, $scoreFor, $latestScore)) {
            return null;
        }

        $magnitude = abs($latestScore);
        $severity = $magnitude > self::MAD_CRITICAL_SCORE ? IncidentSeverity::Critical : IncidentSeverity::Warn;
        $direction = $latestScore >= 0.0 ? 1.0 : -1.0;
        $boundary = $median + $direction * ($threshold * $mad / $constant);

        return $this->buildCandidate(
            method: self::METHOD_MAD,
            score: $latestScore,
            severity: $severity,
            config: $config,
            values: $values,
            evidence: [
                'observed' => $values[array_key_last($values)],
                'baseline' => $median,
                'threshold' => $boundary,
                'unit' => 'ms',
            ],
        );
    }

    /**
     * EWMA drift branch. Smooths the series and flags a sustained shift of the
     * center away from the window mean beyond the time-varying control limit.
     *
     * @param  array<int, float>  $values
     * @param  array<string, mixed>  $config
     */
    private function detectEwma(array $values, array $config): ?AnomalyCandidate
    {
        $lambda = (float) ($config['lambda'] ?? self::DEFAULT_LAMBDA);
        $limitL = (float) ($config['ewma_l'] ?? self::DEFAULT_EWMA_L);
        $confirmK = (int) ($config['confirm_k'] ?? self::DEFAULT_CONFIRM_K);

        $baseline = $this->mean($values);
        $sigma = $this->populationStandardDeviation($values, $baseline);

        // Guard sigma == 0: a constant series has no variation to drift from.
        if ($sigma === 0.0) {
            return null;
        }

        // 1. Roll the EWMA statistic and standardize each step against its own
        //    time-varying control sigma so early points are not over-flagged.
        $z = $baseline;
        $scores = [];
        $smoothed = [];

        foreach ($values as $index => $value) {
            $z = $lambda * $value + (1.0 - $lambda) * $z;
            $step = $index + 1;
            $factor = ($lambda / (2.0 - $lambda)) * (1.0 - (1.0 - $lambda) ** (2 * $step));
            $sigmaZ = $sigma * sqrt($factor);
            $scores[] = $sigmaZ > 0.0 ? ($z - $baseline) / $sigmaZ : 0.0;
            $smoothed[] = $z;
        }

        $latestScore = $scores[array_key_last($scores)];
        $scoreFor = static fn (int $index): float => $scores[$index];

        if (! $this->confirmedByIndex($scores, $confirmK, $limitL, $scoreFor, $latestScore)) {
            return null;
        }

        // A negative statistic is a centre that drifted DOWN, which is the
        // endpoint answering faster. `raisedAboveBaseline()` cannot catch it:
        // that guard reads the latest RAW sample and this one reads the smoothed
        // CENTRE, and the two disagree whenever a falling series ends on a
        // sample that grazes the window mean. Measured on `betanket.com`,
        // 2026-08-28: observed 414ms over a 409.97ms baseline cleared the
        // reading guard by 1% while the centre sat at 286.93ms.
        //
        // Dropping it here rather than downstream is what keeps `threshold` an
        // UPPER bound. A negative direction wrote it BELOW the baseline, into a
        // field {@see SweepAiSuggestions::readingsAreUnder()} reads as the level
        // readings must fall under to recover, so the incident opened against a
        // level the monitor never reaches and no tick could ever close it.
        if ($latestScore < 0.0) {
            return null;
        }

        $magnitude = abs($latestScore);
        $severity = $magnitude > self::EWMA_CRITICAL_FACTOR * $limitL
            ? IncidentSeverity::Critical
            : IncidentSeverity::Warn;

        $lastFactor = ($lambda / (2.0 - $lambda)) * (1.0 - (1.0 - $lambda) ** (2 * count($values)));
        $controlLimit = $limitL * $sigma * sqrt($lastFactor);

        return $this->buildCandidate(
            method: self::METHOD_EWMA,
            score: $latestScore,
            severity: $severity,
            config: $config,
            values: $values,
            evidence: [
                'observed' => $values[array_key_last($values)],
                'baseline' => $baseline,
                'threshold' => $baseline + $controlLimit,
                'smoothed' => $smoothed[array_key_last($smoothed)],
                'unit' => 'ms',
            ],
        );
    }

    /**
     * Cold-start branch: compare the latest sample to the monitor's configured
     * warn/critical bounds (higher response time is worse, so bounds are upper).
     *
     * @param  array<int, float>  $values
     * @param  array<string, mixed>  $config
     */
    private function detectStatic(array $values, array $config): ?AnomalyCandidate
    {
        $warn = $this->nullableFloat($config['warn_bound'] ?? null);
        $critical = $this->nullableFloat($config['critical_bound'] ?? null);
        $latest = $values[array_key_last($values)];

        [$severity, $bound] = match (true) {
            $critical !== null && $latest >= $critical => [IncidentSeverity::Critical, $critical],
            $warn !== null && $latest >= $warn => [IncidentSeverity::Warn, $warn],
            default => [null, null],
        };

        if ($severity === null || $bound === null) {
            return null;
        }

        return $this->buildCandidate(
            method: self::METHOD_STATIC,
            score: $latest / $bound,
            severity: $severity,
            config: $config,
            values: $values,
            evidence: [
                'observed' => $latest,
                'baseline' => null,
                'threshold' => $bound,
                'unit' => 'ms',
            ],
        );
    }

    /**
     * Consecutive-confirmation over the trailing K raw samples: each must breach
     * the threshold in the same direction as the latest.
     *
     * @param  array<int, float>  $values
     * @param  callable(float): float  $scoreFor
     */
    private function confirmed(array $values, int $confirmK, float $threshold, callable $scoreFor, float $latestScore): bool
    {
        $direction = $latestScore >= 0.0 ? 1.0 : -1.0;
        $trailing = array_slice($values, -max(1, $confirmK));

        foreach ($trailing as $value) {
            $score = $scoreFor($value);

            if (abs($score) <= $threshold || ($score >= 0.0 ? 1.0 : -1.0) !== $direction) {
                return false;
            }
        }

        return true;
    }

    /**
     * Consecutive-confirmation over the trailing K precomputed scores (EWMA).
     *
     * @param  array<int, float>  $scores
     * @param  callable(int): float  $scoreFor
     */
    private function confirmedByIndex(array $scores, int $confirmK, float $threshold, callable $scoreFor, float $latestScore): bool
    {
        $direction = $latestScore >= 0.0 ? 1.0 : -1.0;
        $lastIndex = array_key_last($scores);
        $start = max(0, $lastIndex - max(1, $confirmK) + 1);

        for ($index = $start; $index <= $lastIndex; $index++) {
            $score = $scoreFor($index);

            if (abs($score) <= $threshold || ($score >= 0.0 ? 1.0 : -1.0) !== $direction) {
                return false;
            }
        }

        return true;
    }

    /**
     * Assemble the candidate with its evidence window and dedupe key.
     *
     * @param  array<int, float>  $values
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $evidence
     */
    private function buildCandidate(
        string $method,
        float $score,
        IncidentSeverity $severity,
        array $config,
        array $values,
        array $evidence,
    ): AnomalyCandidate {
        $region = (string) ($config['region'] ?? 'global');

        $evidence['window'] = [
            'from' => $config['window_from'] ?? null,
            'to' => $config['window_to'] ?? null,
            'n' => count($values),
        ];

        return new AnomalyCandidate(
            monitorId: (string) ($config['monitor_id'] ?? ''),
            signal: self::SIGNAL_RESPONSE_TIME,
            method: $method,
            score: $score,
            severity: $severity->value,
            evidence: $evidence,
            regionVotes: [$region => true],
            dedupeKey: $this->dedupeKey($method, $config),
        );
    }

    /**
     * Episode-oriented dedupe key: `monitor:{id}:response_time:{method}:{bucket}`.
     *
     * @param  array<string, mixed>  $config
     */
    private function dedupeKey(string $method, array $config): string
    {
        $monitorId = (string) ($config['monitor_id'] ?? '');
        $bucketWidth = (int) ($config['dedupe_bucket_seconds'] ?? self::DEFAULT_DEDUPE_BUCKET);
        $epoch = $this->toEpoch($config['window_to'] ?? null) ?? 0;
        $bucket = $bucketWidth > 0 ? intdiv($epoch, $bucketWidth) : $epoch;

        return sprintf('monitor:%s:%s:%s:%d', $monitorId, self::SIGNAL_RESPONSE_TIME, $method, $bucket);
    }

    /**
     * Resolve the window span in seconds: an explicit value wins, otherwise it
     * is derived from the from/to timestamps when both are present.
     *
     * @param  array<string, mixed>  $config
     */
    private function windowAgeSeconds(array $config): ?int
    {
        if (isset($config['window_age_seconds'])) {
            return (int) $config['window_age_seconds'];
        }

        $from = $this->toEpoch($config['window_from'] ?? null);
        $to = $this->toEpoch($config['window_to'] ?? null);

        if ($from === null || $to === null) {
            return null;
        }

        return $to - $from;
    }

    /**
     * Normalize a timestamp (epoch int, parseable string, or DateTimeInterface)
     * to epoch seconds, or null when it cannot be resolved.
     */
    private function toEpoch(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);

            return $parsed === false ? null : $parsed;
        }

        return null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        $sorted = $values;
        sort($sorted);
        $count = count($sorted);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $sorted[$middle];
        }

        return ($sorted[$middle - 1] + $sorted[$middle]) / 2.0;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    /**
     * Population standard deviation (divide by N), the process-sigma estimate
     * the EWMA control limit is scaled against.
     *
     * @param  array<int, float>  $values
     */
    private function populationStandardDeviation(array $values, float $mean): float
    {
        $sumSquares = array_reduce(
            $values,
            static fn (float $carry, float $value): float => $carry + ($value - $mean) ** 2,
            0.0,
        );

        return sqrt($sumSquares / count($values));
    }
}
