<?php

namespace Database\Seeders;

use App\Enums\AiConfidence;
use App\Enums\AiMode;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Jobs\SweepAiSuggestions;
use App\Models\AiSuggestion;
use App\Models\Monitor;
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
     * Resolve a monitor to attach the demo suggestion to, ensuring it is in
     * `ai_mode=suggest` (StatusPageSeeder's demo monitors default to `off`).
     *
     * Reuses the first monitor of the demo team's monitors instead of
     * duplicating monitor-creation; only falls back to creating one when the
     * team has none yet.
     */
    private function resolveSuggestModeMonitor(Team $team): ?Monitor
    {
        $monitor = Monitor::query()
            ->where('team_id', $team->id)
            ->where('ai_mode', AiMode::Suggest)
            ->first();

        if ($monitor) {
            return $monitor;
        }

        $monitor = Monitor::query()
            ->where('team_id', $team->id)
            ->orderBy('created_at')
            ->first();

        if (! $monitor) {
            return null;
        }

        $monitor->forceFill(['ai_mode' => AiMode::Suggest])->save();

        return $monitor;
    }

    /**
     * Create one pending suggestion, shaped like the statistical degrade
     * path's output: a redacted evidence payload, a stable dedupe_key, and a
     * medium-confidence recommendation an operator can act on.
     */
    private function createPendingSuggestion(Team $team, Monitor $monitor): void
    {
        $bucket = now()->format('YmdH');

        AiSuggestion::create([
            'team_id' => $team->id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => 'response_time_ms',
            'method' => 'static',
            'score' => 3.2,
            'severity' => 'warn',
            'confidence' => AiConfidence::Medium,
            'source' => 'statistical',
            'recommendation' => "Response times on {$monitor->name} are running well above baseline. "
                .'Consider opening an incident to notify subscribers while you investigate.',
            'evidence' => [
                'observed' => 842.0,
                'baseline' => 210.0,
                'threshold' => 3.0,
                'unit' => 'ms',
                'window' => '15m',
            ],
            'dedupe_key' => "monitor:{$monitor->id}:response_time:static:{$bucket}",
            'status' => AiSuggestionStatus::Pending,
            'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
        ]);
    }
}
