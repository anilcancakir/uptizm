<?php

namespace App\Services\Ai;

use App\Enums\MetricBand;
use App\Enums\MetricType;
use App\Enums\MetricUnit;
use App\Enums\ThresholdDirection;
use App\Exceptions\AiBudgetExhaustedException;
use App\Jobs\AnalyzeMonitorJob;
use App\Models\Monitor;
use App\Models\MonitorMetric;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Services\Monitoring\MetricExtractor;
use App\Services\Monitoring\ThresholdEvaluator;
use App\Support\Ai\PromptLanguage;
use App\Support\Monitoring\CredentialRedactor;
use App\Support\Monitoring\HealthVocabulary;
use App\Support\Monitoring\MetricCandidate;
use App\Support\Monitoring\ProbeHeaderAllowList;
use App\Support\Monitoring\ThresholdQuantiser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
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
 * Four more corrections live here because only this class holds the candidate,
 * and therefore the OBSERVED sample the gateway never sees:
 *
 * - A selection whose type is not in that candidate's `eligibleTypes` is
 *   dropped. {@see MetricExtractor::validateType()} discards a non-numeric value
 *   under `numeric`, so a `120ms` candidate accepted as numeric would extract on
 *   every check and record nothing, which reads to the operator as a metric that
 *   is silently always empty.
 * - A selection whose sample the {@see CredentialRedactor} touched is dropped
 *   entirely, because its path names a field that echoed the operator's own
 *   credential and a metric records its path on every check, forever.
 * - Its string bands are corrected against the observation and against each
 *   other ({@see self::bandsFor()}).
 * - A numeric bound the model left out is anchored to the observed reading
 *   ({@see self::boundsFor()}), because a metric with a direction and no bound
 *   bands nothing either.
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

    /**
     * How far from the observed reading a DERIVED warn bound sits, and how much
     * farther out on the bad side its critical sibling sits.
     *
     * Neither factor names a numeric direction, because neither has one: under
     * `high_bad` critical lands ABOVE warn, under `low_bad` BELOW it.
     *
     * Not new numbers: {@see AnalyzeMonitorJob::deterministicSuggestion()}
     * already anchors a response-time threshold at three and six times the
     * probe's own reading, and one convention across both surfaces is worth more
     * than a second one tuned here. They MULTIPLY under `high_bad` and DIVIDE
     * under `low_bad`, so the bad side is always the far one
     * ({@see self::derivedBound()}).
     */
    protected const int WARN_MULTIPLIER = 3;

    protected const int CRITICAL_MULTIPLIER = 6;

    /** A suggestion a model selected. */
    public const string ORIGIN_MODEL = 'model';

    /** A suggestion this backend derived on its own, with no model involved. */
    public const string ORIGIN_RULE = 'rule';

    /**
     * How many distinct words one rule-proposed band list may carry.
     *
     * Bounded because the words come from every verdict field in one response
     * and a payload is free to have many. Far below the write endpoint's own
     * {@see LaravelAiMetricDiscoveryGateway::VALUE_LIST_MAX_ITEMS}, because a
     * health vocabulary that needs more than this many spellings for one
     * severity is not a vocabulary this class should be guessing at.
     */
    protected const int RULE_VALUES_PER_BAND = 8;

    /**
     * The upper end of the units that HAVE one, keyed by
     * {@see MetricUnit} value.
     *
     * A reading on one of these cannot exceed its ceiling, so multiplying past
     * it produces a bound nothing can ever reach. Every other unit is left out
     * deliberately rather than given a large ceiling: milliseconds, bytes and
     * counts are genuinely unbounded, and inventing a maximum for them would put
     * a wrong number where there is currently an honest one.
     *
     * `percent` is the case that produced this: a gauge reading 27.05% derived a
     * critical bound of 162. Note this is the unit's own ceiling and not a claim
     * about the SERVICE: a CPU percentage across cores legitimately passes 100,
     * which is why an operator can still type a higher bound by hand and why a
     * bound the MODEL supplies is never clamped here. This governs only what
     * this class derives on its own.
     *
     * @var array<string, float>
     */
    protected const array SCALE_CEILINGS = [
        'percent' => 100.0,
        'ratio' => 1.0,
    ];

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
     * The two callers differ on `$headers` deliberately. `MonitorController::analyze()`
     * passes the filtered headers of the one `CheckResult` it just probed, so
     * body and headers are one observation. The re-runnable
     * `POST /monitors/{monitor}/metrics/discover` leaves the default and
     * therefore proposes no header candidate: it mines the newest ARCHIVED body,
     * while the only headers within its reach are a separate sample check's
     * `response_headers`, and those two are not guaranteed to come from the same
     * check. A header candidate proposed from one check's headers beside another
     * check's body is evidence that was never observed together, and this class
     * only ever advertises a sample a single response really carried.
     *
     * @param  Monitor  $monitor  Persisted, or the transient instance the analyze
     *                            path probes with before a row exists.
     * @param  string|null  $body  The captured response body to mine.
     * @param  string  $teamId  The team whose daily AI budget this spends. Passed
     *                          explicitly because the analyze path's monitor is
     *                          transient and carries no `team_id`.
     * @param  array<string, mixed>  $headers  {@see ProbeHeaderAllowList::filter()}'s
     *                                         output for the SAME response `$body`
     *                                         came from, never a raw header set.
     *                                         Empty means no header candidates.
     * @param  string|null  $locale  The OPERATOR's locale, which decides the language the
     *                               model writes labels in. Null falls back to English via
     *                               {@see PromptLanguage::nameFor()}. It is the operator's
     *                               stored preference and never the target's, because a page
     *                               must not choose what language our dashboard reads in.
     * @param  string|null  $runId  The analyze run this call belongs to, closing the same
     *                              gap `$teamId` closes: the analyze path's monitor is
     *                              transient, so `monitor_id` is empty on every degrade line
     *                              it produces, and this app has no correlation-id
     *                              infrastructure (no `Log::withContext`, no `Context::`
     *                              facade) to hook instead. `MonitorMetricController::discover()`
     *                              operates on a PERSISTED monitor whose `monitor_id` is
     *                              already meaningful and has no run to name, so it passes
     *                              nothing and its log lines are unchanged.
     * @return list<array<string, mixed>>
     */
    public function discover(
        Monitor $monitor,
        ?string $body,
        string $teamId,
        array $headers = [],
        ?string $locale = null,
        ?string $runId = null,
    ): array {
        // 1. Generate the candidates first. No candidates means there is nothing
        //    for a model to select among, so this costs neither a budget unit nor
        //    a provider call.
        if ($body === null || trim($body) === '') {
            return [];
        }

        $candidates = $this->extractor->extract($body, $headers);
        if ($candidates === []) {
            return [];
        }

        // 2. The verdict metric this backend proposes on its own, computed
        //    BEFORE the budget is touched and independently of whether a model
        //    ever answers. It needs no provider, so it is the one suggestion
        //    that still arrives on a day the AI cannot run at all.
        $ruleRows = $this->verdictRows($monitor, $candidates);

        // 3. Spend one unit of the team's daily AI budget atomically. Over budget
        //    is not a failure: it degrades to the rule rows alone, and never
        //    calls the provider.
        if (! $this->budget->tryConsume($teamId)) {
            return $ruleRows;
        }

        $result = $this->select($monitor, $candidates, $locale, $runId);
        if ($result === null) {
            return $ruleRows;
        }

        // 4. The model's own selections lead, and a rule row stands down for any
        //    path the model already took: its label is one a model wrote for a
        //    human to read, and two rows on one path is noise the operator has
        //    to resolve by hand.
        $modelRows = $this->toWireRows($monitor, $candidates, $result);
        $taken = array_column($modelRows, 'path');

        return [
            ...$modelRows,
            ...array_values(array_filter(
                $ruleRows,
                static fn (array $row): bool => ! in_array($row['path'], $taken, true),
            )),
        ];
    }

    /**
     * Ask the gateway to select among the candidates, or null when it could not
     * be trusted or could not be reached.
     *
     * @param  list<MetricCandidate>  $candidates
     */
    protected function select(
        Monitor $monitor,
        array $candidates,
        ?string $locale = null,
        ?string $runId = null,
    ): ?MetricDiscoveryResult {
        $payload = $this->payload($monitor, $candidates, $locale);

        // Non-conforming output past the gateway's own retry, and an unreachable
        // provider (outage, timeout, or a missing key), degrade identically. The
        // transport failure is logged first so the ops problem stays visible.
        try {
            return $this->gateway->discover($payload);
        } catch (AiBudgetExhaustedException $exception) {
            // FIRST, because it extends RuntimeException and the branch below
            // would otherwise swallow it under a label that describes a
            // different failure. Nothing was sent, so there is no output to
            // distrust: the two earlier calls on this request used the wall
            // clock this one needed. It is an ops signal about the BUDGET
            // (`ai.request_budget_seconds`) or about a slow provider, not about
            // the model, and production spent a day reading it as the latter.
            Log::warning("Metric discovery degraded: the request's AI budget was already spent.", [
                'monitor_id' => (string) ($monitor->getKey() ?? ''),
                'run_id' => $runId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        } catch (RuntimeException $exception) {
            Log::warning('Metric discovery degraded: the model output could not be trusted.', [
                'monitor_id' => (string) ($monitor->getKey() ?? ''),
                'run_id' => $runId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Metric discovery degraded: the AI service was unreachable.', [
                'monitor_id' => (string) ($monitor->getKey() ?? ''),
                'run_id' => $runId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        } catch (AiException) {
            // The fourth class, and not redundant with the two above:
            // `Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors` converts a
            // provider 429, 402 or 503 into an `AiException` SUBCLASS before it
            // reaches a caller, and the OpenRouter gateway raises a plain
            // `AiException` for an error body delivered in-band with HTTP 200.
            // `AiException extends Exception`, so neither is caught above, and
            // without this branch the most ordinary provider bad day there is
            // would throw out of `discover()` and 500 the whole analyze request
            // that only asked for a metric SUGGESTION.
            //
            // No `exception` key here, unlike the two branches above: this class
            // reaches us from a provider we do not control, and a message it
            // chose is not something to copy into our logs.
            Log::warning('Metric discovery degraded: the AI provider could not complete the request.', [
                'monitor_id' => (string) ($monitor->getKey() ?? ''),
                'run_id' => $runId,
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
    protected function payload(
        Monitor $monitor,
        array $candidates,
        ?string $locale = null,
    ): MetricDiscoveryPayload {
        return new MetricDiscoveryPayload(
            url: (string) $monitor->url,
            monitorType: $monitor->type?->value ?? '',
            candidateRefs: array_map(fn (MetricCandidate $candidate): string => $candidate->ref, $candidates),
            digestRows: array_map(fn (MetricCandidate $candidate): array => $candidate->toDigestRow(), $candidates),
            language: PromptLanguage::nameFor($locale),
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

            // 3. Refuse a candidate the redactor touched. The analyze path hands
            //    this service a body that has already been through
            //    {@see CredentialRedactor}, so a sample carrying the marker was
            //    read from a field that echoed the operator's own credential.
            //    Accepting it would write that field's value into
            //    `monitor_metric_values.string_value`, a plain text column, on
            //    every check forever, and from there into the anomaly prompt.
            //    This is the only seam that still holds the sample, so it is the
            //    only place the drop can happen.

            if (str_contains($candidate->sampleValue, CredentialRedactor::MARKER)) {
                continue;
            }

            $key = $this->uniqueKey($selection['label'], $takenKeys);
            $bands = $this->bandsFor($candidate, $selection);
            $bounds = $this->boundsFor($candidate, $selection);

            // 4. Refuse a banded selection that would report the observation as
            //    healthy after correction. See bandsFor()'s docblock; the key
            //    is claimed only after this, so a refused row does not burn one.
            if ($bands === null) {
                continue;
            }

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
                // The candidate's own unit outranks the model's choice, and only
                // under `numeric`. It was derived from the very suffix
                // `MetricExtractor::splitUnit()` strips at check time, so it is
                // the one unit the recorded number is actually expressed in; a
                // model that read `120ms` and answered `second` would otherwise
                // render 120 ms as two minutes. Under `string` the value keeps
                // its suffix verbatim, so attaching a unit there would print it
                // twice.
                'unit' => $this->unitFor($candidate, $selection),
                // Without the direction the two bounds beside it are inert.
                // `ThresholdEvaluator::numericBreach()` needs to know which
                // side of a bound is bad before it can band anything, so a
                // numeric metric that arrives with `warn_bound: 400` and no
                // direction records its readings forever and never bands one,
                // never breaches, and never opens an incident. The screen
                // meanwhile says "warn at 400". The gateway resolves this
                // against the real enum and `SELECTION_KEYS` admits it; it was
                // simply never put on the wire, which is a failure with no
                // error attached to it anywhere.
                'threshold_direction' => $selection['thresholdDirection']?->value,
                // Anchored to the observed reading wherever the model supplied
                // no bound, because a direction on its own bands nothing either:
                // `ThresholdEvaluator::band()` compares against a bound, and a
                // null one is never crossed. See boundsFor().
                'warn' => $bounds['warn'],
                'critical' => $bounds['critical'],
                // The string bands travel under their COLUMN names, unlike
                // `path` / `warn` / `critical` above: there is no Flutter form
                // vocabulary for a value list, so the client passes these three
                // through to the write endpoint unchanged.
                ...$bands,
                // The digest representation, so the pill shows exactly the sample
                // the model was shown rather than an unbounded page fragment.
                'sample_value' => $candidate->toDigestRow()['value'],
                // Who proposed this row. The surface must be able to tell the
                // operator which suggestions a model wrote and which a rule did,
                // because badging a deterministic row as AI work is a claim this
                // product does not get to make.
                'origin' => self::ORIGIN_MODEL,
            ];
        }

        return $rows;
    }

    /**
     * The verdict metric this backend proposes without asking a model, or an
     * empty list when the response gives it nothing it can stand behind.
     *
     * WHY A RULE AND NOT A BETTER PROMPT. Three earlier fixes made the correct
     * configuration reachable: the verdict fields now lead the candidate table,
     * a string metric can no longer ship without bands, and the observed value
     * may sit in a paging band when the vocabulary agrees. Across live runs on a
     * real health endpoint the model still selected only numbers, every time. A
     * field publishing a service's own health is the most valuable thing on that
     * payload and it is the one the model reliably declines, so it stops being
     * asked for.
     *
     * WHAT THIS COSTS, stated plainly because it reverses something written one
     * step earlier: {@see HealthVocabulary} was introduced as a class that only
     * validates a model's placement and never authors a band. Here it authors
     * one. The reversal is deliberate and it is bounded by the same conservatism
     * that made the validator safe: only a word the vocabulary knows is ever
     * placed, and a response whose verdict reads something unrecognised gets no
     * proposal at all rather than a guessed one.
     *
     * THE LISTS COME FROM THE RESPONSE, NOT FROM THE VOCABULARY. Every verdict
     * field in this one body contributes the word it is actually reading, so a
     * payload whose subsystems say `ok` while the top level says `degraded`
     * yields `ok_values: [ok]` and `warn_values: [degraded]`. Seeding the lists
     * from the vocabulary's own table instead would write words the service may
     * never use, and a band value that never matches is worse than an absent one
     * because `unmatched_band` then reports it as healthy.
     *
     * ONE ROW, on the SHALLOWEST verdict path. A health payload carries a
     * verdict per subsystem and the top-level one is already their rollup, so
     * proposing all of them would bury the model's own suggestions under nine
     * near-identical rows. The operator adds the per-subsystem ones by hand when
     * they want to know WHICH rather than WHETHER.
     *
     * @param  list<MetricCandidate>  $candidates
     * @return list<array<string, mixed>>
     */
    protected function verdictRows(Monitor $monitor, array $candidates): array
    {
        // 1. Every verdict field this response carries, in candidate order.
        $verdicts = array_values(array_filter(
            $candidates,
            static fn (MetricCandidate $candidate): bool => MetricCandidateExtractor::namesAVerdict(
                $candidate->extractionPath,
            ),
        ));

        if ($verdicts === []) {
            return [];
        }

        // 2. The shallowest one carries the proposal. `usort` is not stable
        //    across equal depths in the way this needs, so the minimum is taken
        //    by comparison and the FIRST candidate at that depth wins, which is
        //    document order and therefore reproducible.
        $subject = $verdicts[0];
        foreach ($verdicts as $candidate) {
            if (substr_count($candidate->extractionPath, '.') < substr_count($subject->extractionPath, '.')) {
                $subject = $candidate;
            }
        }

        // 3. The proposal is only as good as the reading it is anchored to: a
        //    subject whose own current word is unrecognised would ship a metric
        //    that reports today's reading through `unmatched_band`, which the
        //    create path pins to `ok`. That is the false-Ok this whole path
        //    exists to prevent, so it is not proposed at all.
        if (HealthVocabulary::bandFor($subject->sampleValue) === null) {
            return [];
        }

        $lists = $this->verdictLists($verdicts);

        return [[
            'key' => $this->uniqueKey(
                (string) trans('monitors.verdict_metric_label'),
                $this->existingKeys($monitor),
            ),
            'label' => (string) trans('monitors.verdict_metric_label'),
            'type' => MetricType::String->value,
            'source' => $subject->source->value,
            'path' => $subject->extractionPath,
            'unit' => null,
            'threshold_direction' => null,
            'warn' => null,
            'critical' => null,
            ...$lists,
            'sample_value' => $subject->toDigestRow()['value'],
            'origin' => self::ORIGIN_RULE,
        ]];
    }

    /**
     * The three band lists, built from the words the verdict fields of this one
     * response are actually reading.
     *
     * A word is transcribed in the spelling it first appeared in and matched
     * case-insensitively afterwards, so `OK` beside `ok` contributes one entry
     * rather than two that {@see StoreMonitorMetricRequest} would then refuse as
     * an overlap.
     *
     * @param  list<MetricCandidate>  $verdicts
     * @return array{ok_values: list<string>, warn_values: list<string>, critical_values: list<string>}
     */
    protected function verdictLists(array $verdicts): array
    {
        $lists = [
            'ok_values' => [],
            'warn_values' => [],
            'critical_values' => [],
        ];
        $seen = [];

        foreach ($verdicts as $candidate) {
            $band = HealthVocabulary::bandFor($candidate->sampleValue);
            if ($band === null) {
                continue;
            }

            $normalized = ThresholdEvaluator::normalizeMatchValue($candidate->sampleValue);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $field = match ($band) {
                MetricBand::Ok => 'ok_values',
                MetricBand::Warn => 'warn_values',
                MetricBand::Critical => 'critical_values',
            };
            $lists[$field][] = trim($candidate->sampleValue);
        }

        foreach ($lists as $field => $values) {
            $lists[$field] = array_slice($values, 0, self::RULE_VALUES_PER_BAND);
        }

        return $lists;
    }

    /**
     * The three string-band lists an accepted selection ships with, after the
     * two corrections only this class can make.
     *
     * The gateway already bounded each list to what the write endpoint accepts
     * per item. What it cannot do is compare across lists or against the
     * observation, and both of those are how a banded suggestion turns into a
     * page rather than a configuration:
     *
     *   1. A selection that puts the OBSERVED value in `warn_values` or
     *      `critical_values`, and nowhere else, is REFUSED WHOLE (this method
     *      answers null). {@see ThresholdEvaluator::bandString()} tests critical
     *      first and {@see MonitorMetric::alertsOnString()} is true the moment
     *      any list is non-empty, so keeping such a selection and merely
     *      deleting the offending entry is the worse of the two failures: the
     *      value the model called critical then matches nothing, falls through
     *      to `unmatched_band`, which the create path pins to `ok`, and the
     *      metric reports the reading the model flagged as HEALTHY. A false Ok
     *      on an alerting metric is worse than no metric, so the row goes.
     *      Refusing the row also matches the two drops directly above it in
     *      {@see self::toWireRows()} (a redacted sample, an ineligible type):
     *      one vocabulary, "we do not propose what we cannot stand behind".
     *   2. A value sitting in two lists is kept in the LEAST severe one and
     *      dropped from the others. `validateNoOverlappingValues` refuses the
     *      pair with a 422 on `metrics.2.warn_values.0`, which is an error the
     *      operator saw as a pill and cannot act on, and under the all-or-nothing
     *      create it would take the whole monitor down with it. The 422 stays
     *      correct for a hand-authored bulk row; a suggestion is corrected
     *      instead, downward.
     *
     * @param  array{okValues: list<string>, warnValues: list<string>, criticalValues: list<string>}  $selection
     * @return array{ok_values: list<string>, warn_values: list<string>, critical_values: list<string>}|null Null
     *                                                                                                       refuses the whole row.
     */
    protected function bandsFor(MetricCandidate $candidate, array $selection): ?array
    {
        $observed = ThresholdEvaluator::normalizeMatchValue($candidate->sampleValue);

        $submitted = [
            'ok_values' => $selection['okValues'],
            'warn_values' => $selection['warnValues'],
            'critical_values' => $selection['criticalValues'],
        ];

        $bands = [];
        $claimed = [];

        foreach ($submitted as $field => $values) {
            $kept = [];

            foreach ($values as $value) {
                $normalized = ThresholdEvaluator::normalizeMatchValue($value);

                // The observed value in a severe list, and not also in
                // `ok_values`. Deleting the entry would leave the reading the
                // model flagged unmatched and therefore banded `ok`, so the
                // whole row is refused instead. When it IS also in `ok_values`
                // the `$claimed` dedupe below has already resolved it downward,
                // because `ok_values` is walked first.
                //
                // Unless the word itself settles it. A health endpoint reading
                // `degraded` has exactly one correct configuration, `degraded`
                // in `warn_values`, so a blanket refusal here cost the operator
                // the single field they most wanted watched.
                // {@see HealthVocabulary} answers only the narrow question of
                // whether the word is unambiguous enough to check rather than
                // trust, and it agrees with nothing it does not know, so an
                // unfamiliar vocabulary takes the refusal it always took. It
                // never authors a band: this still admits only what the model
                // itself proposed.
                //
                // Banding on the first check is the INTENDED outcome when the
                // vocabulary agrees. The service is saying it is unwell right
                // now, and a monitoring product that waits for it to say so
                // twice is the false-Ok this method exists to prevent.
                if ($field !== 'ok_values' && $normalized === $observed
                    && ! isset($claimed[$normalized])
                    && ! HealthVocabulary::agrees($normalized, $this->bandOfField($field))) {
                    return null;
                }

                if (isset($claimed[$normalized])) {
                    continue;
                }

                $claimed[$normalized] = true;
                $kept[] = $value;
            }

            $bands[$field] = $kept;
        }

        return $bands;
    }

    /**
     * The band a value list confers on the values inside it.
     *
     * Exhaustive with no `default`, unlike {@see HealthVocabulary::bandFor()}:
     * this set is closed by {@see LaravelAiMetricDiscoveryGateway::VALUE_LIST_KEYS}
     * and a fourth list arriving here should be a failure rather than a silent
     * band nobody chose.
     */
    protected function bandOfField(string $field): MetricBand
    {
        return match ($field) {
            'ok_values' => MetricBand::Ok,
            'warn_values' => MetricBand::Warn,
            'critical_values' => MetricBand::Critical,
        };
    }

    /**
     * The numeric bounds an accepted selection ships with: the model's own
     * wherever it supplied one, and a bound anchored to the OBSERVED reading
     * wherever it did not.
     *
     * The gateway already refuses a numeric selection with no direction, which
     * leaves the other half of the same defect: a direction with no bound bands
     * nothing either, because {@see ThresholdEvaluator::band()} compares a
     * reading against a bound and a null bound is never crossed. A model that
     * has no documented budget to quote cannot honestly supply one, so the
     * observation does, under the convention this codebase already applies to a
     * response-time threshold in {@see AnalyzeMonitorJob::deterministicSuggestion()}:
     * warn at {@see self::WARN_MULTIPLIER} times the reading, critical at
     * {@see self::CRITICAL_MULTIPLIER}.
     *
     * Three properties are load-bearing:
     *
     *   - A model-supplied bound always wins, per bound and not per pair. The
     *     model may have read the service's own documentation; the observation is
     *     only the one reading this backend happens to hold.
     *   - `low_bad` DIVIDES by the same two factors. A bound above the reading on
     *     a metric where lower is worse would band the very first check.
     *   - The pair has to satisfy {@see ThresholdDirection::validateBounds()} or
     *     nothing is derived, and it is a reachable case rather than a
     *     formality: a reading of 0 multiplies and divides to itself (an equal
     *     pair, which the write endpoint 422s), and a negative reading inverts
     *     the pair. A metric with a direction and no bound still ships, because
     *     that is a form the operator can finish.
     *
     * @param  array{
     *     type: MetricType,
     *     thresholdDirection: ThresholdDirection|null,
     *     warnBound: float|null,
     *     criticalBound: float|null,
     * }  $selection
     * @return array{warn: float|null, critical: float|null}
     */
    protected function boundsFor(MetricCandidate $candidate, array $selection): array
    {
        $submitted = [
            'warn' => $selection['warnBound'],
            'critical' => $selection['criticalBound'],
        ];
        $direction = $selection['thresholdDirection'];

        // 1. Only a numeric metric with a direction has anything to derive. The
        //    gateway clears bounds on every other type, and nothing else reads a
        //    direction. There is deliberately no second early return for the pair
        //    the model supplied WHOLE: the `??` below is what keeps its bounds,
        //    and a branch that skips work the `??` already does is a guard no test
        //    can tell apart from its absence.
        if ($selection['type'] !== MetricType::Numeric || $direction === null) {
            return $submitted;
        }

        // 2. The reading the check path will actually record, which is the only
        //    number a bound may be anchored to.
        $observed = $this->observedNumber($candidate);
        if ($observed === null || $observed <= 0) {
            return $submitted;
        }

        // Quantised on the way out, exactly like a bound the model supplied
        // (`LaravelAiMetricDiscoveryGateway::resolveBounds()`). Without it this
        // path prints the very number that started the whole fix: an 18.29
        // reading divided by WARN_MULTIPLIER is 6.096666666666667, which is what
        // a live run rendered as "warn 6.096666666666667" for free disk space.
        // Quantising BEFORE the ordering check below is load-bearing: rounding
        // can collapse two bounds onto one value, and a collapsed pair has to be
        // given up like any other pair the direction refuses.
        $unit = $this->effectiveUnit($candidate, $selection);

        $derived = [
            'warn' => $submitted['warn']
                ?? ThresholdQuantiser::quantise(
                    $this->derivedBound($observed, $direction, self::WARN_MULTIPLIER, $unit),
                ),
            'critical' => $submitted['critical']
                ?? ThresholdQuantiser::quantise(
                    $this->derivedBound($observed, $direction, self::CRITICAL_MULTIPLIER, $unit),
                ),
        ];

        // 3. A pair the direction's own ordering refuses is not a suggestion: it
        //    is a 422 on the bulk create the operator kicked off by accepting it.
        //    The model's half is kept and only the derived half is given up.
        return $direction->validateBounds($derived['warn'], $derived['critical']) ? $derived : $submitted;
    }

    /**
     * One bound `$multiplier` steps away from the observed reading, on the side
     * the direction calls bad.
     */
    protected function derivedBound(
        float $observed,
        ThresholdDirection $direction,
        int $multiplier,
        ?MetricUnit $unit,
    ): float {
        // Guarded rather than `[$unit?->value]`: a null array offset is
        // deprecated in PHP 8 and reads as the empty-string key.
        $ceiling = $unit === null ? null : (self::SCALE_CEILINGS[$unit->value] ?? null);

        // A bounded scale has nowhere to multiply into, so it derives toward its
        // own ceiling instead: thirds of the headroom that is actually left.
        // Multiplying put a live Redis memory gauge reading 27.05% at
        // `warn 81.2, critical 162`, and no reading reaches 162 percent, so the
        // critical band was unreachable by construction and the metric shipped
        // with one usable band instead of two. On a storage gauge already at 81%
        // the same arithmetic puts warn at 244 and the metric is inert outright.
        //
        // Only `high_bad` needs it. `low_bad` DIVIDES, which walks toward zero,
        // and zero is the floor of every scale here.
        if ($direction === ThresholdDirection::HighBad && $ceiling !== null) {
            $headroom = max(0.0, $ceiling - $observed);
            $share = $multiplier === self::WARN_MULTIPLIER ? 1 / 3 : 2 / 3;

            return $observed + ($headroom * $share);
        }

        return match ($direction) {
            ThresholdDirection::HighBad => $observed * $multiplier,
            ThresholdDirection::LowBad => $observed / $multiplier,
        };
    }

    /**
     * The unit an accepted selection is actually expressed in.
     *
     * One resolution shared by {@see self::unitFor()} (which puts it on the
     * wire) and {@see self::boundsFor()} (which derives against it), so the
     * number and the scale it is derived on can never disagree.
     *
     * @param  array{type: MetricType, unit: MetricUnit|null}  $selection
     */
    protected function effectiveUnit(MetricCandidate $candidate, array $selection): ?MetricUnit
    {
        if ($selection['type'] !== MetricType::Numeric) {
            return null;
        }

        return $candidate->unit ?? $selection['unit'];
    }

    /**
     * The number the check path will record for this candidate, or null when its
     * sample does not reduce to one.
     *
     * {@see MetricExtractor::splitUnit()} is what makes the two agree: a `120ms`
     * sample records `120` at check time, so a bound derived from anything else
     * would be expressed in a unit the recorded number is not. A candidate whose
     * sample reduces to no number cannot be `MetricType::Numeric` in the first
     * place (it never reaches `eligibleTypes`), so null is unreachable through
     * this class today; it is checked rather than asserted because the two rules
     * live in different files and only one of them is this one.
     */
    protected function observedNumber(MetricCandidate $candidate): ?float
    {
        $split = MetricExtractor::splitUnit($candidate->sampleValue);
        $value = $split === null ? trim($candidate->sampleValue) : $split[0];

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The unit an accepted selection ships with.
     *
     * @param  array{type: MetricType, unit: MetricUnit|null}  $selection
     */
    protected function unitFor(MetricCandidate $candidate, array $selection): ?string
    {
        // Nothing carries a unit but a numeric metric, and the gate belongs
        // here because the gateway does not apply one: `resolveBounds()` and
        // `resolveLists()` both clear on the wrong type, `resolveUnit()` does
        // not. Without this a `120ms` candidate selected as `string` keeps its
        // suffix in the value AND ships `millisecond` beside it, and the pill
        // renders "120ms ms".
        // The candidate's own unit outranks the model's: it comes from the very
        // suffix the check path strips, or failing that from the key the service
        // itself wrote the scale into, so it is the one unit the recorded number
        // is actually expressed in. Resolved once in
        // {@see self::effectiveUnit()} and shared with the bound derivation, so
        // the printed unit and the scale a bound was derived on cannot differ.
        return $this->effectiveUnit($candidate, $selection)?->value;
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
