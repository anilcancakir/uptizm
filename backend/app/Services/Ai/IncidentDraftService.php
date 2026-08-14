<?php

namespace App\Services\Ai;

use App\Enums\AiDegradeReason;
use App\Enums\IncidentDraftKind;
use App\Models\AiIncidentAnalysis;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetricValue;
use App\Services\Monitoring\IncidentTitle;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

/**
 * Composes the evidence for a draft and asks the drafting gateway for one.
 *
 * The sibling of {@see IncidentAnalysisService} and deliberately thinner than
 * it, because the expensive question is already answered by the time this runs.
 * When a stored analysis exists it is handed over whole
 * ({@see AiIncidentAnalysis}), so the draft is a writing task over a decided
 * root cause rather than a second, cheaper investigation that could contradict
 * the first one on the same screen. When none exists the draft still goes
 * ahead, describing what the probes recorded and claiming no cause: that is a
 * thinner note, and an honest one.
 *
 * Degradation mirrors the analysis service exactly, with one difference at the
 * end: there is no deterministic baseline here. The client owns a localized
 * template for both surfaces and has since before this service existed, so a
 * degrade returns null and lets the client fall back to text a person wrote.
 * See {@see IncidentDraftResult}.
 */
class IncidentDraftService
{
    /**
     * How many checks to read for the shape of the failure.
     *
     * Lower than the analysis service's twenty because the rows are collapsed
     * to their distinct verdicts before the model sees them: a draft is written
     * from "down from three regions, 503" and gains nothing from the fortieth
     * row saying it again.
     */
    private const MAX_CHECKS = 40;

    /**
     * How far BEFORE the incident opened the check window reaches, in checks.
     *
     * Same reasoning and same number as the analysis service's: a threshold
     * trips on consecutive failures, so the failures that caused the incident
     * are all before the moment it opened, and a window starting at
     * `started_at` excludes exactly the evidence a draft is about. At open,
     * which is where the autonomous path lives, it excluded everything.
     */
    private const CHECK_LOOKBACK_CHECKS = 10;

    /**
     * How many prior updates to carry.
     *
     * The point of them is continuity of voice and not repeating what was
     * already said, and both are served by the recent ones.
     */
    private const MAX_UPDATES = 8;

    /**
     * Fields of the response-body slice, postmortem only.
     */
    private const MAX_BODY_FIELDS = 20;

    public function __construct(
        protected IncidentDraftGateway $gateway,
        protected AiBudget $budget,
    ) {}

    /**
     * Draft a public status update or a postmortem for an incident.
     */
    public function draftFor(
        Incident $incident,
        IncidentDraftKind $kind,
        string $locale,
        ?string $postingAs = null,
    ): IncidentDraftResult {
        $payload = $this->composePayload($incident, $kind, $locale, $postingAs);
        $teamId = (string) $incident->team_id;

        // 1. Over budget never calls the provider.
        if (! $this->budget->tryConsume($teamId)) {
            return new IncidentDraftResult(null, AiDegradeReason::BudgetExhausted);
        }

        // 2. The same three provider failures the analysis surface degrades on,
        //    for the same reasons and with the same reason codes; the taxonomy
        //    is documented at length in IncidentAnalysisService::analyzeWith().
        try {
            return $this->gateway->draft($payload);
        } catch (NonConformingAnalysisException) {
            return new IncidentDraftResult(null, AiDegradeReason::OutputUntrusted);
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Incident draft degraded: the AI service was unreachable.', [
                'incident_id' => (string) $incident->id,
                'kind' => $kind->value,
                'failure' => class_basename($exception),
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'exception' => $exception->getMessage(),
            ]);

