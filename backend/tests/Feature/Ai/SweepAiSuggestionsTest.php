<?php

namespace Tests\Feature\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiMode;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Jobs\SweepAiSuggestions;
use App\Jobs\TriageAnomalyCandidate;
use App\Models\AiSuggestion;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\ResponseTimeAnomalyDetector;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the scheduled anomaly sweep: it fans out a triage for a fresh anomaly
 * on a suggest-mode monitor, never touches an off-mode monitor, and does not
 * re-dispatch an episode that already carries a live suggestion (the
 * dispatch-time dedupe that blunts the 2-minute re-scan).
 *
 * The window is seeded past the detector's cold-start gate (>= 100 checks over
 * >= 1800s) with a clear sustained spike, so the pure statistical detector
 * returns a real candidate without any configured bounds.
 */
class SweepAiSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_anomalous_suggest_monitor_dispatches_a_triage(): void
    {
        Queue::fake();
        $monitor = $this->makeMonitor(AiMode::Suggest);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        Queue::assertPushed(
            TriageAnomalyCandidate::class,
            fn (TriageAnomalyCandidate $job): bool => $job->monitorId === (string) $monitor->id
                && $job->candidateData['signal'] === 'response_time',
        );
    }

    public function test_off_mode_monitor_dispatches_nothing(): void
    {
        Queue::fake();
        $monitor = $this->makeMonitor(AiMode::Off);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        Queue::assertNotPushed(TriageAnomalyCandidate::class);
    }

    public function test_existing_pending_suggestion_is_not_redispatched(): void
    {
        Queue::fake();
        $monitor = $this->makeMonitor(AiMode::Suggest);
        $this->seedAnomalousWindow($monitor);

        // 1. First sweep produces the candidate; capture its dedupe key.
        $this->runSweep();
        $dedupeKey = null;
        Queue::assertPushed(TriageAnomalyCandidate::class, function (TriageAnomalyCandidate $job) use (&$dedupeKey): bool {
            $dedupeKey = (string) $job->candidateData['dedupe_key'];

            return true;
        });

        // 2. A live suggestion now carries that exact dedupe key.
        $this->seedPendingSuggestion($monitor, $dedupeKey);

        // 3. Re-sweeping the same still-open window must not re-enqueue triage.
        Queue::fake();
        $this->runSweep();

        Queue::assertNotPushed(TriageAnomalyCandidate::class);
    }

    /**
     * Run the sweep with a real (pure) detector, exactly as the queue worker
     * would resolve it.
     */
    protected function runSweep(): void
    {
        (new SweepAiSuggestions)->handle(new ResponseTimeAnomalyDetector);
    }

    /**
     * Persist a team-owned monitor in the given AI mode.
     */
    protected function makeMonitor(AiMode $aiMode): Monitor
    {
        $user = User::query()->create([
            'name' => 'Sweep Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Sweep Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/',
            'check_interval_sec' => 60,
            'ai_mode' => $aiMode,
            'last_status' => MonitorStatus::Up,
        ]);
    }

    /**
     * Seed a window that clears the cold-start gate and ends in a sustained
     * response-time spike, so the statistical detector fires a real candidate.
     *
     * 110 baseline checks with mild variation (a non-zero MAD scale) followed
     * by 10 checks on a high plateau, one minute apart (119 minutes of span,
     * 120 samples).
     */
    protected function seedAnomalousWindow(Monitor $monitor): void
    {
        $start = now()->subMinutes(120);

        // 1. Baseline: values cycle 180..220ms so the robust MAD scale is non-zero.
        for ($i = 0; $i < 110; $i++) {
            $this->makeCheck($monitor, $start->copy()->addMinutes($i), 200 + (($i % 5) - 2) * 10);
        }

        // 2. Trailing plateau: a clear, sustained shift the detector must flag.
        for ($i = 110; $i < 120; $i++) {
            $this->makeCheck($monitor, $start->copy()->addMinutes($i), 2500);
        }
    }

    /**
     * Record one probe check at a fixed time with the given response_ms.
     */
    protected function makeCheck(Monitor $monitor, CarbonInterface $checkedAt, int $responseMs): void
    {
        MonitorCheck::query()->create([
            'id' => (string) Str::uuid(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'global',
            'checked_at' => $checkedAt,
            'status' => MonitorStatus::Up->value,
            'status_code' => 200,
            'response_ms' => $responseMs,
        ]);
    }

    /**
     * Persist a live pending suggestion carrying the given dedupe key, so the
     * dispatch-time dedupe check must short circuit the next sweep.
     */
    protected function seedPendingSuggestion(Monitor $monitor, string $dedupeKey): void
    {
        AiSuggestion::query()->create([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => 'response_time',
            'method' => 'mad',
            'score' => 5.0,
            'severity' => 'critical',
            'confidence' => AiConfidence::High,
            'source' => 'statistical',
            'recommendation' => 'An operator is already reviewing this episode.',
            'evidence' => [
                'observed' => 2500.0,
            ],
            'dedupe_key' => $dedupeKey,
            'status' => AiSuggestionStatus::Pending,
            'expires_at' => now()->addDays(7),
        ]);
    }
}
