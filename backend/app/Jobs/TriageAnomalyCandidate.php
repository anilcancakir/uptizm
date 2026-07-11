<?php

namespace App\Jobs;

use App\Enums\AiConfidence;
use App\Enums\AiMode;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Models\AiSuggestion;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AnomalyCandidate;
use App\Services\Ai\AnomalyTriageGateway;
use App\Services\Ai\TriagePayload;
use App\Services\Ai\TriageResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Triages one statistical anomaly candidate into a single {@see AiSuggestion}.
 *
 * This is the security spine of AI suggest mode, and it holds five invariants
 * that the rest of the pipeline depends on:
 *
 *  - IDS-IN, HYDRATE-IN-HANDLE: the job is constructed with the monitor id and
 *    the NON-secret candidate DTO only, never a raw check/response payload. The
 *    probe-controlled evidence is read and REDACTED here, at run time.
 *  - REDACT + NEVER LOG: sensitive response headers (Set-Cookie, Authorization,
 *    ...) are dropped and every untrusted field is hard-truncated before it
 *    reaches the LLM boundary; the payload and the LLM response are never logged.
 *  - ATOMIC BUDGET: the per-team daily cap is spent with an atomic increment at
 *    the spend point ({@see AiBudget}), so racing workers cannot overspend.
 *  - DEGRADE-PERSISTS: over budget OR a gateway failure never drops the anomaly;
 *    it degrades to a deterministic statistical suggestion. Statistics are the
 *    source of truth; the LLM only labels.
 *  - IDEMPOTENT DEDUPE: a suggestion already carrying this `dedupe_key` short
 *    circuits, and the unique key makes a concurrent race lose gracefully, so
 *    exactly one row is ever written per signal episode.
 */
