<?php

namespace App\Services\Ai;

use App\Models\Monitor;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Services\Monitoring\MetricExtractor;
use App\Support\Monitoring\MetricCandidate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a captured response body into a list of proposed monitor metrics, by
 * generating every extraction candidate itself and asking the model only which
 * of them are worth keeping.
 *
 * This class owns the second half of the selection contract. The gateway refuses
 * a ref that was never sent; here every accepted ref is RESOLVED back to the
 * {@see MetricCandidate} this backend generated, and the emitted `path` is that
 * candidate's path. Nothing the model said contributes an extraction rule, and
 * nothing it said supplies the machine `key` either: that is slugged from the
 * sanitized label and made unique per monitor here.
 *
 * One more refusal lives here because only this class holds the candidates: a
 * selection whose type is not in that candidate's `eligibleTypes`.
 * {@see MetricExtractor::validateType()} discards a non-numeric value under
 * `numeric`, so a `120ms` candidate accepted as numeric would extract on every
 * check and record nothing, which reads to the operator as a metric that is
 * silently always empty.
 *
 * Degradation mirrors {@see IncidentAnalysisService}: over budget, an
 * unreachable provider, or output the gateway refuses past its retry all return
 * the SAME empty array rather than null or an error, so the caller's wire shape
 * never changes shape on a bad day. Nothing here writes a metric; a suggestion
 * is a suggestion and the operator still submits the existing form.
 */
class MetricDiscoveryService
{
    /**
     * The `monitor_metrics.key` column width.
     */
    protected const int KEY_MAX_LENGTH = 40;

    /**
     * Fallback stem when a label slugs to nothing, e.g. a label written entirely
     * in a script `Str::slug()` transliterates away.
     */
    protected const string KEY_FALLBACK = 'metric';

    public function __construct(
        protected MetricCandidateExtractor $extractor,
        protected LaravelAiMetricDiscoveryGateway $gateway,
        protected AiBudget $budget,
    ) {}

    /**
     * The metrics worth proposing for `$monitor` from `$body`.
     *
     * Always an array, never null: an empty one is the honest answer for a body
     * with no candidates, an exhausted budget, or a gateway that could not be
     * trusted.
     *
     * @param  Monitor  $monitor  Persisted, or the transient instance the analyze
     *                            path probes with before a row exists.
     * @param  string|null  $body  The captured response body to mine.
     * @param  string  $teamId  The team whose daily AI budget this spends. Passed
     *                          explicitly because the analyze path's monitor is
     *                          transient and carries no `team_id`.
     * @return list<array<string, mixed>>
     */
    public function discover(Monitor $monitor, ?string $body, string $teamId): array
    {
        // 1. Generate the candidates first. No candidates means there is nothing
        //    for a model to select among, so this costs neither a budget unit nor
        //    a provider call.
        if ($body === null || trim($body) === '') {
            return [];
        }

        $candidates = $this->extractor->extract($body);
        if ($candidates === []) {
            return [];
        }

        // 2. Spend one unit of the team's daily AI budget atomically. Over budget
        //    is not a failure: it degrades to no suggestions, and never calls the
        //    provider.
        if (! $this->budget->tryConsume($teamId)) {
            return [];
        }

        $result = $this->select($monitor, $candidates);
        if ($result === null) {
            return [];
        }

        return $this->toWireRows($monitor, $candidates, $result);
    }

