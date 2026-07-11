<?php

namespace App\Services\Ai;

/**
 * An immutable statistical anomaly proposal produced by a detector.
 *
 * This is the non-secret hand-off between the deterministic detection layer
 * (pure, statistics-only) and the triage layer that later labels it with an
 * LLM. It carries the evidence a human or model needs to narrate the anomaly
 * WITHOUT any probe-controlled payload (headers, body, error text): the raw
 * observed value, the baseline it deviated from, the threshold it crossed, the
 * bounded window it was measured over, and the per-region votes.
 *
 * The `score` is the standardized statistic (a MAD M value, an EWMA z score, or
 * a static exceedance ratio) and `severity` is the band that score fell into.
 * `dedupeKey` is episode-oriented (coarse time bucket) so repeated detections
 * inside one incident collapse to a single suggestion downstream.
 */
readonly class AnomalyCandidate
{
    /**
     * @param  string  $monitorId  The monitor this candidate was raised for.
     * @param  string  $signal  The observed signal, e.g. `response_time`.
     * @param  string  $method  The detection method: `ewma`, `mad`, or `static`.
     * @param  float  $score  The standardized statistic behind the flag.
     * @param  string  $severity  The score band: `warn` or `critical`.
     * @param  array<string, mixed>  $evidence  Redacted, non-secret evidence
     *                                          (`observed`, `baseline`, `threshold`, `unit`, `window`).
     * @param  array<string, bool>  $regionVotes  Per-region anomaly votes.
     * @param  string  $dedupeKey  Episode-oriented idempotency key.
     */
    public function __construct(
        public string $monitorId,
        public string $signal,
        public string $method,
        public float $score,
        public string $severity,
        public array $evidence,
        public array $regionVotes,
        public string $dedupeKey,
    ) {}

    /**
     * Flatten to the snake_case wire shape the triage job carries as an
     * argument (the job never receives the raw check payload, only this).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monitor_id' => $this->monitorId,
            'signal' => $this->signal,
            'method' => $this->method,
            'score' => $this->score,
            'severity' => $this->severity,
            'evidence' => $this->evidence,
            'region_votes' => $this->regionVotes,
            'dedupe_key' => $this->dedupeKey,
        ];
    }
}
