<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AnomalyCandidate;
use App\Services\Ai\ResponseTimeAnomalyDetector;
use PHPUnit\Framework\TestCase;

/**
 * Pins the deterministic correctness core of {@see ResponseTimeAnomalyDetector}.
 *
 * The detector is a PURE function (no DB, no clock): every expected M / z score
 * below is derived by hand from the NIST formulas (MAD z-score eda35h, EWMA
 * pmc324) against a FIXED input series, so a drift in the arithmetic breaks the
 * build. These tests are the red-first specification of the formulas, the
 * cold-start gate (check-count AND window-age), the MAD==0 guard, and the
 * consecutive-confirmation logic.
 *
 * This case extends the bare PHPUnit TestCase (not the Laravel one): the unit
 * under test touches no framework services, so no application is booted.
 */
class ResponseTimeAnomalyDetectorTest extends TestCase
{
    /**
     * A fixed reference timestamp for the newest sample, so the coarse-time
     * dedupe bucket (floor(epoch / 900) = 1_888_888) is deterministic.
     */
    private const WINDOW_TO = 1_700_000_000;

    /**
     * A window age (seconds) that comfortably clears the 1800s minimum, so the
     * statistical path is trusted: 100 checks at a 1/min cadence span 6000s.
     */
    private const AGE_OK = 6000;

    // ---------------------------------------------------------------------
    // MAD z-score spike branch
    // ---------------------------------------------------------------------

    public function test_a_clear_spike_flags_mad_with_the_exact_nist_score(): void
    {
        // Window (n=100): 25x90, 50x100, 24x110, then a single 900 spike.
        // Sorted median = 100. Absolute deviations sorted give a median of 5,
        // so MAD = 5. M = 0.6745 * (900 - 100) / 5 = 0.6745 * 160 = 107.92.
        $window = $this->spikeWindow();

        $candidate = $this->detect($window, $this->config(confirmK: 1));

        $this->assertInstanceOf(AnomalyCandidate::class, $candidate);
        $this->assertSame(ResponseTimeAnomalyDetector::METHOD_MAD, $candidate->method);
        $this->assertSame(ResponseTimeAnomalyDetector::SIGNAL_RESPONSE_TIME, $candidate->signal);
        $this->assertEqualsWithDelta(0.6745 * (900 - 100) / 5, $candidate->score, 1e-9);
        $this->assertSame('critical', $candidate->severity);

        // Evidence reads on the raw axis: observed 900ms against a MAD-derived
        // boundary of median + 3.5*MAD/0.6745 = 100 + 17.5/0.6745 = 125.945ms.
        $this->assertSame(900.0, $candidate->evidence['observed']);
        $this->assertSame(100.0, $candidate->evidence['baseline']);
        $this->assertEqualsWithDelta(100 + (3.5 * 5 / 0.6745), $candidate->evidence['threshold'], 1e-9);
        $this->assertSame('ms', $candidate->evidence['unit']);
        $this->assertSame(100, $candidate->evidence['window']['n']);

        $this->assertSame(['global' => true], $candidate->regionVotes);
        $this->assertSame('monitor:mon-1:response_time:mad:1888888', $candidate->dedupeKey);
    }

    public function test_mad_severity_bands_split_at_five(): void
    {
        // Same shape but a milder spike so |M| lands in the warn band (3.5, 5].
        // With MAD=5, M = 0.6745*(x-100)/5; picking x = 130 gives
        // M = 0.6745*30/5 = 4.047 -> warn.
        $window = $this->spikeWindow(spike: 130);

        $candidate = $this->detect($window, $this->config(confirmK: 1));

        $this->assertNotNull($candidate);
        $this->assertEqualsWithDelta(0.6745 * (130 - 100) / 5, $candidate->score, 1e-9);
        $this->assertSame('warn', $candidate->severity);
    }

    public function test_a_constant_series_never_flags_a_spike(): void
    {
        // MAD == 0 (every sample equals the median): the robust scale is
        // undefined, so the spike branch must be guarded off, and a flat series
        // has no drift either -> null.
        $window = array_fill(0, 100, 100);

        $this->assertNull($this->detect($window, $this->config()));
    }

    // ---------------------------------------------------------------------
    // EWMA drift branch
    // ---------------------------------------------------------------------

