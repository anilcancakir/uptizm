<?php

namespace Tests\Feature\Ai;

use App\Enums\AiMode;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Jobs\PublishAiIncidentUpdate;
use App\Jobs\TranslateStatusPageText;
use App\Models\AiIncidentAnalysis;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\FakeIncidentAnalysisGateway;
use App\Services\Ai\FakeIncidentDraftGateway;
use App\Services\Ai\IncidentAnalysisGateway;
use App\Services\Ai\IncidentAnalysisService;
use App\Services\Ai\IncidentDraftGateway;
use App\Services\Ai\IncidentDraftPayload;
use App\Services\Ai\IncidentDraftResult;
use App\Services\Ai\IncidentDraftService;
use App\Services\Ai\NonConformingAnalysisException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the one path where model output reaches a customer with no human in
 * between: a monitor on `ai_mode = auto` publishing its own status updates.
 *
 * Every test here is a gate rather than a feature, because the feature is one
 * `create()` call and the gates are what make it safe to ship.
 */
class AutonomousStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(IncidentAnalysisGateway::class, FakeIncidentAnalysisGateway::class);
        $this->app->bind(IncidentDraftGateway::class, FakeIncidentDraftGateway::class);
    }

    /**
     * The routing the timing chain rests on, asserted on the constructed job.
     *
     * `Tests\Unit\JobTimeoutFitsItsConnectionTest` reads the `CONNECTION`
     * constant and can prove the number is right; it cannot prove the
     * constructor passed it to `onConnection()`, and a constant nobody applies
     * puts this job back on the shared connection at `retry_after` 90 with a
     * 160-second timeout. That gap is exactly how it shipped, so it is closed
     * here, where the object exists.
     */
    public function test_the_job_rides_the_dedicated_analyze_connection_and_queue(): void
    {
        $job = new PublishAiIncidentUpdate('incident-id', 'investigating');

        $this->assertSame('redis-analyze', $job->connection);
        $this->assertSame('analyze', $job->queue);
        $this->assertSame(160, $job->timeout);
    }

    public function test_an_auto_monitor_publishes_its_own_update(): void
    {
        $incident = $this->makeIncident($this->makeMonitor(true));

        (new PublishAiIncidentUpdate((string) $incident->id, 'investigating'))
            ->handle(
                app(IncidentAnalysisService::class),
                app(IncidentDraftService::class),
            );

        $update = $incident->updates()->first();

        $this->assertNotNull($update, 'the whole point of auto is that nobody had to write it');
        $this->assertTrue((bool) $update->is_public, 'it goes on the status page');
        $this->assertTrue((bool) $update->autonomous);
        $this->assertSame('ai', $update->actor);
        $this->assertStringContainsString('investigating', $update->message);
    }

    public function test_an_autonomous_post_is_written_in_the_teams_language(): void
    {
        // No request exists here, so `SetApiLocale` has nothing to act on: this
        // is a queued job, and reading `app()->getLocale()` would hand it the
        // config default. The team is the nearest audience it has.
        $monitor = $this->makeMonitor(true);
        $monitor->team->owner->forceFill(['locale' => 'tr'])->save();
        $incident = $this->makeIncident($monitor);

        $job = new PublishAiIncidentUpdate((string) $incident->id, 'investigating');
        $locale = (fn (): string => $this->locale($incident->fresh()))->call($job);

        $this->assertSame('tr', $locale);
    }

    public function test_an_autonomous_post_is_queued_for_translation(): void
    {
        // Every operator-written update fans out through `IncidentWriteService`.
        // This job writes the row itself, so it bypassed that and an autonomous
        // post was the one update on the timeline a Turkish status page would
        // have shown in the team's language while every other entry got
        // translated.
        Queue::fake();

        $this->runJob($this->makeIncident($this->makeMonitor(true)));

        Queue::assertPushed(TranslateStatusPageText::class);
    }

    public function test_a_monitor_that_never_allowed_it_publishes_nothing(): void
    {
        // The default, and the only safe one for a flag whose true value writes
        // on a public page.
        $incident = $this->makeIncident($this->makeMonitor(false));

        $this->runJob($incident);

        $this->assertSame(0, $incident->updates()->count());
    }

    public function test_narration_does_not_require_autonomous_incident_creation(): void
    {
        // The correction this column exists for. Riding on `ai_mode = auto`
        // forced an operator who only wanted their outages narrated to also
        // accept autonomous incident creation, and withheld narration from the
        // most common incident there is: the one a threshold opened on a monitor
        // with no anomaly detection at all.
        $monitor = $this->makeMonitor(true);
        $monitor->forceFill(['ai_mode' => AiMode::Off])->save();
        $incident = $this->makeIncident($monitor);

        $this->runJob($incident);

        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_switching_it_off_stops_the_next_post(): void
    {
        // The flag is re-read at FIRE time, not trusted from dispatch time. The
        // two are minutes apart on a real queue, and this is consent: turning it
        // off has to stop the next post rather than the one after it.
        $monitor = $this->makeMonitor(true);
        $incident = $this->makeIncident($monitor);

        $monitor->forceFill(['ai_auto_updates' => false])->save();

        $this->runJob($incident);

        $this->assertSame(0, $incident->updates()->count());
    }

    public function test_a_degrade_posts_nothing_rather_than_a_template(): void
    {
        // The one place that differs from the app's own Draft button, which
        // falls back to a localized template because a person is about to read
        // and edit it. Here nobody is. Silence is a smaller failure than a
        // sentence the operator never approved and no model wrote.
        $this->app->bind(IncidentDraftGateway::class, fn () => new class implements IncidentDraftGateway
        {
            public function draft(IncidentDraftPayload $payload): IncidentDraftResult
            {
                throw new NonConformingAnalysisException('Untrusted.');
            }
        });

        $incident = $this->makeIncident($this->makeMonitor(true));

        $this->runJob($incident);

        $this->assertSame(0, $incident->updates()->count());
    }

    public function test_the_same_stage_is_never_posted_twice(): void
    {
        // A requeue, a reopen, or a second recovery in the same minute would
        // otherwise put two machine-written messages about one moment in front
        // of customers.
        $incident = $this->makeIncident($this->makeMonitor(true));

        $this->runJob($incident);
        $this->runJob($incident);

        $this->assertSame(1, $incident->updates()->count());
    }

    public function test_open_and_resolve_are_two_different_posts(): void
    {
        // The dedupe is per stage, because one incident legitimately gets an
        // opening update and a closing one.
        $incident = $this->makeIncident($this->makeMonitor(true));

        $this->runJob($incident, 'investigating');
        $this->runJob($incident, 'resolved');

        $this->assertSame(2, $incident->updates()->count());
    }

    public function test_the_analysis_is_stored_before_the_update_is_written(): void
    {
        // The order is the product decision: the cause is settled first and the
        // sentence is drafted from it, so the incident page and the status page
        // cannot disagree about the same outage.
        $incident = $this->makeIncident($this->makeMonitor(true));

        $this->runJob($incident);

        $this->assertSame(
            1,
            AiIncidentAnalysis::query()->where('incident_id', $incident->id)->count(),
        );
    }

    public function test_an_incident_customers_cannot_notice_publishes_nothing(): void
    {
        // The live case this closes: a metric read `degraded` out of a health
        // endpoint that was still answering HTTP 200 in 682ms, so the only
        // affected thing was a storage check inside the operator's own system.
        // The analysis said exactly that and was useful on the incident page.
        // The public post could not say it, because `updateRules()` forbids
        // naming an internal component, which left the model with the empty
        // truth: "We are currently investigating this issue." A status page
        // entry that tells a customer something is wrong, while nothing they
        // touch is wrong and no detail is allowed, is worse than silence.
        //
        // `impact` is the right axis and already exists for this: its own
        // docblock calls it the CUSTOMER-facing tier, distinct from the
        // operator's `severity`.
        $incident = $this->makeIncident($this->makeMonitor(true));
        $incident->forceFill(['impact' => IncidentImpact::Minor])->save();

        $this->runJob($incident);

        $this->assertSame(0, $incident->updates()->count());

        // And the analysis is still waiting, which is the half of this that is
        // easy to lose. Gating the whole job on impact would have been the
        // one-line version and it silently took the operator's pre-warmed
        // analysis with it: the incident page then pays 9 to 20 seconds of
        // loading on first open, for a monitor whose operator asked for MORE
        // automation, not less. The impact rule is about what customers are
        // told; it was never about what the operator gets to see.
        $this->assertSame(
            1,
            AiIncidentAnalysis::query()->where('incident_id', $incident->id)->count(),
        );
    }

    public function test_a_team_below_the_analysis_tier_publishes_nothing(): void
    {
        // The analysis tier, not the auto one: this is built on the analysis that
        // tier already grants. A team below it keeps its incidents and loses the
        // narration.
        $monitor = $this->makeMonitor(true, plan: 'free');
        $incident = $this->makeIncident($monitor);

        $this->runJob($incident);

        $this->assertSame(0, $incident->updates()->count());
    }

    protected function runJob(Incident $incident, string $stage = 'investigating'): void
    {
        (new PublishAiIncidentUpdate((string) $incident->id, $stage))->handle(
            app(IncidentAnalysisService::class),
            app(IncidentDraftService::class),
        );
    }

    protected function makeMonitor(bool $autoUpdates, string $plan = 'pro'): Monitor
    {
        $user = User::query()->create([
            'name' => 'Autonomy Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Autonomy Team',
            'plan' => $plan,
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
            'ai_auto_updates' => $autoUpdates,
        ]);
    }

    protected function makeIncident(Monitor $monitor): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now()->subMinutes(5),
        ]);
    }
}
