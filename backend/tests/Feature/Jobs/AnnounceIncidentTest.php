<?php

namespace Tests\Feature\Jobs;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Jobs\AnnounceIncident;
use App\Mail\IncidentAnnounced;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\ThresholdEvaluator;
use App\Services\StatusPages\StatusPageAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The incident announcement is the product's SECOND outbound mail to third
 * parties, so every guard the maintenance announcement carries is re-asserted
 * here rather than assumed to have been inherited.
 *
 * The form has offered a "Notify subscribers" switch since the incident screen
 * was written, with a hint promising to "email everyone subscribed to the
 * affected components". Nothing kept that promise in either position: no
 * incident had ever reached a subscriber, so the switch was inert and the hint
 * was a claim the product could not make.
 *
 * @see AnnounceIncident
 */
class AnnounceIncidentTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Consent
    // -------------------------------------------------------------------------

    public function test_only_a_proven_public_opt_in_is_mailed(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);

        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        // Active and tokenless exactly like a completed opt-in, which is why
        // selecting on `confirmed_at` would mail an address this system never
        // saw consent for, from the product's own sending domain.
        $this->makePreChangeConfirmedSubscriber($page, 'pasted-by-an-operator@example.com');
        $this->makePendingSubscriber($page, 'never-clicked@example.com');

        $this->openIncident($monitor);

        Mail::assertQueued(IncidentAnnounced::class, 1);
        Mail::assertQueued(
            IncidentAnnounced::class,
            fn (IncidentAnnounced $mail): bool => $mail->hasTo('opted-in@example.com'),
        );
        Mail::assertNotQueued(
            IncidentAnnounced::class,
            fn (IncidentAnnounced $mail): bool => $mail->hasTo('pasted-by-an-operator@example.com'),
        );
        Mail::assertNotQueued(
            IncidentAnnounced::class,
            fn (IncidentAnnounced $mail): bool => $mail->hasTo('never-clicked@example.com'),
        );
    }

    public function test_another_pages_subscribers_are_never_announced_to(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $other = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);

        $this->makeOptedInSubscriber($page, 'affected@example.com');
        // Subscribed to a page that does not carry this component at all.
        $this->makeOptedInSubscriber($other, 'unrelated@example.com');

        $this->openIncident($monitor);

        Mail::assertQueued(IncidentAnnounced::class, 1);
        Mail::assertNotQueued(
            IncidentAnnounced::class,
            fn (IncidentAnnounced $mail): bool => $mail->hasTo('unrelated@example.com'),
        );
    }

    public function test_a_component_the_page_hides_announces_nothing(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Internal queue');
        // Attached to the page but not published on it. Its readers were never
        // told this component exists, so an outage mail about it would be the
        // first they hear of it.
        $this->publishOnPage($page, $monitor, visible: false);

        $this->makeOptedInSubscriber($page, 'reader@example.com');

        $this->openIncident($monitor);

        Mail::assertNothingQueued();
    }

    // -------------------------------------------------------------------------
    // Announce once
    // -------------------------------------------------------------------------

    public function test_the_open_claims_the_announcement(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        $incident = $this->openIncident($monitor);

        $this->assertNotNull($incident->refresh()->subscribers_announced_at);
    }

    public function test_running_the_job_twice_mails_once(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        $incident = $this->openIncident($monitor);

        // Every way this job can run again resolves through the claim: a worker
        // retry, a re-dispatch, a duplicate queue delivery.
        (new AnnounceIncident($incident->refresh()))->handle(app(StatusPageAssembler::class));

        Mail::assertQueued(IncidentAnnounced::class, 1);
    }

    // -------------------------------------------------------------------------
    // What triggers it, and what must not
    // -------------------------------------------------------------------------

    public function test_notify_false_announces_nothing(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        $this->openIncident($monitor, notify: false);

        Mail::assertNothingQueued();
    }

    public function test_an_omitted_notify_still_announces(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        // A client older than the field must behave as its own UI promised,
        // which is with the switch on.
        $this->postJson('/api/v1/incidents', [
            'monitor_id' => $monitor->id,
            'severity' => IncidentSeverity::Critical->value,
            'title' => 'Checkout is failing',
        ])->assertStatus(201);

        Mail::assertQueued(IncidentAnnounced::class, 1);
    }

    public function test_an_automated_open_never_announces(): void
    {
        Mail::fake();

        $team = $this->makeTeam();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        // The automated path opens through the evaluator, which never carries
        // an operator's yes. A flapping monitor opens and resolves repeatedly,
        // and each of those would be mail nobody chose to send.
        app(ThresholdEvaluator::class)->createIncident(
            monitor: $monitor,
            source: SignalSource::UserThreshold,
            check: null,
            severity: IncidentSeverity::Critical,
            title: 'Checkout is down',
        );

        Mail::assertNothingQueued();
    }

    // -------------------------------------------------------------------------
    // Impact
    // -------------------------------------------------------------------------

    public function test_an_operator_impact_outranks_the_severity_projection(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Checkout');

        // Critical severity projects to Critical impact. Severity is what the
        // operator graded the failure; impact is what the customer is told, and
        // a critical failure of a component nobody depends on is not a critical
        // customer impact.
        $incident = $this->openIncident($monitor, impact: IncidentImpact::Minor);

        $this->assertSame(IncidentImpact::Minor, $incident->refresh()->impact);
    }

    public function test_an_omitted_impact_keeps_the_projection(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team, 'Checkout');

        $incident = $this->openIncident($monitor);

        $this->assertSame(IncidentImpact::Critical, $incident->refresh()->impact);
    }

    public function test_the_mail_shows_the_impact_and_never_the_severity(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor, label: 'Checkout API');
        $subscriber = $this->makeOptedInSubscriber($page, 'reader@example.com');

        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Checkout is failing',
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::Manual,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now(),
        ]);

        $rendered = (new IncidentAnnounced($page, $incident, $subscriber, ['Checkout API']))->render();

        $this->assertStringContainsString('Checkout is failing', $rendered);
        $this->assertStringContainsString(__('status.emails.incident.impact.minor'), $rendered);
        // The internal triage grade is not a statement to a customer.
        $this->assertStringNotContainsString(__('status.emails.incident.impact.critical'), $rendered);
        // The label the PAGE publishes, not the internal monitor name.
        $this->assertStringContainsString('Checkout API', $rendered);
        $this->assertStringNotContainsString('Checkout</li>', $rendered);
    }

    public function test_the_rendered_mail_carries_the_unsubscribe_url_from_config(): void
    {
        config(['app.url' => 'https://status.example.test']);

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $subscriber = $this->makeOptedInSubscriber($page, 'reader@example.com');

        $incident = $this->openIncident($monitor);

        $rendered = (new IncidentAnnounced($page, $incident, $subscriber, ['Checkout']))->render();

        // The link outlives the request inside somebody's inbox, so it is
        // composed from configuration rather than from the request host.
        $this->assertStringContainsString('https://status.example.test', $rendered);
        $this->assertStringContainsString($subscriber->unsubscribe_token, $rendered);
    }

    public function test_the_mail_is_never_sent_synchronously(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        $this->openIncident($monitor);

        // A synchronous fan-out would hold the worker open across one
        // third-party handshake per subscriber.
        Mail::assertNothingSent();
        Mail::assertQueued(IncidentAnnounced::class, 1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Open a manual incident through the real endpoint, which is what dispatches
     * the announcement.
     */
    protected function openIncident(
        Monitor $monitor,
        bool $notify = true,
        ?IncidentImpact $impact = null,
    ): Incident {
        $response = $this->postJson('/api/v1/incidents', [
            'monitor_id' => $monitor->id,
            'severity' => IncidentSeverity::Critical->value,
            'title' => 'Checkout is failing',
            'notify' => $notify,
            'impact' => $impact?->value,
        ]);

        $response->assertStatus(201);

        return Incident::query()->findOrFail($response->json('data.id'));
    }

    protected function publishOnPage(
        StatusPage $page,
        Monitor $monitor,
        ?string $label = null,
        bool $visible = true,
    ): void {
        $monitor->update(['show_on_status_page' => $visible]);

        $page->monitors()->attach($monitor->id, [
            'display_order' => 0,
            'custom_label' => $label,
        ]);
    }

    protected function actingAsTeamMember(): Team
    {
        $team = $this->makeTeam();
        Sanctum::actingAs($team->owner);

        return $team;
    }

    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Incident Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $team = Team::query()->create(['user_id' => $user->id, 'name' => 'Announce Team']);
        $team->users()->attach($user->id, ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $team;
    }

    protected function makeStatusPage(Team $team): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Public Status',
            'slug' => Str::uuid().'-status',
        ]);
    }

    protected function makeMonitor(Team $team, string $name): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => 'http',
            'url' => 'https://example.com/'.Str::uuid(),
            'check_interval_sec' => 60,
            'last_status' => MonitorStatus::Down->value,
        ]);
    }

    protected function makeOptedInSubscriber(StatusPage $page, string $email): StatusPageSubscriber
    {
        return StatusPageSubscriber::query()->create([
            'status_page_id' => $page->id,
            'email' => $email,
            'confirmed_token' => null,
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now()->subDay(),
            'confirmed_at' => now()->subDay(),
            'opt_in_confirmed_at' => now()->subDay(),
        ]);
    }

    /**
     * A row an operator pasted in before the add path required a click: active
     * (`confirmed_at`) and tokenless, exactly like a completed public opt-in,
     * which is precisely why it may not be selected on those two columns.
     */
    protected function makePreChangeConfirmedSubscriber(StatusPage $page, string $email): StatusPageSubscriber
    {
        return StatusPageSubscriber::query()->create([
            'status_page_id' => $page->id,
            'email' => $email,
            'confirmed_token' => null,
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now()->subMonth(),
            'confirmed_at' => now()->subMonth(),
            'opt_in_confirmed_at' => null,
        ]);
    }

    protected function makePendingSubscriber(StatusPage $page, string $email): StatusPageSubscriber
    {
        return StatusPageSubscriber::query()->create([
            'status_page_id' => $page->id,
            'email' => $email,
            'confirmed_token' => Str::random(48),
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now()->subDay(),
            'confirmed_at' => null,
            'opt_in_confirmed_at' => null,
        ]);
    }
}
