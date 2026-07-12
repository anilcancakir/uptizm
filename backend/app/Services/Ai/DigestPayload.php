<?php

namespace App\Services\Ai;

use App\Jobs\GenerateWeeklyDigest;

/**
 * The immutable evidence handed to the weekly-digest LLM.
 *
 * Cloned from {@see IncidentAnalysisPayload} for a different moment in the
 * lifecycle: instead of a single incident's timeline, this fences a team's
 * whole week (aggregate uptime, the trend against the prior week, and the
 * incidents opened during the window).
 *
 * Unlike {@see TriagePayload} / {@see IncidentAnalysisPayload}, this payload
 * carries no probe-controlled (attacker-influenceable) fields: uptime
 * percentages come from our own `monitor_daily_uptime` rollup and every
 * incident field is our own product data (opened by a threshold breach, an
 * AI signal, or an operator, never by the monitored target). There is
 * therefore no UNTRUSTED PROBE DATA fence to build here; only the
 * OWNED-CITATION CATALOG (the incident ids and monitor ids folded into this
 * payload) survives, so the gateway can still strip any citation the model
 * hallucinates back out of the narration before it is persisted.
 *
 * The caller ({@see GenerateWeeklyDigest}) hydrates this from the
 * team's monitors, its `monitor_daily_uptime` rollup, and its incidents; the
 * payload itself performs no I/O and holds no secrets.
 */
readonly class DigestPayload
{
    /**
     * @param  string  $teamId  The team the digest is generated for.
     * @param  string  $weekStart  ISO-8601 date, the start of the digest window.
     * @param  string  $weekEnd  ISO-8601 date, the end of the digest window.
     * @param  float  $uptimePercent  The team's aggregate uptime percent this week.
     * @param  float  $previousUptimePercent  The team's aggregate uptime percent the prior week, for trend narration.
     * @param  int  $incidentCount  The number of incidents opened this week.
     * @param  list<array{incident_id: string, title: string, severity: string, impact: string, started_at: string, resolved_at: string|null}>  $incidents  TRUSTED incidents opened this week.
     * @param  list<string>  $knownIncidentIds  The owned catalog of incident ids folded into this payload.
     * @param  list<string>  $knownMonitorIds  The owned catalog of the team's monitor ids.
     */
    public function __construct(
        public string $teamId,
        public string $weekStart,
        public string $weekEnd,
        public float $uptimePercent,
        public float $previousUptimePercent,
        public int $incidentCount,
        public array $incidents,
        public array $knownIncidentIds,
        public array $knownMonitorIds,
    ) {}

    /**
     * Build the user message: the trusted weekly evidence stated plainly.
     * There is no untrusted block here (see class docblock).
     */
    public function buildUserMessage(): string
    {
        return implode("\n", [
            'EVIDENCE (team-owned, trusted):',
            "team_id: {$this->teamId}",
            "week_start: {$this->weekStart}",
            "week_end: {$this->weekEnd}",
            'uptime_percent: '.$this->uptimePercent,
            'previous_uptime_percent: '.$this->previousUptimePercent,
            'incident_count: '.$this->incidentCount,
            'incidents: '.$this->encode($this->incidents),
            'known incident_ids: '.$this->encode($this->knownIncidentIds),
            'known monitor_ids: '.$this->encode($this->knownMonitorIds),
        ])."\n\nSummarize this team's week using only the evidence above.";
    }

    /**
     * Determine whether a cited owned signal is actually in our catalog.
     *
     * @param  string  $type  One of `incident_id` or `monitor_id`.
     */
    public function isKnownCitation(string $type, string $value): bool
    {
        $catalog = match ($type) {
            'incident_id' => $this->knownIncidentIds,
            'monitor_id' => $this->knownMonitorIds,
            default => [],
        };

        return in_array($value, $catalog, true);
    }

    /**
     * Compactly encode a structured value for a single prompt line.
     */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
