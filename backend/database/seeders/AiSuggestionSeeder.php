<?php

namespace Database\Seeders;

use App\Enums\AiConfidence;
use App\Enums\AiMode;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Jobs\SweepAiSuggestions;
use App\Models\AiSuggestion;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use Illuminate\Database\Seeder;

/**
 * Seeds a demo pending {@see AiSuggestion} so the AI inbox renders end-to-end.
 *
 * There is no detection job running in local/dev, so `migrate:fresh --seed`
 * alone would leave `/dashboard/ai-inbox` empty. This seeder ensures one of
 * the demo team's monitors is in suggest mode, then writes a single pending
 * suggestion for it, mirroring the shape the statistical degrade path would
 * have produced (never calling the LLM).
 */
class AiSuggestionSeeder extends Seeder
{
    /**
     * Number of days the demo suggestion stays actionable before it would be
     * swept by {@see SweepAiSuggestions}.
     */
    private const EXPIRES_IN_DAYS = 7;

    /** Checks read to establish a monitor's own baseline. */
    private const HISTORY_SAMPLE = 60;

    /** The most recent slice compared against that baseline. */
    private const RECENT_SAMPLE = 10;

    /** Below this many timed checks there is no baseline worth claiming. */
    private const MIN_SAMPLE = 20;

    /**
     * Ratio of recent median to baseline median below which the data does not
     * support an anomaly claim, so no suggestion is written.
     */
    private const MIN_SCORE = 1.5;

    /**
     * Seed the demo AI suggestion.
     */
    public function run(): void
    {
        // 1. Never seed demo AI data outside local/dev; this is fixture data,
        //    not a real detection, so it has no place on a real host.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $team = Team::query()->first();

        if (! $team) {
            return;
        }

        // 2. Skip when the team already has a pending suggestion, guarding
        //    against duplicate dedupe_key inserts on repeated seed runs.
        if (AiSuggestion::query()->forTeam($team->id)->pending()->exists()) {
            return;
        }

        $monitor = $this->resolveSuggestModeMonitor($team);

        if (! $monitor) {
            return;
        }

        $this->createPendingSuggestion($team, $monitor);
    }

    /**
     * Resolve the monitor whose OWN check history actually shows a response-time
     * anomaly, and put it in `ai_mode=suggest` (StatusPageSeeder's demo monitors
     * default to `off`).
     *
     * Selecting by `created_at` (which this used to do) attached a confident
     * "running well above baseline" claim to whichever monitor happened to be
     * created first. On the demo fixture that is the HEALTHIEST one, so the AI
     * inbox shipped a claim the monitor's own data contradicts. The product's
     * honesty rules forbid exactly that, and it is the AI feature's whole
     * premise, so the demo has to be true by construction: pick the monitor the
     * evidence describes, and derive the evidence from it (see
     * {@see measureAnomaly}).
     *
     * Returns null when no monitor's data supports a claim, which correctly
     * leaves the inbox empty rather than inventing one.
     */
    private function resolveSuggestModeMonitor(Team $team): ?Monitor
    {
        $existing = Monitor::query()
            ->where('team_id', $team->id)
            ->where('ai_mode', AiMode::Suggest)
            ->first();

        if ($existing && $this->measureAnomaly($existing) !== null) {
            return $existing;
        }

        $candidates = Monitor::query()
            ->where('team_id', $team->id)
            ->get()
            ->map(fn (Monitor $monitor): array => [
                'monitor' => $monitor,
                'anomaly' => $this->measureAnomaly($monitor),
            ])
            ->filter(fn (array $row): bool => $row['anomaly'] !== null)
            // Strongest signal first, so the demo shows the clearest case.
            ->sortByDesc(fn (array $row): float => $row['anomaly']['score'])
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        /** @var Monitor $monitor */
        $monitor = $candidates->first()['monitor'];

        $monitor->forceFill(['ai_mode' => AiMode::Suggest])->save();

        return $monitor;
    }

    /**
     * Measure whether [$monitor]'s recorded checks show a response-time anomaly,
     * returning the real numbers behind it or null when they do not.
     *
     * The baseline is the monitor's own median across its history and the
     * observed value is the median of its most recent window, so both numbers
     * come from rows that exist. A monitor with too few timed checks, or one
     * whose recent window is not meaningfully slower, yields null.
     *
     * @return array{observed: float, baseline: float, score: float, sample: int}|null
     */
    private function measureAnomaly(Monitor $monitor): ?array
    {
        $timings = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->whereNotNull('response_ms')
            ->orderByDesc('checked_at')
            ->limit(self::HISTORY_SAMPLE)
            ->pluck('response_ms')
            ->map(fn ($ms): float => (float) $ms)
            ->values();

        if ($timings->count() < self::MIN_SAMPLE) {
            return null;
        }

        $baseline = $this->median($timings->all());
        $observed = $this->median($timings->take(self::RECENT_SAMPLE)->all());

        if ($baseline <= 0.0) {
            return null;
        }

        $score = round($observed / $baseline, 2);

        if ($score < self::MIN_SCORE) {
            return null;
        }

        return [
            'observed' => round($observed, 1),
            'baseline' => round($baseline, 1),
            'score' => $score,
            'sample' => $timings->count(),
        ];
    }

    /**
     * Median of [$values], which is used rather than a mean so a single probe
     * spike cannot masquerade as a sustained shift.
     *
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /**
     * Create one pending suggestion, shaped like the statistical degrade
     * path's output: a redacted evidence payload, a stable dedupe_key, and a
     * medium-confidence recommendation an operator can act on.
     */
    private function createPendingSuggestion(Team $team, Monitor $monitor): void
    {
        $anomaly = $this->measureAnomaly($monitor);

        // Only reachable via resolveSuggestModeMonitor, which already required
        // a measurable anomaly; re-measuring keeps this method honest on its own
        // rather than trusting a caller to have checked.
        if ($anomaly === null) {
            return;
        }

        $bucket = now()->format('YmdH');
        $observed = (int) round($anomaly['observed']);
        $baseline = (int) round($anomaly['baseline']);

        AiSuggestion::create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => 'response_time_ms',
            'method' => 'static',
            'score' => $anomaly['score'],
            'severity' => 'warn',
            'confidence' => AiConfidence::Medium,
            'source' => 'statistical',
            // Every number in this sentence is read back out of the monitor's
            // own checks, so an operator who opens the evidence finds the rows
            // that produced it.
            'recommendation' => "Response times on {$monitor->name} are averaging "
                ."{$observed}ms against a {$baseline}ms baseline. "
                .'Consider opening an incident to notify subscribers while you investigate.',
            'evidence' => [
                'observed' => $anomaly['observed'],
                'baseline' => $anomaly['baseline'],
                'threshold' => self::MIN_SCORE,
                'unit' => 'ms',
                'window' => self::RECENT_SAMPLE.' checks',
                'sample' => $anomaly['sample'],
            ],
            'dedupe_key' => "monitor:{$monitor->id}:response_time:static:{$bucket}",
            'status' => AiSuggestionStatus::Pending,
            'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
        ]);
    }
}
