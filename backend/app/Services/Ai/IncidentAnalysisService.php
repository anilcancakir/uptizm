<?php

namespace App\Services\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiDegradeReason;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\AiIncidentAnalysis;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;
use App\Models\MonitorMetricValue;
use App\Services\Monitoring\MetricCandidateExtractor;
use App\Support\Ai\PromptLanguage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

/**
 * Composes an incident's timeline plus the checks recorded against its
 * affected monitors into a fenced {@see IncidentAnalysisPayload} and asks the
 * {@see IncidentAnalysisGateway} for a post-incident root-cause summary.
 *
 * Mirrors {@see MonitorController::analyze()}'s
 * budget-guard shape: the per-team AI budget is spent AT THIS call site. Over
 * budget degrades to a deterministic summary derived from the incident's own
 * fields, never calling the LLM, so the endpoint still answers.
 */
class IncidentAnalysisService
{
    /**
     * Maximum number of recent checks folded into the RCA evidence.
     */
    private const MAX_CHECKS = 20;

    /**
     * How far BEFORE an incident opened its evidence window reaches, in checks.
     *
     * The window used to start at `started_at`, which reads as the obvious
     * boundary and excludes the only checks that explain the incident: a
     * threshold trips on CONSECUTIVE failures, so the failures that caused it
     * are all before the moment it opened. Measured on the running system, an
     * incident with thirty checks inside its window had thirteen in the fifteen
     * minutes before it that nothing ever read.
     *
     * It is worse at open, which is where the autonomous path lives. That job
     * runs seconds after the incident is created, and the first live analysis it
     * produced said "No checks were recorded and no probe response body was
     * provided, so no further root cause can be established" three seconds after
     * an outage that had been failing for minutes. That sentence is what the
     * customer-facing draft was then built from.
     *
     * Ten checks' worth of time, derived from the monitor's own cadence for the
     * same reason the reopen window is: it scales with how closely the thing is
     * watched, and it comfortably covers any `incident_threshold` anyone sets.
     */
    private const CHECK_LOOKBACK_CHECKS = 10;

    /** How many DISTINCT response bodies reach the prompt. */
    private const MAX_DISTINCT_BODIES = 5;

    /** How many fields one slice or one diff may carry. */
    private const MAX_BODY_FIELDS = 20;

    /** How many readings of the triggering metric travel with it. */
    private const MAX_METRIC_READINGS = 12;

    /**
     * Leaf names that change on every check whatever the service is doing.
     *
     * `duration_ms` is matched by suffix rather than listed, because a health
     * payload carries one per subsystem.
     *
     * @var list<string>
     */
    private const JITTER_LEAVES = [
        'timestamp',
        'checked_at',
        'generated_at',
        'time',
        'now',
        'uptime',
        'uptime_seconds',
    ];

    public function __construct(
        protected IncidentAnalysisGateway $gateway,
        protected AiBudget $budget,
    ) {}

    /**
     * Summarize the likely root cause of an incident from its timeline and
     * the checks recorded against its affected monitors during the incident
     * window.
     */
    public function analyzeFor(Incident $incident): IncidentAnalysisResult
    {
        return $this->analyzeWith($incident, $this->composePayload($incident));
    }

