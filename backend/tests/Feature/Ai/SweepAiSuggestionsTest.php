<?php

namespace Tests\Feature\Ai;

use App\Enums\AiConfidence;
use App\Enums\AiMode;
use App\Enums\AiSuggestionKind;
use App\Enums\AiSuggestionStatus;
use App\Enums\EscalationTargetType;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Events\IncidentBroadcast;
use App\Jobs\DispatchEscalationStep;
use App\Jobs\SweepAiSuggestions;
use App\Jobs\TriageAnomalyCandidate;
use App\Models\AiSuggestion;
use App\Models\EscalationPolicy;
use App\Models\EscalationStep;
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
use App\Services\StatusPages\StatusPageCache;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
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

    public function test_autonomous_auto_open_dispatches_through_the_shared_seam(): void
    {
        // The AI open must drive the SAME off-transaction dispatch as every other
        // incident path: broadcast to the dashboard, bust the status-page cache,
        // and page the on-call escalation ladder.
        Event::fake([IncidentBroadcast::class]);
        Queue::fake();
        $cache = Mockery::spy(StatusPageCache::class);
        $this->app->instance(StatusPageCache::class, $cache);

        $this->app->instance(AnomalyTriageGateway::class, new HighConfidenceTriageGateway);
        $monitor = $this->makeMonitor(AiMode::Auto);
        $this->seedAnomalousWindow($monitor);
        $this->seedDefaultPolicy($monitor);

        $this->runSweep();

        $incident = Incident::query()->sole();

        // 1. The dashboard broadcast fired for the freshly opened AI incident.
        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'opened'
                && $event->incident->id === $incident->id,
        );

        // 2. The public status-page cache was busted for the incident's monitor.
        $cache->shouldHaveReceived('invalidateForMonitors')->once()->with([$monitor->id]);

        // 3. The escalation ladder was walked: a step is queued for the incident.
        Queue::assertPushed(
            DispatchEscalationStep::class,
            fn (DispatchEscalationStep $job): bool => $job->incidentId === $incident->id,
        );
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

    // ---------------------------------------------------------------------
    // The closing half of the autonomous lane
    // ---------------------------------------------------------------------

    public function test_a_quiet_signal_resolves_the_incident_the_lane_opened(): void
    {
        $monitor = $this->makeMonitor(AiMode::Auto);
        $incident = $this->openedAnomalyIncident($monitor, threshold: 900.0);
        $this->seedQuietWindow($monitor, responseMs: 200);

        $this->runSweep();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->lifecycle);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_the_resolve_leaves_a_public_system_note(): void
    {
        $monitor = $this->makeMonitor(AiMode::Auto);
        $incident = $this->openedAnomalyIncident($monitor, threshold: 900.0);
        $this->seedQuietWindow($monitor, responseMs: 200);

        $this->runSweep();

        // A status page renders this like any other update, so it has to be
        // public, system-authored, and not marked autonomous (the AI did not
        // write the sentence; the lane's own arithmetic did).
        $note = $incident->updates()->where('status', IncidentStatus::Resolved->value)->sole();
        $this->assertSame('system', $note->actor);
        $this->assertTrue($note->is_public);
        $this->assertFalse($note->autonomous);
        $this->assertStringContainsString($monitor->name, $note->message);
    }

    public function test_the_resolve_dispatches_through_the_shared_seam(): void
    {
        // The mirror of the open-path assertion above. A resolve that only wrote
        // the row would leave the dashboard showing an incident that is over and
        // the public status page serving it from a stale cache, and no other
        // test here would notice.
        Event::fake([IncidentBroadcast::class]);
        $cache = Mockery::spy(StatusPageCache::class);
        $this->app->instance(StatusPageCache::class, $cache);

        $monitor = $this->makeMonitor(AiMode::Auto);
        $incident = $this->openedAnomalyIncident($monitor, threshold: 900.0);
        $this->seedQuietWindow($monitor, responseMs: 200);

        $this->runSweep();

        Event::assertDispatched(
            IncidentBroadcast::class,
            fn (IncidentBroadcast $event): bool => $event->kind === 'resolved'
                && $event->incident->id === $incident->id,
        );
        $cache->shouldHaveReceived('invalidateForMonitors')->once()->with([$monitor->id]);
    }

    public function test_a_signal_still_above_its_level_keeps_the_incident_open(): void
    {
        // Quiet enough that the DETECTOR raises nothing (a flat series has no
        // spike and no drift), yet every reading is still above the level this
        // incident was opened against. Nothing recovered, so nothing closes.
        $monitor = $this->makeMonitor(AiMode::Auto);
        $incident = $this->openedAnomalyIncident($monitor, threshold: 900.0);
        $this->seedQuietWindow($monitor, responseMs: 2500);

        $this->runSweep();

        $this->assertSame(IncidentStatus::Detected, $incident->refresh()->lifecycle);
    }

    public function test_an_incident_with_no_judgeable_level_is_left_for_a_human(): void
    {
        // Fail-closed: with no numeric threshold on the suggestion behind it
        // there is nothing to measure recovery against, and guessing would close
        // a real outage. Leaving it open is exactly today's behaviour, so the
        // fallback is never worse than the state this change replaces.
        $monitor = $this->makeMonitor(AiMode::Auto);
        $incident = $this->openedAnomalyIncident($monitor, threshold: null);
        $this->seedQuietWindow($monitor, responseMs: 200);

        $this->runSweep();

        $this->assertSame(IncidentStatus::Detected, $incident->refresh()->lifecycle);
    }

    public function test_a_non_ai_incident_is_never_touched_by_this_lane(): void
    {
        // The down lane and the metric lane own their own incidents, and
        // ThresholdEvaluator scopes itself off ai_owned rows for the mirror of
        // this reason. Closing one from here would resolve an outage on the
        // strength of a response-time number.
        $monitor = $this->makeMonitor(AiMode::Auto);
        $incident = $this->openedAnomalyIncident($monitor, threshold: 900.0);
        $incident->update(['ai_owned' => false]);
        $this->seedQuietWindow($monitor, responseMs: 200);

        $this->runSweep();

        $this->assertSame(IncidentStatus::Detected, $incident->refresh()->lifecycle);
    }

    public function test_auto_monitor_the_model_did_not_confirm_does_not_auto_open(): void
    {
        // The confidence bar alone is not enough: this stub is as confident as
        // the auto-open path ever requires, and says the evidence is not a real
        // deviation. Acting on that without a human is what this guard stops.
        $this->app->instance(AnomalyTriageGateway::class, new UnconfirmedTriageGateway);
        $monitor = $this->makeMonitor(AiMode::Auto);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        $this->assertSame(0, Incident::query()->count());

        // Not suppressed: the anomaly still reaches the operator's inbox, and it
        // carries the verdict that kept it there.
        $suggestion = AiSuggestion::query()->sole();
        $this->assertSame(AiSuggestionStatus::Pending, $suggestion->status);
        $this->assertSame('llm', $suggestion->source);
        $this->assertFalse($suggestion->confirmed);
        $this->assertNull($suggestion->accepted_incident_id);
    }

    public function test_an_auto_opened_suggestion_records_the_confirming_verdict(): void
    {
        $this->app->instance(AnomalyTriageGateway::class, new HighConfidenceTriageGateway);
        $monitor = $this->makeMonitor(AiMode::Auto);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        $this->assertTrue(AiSuggestion::query()->sole()->confirmed);
    }

    public function test_a_statistical_degrade_records_no_verdict(): void
    {
        // Over budget: no model ran, so there is no verdict to record and the
        // column must stay null rather than reading as a denial.
        config(['ai.budget.daily_per_team' => 0]);
        $this->app->instance(AnomalyTriageGateway::class, new HighConfidenceTriageGateway);
        $monitor = $this->makeMonitor(AiMode::Auto);
        $this->seedAnomalousWindow($monitor);

        $this->runSweep();

        $suggestion = AiSuggestion::query()->sole();
        $this->assertSame('statistical', $suggestion->source);
        $this->assertNull($suggestion->confirmed);
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
     * Put the monitor in the state the autonomous lane leaves behind: one open
     * `ai_owned` incident, plus the accepted suggestion that opened it carrying
     * the level the anomaly was raised against.
     *
     * A null [$threshold] models evidence with nothing numeric to recover
     * against, which is the fail-closed branch.
     */
    protected function openedAnomalyIncident(Monitor $monitor, ?float $threshold): Incident
    {
        $incident = Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Anomaly detected on '.$monitor->name,
            'impact' => IncidentImpact::Major,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::AiAnomaly,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => true,
            'trigger_metric_key' => 'response_time',
            'started_at' => now()->subHours(3),
        ]);

        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => MonitorStatus::Up->value,
            'component_status_current' => MonitorStatus::Up->value,
        ]);

        AiSuggestion::query()->create([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'kind' => AiSuggestionKind::ResponseTimeAnomaly,
            'signal' => 'response_time',
            'method' => 'ewma',
            'score' => 4.2,
            'severity' => 'warn',
            'confidence' => AiConfidence::High,
            'source' => 'llm',
            'recommendation' => 'Response time drifted well past its control limit and held there.',
            'evidence' => $threshold === null
                ? ['unit' => 'ms', 'observed' => 2500]
                : ['unit' => 'ms', 'observed' => 2500, 'baseline' => 200, 'threshold' => $threshold],
            'dedupe_key' => 'opened:'.Str::uuid(),
            'status' => AiSuggestionStatus::Accepted,
            'accepted_incident_id' => $incident->id,
            'expires_at' => now()->addDays(7),
        ]);

        return $incident;
    }

    /**
     * Seed a window the detector finds nothing in: a flat series has no spike
     * (MAD scale is zero) and no drift (sigma is zero), so both branches guard
     * off and the sweep reaches its resolve arm.
     */
    protected function seedQuietWindow(Monitor $monitor, int $responseMs): void
    {
        $start = now()->subMinutes(120);

        for ($i = 0; $i < 120; $i++) {
            $this->makeCheck($monitor, $start->copy()->addMinutes($i), $responseMs);
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
     * Give the monitor's team a default escalation policy with one on-call step,
     * so the shared dispatcher's escalation walk queues a real step job.
     */
    protected function seedDefaultPolicy(Monitor $monitor): void
    {
        $policy = EscalationPolicy::query()->create([
            'team_id' => $monitor->team_id,
            'name' => 'Primary On-Call Policy',
        ]);

        EscalationStep::query()->create([
            'escalation_policy_id' => $policy->id,
            'position' => 0,
            'delay_minutes' => 0,
            'target_type' => EscalationTargetType::OnCall,
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

/**
 * A gateway that is confidently NEGATIVE: it clears the confidence bar while
 * reading the evidence as no real deviation, which is the shape production
 * produced on 2026-08-15 and the only one that isolates the confirmed guard
 * from the confidence guard beside it.
 */
class UnconfirmedTriageGateway implements AnomalyTriageGateway
{
    public function triage(TriagePayload $payload): TriageResult
    {
        return new TriageResult(
            confirmed: false,
            severity: 'info',
            confidence: AiConfidence::High,
            recommendation: 'The latest reading sits below its baseline; the earlier spike has passed.',
            strippedCitations: [],
        );
    }
}