    public function test_a_small_sustained_drift_flags_ewma_with_the_exact_nist_score(): void
    {
        // Window (n=100): 90x100 then 10x110 (a +10ms, ~3.3-sigma-of-the-mean
        // step). MAD over this window is 0 (>=50% of samples equal the median),
        // so the spike branch is guarded off and EWMA owns the detection.
        //
        // mu_0 = mean = (90*100 + 10*110)/100 = 101.
        // sigma = population std = sqrt((90*1 + 10*81)/100) = sqrt(9) = 3.
        // EWMA settles at 100 over the first 90 samples, then ramps:
        //   z_100 = 110 - 10*0.75^10 = 109.436864852905...
        // sigma_Z (large t) = 3*sqrt(0.25/1.75) = 3*sqrt(1/7).
        // score = (z_100 - mu_0)/sigma_Z ~= 7.4406.
        $window = array_merge(array_fill(0, 90, 100), array_fill(0, 10, 110));

        $candidate = $this->detect($window, $this->config());

        $this->assertInstanceOf(AnomalyCandidate::class, $candidate);
        $this->assertSame(ResponseTimeAnomalyDetector::METHOD_EWMA, $candidate->method);

        $expectedZ = 110 - 10 * (0.75 ** 10);
        $sigmaZ = 3 * sqrt(1 / 7);
        $expectedScore = ($expectedZ - 101) / $sigmaZ;
        $this->assertEqualsWithDelta($expectedScore, $candidate->score, 1e-6);
        $this->assertGreaterThan(3.0, abs($candidate->score));
        $this->assertSame('critical', $candidate->severity);

        $this->assertSame(110.0, $candidate->evidence['observed']);
        $this->assertSame(101.0, $candidate->evidence['baseline']);
        $this->assertEqualsWithDelta($expectedZ, $candidate->evidence['smoothed'], 1e-6);
        $this->assertEqualsWithDelta(101 + 3 * $sigmaZ, $candidate->evidence['threshold'], 1e-6);
        $this->assertSame('monitor:mon-1:response_time:ewma:1888888', $candidate->dedupeKey);
    }

    public function test_a_stationary_noisy_series_returns_null(): void
    {
        // 96 samples oscillating 95/105 (mean 100), then 4x100. Every EWMA value
        // is a convex mix of samples in [95,105], so |z - mu_0| <= 5, always
        // under the ~5.55 control limit; the latest (100) also has M = 0.
        $osc = [];

        for ($i = 0; $i < 48; $i++) {
            $osc[] = 95;
            $osc[] = 105;
        }

        $window = array_merge($osc, [100, 100, 100, 100]);

        $this->assertNull($this->detect($window, $this->config()));
    }

    // ---------------------------------------------------------------------
    // Consecutive-confirmation
    // ---------------------------------------------------------------------

    public function test_a_single_sample_blip_is_suppressed_by_confirmation(): void
    {
        // 99 nominal samples + one 900 spike, but confirm_k = 3: only the last
        // sample breaches, so neither MAD (2 of the last 3 are nominal) nor EWMA
        // (the smoothed value has not sustained) confirms -> null.
        $window = array_merge(array_fill(0, 99, 100), [900]);

        $this->assertNull($this->detect($window, $this->config(confirmK: 3)));
    }

    public function test_a_sustained_spike_confirms_under_k_of_three(): void
    {
        // The last three samples all spike to 900; MAD stays non-zero on the
        // varied baseline, so all three breach the same direction -> flag.
        $window = array_merge(
            array_fill(0, 25, 90),
            array_fill(0, 50, 100),
            array_fill(0, 22, 110),
            [900, 900, 900],
        );

        $candidate = $this->detect($window, $this->config(confirmK: 3));

        $this->assertNotNull($candidate);
        $this->assertSame(ResponseTimeAnomalyDetector::METHOD_MAD, $candidate->method);
    }

    // ---------------------------------------------------------------------
    // Direction sanity: the reading, not just the shift
    // ---------------------------------------------------------------------