    /**
     * The stored analysis for an incident, asking the model only when what is
     * on file no longer matches the evidence.
     *
     * This is the endpoint's entry point, and the read-through is the whole
     * point of it: `analyzeFor()` spends one AI budget unit per call, so before
     * this table existed three responders opening one incident bought three
     * answers to one question, and a single operator refreshing a page did the
     * same. The evidence fingerprint decides
     * ({@see IncidentAnalysisPayload::evidenceFingerprint()}), not the row's
     * age, so a hit is not a cache in the "possibly stale" sense: it is the
     * same question, and re-asking it would buy the same answer.
     *
     * The returned pair carries a `stored` row only when the model answered. A
     * degrade is not stored (see the table's migration), so on that path the
     * result is the transient baseline and there is nothing to rate.
     *
     * @param  bool  $refresh  Skip the read and ask again. This is the retry
     *                         button, and the one path that spends on purpose.
     */
    public function storedAnalysisFor(Incident $incident, bool $refresh = false): ResolvedAnalysis
    {
        $payload = $this->composePayload($incident);
        $fingerprint = $payload->evidenceFingerprint();

        if (! $refresh) {
            $stored = AiIncidentAnalysis::query()
                ->where('incident_id', $incident->getKey())
                ->where('evidence_fingerprint', $fingerprint)
                ->latest('created_at')
                ->first();

            if ($stored !== null) {
                return new ResolvedAnalysis($stored->result, $stored);
            }
        }

        $result = $this->analyzeWith($incident, $payload);

        if ($result->degradeReason !== null) {
            return new ResolvedAnalysis($result->toArray(), null);
        }

        // `updateOrCreate` rather than `create`: the unique index over
        // (incident, fingerprint) is what stops two concurrent readers of the
        // same incident from writing two rows for one answer, and on a refresh
        // that produced the same fingerprint it replaces the text in place.
        $stored = AiIncidentAnalysis::updateOrCreate(
            [
                'incident_id' => $incident->getKey(),
                'evidence_fingerprint' => $fingerprint,
            ],
            [
                'team_id' => $incident->team_id,
                'result' => $result->toArray(),
            ],
        );

        // Replacing the text in place is what makes this necessary. A vote is a
        // statement about words, and after a refresh answered differently the
        // words are gone while the row id is not, so a rating left attached
        // would silently come to mean "this NEW text was unhelpful": the exact
        // re-targeting the vote is keyed to an analysis rather than an incident
        // to prevent. The compare is on the rendered result, so a re-ask that
        // came back identical keeps its ratings, which is the honest outcome
        // there: nothing the operator read has changed.
        if ($stored->wasChanged('result')) {
            $stored->feedback()->delete();
        }

        return new ResolvedAnalysis($stored->result, $stored);
    }

    /**
     * Assemble the evidence for an incident: its timeline, the checks recorded
     * against its affected monitors inside the incident window, the monitor
     * roster, and the triggering metric.
     */
    protected function composePayload(Incident $incident): IncidentAnalysisPayload
    {
        $incident->loadMissing([
            'updates' => fn ($query) => $query->orderBy('display_at'),
            'monitors',
        ]);

        $monitorIds = $this->affectedMonitorIds($incident);

        $checks = MonitorCheck::query()
            ->whereIn('monitor_id', $monitorIds)
            ->where('checked_at', '>=', $this->evidenceFrom($incident))
            ->orderByDesc('checked_at')
            ->limit(self::MAX_CHECKS)
            ->get();

        return $this->buildPayload($incident, $checks, $monitorIds);
    }

    /**
     * Ask the model, degrading to the deterministic baseline on each of the
     * four ways that can fail to produce a trustworthy answer.
     */
    protected function analyzeWith(Incident $incident, IncidentAnalysisPayload $payload): IncidentAnalysisResult
    {
        $teamId = (string) $incident->team_id;

        // 1. Over budget never calls the LLM: degrade to the deterministic
        //    baseline so the endpoint still answers.
        if (! $this->budget->tryConsume($teamId)) {
            return $this->deterministicSummary($incident, AiDegradeReason::BudgetExhausted);
        }

        // 2. Three failures, one baseline: non-conforming output past the
        //    gateway's single retry, an unreachable AI service (outage, timeout,
        //    or a missing/invalid key), and a provider that answered with an
        //    error body all degrade to the SAME deterministic baseline, so the
        //    endpoint returns the identical empty-array wire shape rather than a
        //    500. They differ only in the reason code the client reads. The two
        //    provider-side failures are logged so the ops problem stays visible.
        try {
            return $this->gateway->analyze($payload);
        } catch (NonConformingAnalysisException) {
            return $this->deterministicSummary($incident, AiDegradeReason::OutputUntrusted);
        } catch (ConnectionException|RequestException $exception) {
            // `failure` and `status` alongside the message, following
            // {@see AnalyzeMonitorJob::degradeContext()}: one reason code now
            // covers three provider failures the operator cannot act on
            // differently, and {@see AiDegradeReason} states that the finer
            // taxonomy survives in the log. It only survives if it is written.
            Log::warning('Incident AI analysis degraded: the AI service was unreachable.', [
                'incident_id' => (string) $incident->id,
                'failure' => class_basename($exception),
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'exception' => $exception->getMessage(),
            ]);

            return $this->deterministicSummary($incident, AiDegradeReason::ServiceUnreachable);
        } catch (AiException $exception) {
            // The third class, and not redundant with the two above:
            // `AiException extends Exception`, so it descends from neither this
            // app's own exception nor the Guzzle pair, and OpenRouter's
            // `ParsesTextResponses::validateTextResponse()` raises a PLAIN one
            // for an error body delivered in-band with HTTP 200. Without this
            // branch the most ordinary provider bad day there is answered 500,
            // against this method's own promise to degrade. It maps to the same
            // reason as an unreachable service because to a caller a provider
            // that answered with an error body is a provider that did not
            // answer; the finer distinction (429 vs 402 vs 503, all
            // `AiException` subclasses) is an ops concern, not an operator one.
            //
            // The CLASS but not the message, unlike the branch above: the class
            // is `laravel/ai`'s own name for the failure and it is the only place
            // the 429-vs-402-vs-503 distinction the enum promises survives,
            // while the message is text the PROVIDER chose, and a message a third
            // party authored is not something to copy into our logs.
            Log::warning('Incident AI analysis degraded: the AI provider could not complete the request.', [
                'incident_id' => (string) $incident->id,
                'failure' => class_basename($exception),
            ]);

            return $this->deterministicSummary($incident, AiDegradeReason::ServiceUnreachable);
        }
    }

