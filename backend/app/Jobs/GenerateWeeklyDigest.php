<?php

namespace App\Jobs;

use App\Enums\AiConfidence;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\TeamDigest;
use App\Services\Ai\AiBudget;
use App\Services\Ai\DigestGateway;
use App\Services\Ai\DigestPayload;
use App\Services\Ai\DigestResult;
use App\Support\Ai\PromptLanguage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Composes one team's week (aggregate uptime, the trend against the prior
 * week, and the incidents opened during the window) into a
 * {@see DigestPayload} and asks the {@see DigestGateway} to narrate it into a
 * persisted {@see TeamDigest} row.
 *
 * Mirrors {@see TriageAnomalyCandidate}'s budget-guard and degrade-persists
 * shape: the per-team daily AI budget is spent AT THIS call site, and both an
 * over-budget team and a gateway failure degrade to a deterministic digest
 * derived from the team's own aggregate numbers, never dropping the week's
 * recap. This is a queued job: it is never invoked synchronously from a
 * request, so `GET /incidents/digest` only ever reads the latest persisted
 * row.
 */
class GenerateWeeklyDigest implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of recent incidents folded into the digest evidence.
     */
    private const MAX_INCIDENTS = 20;

    /**
     * The AI tier a team's plan must reach for the weekly digest.
     *
     * Declared once and read by both sides of the feature, because the two have
     * to agree: `DispatchWeeklyDigests` decides who a digest is generated FOR
     * and `DigestController` decides who may READ one. A literal in each place
     * would let them drift into generating rows nobody can fetch, or skipping
     * teams that are entitled to one.
     */
    public const string AI_LEVEL = 'auto';

    /**
     * @param  string  $teamId  The team the digest is generated for.
     */
    public function __construct(
        public string $teamId,
    ) {
        $this->onQueue('ai');
    }

    /**
     * Compose, budget-guard, narrate, and persist exactly one weekly digest.
     *
     * @param  DigestGateway  $gateway  The LLM boundary (faked in tests).
     * @param  AiBudget  $budget  The atomic per-team daily spend guard.
     */
    public function handle(DigestGateway $gateway, AiBudget $budget): void
    {
        // 1. Resolve the team; an unknown team has nothing to digest.
        $team = Team::query()->find($this->teamId);
        if ($team === null) {
            return;
        }

        // 2. This week and the prior week, both UTC calendar-day aligned so
        //    the trend comparison is stable across runs.
        $weekEnd = CarbonImmutable::now('UTC')->startOfDay();
        $weekStart = $weekEnd->subDays(7);
        $previousWeekStart = $weekStart->subDays(7);

        $monitorIds = Monitor::query()
            ->where('team_id', $team->id)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $uptimePercent = $this->averageUptime($monitorIds, $weekStart, $weekEnd);
        $previousUptimePercent = $this->averageUptime($monitorIds, $previousWeekStart, $weekStart);

        $incidents = Incident::query()
            ->where('team_id', $team->id)
            ->where('started_at', '>=', $weekStart)
            ->orderByDesc('started_at')
            ->limit(self::MAX_INCIDENTS)
            ->get();

        $payload = $this->buildPayload(
            $team,
            $weekStart,
            $weekEnd,
            $uptimePercent,
            $previousUptimePercent,
            $incidents,
            $monitorIds,
        );

        // 3. Spend one unit of the team's daily budget atomically. Over budget
        //    degrades to a deterministic digest; it never blocks the recap.
        if (! $budget->tryConsume((string) $team->id)) {
            $result = $this->deterministicDigest($uptimePercent, $incidents->count(), 'AI digest budget exhausted for today');
        } else {
            // 4. Within budget: narrate via the LLM. Any gateway failure
            //    degrades to a deterministic digest (the week's recap always
            //    lands).
            try {
                $result = $gateway->summarize($payload);
            } catch (Throwable) {
                // Deliberate degrade, not a silent swallow: the aggregate
                // numbers are the source of truth and the digest must still
                // be persisted. The exception detail is withheld from the
                // log on purpose.
                Log::warning('AI digest degraded to a deterministic summary.', [
                    'team_id' => $team->id,
                ]);
                $result = $this->deterministicDigest($uptimePercent, $incidents->count(), 'AI digest gateway unavailable');
            }
        }

        $this->persist($team, $weekStart, $weekEnd, $uptimePercent, $incidents->count(), $result);
    }

    /**
     * The team's aggregate uptime percent across its monitors' daily rollups
     * within `[from, to)`, defaulting to a fully healthy 100.0 when the
     * rollup has no rows yet for the window.
     *
     * @param  list<string>  $monitorIds
     */
    private function averageUptime(array $monitorIds, CarbonImmutable $from, CarbonImmutable $to): float
    {
        if ($monitorIds === []) {
            return 100.0;
        }

        $average = DB::table('monitor_daily_uptime')
            ->whereIn('monitor_id', $monitorIds)
            ->whereBetween('date', [
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ])
            ->avg('uptime_percent');

        return $average === null ? 100.0 : round((float) $average, 2);
    }

    /**
     * Hydrate the fenced payload from the team's week. There is no untrusted
     * probe data here (see {@see DigestPayload}'s docblock): every field is
     * our own aggregate uptime number or our own incident record.
     *
     * @param  Collection<int, Incident>  $incidents
     * @param  list<string>  $monitorIds
     */
    private function buildPayload(
        Team $team,
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        float $uptimePercent,
        float $previousUptimePercent,
        Collection $incidents,
        array $monitorIds,
    ): DigestPayload {
        $trustedIncidents = $incidents->map(fn (Incident $incident): array => [
            'incident_id' => (string) $incident->id,
            'title' => $incident->title,
            'severity' => $incident->severity?->value ?? 'unknown',
            'impact' => $incident->impact?->value ?? 'unknown',
            'started_at' => $incident->started_at?->toIso8601String() ?? '',
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ])->all();

        return new DigestPayload(
            teamId: (string) $team->id,
            weekStart: $weekStart->format('Y-m-d'),
            weekEnd: $weekEnd->format('Y-m-d'),
            uptimePercent: $uptimePercent,
            previousUptimePercent: $previousUptimePercent,
            incidentCount: $incidents->count(),
            incidents: $trustedIncidents,
            knownIncidentIds: array_column($trustedIncidents, 'incident_id'),
            knownMonitorIds: $monitorIds,
            // The digest MAIL already localizes through HasLocalePreference,
            // so an English body inside a Turkish email was the whole defect.
            language: PromptLanguage::nameFor($team?->preferredLocale()),
        );
    }

    /**
     * Build a deterministic digest from the team's own aggregate numbers,
     * used when the team is over its daily AI budget or the gateway fails,
     * so the LLM is never called (or its failure never drops the recap).
     */
    private function deterministicDigest(float $uptimePercent, int $incidentCount, string $reason): DigestResult
    {
        return new DigestResult(
            summary: sprintf(
                'Deterministic weekly digest (%s): %s%% uptime with %d incident%s this week.',
                $reason,
                number_format($uptimePercent, 1),
                $incidentCount,
                $incidentCount === 1 ? '' : 's',
            ),
            confidence: AiConfidence::Low,
            highlights: [],
            strippedCitations: [],
        );
    }

    /**
     * Write the digest row for this run. Weekly digests are append-only:
     * each run persists a new row, and the controller reads the latest by
     * `generated_at`.
     */
    private function persist(
        Team $team,
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        float $uptimePercent,
        int $incidentCount,
        DigestResult $result,
    ): void {
        TeamDigest::query()->create([
            'team_id' => $team->id,
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'uptime_percent' => $uptimePercent,
            'incident_count' => $incidentCount,
            'confidence' => $result->confidence,
            'summary' => $result->summary,
            'highlights' => $result->highlights,
            'stripped_citations' => $result->strippedCitations,
            'generated_at' => CarbonImmutable::now(),
        ]);
    }
}
