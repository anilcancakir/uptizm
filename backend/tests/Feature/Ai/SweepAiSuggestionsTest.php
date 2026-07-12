<?php

namespace Tests\Feature\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiMode;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Jobs\SweepAiSuggestions;
use App\Jobs\TriageAnomalyCandidate;
use App\Models\AiSuggestion;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AnomalyTriageGateway;
use App\Services\Ai\FakeAnomalyTriageGateway;
use App\Services\Ai\TriagePayload;
use App\Services\Ai\TriageResult;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Default the triage boundary to the deterministic fake, so no run ever
        // reaches the real Anthropic gateway; auto-mode tests rebind a specific
        // confidence stub before sweeping.
        $this->app->bind(AnomalyTriageGateway::class, FakeAnomalyTriageGateway::class);
    }

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

    public function test_auto_monitor_above_threshold_within_budget_auto_opens_an_incident(): void
    {
        // A high-confidence label clears the auto-open threshold; the anomalous
        // window puts a real candidate under budget.
        $this->app->instance(AnomalyTriageGateway::class, new HighConfidenceTriageGateway);
        $monitor = $this->makeMonitor(AiMode::Auto);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        // 1. Exactly one AI-owned incident is opened, sourced from the anomaly.
        $incident = Incident::query()->sole();
        $this->assertTrue($incident->ai_owned);
        $this->assertSame(SignalSource::AiAnomaly, $incident->signal_source);
        $this->assertSame($monitor->id, $incident->primary_monitor_id);

        // 2. The opening timeline entry is flagged autonomous (AI, no human ok).
        $opening = IncidentUpdate::query()->where('incident_id', $incident->id)->sole();
        $this->assertTrue($opening->autonomous);
        $this->assertSame('ai', $opening->actor);

        // 3. The suggestion audit-trails the accept, linked to the incident.
        $suggestion = AiSuggestion::query()->sole();
        $this->assertSame(AiSuggestionStatus::Accepted, $suggestion->status);
        $this->assertSame('llm', $suggestion->source);
        $this->assertSame($incident->id, $suggestion->accepted_incident_id);
    }

    public function test_auto_monitor_below_threshold_does_not_auto_open(): void
    {
        // The default fake labels at medium confidence, below the high-only
        // auto-open bar: the anomaly falls back to a pending suggestion.
        $monitor = $this->makeMonitor(AiMode::Auto);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        $this->assertSame(0, Incident::query()->count());

        $suggestion = AiSuggestion::query()->sole();
        $this->assertSame(AiSuggestionStatus::Pending, $suggestion->status);
        $this->assertSame('llm', $suggestion->source);
        $this->assertNull($suggestion->accepted_incident_id);
    }

    public function test_auto_monitor_over_budget_does_not_auto_open(): void
    {
        // A zero daily cap forces over budget even with a high-confidence stub:
        // the LLM is never called and no incident is auto-opened.
        config(['ai.budget.daily_per_team' => 0]);
        $this->app->instance(AnomalyTriageGateway::class, new HighConfidenceTriageGateway);
        $monitor = $this->makeMonitor(AiMode::Auto);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        $this->assertSame(0, Incident::query()->count());

        $suggestion = AiSuggestion::query()->sole();
        $this->assertSame(AiSuggestionStatus::Pending, $suggestion->status);
        $this->assertSame('statistical', $suggestion->source);
        $this->assertNull($suggestion->accepted_incident_id);
    }

    /**
     * Run the sweep with a real (pure) detector, resolving the gateway, budget,
     * and incident opener from the container exactly as the queue worker would.
     */
    protected function runSweep(): void
    {
        $this->app->call([new SweepAiSuggestions, 'handle']);
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

/**
 * A gateway that always labels at high confidence, so a test can exercise the
 * autonomous auto-open branch (which fires only above the confidence threshold).
 */
class HighConfidenceTriageGateway implements AnomalyTriageGateway
{
    public function triage(TriagePayload $payload): TriageResult
    {
        return new TriageResult(
            confirmed: true,
            severity: 'critical',
            confidence: AiConfidence::High,
            recommendation: 'High-confidence stub: sustained response-time deviation.',
            strippedCitations: [],
        );
    }
}