    public function test_a_spike_that_has_already_passed_is_not_flagged(): void
    {
        // The production shape, measured on fluttersdk.com over 2026-08-15..17:
        // a spike ends and the EWMA carries its decaying tail for several more
        // samples, so the statistic still clears its control limit while the
        // endpoint is already answering FASTER than its own window average.
        //
        // 90x100, 9x400, then one 50. mean = 126.5, so the latest sample sits
        // well below the baseline. MAD is guarded off (>=50% of samples equal
        // the median), so EWMA owns this and its smoothed value is still ~295
        // against a ~224 limit: it flags, and there is nothing to flag.
        $window = array_merge(array_fill(0, 90, 100), array_fill(0, 9, 400), [50]);

        $this->assertNull($this->detect($window, $this->config()));
    }

    public function test_an_endpoint_that_got_faster_is_not_an_anomaly(): void
    {
        // The MAD half of the same rule. Three trailing 1ms samples breach the
        // robust threshold hard (M ~= -13.4) in the NEGATIVE direction, so the
        // spike branch confirms and would raise a `critical` response-time
        // anomaly for a service that got a hundred times faster.
        $window = array_merge(
            array_fill(0, 25, 90),
            array_fill(0, 50, 100),
            array_fill(0, 22, 110),
            [1, 1, 1],
        );

        $this->assertNull($this->detect($window, $this->config()));
    }

    public function test_a_real_drift_upward_still_flags(): void
    {
        // The guard is about the DIRECTION of the reading, not its size: the
        // same window shape with the latest sample above the baseline is still
        // an anomaly, or the rule would have silenced the detector outright.
        $window = array_merge(array_fill(0, 90, 100), array_fill(0, 10, 400));

        $candidate = $this->detect($window, $this->config());

        $this->assertNotNull($candidate);
        $this->assertGreaterThan(
            $candidate->evidence['baseline'],
            $candidate->evidence['observed'],
        );
    }

    public function test_a_downward_drift_is_not_flagged_by_one_sample_over_the_mean(): void
    {
        // The production shape, measured on `betanket.com` on 2026-08-28:
        // observed 414ms against a baseline of 409.97ms cleared the reading
        // guard by 1%, while the SMOOTHED centre sat at 286.93ms, far below the
        // same baseline. The two answer different questions, and only the first
        // one was being asked.
        //
        // 74x600, 25x200, then a single 500. MAD is guarded off (74 of 100
        // deviations from the median are 0), so EWMA owns this.
        //
        // mean = (74*600 + 25*200 + 500)/100 = 499, so the latest sample clears
        // its own baseline by 1ms and the reading guard passes.
        // EWMA settles at ~200.30 over the 25 low samples, then the 500 lifts it
        // to 0.25*500 + 0.75*200.30 = 275.23: still 224ms UNDER the baseline.
        // sigma = 172.913, sigma_Z = sigma*sqrt(1/7) = 65.353, so the statistic
        // is -3.42 and confirms in the negative direction over the trailing 3.
        //
        // Left unguarded this raises a `response_time` anomaly whose threshold
        // is baseline - 3*sigma_Z = 302.9, i.e. BELOW the baseline, on a monitor
        // that never answers that fast. SweepAiSuggestions resolves an incident
        // only once the trailing readings all sit under the level it was raised
        // against, so such an incident opens and can never close.
        $window = array_merge(array_fill(0, 74, 600), array_fill(0, 25, 200), [500]);

        $this->assertNull($this->detect($window, $this->config()));
    }

    public function test_a_raised_threshold_is_always_above_its_baseline(): void
    {
        // The contract SweepAiSuggestions::readingsAreUnder() consumes: the
        // stored `threshold` is an UPPER bound, so "the readings came back under
        // it" is a satisfiable recovery test. A threshold written below the
        // baseline inverts that predicate into one nothing can satisfy.
        // The falling window is in this list on purpose: it is the one that
        // raised an inverted threshold, so a list of rising windows alone would
        // pass with the defect in place and prove nothing.
        $windows = [
            'mad spike' => $this->spikeWindow(),
            'ewma step' => array_merge(array_fill(0, 90, 100), array_fill(0, 10, 110)),
            'ewma drift' => array_merge(array_fill(0, 90, 100), array_fill(0, 10, 400)),
            'ewma fall' => array_merge(array_fill(0, 74, 600), array_fill(0, 25, 200), [500]),
        ];

        foreach ($windows as $label => $window) {
            $candidate = $this->detect($window, $this->config(confirmK: 1));

            if ($candidate === null) {
                continue;
            }

            $this->assertGreaterThan(
                $candidate->evidence['baseline'],
                $candidate->evidence['threshold'],
                $label,
            );
        }
    }