    /**
     * The instant the evidence window opens: a few checks before the incident
     * did. See {@see self::CHECK_LOOKBACK_CHECKS}.
     */
    protected function evidenceFrom(Incident $incident): \DateTimeInterface
    {
        $cadence = (int) ($incident->primaryMonitor?->check_interval_sec
            ?? Monitor::DEFAULT_CHECK_INTERVAL_SEC);

        return $incident->started_at->copy()->subSeconds(self::CHECK_LOOKBACK_CHECKS * $cadence);
    }

    /**
     * The affected monitor ids: the primary hint plus the full pivot set.
     *
     * @return list<string>
     */
    protected function affectedMonitorIds(Incident $incident): array
    {
        return $incident->monitors
            ->pluck('id')
            ->push($incident->primary_monitor_id)
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Hydrate the fenced payload from the incident's timeline and its
     * recorded checks.
     *
     * @param  Collection<int, MonitorCheck>  $checks
     * @param  list<string>  $monitorIds
     */
    protected function buildPayload(Incident $incident, Collection $checks, array $monitorIds): IncidentAnalysisPayload
    {
        $timeline = $incident->updates->map(fn ($update) => [
            'author' => $update->author,
            'status' => $update->status?->value,
            'is_public' => (bool) $update->is_public,
            'autonomous' => (bool) $update->autonomous,
            'display_at' => $update->display_at?->toIso8601String(),
            'message' => $update->message,
        ])->all();

        $roster = $this->monitorRoster($monitorIds);
        $namesById = array_column($roster, 'name', 'monitor_id');

        // Numbered, not identified. A uuid is 36 characters that tell the
        // analyser nothing; the ordinal says "this check, not that one", which
        // is the only identity this evidence needs, and it becomes the citation
        // handle with it.
        $trustedChecks = $checks->values()->map(fn (MonitorCheck $check, int $index) => [
            'n' => $index + 1,
            'monitor' => $namesById[(string) $check->monitor_id] ?? null,
            'region' => $check->region,
            'status' => $check->status?->value,
            'status_code' => $check->status_code,
            'response_ms' => $check->response_ms,
            'checked_at' => $check->checked_at?->toIso8601String(),
        ])->all();

        $metric = $this->triggeringMetric($incident);

        return new IncidentAnalysisPayload(
            incidentId: (string) $incident->id,
            severity: $incident->severity?->value ?? 'unknown',
            impact: $incident->impact?->value ?? 'unknown',
            lifecycle: $incident->lifecycle?->value ?? 'unknown',
            signalSource: $incident->signal_source?->value ?? 'unknown',
            aiOwned: (bool) $incident->ai_owned,
            startedAt: $incident->started_at?->toIso8601String() ?? '',
            resolvedAt: $incident->resolved_at?->toIso8601String(),
            timeline: $timeline,
            checks: $trustedChecks,
            bodies: $this->bodyEvidence($checks, $metric['path'] ?? null),
            knownCheckIds: array_map(strval(...), array_column($trustedChecks, 'n')),
            knownMonitorIds: $monitorIds,
            monitors: $roster,
            triggeringMetric: $metric,
            // The TEAM's language, not the request's. Most analyses are composed
            // on the queue by `PublishAiIncidentUpdate`, where there is no
            // request and so nothing for `SetApiLocale` to have set; reading
            // `app()->getLocale()` here would quietly hand those the config
            // default and leave the operator with the English analysis this was
            // meant to fix.
            //
            // It deliberately does NOT enter `evidenceFingerprint()`: the
            // fingerprint answers "does this analysis still fit the evidence",
            // and a language is not evidence. The consequence is worth knowing:
            // an operator who switches languages keeps the stored analysis until
            // the evidence itself moves, which is what one row per incident
            // means and is why it stays at one model call.
            language: PromptLanguage::nameFor($incident->team?->preferredLocale()),
        );
    }

    /**
     * The response-body evidence: one entry per DISTINCT body, the first a
     * relevant slice and the rest a diff against it.
     *
     * Measured on one real incident: twenty checks carried four distinct
     * bodies, and the old shape sent all twenty front-truncated at 500
     * characters, which cost roughly 20,000 characters and cut the very field
     * the incident was named after (`cache` did not appear in the first 500).
     * Sending a body sixteen extra times conveys one fact, that it did not
     * change, and `repeat` conveys the same fact in three characters.
     *
     * The SLICE is driven by the operator's own `extraction_path`, which is the
     * point: we are not guessing where the interesting field is, the person who
     * configured the metric already told us. Its parent subtree goes in whole,
     * so the neighbours that explain it come along, plus every verdict field the
     * service publishes about itself.
     *
     * The DIFF is filtered. Unfiltered it was 58 percent clock and
     * self-measurement ({@see self::JITTER_LEAVES}), which is a field changing
     * on every check by construction and therefore evidence of nothing.
     *
     * @param  Collection<int, MonitorCheck>  $checks
     * @return list<array{at: string, repeat: int, baseline: bool, fields: array<string, string>}>
     */
    protected function bodyEvidence(Collection $checks, ?string $metricPath): array
    {
        $distinct = [];

        foreach ($checks as $check) {
            $body = $check->response_body_preview;

            if ($body === null || $body === '') {
                continue;
            }

            $key = $check->content_hash ?: md5($body);

            if (isset($distinct[$key])) {
                $distinct[$key]['repeat']++;

                continue;
            }

            $decoded = json_decode($body, true);

            $distinct[$key] = [
                'at' => $check->checked_at?->toIso8601String() ?? 'unknown',
                'repeat' => 1,
                'fields' => is_array($decoded) ? $this->flatten($decoded) : ['body' => $body],
            ];

            if (count($distinct) >= self::MAX_DISTINCT_BODIES) {
                break;
            }
        }

        $evidence = [];
        $baseline = null;

        foreach ($distinct as $entry) {
            if ($baseline === null) {
                $baseline = $entry['fields'];
                $evidence[] = [
                    'at' => $entry['at'],
                    'repeat' => $entry['repeat'],
                    'baseline' => true,
                    'fields' => $this->slice($entry['fields'], $metricPath),
                ];

                continue;
            }

            $evidence[] = [
                'at' => $entry['at'],
                'repeat' => $entry['repeat'],
                'baseline' => false,
                'fields' => $this->diff($baseline, $entry['fields']),
            ];
        }

        return $evidence;
    }

    /**
     * The fields worth showing from the baseline body: the metric's own subtree
     * and every verdict the service publishes.
     *
     * With no metric path (an incident opened by consecutive failures) the
     * verdicts alone are the slice, which is still far less than the whole body
     * and is the half a reader would look at first.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    protected function slice(array $fields, ?string $metricPath): array
    {
        $parent = $metricPath !== null && str_contains($metricPath, '.')
            ? substr($metricPath, 0, (int) strrpos($metricPath, '.'))
            : $metricPath;

        $kept = [];

        foreach ($fields as $path => $value) {
            // A dot-path subtree test, not a raw string prefix. `checks.cache` is
            // a prefix of `checks.cache2.latency_ms`, so a prefix test pulls a
            // NEIGHBOURING component's fields into the slice under the label of
            // the one being investigated. The boundary is the separator.
            $isMetricNeighbour = $parent !== null && $parent !== ''
                && ($path === $parent || str_starts_with($path, $parent.'.'));

            if ($isMetricNeighbour || MetricCandidateExtractor::namesAVerdict($path)) {
                $kept[$path] = $value;
            }

            if (count($kept) >= self::MAX_BODY_FIELDS) {
                break;
            }
        }

        return $kept === [] ? array_slice($fields, 0, self::MAX_BODY_FIELDS, true) : $kept;
    }

    /**
     * What changed between the baseline body and a later one, minus the fields
     * that change every time and mean nothing.
     *
     * @param  array<string, string>  $baseline
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    protected function diff(array $baseline, array $current): array
    {
        $changed = [];

        foreach ($current as $path => $value) {
            if ($this->isJitter($path)) {
                continue;
            }

            $before = $baseline[$path] ?? null;

            if ($before === $value) {
                continue;
            }

            $changed[$path] = ($before ?? 'absent').' -> '.$value;

            if (count($changed) >= self::MAX_BODY_FIELDS) {
                break;
            }
        }

        return $changed;
    }

    /**
     * Whether this leaf changes on every check by construction.
     *
     * A clock and a self-measured duration are not observations about the
     * target: they differ between any two bodies whatever the service is doing,
     * so a diff carrying them spends its budget proving that time passed. The
     * timing that DOES matter travels in the trusted check rows as `response_ms`,
     * measured by our own probe rather than reported by the target.
     */
    protected function isJitter(string $path): bool
    {
        $segments = explode('.', $path);
        $leaf = (string) end($segments);

        return in_array($leaf, self::JITTER_LEAVES, true) || str_ends_with($leaf, 'duration_ms');
    }

    /**
     * A decoded body as dot paths, matching the dialect
     * {@see MetricExtractor::extractJsonPath()} evaluates, so a path in the
     * evidence is a path the operator could paste into a metric.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, string>
     */
    protected function flatten(array $node, string $prefix = ''): array
    {
        $flat = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== []) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => '[]',
                $value === null => 'null',
                default => (string) $value,
            };
        }

        return $flat;
    }

    /**
     * The affected monitors as name + id, including soft-deleted ones.
     *
     * `withTrashed` on purpose: an incident outlives its monitor, and the name
     * is the only readable handle the analysis has. Without it a post-mortem
     * written after the monitor was removed loses the one word a person would
     * recognise and falls back to the uuid, which is the defect this roster was
     * added for.
     *
     * @param  list<string>  $monitorIds
     * @return list<array{monitor_id: string, name: string, url: string|null}>
     */
    protected function monitorRoster(array $monitorIds): array
    {
        return Monitor::withTrashed()
            ->whereIn('id', $monitorIds)
            // The URL is deliberately NOT selected. Its path segment is often the
            // credential (`https://host/api/v1/<32 hex>/status`), and this roster
            // is rendered into the prompt's trusted half, so the model copied the
            // whole address into a summary an operator then read back. Worse, the
            // draft service hands the stored analysis to the model as the settled
            // cause, so a credential here reaches a PUBLISHED postmortem through
            // a payload that had already been cleaned of URLs itself.
            // `IncidentAnalysisRedactionTest` pins it, and sweeps the other
            // payloads so a third surface cannot reopen it.
            ->get(['id', 'name'])
            ->map(fn (Monitor $monitor): array => [
                'monitor_id' => (string) $monitor->id,
                'name' => (string) $monitor->name,
            ])
            ->values()
            ->all();
    }

    /**
     * The metric whose breach opened this incident, with the bound it crossed
     * and the readings around it, or null when no metric did.
     *
     * This is the evidence that used to be missing outright. The incident row
     * carries `trigger_metric_key` and the readings sit in
     * `monitor_metric_values` with the band already frozen on them, so the
     * analyser was being asked to explain a breach whose number, threshold and
     * verdict were all one join away and none of them were sent.
     *
     * Windowed to the incident and one interval either side, because a reading
     * from a week ago says nothing about this outage, and the readings that
     * bracket the breach are what show whether it was a spike or a climb.
     *
     * @return array{label: string, path: string|null, direction: string|null, warn: string|null, critical: string|null, readings: list<array{value: string, band: string|null, recorded_at: string|null}>}|null
     */
    protected function triggeringMetric(Incident $incident): ?array
    {
        $key = $incident->trigger_metric_key;

        if ($key === null || $key === '' || $incident->primary_monitor_id === null) {
            return null;
        }

        $definition = MonitorMetric::query()
            ->where('monitor_id', $incident->primary_monitor_id)
            ->where('key', $key)
            ->first();

        $readings = MonitorMetricValue::query()
            ->where('monitor_id', $incident->primary_monitor_id)
            ->where('metric_key', $key)
            ->when(
                $incident->started_at !== null,
                fn ($query) => $query->where('recorded_at', '>=', $incident->started_at->copy()->subHour()),
            )
            // Closed at the far end too, once the incident has one. Ordering by
            // `recorded_at` desc with only a lower bound hands back the twelve
            // NEWEST readings the metric has, which on an incident resolved last
            // week are twelve readings from today: evidence about a different
            // day, presented as the evidence for this outage.
            ->when(
                $incident->resolved_at !== null,
                fn ($query) => $query->where('recorded_at', '<=', $incident->resolved_at->copy()->addHour()),
            )
            ->orderByDesc('recorded_at')
            ->limit(self::MAX_METRIC_READINGS)
            ->get();

        if ($definition === null && $readings->isEmpty()) {
            return null;
        }

        return [
            'label' => (string) ($definition?->label ?? $key),
            'path' => $definition?->extraction_path,
            'direction' => $definition?->threshold_direction?->value,
            'warn' => $definition?->warn_bound === null ? null : (string) (float) $definition->warn_bound,
            'critical' => $definition?->critical_bound === null ? null : (string) (float) $definition->critical_bound,
            // A string metric bands on words rather than bounds, so its
            // threshold travels as the lists. Empty on a numeric metric, which
            // is what makes the payload print the bounds instead.
            'ok_values' => (array) ($definition?->ok_values ?? []),
            'warn_values' => (array) ($definition?->warn_values ?? []),
            'critical_values' => (array) ($definition?->critical_values ?? []),
            'readings' => $readings->map(fn (MonitorMetricValue $value): array => [
                'value' => (string) ($value->numeric_value ?? $value->string_value ?? '?'),
                'band' => $value->band?->value,
                'recorded_at' => $value->recorded_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Build a deterministic summary from the incident's own fields, used
     * whenever the LLM is not consulted (over budget) or its output could not
     * be trusted (non-conforming past the retry).
     *
     * The enriched evidence and action arrays are left empty so this fallback
     * returns the IDENTICAL wire shape as the LLM path: the client renders no
     * hole and never sees a fabricated source.
     *
     * `summary` here is a MACHINE-READABLE BASELINE, not display copy. It
     * carries only the incident's own severity and lifecycle, in English, so a
     * direct API consumer sees a summary rather than an empty string; the client
     * ignores it and composes its own localized sentence from `degradeReason`
     * plus the labels it already translates. It used to open with a preamble
     * naming the baseline and parenthesising the reason as an English clause, and
     * a Turkish operator read that clause verbatim on the incident screen, which
     * is why the reason left the prose and became a field.
     *
     * @param  AiDegradeReason  $reason  Which failure sent us to the baseline.
     */
    protected function deterministicSummary(Incident $incident, AiDegradeReason $reason): IncidentAnalysisResult
    {
        return new IncidentAnalysisResult(
            summary: sprintf(
                '%s severity incident, currently %s.',
                $incident->severity?->value ?? 'unknown',
                $incident->lifecycle?->value ?? 'unknown',
            ),
            confidence: AiConfidence::Low,
            contributingFactors: [],
            strippedCitations: [],
            evidenceFor: [],
            evidenceAgainst: [],
            suggestedActions: [],
            degradeReason: $reason,
        );
    }
}
