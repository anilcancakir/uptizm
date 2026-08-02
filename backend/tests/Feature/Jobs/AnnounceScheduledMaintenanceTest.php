<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AnnounceScheduledMaintenance;
use App\Mail\ScheduledMaintenanceAnnounced;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see AnnounceScheduledMaintenance}, the product's first outbound mail
 * to third parties.
 *
 * Three contracts are pinned here and none of them may be relaxed:
 *
 *  - CONSENT. A recipient is a subscriber holding `opt_in_confirmed_at`, the
 *    provenance column only the public confirm endpoint writes. A row carrying
 *    `confirmed_at` with no provenance is a pre-change operator add whose
 *    consent this system never observed, and it gets nothing.
 *  - ANNOUNCE ONCE. The window's `announced_at` claim is a conditional UPDATE,
 *    so a retried or re-dispatched job mails nobody a second time, and neither
 *    an edit nor a delete announces at all.
 *  - THE LINK OUTLIVES THE REQUEST. The unsubscribe URL is composed from
 *    `config('app.url')`, never from the host the create request arrived on.
 *
 * The suite runs on the `sync` queue connection, so the create request executes
 * the job inline and `Mail::fake()` observes exactly what a worker would queue.
 */
class AnnounceScheduledMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_window_queues_one_mail_per_opted_in_subscriber(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);

        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $this->makePreChangeConfirmedSubscriber($page, 'pasted-by-an-operator@example.com');
        $this->makePendingSubscriber($page, 'never-clicked@example.com');

        $response = $this->createWindow($page);

        $response->assertStatus(201);

        Mail::assertQueued(ScheduledMaintenanceAnnounced::class, 1);
        Mail::assertQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->hasTo('opted-in@example.com'),
        );
        Mail::assertNotQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->hasTo('pasted-by-an-operator@example.com'),
        );
        Mail::assertNotQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->hasTo('never-clicked@example.com'),
        );
    }

    public function test_creating_a_window_queues_one_mail_for_every_opted_in_subscriber(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);

        $this->makeOptedInSubscriber($page, 'first@example.com');
        $this->makeOptedInSubscriber($page, 'second@example.com');

        $this->createWindow($page)->assertStatus(201);

        Mail::assertQueued(ScheduledMaintenanceAnnounced::class, 2);
        Mail::assertQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->hasTo('second@example.com'),
        );
    }

    public function test_creating_a_window_never_announces_another_pages_subscribers(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $siblingPage = $this->makeStatusPage($team);

        $this->makeOptedInSubscriber($siblingPage, 'other-page@example.com');

        $this->createWindow($page)->assertStatus(201);

        Mail::assertNothingQueued();
    }

    public function test_creating_a_window_claims_the_announcement(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');

        $response = $this->createWindow($page);

        $window = ScheduledMaintenance::query()->findOrFail($response->json('data.id'));
        $this->assertNotNull($window->announced_at);
    }

    public function test_running_the_job_twice_queues_nothing_the_second_time(): void
    {
        Mail::fake();

        $team = $this->makeTeam();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $window = $this->makeWindow($team, $page);

        AnnounceScheduledMaintenance::dispatchSync($window);
        AnnounceScheduledMaintenance::dispatchSync($window->fresh());

        Mail::assertQueued(ScheduledMaintenanceAnnounced::class, 1);
    }

    public function test_an_already_announced_window_queues_nothing(): void
    {
        Mail::fake();

        $team = $this->makeTeam();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $window = $this->makeWindow($team, $page);
        $window->forceFill(['announced_at' => now()->subHour()])->save();

        AnnounceScheduledMaintenance::dispatchSync($window);

        Mail::assertNothingQueued();
    }

    public function test_updating_a_window_queues_nothing(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $window = $this->makeWindow($team, $page);

        Mail::fake();

        $response = $this->putJson("/api/v1/scheduled-maintenances/{$window->id}", [
            'title' => 'Moved window',
        ]);

        $response->assertStatus(200);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_deleting_a_window_queues_nothing(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $window = $this->makeWindow($team, $page);

        Mail::fake();

        $this->deleteJson("/api/v1/scheduled-maintenances/{$window->id}")->assertStatus(204);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_a_page_with_no_proven_opt_in_queues_nothing(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makePreChangeConfirmedSubscriber($page, 'pasted-by-an-operator@example.com');
        $this->makePendingSubscriber($page, 'never-clicked@example.com');

        $this->createWindow($page)->assertStatus(201);

        Mail::assertNothingQueued();
    }

    public function test_the_mail_is_never_sent_synchronously(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');

        $this->createWindow($page)->assertStatus(201);

        Mail::assertNothingSent();
    }

    public function test_the_rendered_mail_carries_the_unsubscribe_url_from_config(): void
    {
        config(['app.url' => 'https://status.uptizm.test']);

        $team = $this->makeTeam();
        $page = $this->makeStatusPage($team);
        $subscriber = $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $window = $this->makeWindow($team, $page);

        $mailable = new ScheduledMaintenanceAnnounced($page, $window, $subscriber, ['API']);

        $mailable->assertSeeInHtml(
            'https://status.uptizm.test/unsubscribe/'.$subscriber->unsubscribe_token,
        );
    }

    public function test_the_rendered_mail_carries_the_window_and_its_components(): void
    {
        $team = $this->makeTeam();
        $page = $this->makeStatusPage($team);
        $subscriber = $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $window = $this->makeWindow($team, $page);

        $mailable = new ScheduledMaintenanceAnnounced($page, $window, $subscriber, ['API', 'Web']);

        $mailable->assertSeeInHtml('Database upgrade');
        $mailable->assertSeeInHtml('Rolling PostgreSQL 17 upgrade.');
        $mailable->assertSeeInHtml('API');
        $mailable->assertSeeInHtml('Web');
        // The bounds are rendered in UTC and labelled, because neither the page
        // nor the subscriber carries a timezone this could honestly localise to.
        $mailable->assertSeeInHtml('1 Sep 2026, 22:00 UTC');
        $mailable->assertSeeInHtml('2 Sep 2026, 00:00 UTC');
    }

    public function test_the_rendered_mail_never_carries_the_windows_private_fields(): void
    {
        $team = $this->makeTeam();
        $page = $this->makeStatusPage($team);
        $subscriber = $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $window = $this->makeWindow($team, $page);
        $window->forceFill(['announced_at' => now()])->save();

        $mailable = new ScheduledMaintenanceAnnounced($page, $window->fresh(), $subscriber, []);

        // A model dump into the view is what this catches: the window's own
        // columns must not reach a stranger, only the four public fields do.
        // The ids are deliberately not asserted on: keys are UUID-optional in
        // this codebase, and an integer key would match unrelated digits in the
        // rendered dates.
        $mailable->assertDontSeeInHtml('suppress_alerts');
        $mailable->assertDontSeeInHtml('announced_at');
        $mailable->assertDontSeeInHtml('status_page_id');
    }

    public function test_the_rendered_mail_names_no_other_subscriber(): void
    {
        $team = $this->makeTeam();
        $page = $this->makeStatusPage($team);
        $subscriber = $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $this->makeOptedInSubscriber($page, 'somebody-else@example.com');
        $window = $this->makeWindow($team, $page);

        $mailable = new ScheduledMaintenanceAnnounced($page, $window, $subscriber, []);

        $mailable->assertDontSeeInHtml('somebody-else@example.com');
    }

    public function test_the_announcement_names_the_affected_components(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $monitor = $this->makeMonitor($team, 'Checkout API');
        $this->publishOnPage($page, $monitor);

        $this->announceWindowFor($page, $monitor);

        Mail::assertQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->componentNames === ['Checkout API'],
        );
    }

    /**
     * The mail publishes the PAGE'S label, not the internal monitor name.
     *
     * `custom_label` exists so a team can call a component "Checkout" in public
     * while the monitor stays "prod-checkout-api-eu" internally. The mail read
     * the window's own pivot and so published the internal name to every
     * subscriber, on every announcement for any renamed component.
     */
    public function test_the_announcement_uses_the_pages_custom_label_over_the_monitor_name(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $monitor = $this->makeMonitor($team, 'prod-checkout-api-eu');
        $this->publishOnPage($page, $monitor, 'Checkout');

        $this->announceWindowFor($page, $monitor);

        Mail::assertQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->componentNames === ['Checkout'],
        );
    }

    /**
     * A component the page HIDES is not named in the mail.
     *
     * `show_on_status_page = false` is the one control a team has for deciding
     * what the public may know a component even exists. A window may legitimately
     * attach such a monitor (suppression is the other half of the feature), so
     * the write is allowed and the READ is what has to be filtered: otherwise
     * strangers get "Affected components: payments-db-internal" from our own
     * sending domain, for a row the page deliberately withholds.
     */
    public function test_the_announcement_omits_a_component_the_page_does_not_show(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $shown = $this->makeMonitor($team, 'Checkout API');
        $hidden = $this->makeMonitor($team, 'payments-db-internal');
        $this->publishOnPage($page, $shown);
        $this->publishOnPage($page, $hidden, visible: false);

        $this->announceWindowFor($page, $shown, $hidden);

        Mail::assertQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->componentNames === ['Checkout API'],
        );
    }

    /**
     * A monitor never attached to the announcing page is not named either.
     *
     * Nothing validates that `monitor_ids` belong to the submitted
     * `status_page_id`, on purpose: a suppression-only window on an internal
     * monitor is legitimate and still has to name a page. So the mail carries
     * only what that page publishes.
     */
    public function test_the_announcement_omits_a_monitor_not_on_the_announcing_page(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'opted-in@example.com');
        $elsewhere = $this->makeMonitor($team, 'Checkout API');

        $this->announceWindowFor($page, $elsewhere);

        Mail::assertQueued(
            ScheduledMaintenanceAnnounced::class,
            fn (ScheduledMaintenanceAnnounced $mail): bool => $mail->componentNames === [],
        );
    }

    /**
     * Attach a monitor to a status page the way the admin endpoint does.
     *
     * `visible` drives `show_on_status_page`, which lives on the MONITOR rather
     * than on the pivot; `label` is the pivot's `custom_label`.
     */
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

    /**
     * Create a window on `$page` through the real endpoint, attaching the given
     * monitors, which dispatches the announcement.
     */
    protected function announceWindowFor(StatusPage $page, Monitor ...$monitors): void
    {
        $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $page->id,
            'title' => 'Database upgrade',
            'description' => 'Rolling PostgreSQL 17 upgrade.',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
            'monitor_ids' => array_map(static fn (Monitor $m): string => (string) $m->id, $monitors),
        ])->assertStatus(201);
    }

    /**
     * Create a window through the real endpoint, on the given page.
     */
    protected function createWindow(StatusPage $page): TestResponse
    {
        return $this->postJson('/api/v1/scheduled-maintenances', [
            'status_page_id' => $page->id,
            'title' => 'Database upgrade',
            'description' => 'Rolling PostgreSQL 17 upgrade.',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
        ]);
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $team = $this->makeTeam();
        $user = User::query()->findOrFail($team->user_id);
        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    /**
     * Build a persisted team owned by a fresh user.
     */
    protected function makeTeam(): Team
    {
        return Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
    }

    /**
     * Build a persisted status page for the given team.
     */
    protected function makeStatusPage(Team $team): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Acme Status',
            'slug' => Str::uuid().'-status',
        ]);
    }

    /**
     * Build a persisted monitor for the given team.
     */
    protected function makeMonitor(Team $team, string $name): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => 'http',
            'url' => 'https://example.com/'.Str::slug($name),
            'check_interval_sec' => 60,
        ]);
    }

    /**
     * Build a persisted, unannounced window on the given page.
     */
    protected function makeWindow(Team $team, StatusPage $page): ScheduledMaintenance
    {
        return ScheduledMaintenance::factory()->create([
            'team_id' => $team->id,
            'status_page_id' => $page->id,
            'title' => 'Database upgrade',
            'description' => 'Rolling PostgreSQL 17 upgrade.',
            'starts_at' => '2026-09-01T22:00:00Z',
            'ends_at' => '2026-09-02T00:00:00Z',
            'announced_at' => null,
        ]);
    }

    /**
     * A subscriber who clicked the confirm link: the only population an
     * announcement may reach.
     */
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

    /**
     * A subscriber who was mailed a confirm link and never clicked it.
     */
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