class TriageAnomalyCandidate implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The ONLY response header names allowed to reach the LLM. This is a
     * fail-closed allowlist, not a denylist: a probe-controlled endpoint can
     * name a secret-bearing header anything (x-auth-token, x-amz-security-token,
     * a custom bearer header), so anything not explicitly known-safe is dropped.
     * Matched case-insensitively.
     */
    private const SAFE_HEADERS = [
        'content-type',
        'content-length',
        'content-encoding',
        'server',
        'date',
        'cache-control',
        'last-modified',
        'etag',
        'vary',
        'age',
        'via',
    ];

    /**
     * How many recent check ids seed the owned-signal catalog the gateway
     * allowlists citations against.
     */
    private const CATALOG_LIMIT = 50;

    /**
     * Days a pending suggestion stays actionable before it is considered stale.
     */
    private const EXPIRES_AFTER_DAYS = 7;

    /**
     * @param  string  $monitorId  The monitor the anomaly fired on.
     * @param  array<string, mixed>  $candidateData  The non-secret candidate DTO
     *                                               (from {@see AnomalyCandidate::toArray()}).
     */
    public function __construct(
        public string $monitorId,
        public array $candidateData,
    ) {
        $this->onQueue('ai');
    }

    /**
     * Resolve, gate, hydrate, budget, and persist exactly one suggestion.
     *
     * @param  AnomalyTriageGateway  $gateway  The LLM boundary (faked in tests).
     * @param  AiBudget  $budget  The atomic per-team daily spend guard.
     */
    public function handle(AnomalyTriageGateway $gateway, AiBudget $budget): void
    {
        // 1. Resolve the monitor and re-check the gate at run time: the mode may
        //    have flipped to Off/Auto between the sweep and this job. Auto is
        //    deferred, so only Suggest proceeds.
        $monitor = Monitor::query()->find($this->monitorId);
        if ($monitor === null || $monitor->ai_mode !== AiMode::Suggest) {
            return;
        }

        // 2. Idempotency: a live suggestion for this episode already exists, so
        //    there is nothing to spend or write.
        $dedupeKey = (string) $this->candidateData['dedupe_key'];
        if (AiSuggestion::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return;
        }

        // 3. Hydrate + redact the probe-controlled evidence into a self-contained
        //    payload. Secrets are stripped here, before the LLM boundary.
        $payload = $this->buildPayload($monitor);

        // 4. Spend one unit of the team's daily budget atomically. Over budget is
        //    not a failure: it degrades, it never gates the anomaly away.
        if (! $budget->tryConsume($monitor->team_id)) {
            $this->persistStatistical($monitor, $dedupeKey);

            return;
        }

        // 5. Within budget: label via the LLM. Any gateway failure degrades to a
        //    deterministic statistical suggestion (the anomaly always stands).
        try {
            $result = $gateway->triage($payload);
        } catch (Throwable) {
            // Deliberate degrade, not a silent swallow: statistics are the source
            // of truth and the anomaly must still reach the inbox. The exception
            // detail is withheld from the log because it may echo probe payload.
            Log::warning('AI triage degraded to a statistical suggestion.', [
                'monitor_id' => $monitor->id,
            ]);
            $this->persistStatistical($monitor, $dedupeKey);

            return;
        }

        $this->persistFromTriage($monitor, $dedupeKey, $result);
    }

    /**
     * Assemble the self-contained triage payload: the candidate's redacted,
     * non-secret evidence plus the hydrated-and-redacted untrusted probe fields
     * and the owned-signal catalog the gateway allowlists against.
     */
    private function buildPayload(Monitor $monitor): TriagePayload
    {
        $candidate = $this->candidateData;

        // The single most recent check supplies the untrusted probe fields; a
        // monitor with no checks yet simply yields empty/none values.
        $latestCheck = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->orderByDesc('checked_at')
            ->first();

        $metricStringValue = MonitorMetricValue::query()
            ->where('monitor_id', $monitor->id)
            ->whereNotNull('string_value')
            ->orderByDesc('recorded_at')
            ->value('string_value');

        return new TriagePayload(
            monitorId: $monitor->id,
            signal: (string) $candidate['signal'],
            method: (string) $candidate['method'],
            score: (float) $candidate['score'],
            severity: (string) $candidate['severity'],
            evidence: $candidate['evidence'],
            regionVotes: $candidate['region_votes'],
            knownCheckIds: $this->knownCheckIds($monitor),
            knownMetricKeys: $this->knownMetricKeys($monitor),
            knownRegions: $this->knownRegions($monitor),
            errorMessage: $this->truncate($latestCheck?->error_message),
            responseBodyPreview: $this->truncate($latestCheck?->response_body_preview),
            responseHeaders: $this->redactHeaders($latestCheck?->response_headers ?? []),
            metricStringValue: $this->truncate(is_string($metricStringValue) ? $metricStringValue : null),
        );
    }

    /**
     * Keep ONLY allowlisted, known-safe headers. Fail-closed: an unknown header
     * (which a hostile endpoint could use to smuggle a credential) is dropped
     * before it reaches the payload, the LLM, or storage. Defense in depth: the
     * payload also fences and truncates what survives.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function redactHeaders(array $headers): array
    {
        $safe = [];

        foreach ($headers as $name => $value) {
            if (! in_array(strtolower((string) $name), self::SAFE_HEADERS, true)) {
                continue;
            }

            $encoded = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');
            $safe[(string) $name] = (string) $this->truncate($encoded);
        }

        return $safe;
    }

    /**
     * Hard-truncate an untrusted value to the payload's field cap, so a hostile
     * endpoint cannot inflate the context even before the payload fences it.
     */
    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, TriagePayload::UNTRUSTED_FIELD_MAX_LENGTH);
    }

    /**
     * The recent check ids the model is allowed to cite.
     *
     * @return list<string>
     */
    private function knownCheckIds(Monitor $monitor): array
    {
        return MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->orderByDesc('checked_at')
            ->limit(self::CATALOG_LIMIT)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * The metric keys the model is allowed to cite.
     *
     * @return list<string>
     */
    private function knownMetricKeys(Monitor $monitor): array
    {
        return MonitorMetric::query()
            ->where('monitor_id', $monitor->id)
            ->pluck('key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();
    }

    /**
     * The regions the model is allowed to cite: the candidate's voting regions
     * plus the monitor's configured region set.
     *
     * @return list<string>
     */
    private function knownRegions(Monitor $monitor): array
    {
        $voting = array_keys($this->candidateData['region_votes'] ?? []);
        $configured = is_array($monitor->regions) ? $monitor->regions : [];

        return array_values(array_unique(array_map(
            static fn (mixed $region): string => (string) $region,
            array_merge($voting, $configured),
        )));
    }

    /**
     * Persist an LLM-labeled suggestion. Confidence, severity, and the (already
     * allowlist-cleaned) recommendation come from the model; the score and
     * evidence come from the statistical candidate, which is the source of truth.
     */
    private function persistFromTriage(Monitor $monitor, string $dedupeKey, TriageResult $result): void
    {
        $this->persist($monitor, $dedupeKey, [
            'severity' => $result->severity,
            'confidence' => $result->confidence,
            'source' => 'llm',
            'recommendation' => $result->recommendation,
        ]);
    }

    /**
     * Persist the deterministic degrade path: a templated recommendation and a
     * confidence read straight off the statistical severity band. No LLM text.
     */
    private function persistStatistical(Monitor $monitor, string $dedupeKey): void
    {
        $this->persist($monitor, $dedupeKey, [
            'severity' => (string) $this->candidateData['severity'],
            'confidence' => $this->statisticalConfidence(),
            'source' => 'statistical',
            'recommendation' => $this->statisticalRecommendation(),
        ]);
    }

    /**
     * Write exactly one suggestion, merging the source-specific fields over the
     * common statistical base. A lost `dedupe_key` race is swallowed on purpose:
     * the row already exists, which is precisely what the unique key guarantees.
     *
     * @param  array<string, mixed>  $sourceFields
     */
    private function persist(Monitor $monitor, string $dedupeKey, array $sourceFields): void
    {
        $attributes = array_merge([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => (string) $this->candidateData['signal'],
            'method' => (string) $this->candidateData['method'],
            'score' => (float) $this->candidateData['score'],
            'evidence' => $this->candidateData['evidence'],
            'dedupe_key' => $dedupeKey,
            'status' => AiSuggestionStatus::Pending,
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ], $sourceFields);

        try {
            AiSuggestion::query()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker won the same dedupe_key: the suggestion for this
            // episode is already in the inbox, so there is nothing left to do.
        }
    }

    /**
     * Map the statistical severity band (the detector's score band) to an inbox
     * confidence. The band already encodes how far the score cleared the limit.
     */
    private function statisticalConfidence(): AiConfidence
    {
        return match ((string) $this->candidateData['severity']) {
            'critical' => AiConfidence::High,
            'warn' => AiConfidence::Medium,
            default => AiConfidence::Low,
        };
    }

    /**
     * Build a deterministic, human-readable recommendation from the non-secret
     * evidence only. It cites no probe-controlled data and calls no model.
     */
    private function statisticalRecommendation(): string
    {
        $evidence = $this->candidateData['evidence'] ?? [];
        $unit = is_string($evidence['unit'] ?? null) ? $evidence['unit'] : '';
        $observed = $this->evidenceNumber($evidence['observed'] ?? null, $unit);
        $baseline = $this->evidenceNumber($evidence['baseline'] ?? null, $unit);
        $method = strtoupper((string) $this->candidateData['method']);

        $sentence = "Response time flagged by the {$method} detector: observed {$observed}";
        if ($baseline !== null) {
            $sentence .= " against a {$baseline} baseline";
        }

        return $sentence.'. Review the recent checks and confirm before opening an incident.';
    }

    /**
     * Render an evidence number with its unit, or null when it is absent.
     */
    private function evidenceNumber(mixed $value, string $unit): ?string
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').$unit;
    }
}