    /**
     * Ask the gateway to select among the candidates, or null when it could not
     * be trusted or could not be reached.
     *
     * @param  list<MetricCandidate>  $candidates
     */
    protected function select(Monitor $monitor, array $candidates): ?MetricDiscoveryResult
    {
        $payload = $this->payload($monitor, $candidates);

        // Non-conforming output past the gateway's own retry, and an unreachable
        // provider (outage, timeout, or a missing key), degrade identically. The
        // transport failure is logged first so the ops problem stays visible.
        try {
            return $this->gateway->discover($payload);
        } catch (RuntimeException $exception) {
            Log::warning('Metric discovery degraded: the model output could not be trusted.', [
                'monitor_id' => (string) ($monitor->getKey() ?? ''),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Metric discovery degraded: the AI service was unreachable.', [
                'monitor_id' => (string) ($monitor->getKey() ?? ''),
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The two-trust-zone payload: our monitor context and ref catalog outside the
     * fence, the candidate digest inside it.
     *
     * @param  list<MetricCandidate>  $candidates
     */
    protected function payload(Monitor $monitor, array $candidates): MetricDiscoveryPayload
    {
        return new MetricDiscoveryPayload(
            url: (string) $monitor->url,
            monitorType: $monitor->type?->value ?? '',
            candidateRefs: array_map(fn (MetricCandidate $candidate): string => $candidate->ref, $candidates),
            digestRows: array_map(fn (MetricCandidate $candidate): array => $candidate->toDigestRow(), $candidates),
        );
    }

    /**
     * Map accepted selections onto the pinned `suggested_metrics` wire shape.
     *
     * @param  list<MetricCandidate>  $candidates
     * @return list<array<string, mixed>>
     */
    protected function toWireRows(Monitor $monitor, array $candidates, MetricDiscoveryResult $result): array
    {
        $byRef = [];
        foreach ($candidates as $candidate) {
            $byRef[$candidate->ref] = $candidate;
        }

        $takenKeys = $this->existingKeys($monitor);
        $rows = [];

        foreach ($result->selections as $selection) {
            // 1. Resolve the ref back to OUR candidate. A ref with no candidate
            //    is dropped here as well as at the gateway: the two checks are
            //    deliberately independent, because either one alone is one edit
            //    away from letting a model-authored path through.
            $candidate = $byRef[$selection['ref']] ?? null;
            if ($candidate === null) {
                continue;
            }

            // 2. Refuse a type this candidate's own sample cannot sustain, or the
            //    operator gets a metric that extracts and then discards on every
            //    single check.
            if (! in_array($selection['type'], $candidate->eligibleTypes, true)) {
                continue;
            }

            $key = $this->uniqueKey($selection['label'], $takenKeys);
            $takenKeys[] = $key;

            $rows[] = [
                'key' => $key,
                'label' => $selection['label'],
                'type' => $selection['type']->value,
                'source' => $candidate->source->value,
                // The WIRE field is `path` (matching the Flutter DTO) while the
                // column is `extraction_path`. The value is the candidate's own
                // path, never anything the model returned.
                'path' => $candidate->extractionPath,
                'unit' => $selection['unit']?->value,
                'warn' => $selection['warnBound'],
                'critical' => $selection['criticalBound'],
                // The digest representation, so the pill shows exactly the sample
                // the model was shown rather than an unbounded page fragment.
                'sample_value' => $candidate->toDigestRow()['value'],
            ];
        }

        return $rows;
    }

    /**
     * The metric keys already taken on this monitor.
     *
     * Empty for the transient monitor the analyze path uses: there is no row yet,
     * so nothing can collide.
     *
     * @return list<string>
     */
    protected function existingKeys(Monitor $monitor): array
    {
        if ($monitor->getKey() === null) {
            return [];
        }

        return $monitor->metrics()
            ->pluck('key')
            ->filter()
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();
    }

    /**
     * A machine key slugged from the label, valid against the metric write path's
     * `^[a-z][a-z0-9_]*$` rule, within the column width, and unique among
     * `$taken`.
     *
     * @param  list<string>  $taken
     */
    protected function uniqueKey(string $label, array $taken): string
    {
        $stem = $this->keyStem($label);
        $key = $stem;

        // A collision is ordinary here: two candidates on one page legitimately
        // carry the same label, and the operator may already own a metric under
        // this key.
        for ($suffix = 2; in_array($key, $taken, true); $suffix++) {
            $tail = '_'.$suffix;
            $key = mb_substr($stem, 0, self::KEY_MAX_LENGTH - strlen($tail)).$tail;
        }

        return $key;
    }

    /**
     * The bounded, rule-conforming stem of a key.
     */
    protected function keyStem(string $label): string
    {
        $slug = Str::slug($label, '_');

        if ($slug === '') {
            return self::KEY_FALLBACK;
        }

        // The write path requires a leading letter, and a slug can legitimately
        // start with a digit (`404_count`).
        if (preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'm_'.$slug;
        }

        return trim(mb_substr($slug, 0, self::KEY_MAX_LENGTH), '_') ?: self::KEY_FALLBACK;
    }
}