    // ---------------------------------------------------------------------
    // Cold-start gate + static bounds
    // ---------------------------------------------------------------------

    public function test_below_min_checks_uses_static_bounds_for_a_critical_breach(): void
    {
        // Only 30 samples -> cold-start. The static critical bound (800ms) is
        // crossed by the latest 900ms sample; score is the exceedance ratio.
        $window = array_merge(array_fill(0, 29, 120), [900]);

        $candidate = $this->detect(
            $window,
            $this->config(warnBound: 300.0, criticalBound: 800.0),
        );

        $this->assertInstanceOf(AnomalyCandidate::class, $candidate);
        $this->assertSame(ResponseTimeAnomalyDetector::METHOD_STATIC, $candidate->method);
        $this->assertSame('critical', $candidate->severity);
        $this->assertEqualsWithDelta(900.0 / 800.0, $candidate->score, 1e-9);
        $this->assertSame(900.0, $candidate->evidence['observed']);
        $this->assertSame(800.0, $candidate->evidence['threshold']);
        $this->assertSame('monitor:mon-1:response_time:static:1888888', $candidate->dedupeKey);
    }

    public function test_below_min_checks_flags_warn_when_only_the_warn_bound_is_crossed(): void
    {
        $window = array_merge(array_fill(0, 29, 120), [400]);

        $candidate = $this->detect(
            $window,
            $this->config(warnBound: 300.0, criticalBound: 800.0),
        );

        $this->assertNotNull($candidate);
        $this->assertSame('warn', $candidate->severity);
        $this->assertEqualsWithDelta(400.0 / 300.0, $candidate->score, 1e-9);
        $this->assertSame(300.0, $candidate->evidence['threshold']);
    }

    public function test_below_min_checks_with_a_nominal_latest_returns_null(): void
    {
        $window = array_merge(array_fill(0, 29, 120), [150]);

        $this->assertNull($this->detect(
            $window,
            $this->config(warnBound: 300.0, criticalBound: 800.0),
        ));
    }

    public function test_no_history_and_no_bounds_returns_null(): void
    {
        $window = array_fill(0, 10, 200);

        $this->assertNull($this->detect($window, $this->config()));
    }

    public function test_a_compressed_window_falls_back_to_static_even_above_min_checks(): void
    {
        // 100 samples clears MIN_CHECKS, but the window spans only 60s (a burst),
        // below MIN_WINDOW_AGE. The statistical limits must NOT be trusted; the
        // static critical bound owns the 900ms latest instead.
        $window = array_merge(array_fill(0, 99, 100), [900]);

        $candidate = $this->detect(
            $window,
            $this->config(warnBound: 300.0, criticalBound: 800.0, ageSeconds: 60),
        );

        $this->assertInstanceOf(AnomalyCandidate::class, $candidate);
        $this->assertSame(ResponseTimeAnomalyDetector::METHOD_STATIC, $candidate->method);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Build the reference 100-sample spike window: a varied baseline (median
     * 100, MAD 5) capped by a single spike as the newest sample.
     *
     * @return array<int, int>
     */
    private function spikeWindow(int $spike = 900): array
    {
        return array_merge(
            array_fill(0, 25, 90),
            array_fill(0, 50, 100),
            array_fill(0, 24, 110),
            [$spike],
        );
    }

    /**
     * @param  array<int, int|float>  $window
     */
    private function detect(array $window, array $config): ?AnomalyCandidate
    {
        return (new ResponseTimeAnomalyDetector)->detect($window, $config);
    }

    /**
     * Assemble a detector config with sane test defaults.
     *
     * @return array<string, mixed>
     */
    private function config(
        ?float $warnBound = null,
        ?float $criticalBound = null,
        int $ageSeconds = self::AGE_OK,
        int $confirmK = 3,
    ): array {
        return [
            'monitor_id' => 'mon-1',
            'warn_bound' => $warnBound,
            'critical_bound' => $criticalBound,
            'window_to' => self::WINDOW_TO,
            'window_from' => self::WINDOW_TO - $ageSeconds,
            'window_age_seconds' => $ageSeconds,
            'confirm_k' => $confirmK,
        ];
    }
}