            return new IncidentDraftResult(null, AiDegradeReason::ServiceUnreachable);
        } catch (AiException $exception) {
            Log::warning('Incident draft degraded: the AI provider could not complete the request.', [
                'incident_id' => (string) $incident->id,
                'kind' => $kind->value,
                'failure' => class_basename($exception),
            ]);

            return new IncidentDraftResult(null, AiDegradeReason::ServiceUnreachable);
        }
    }

    /**
     * Assemble what a draft is written from.
     */
    protected function composePayload(
        Incident $incident,
        IncidentDraftKind $kind,
        string $locale,
        ?string $postingAs = null,
    ): IncidentDraftPayload {
        $incident->loadMissing([
            'updates' => fn ($query) => $query->orderByDesc('display_at')->limit(self::MAX_UPDATES),
            'monitors',
            'primaryMonitor',
        ]);

        $monitors = $incident->monitors
            ->push($incident->primaryMonitor)
            ->filter()
            ->unique('id');

        $cadence = (int) ($incident->primaryMonitor?->check_interval_sec
            ?? Monitor::DEFAULT_CHECK_INTERVAL_SEC);

        $checks = MonitorCheck::query()
            ->whereIn('monitor_id', $monitors->pluck('id'))
            ->where(
                'checked_at',
                '>=',
                $incident->started_at->copy()->subSeconds(self::CHECK_LOOKBACK_CHECKS * $cadence),
            )
            ->when(
                $incident->resolved_at !== null,
                fn ($query) => $query->where('checked_at', '<=', $incident->resolved_at),
            )
            ->orderByDesc('checked_at')
            ->limit(self::MAX_CHECKS)
            ->get();

        return new IncidentDraftPayload(
            kind: $kind,
            locale: $locale,
            // Through IncidentTitle so an auto-generated headline arrives in the
            // reader's language rather than as its catalogue key.
            title: IncidentTitle::render($incident, $locale),
            severity: (string) ($incident->severity?->value ?? 'unknown'),
            impact: (string) ($incident->impact?->value ?? 'unknown'),
            lifecycle: (string) ($incident->lifecycle?->value ?? 'unknown'),
            postingAs: $postingAs,
            startedAt: (string) $incident->started_at?->toIso8601String(),
            resolvedAt: $incident->resolved_at?->toIso8601String(),
            duration: $this->duration($incident),
            // Name only. The monitor URL is deliberately NOT passed; see the
            // reasoning at IncidentDraftPayload's docblock. It can be a
            // credential, and a postmortem is published.
            monitors: $monitors->map(fn ($monitor): array => [
                'name' => (string) $monitor->name,
            ])->values()->all(),
            checks: $this->collapseChecks($checks),
            triggeringMetric: $this->triggeringMetric($incident),
            updates: $incident->updates->map(fn ($update): array => [
                'status' => (string) ($update->status?->value ?? $update->status ?? 'unknown'),
                'at' => (string) $update->display_at?->toIso8601String(),
                'is_public' => (bool) $update->is_public,
                'message' => (string) $update->message,
            ])->values()->all(),
            analysis: $this->storedAnalysis($incident),
            bodies: $kind === IncidentDraftKind::Postmortem ? $this->bodySlice($checks) : [],
        );
    }

    /**
     * The distinct check verdicts and how many times each was recorded.
     *
     * @param  Collection<int, MonitorCheck>  $checks
     * @return list<array<string, mixed>>
     */
    protected function collapseChecks(Collection $checks): array
    {
        $rows = [];

        foreach ($checks as $check) {
            // Latency is deliberately OUT of the key. Including it collapsed
            // nothing, because no two checks answer in the same millisecond: a
            // live dump printed twenty-two rows of "eu-central up HTTP 200"
            // differing only in `1353ms` versus `614ms`, which is twenty-two
            // ways of telling the model one fact. It travels as a range instead,
            // which is what a sentence about latency can honestly say anyway.
            $key = implode('|', [
                (string) $check->region,
                (string) ($check->status?->value ?? $check->status),
                (string) $check->status_code,
            ]);

            $ms = $check->response_ms === null ? null : (int) $check->response_ms;

            if (isset($rows[$key])) {
                $rows[$key]['count']++;
                if ($ms !== null) {
                    $rows[$key]['slowest_ms'] = max($rows[$key]['slowest_ms'] ?? $ms, $ms);
                    $rows[$key]['fastest_ms'] = min($rows[$key]['fastest_ms'] ?? $ms, $ms);
                }

                continue;
            }

            $rows[$key] = [
                'region' => (string) $check->region,
                'status' => (string) ($check->status?->value ?? $check->status),
                'status_code' => $check->status_code,
                'fastest_ms' => $ms,
                'slowest_ms' => $ms,
                'count' => 1,
            ];
        }

        return array_values($rows);
    }

    /**
     * The metric that opened the incident, with its latest reading only.
     *
     * The analysis payload carries twelve readings because it is deciding what
     * happened. This one carries the current value because it is writing a
     * sentence about it.
     *
     * @return array<string, mixed>|null
     */
    protected function triggeringMetric(Incident $incident): ?array
    {
        $key = $incident->trigger_metric_key;

        if ($key === null || $key === '' || $incident->primary_monitor_id === null) {
            return null;
        }

        $definition = $incident->primaryMonitor?->metrics()
            ->where('key', $key)
            ->first();

        $latest = MonitorMetricValue::query()
            ->where('monitor_id', $incident->primary_monitor_id)
            ->where('metric_key', $key)
            ->orderByDesc('recorded_at')
            ->first();

        if ($definition === null && $latest === null) {
            return null;
        }

        return [
            'label' => (string) ($definition?->label ?? $key),
            'warn' => $definition?->warn_bound === null ? null : (string) (float) $definition->warn_bound,
            'critical' => $definition?->critical_bound === null ? null : (string) (float) $definition->critical_bound,
            'latest' => $latest === null
                ? 'unknown'
                : (string) ($latest->numeric_value ?? $latest->string_value ?? 'unknown'),
        ];
    }

    /**
     * The stored analysis, when one has been produced for this incident.
     *
     * The newest row wins. There can be several, one per evidence fingerprint,
     * and the newest is the one the operator is looking at on the same screen.
     *
     * @return array{summary: string, confidence: string, contributing_factors: list<string>}|null
     */
    protected function storedAnalysis(Incident $incident): ?array
    {
        $stored = AiIncidentAnalysis::query()
            ->where('incident_id', $incident->getKey())
            ->latest('created_at')
            ->first();

        if ($stored === null) {
            return null;
        }

        $result = $stored->result;

        return [
            'summary' => (string) ($result['summary'] ?? ''),
            'confidence' => (string) ($result['confidence'] ?? 'unknown'),
            'contributing_factors' => array_values(array_map(
                fn ($factor): string => (string) $factor,
                (array) ($result['contributing_factors'] ?? []),
            )),
        ];
    }

    /**
     * A flat slice of the most recent response body, for a postmortem.
     *
     * One body and not a diff series: the postmortem is written after the fact
     * and needs to say what the service was reporting, not to reconstruct the
     * minute it changed. The analysis already did that.
     *
     * @param  Collection<int, MonitorCheck>  $checks
     * @return list<array<string, mixed>>
     */
    protected function bodySlice(Collection $checks): array
    {
        foreach ($checks as $check) {
            $raw = $check->response_body_preview;

            if ($raw === null || $raw === '') {
                continue;
            }

            $body = json_decode($raw, true);

            if (! is_array($body) || $body === []) {
                continue;
            }

            $fields = [];
            foreach ($this->flatten($body) as $path => $value) {
                if (count($fields) >= self::MAX_BODY_FIELDS) {
                    break;
                }
                $fields[$path] = $value;
            }

            return $fields === [] ? [] : [['fields' => $fields]];
        }

        return [];
    }

    /**
     * Flatten a decoded body to dot paths.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, string>
     */
    protected function flatten(array $node, string $prefix = ''): array
    {
        $flat = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = [...$flat, ...$this->flatten($value, $path)];

                continue;
            }

            $flat[$path] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $flat;
    }

    /**
     * How long the incident ran, in words.
     *
     * An open incident is measured to now, because that is what a status update
     * posted right now would say.
     */
    protected function duration(Incident $incident): string
    {
        $start = $incident->started_at;

        // CarbonInterface and not Carbon: the model casts these as
        // `immutable_datetime`, so they arrive as CarbonImmutable, which does
        // NOT extend Illuminate\Support\Carbon. Typed against the class, this
        // guard answered "unknown" for every incident that has ever existed,
        // which is exactly what the first live prompt dump printed.
        if (! $start instanceof CarbonInterface) {
            return 'unknown';
        }

        return $start->diffForHumans(
            $incident->resolved_at ?? now(),
            ['syntax' => true, 'parts' => 2],
        );
    }
}
