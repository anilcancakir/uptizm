<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AnnounceMaintenanceCancelled;
use App\Mail\ScheduledMaintenanceCancelled;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cancelling an announced window has to tell the people who were told about it.
 *
 * The announcement mail sits in their inbox naming a date, and deleting the
 * window used to say nothing, so that mail went on describing work that would
 * never happen. This is the third outbound mail to third parties, and it
 * carries the same consent and announce-once guards as the other two, for the
 * same reason: it leaves the product's own sending domain.
 *
 * @see AnnounceMaintenanceCancelled
 */
class AnnounceMaintenanceCancelledTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_an_announced_window_tells_its_subscribers(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor, label: 'Checkout API');
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        $window = $this->makeWindow($team, $page, $monitor, announced: true);

        $this->deleteJson("/api/v1/scheduled-maintenances/{$window->id}")
            ->assertStatus(204);

        Mail::assertQueued(ScheduledMaintenanceCancelled::class, 1);
        Mail::assertQueued(
            ScheduledMaintenanceCancelled::class,
            fn (ScheduledMaintenanceCancelled $mail): bool => $mail->hasTo('reader@example.com'),
        );
    }

    public function test_a_window_that_was_never_announced_cancels_quietly(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Checkout');
        $this->publishOnPage($page, $monitor);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        // Nobody was told this window existed, so a cancellation mail would be
        // the first they hear of either it or its cancellation.
        $window = $this->makeWindow($team, $page, $monitor, announced: false);

        $this->deleteJson("/api/v1/scheduled-maintenances/{$window->id}")
            ->assertStatus(204);

        Mail::assertNothingQueued();
    }

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
        // saw consent for.
        $this->makePreChangeConfirmedSubscriber($page, 'pasted-by-an-operator@example.com');
        $this->makePendingSubscriber($page, 'never-clicked@example.com');

        $window = $this->makeWindow($team, $page, $monitor, announced: true);

        $this->deleteJson("/api/v1/scheduled-maintenances/{$window->id}")
            ->assertStatus(204);

        Mail::assertQueued(ScheduledMaintenanceCancelled::class, 1);
        Mail::assertNotQueued(
            ScheduledMaintenanceCancelled::class,
            fn (ScheduledMaintenanceCancelled $mail): bool => $mail->hasTo('pasted-by-an-operator@example.com'),
        );
    }

    public function test_running_the_job_twice_mails_once(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        // Driven directly, because the point is the claim rather than the
        // endpoint: the row is deleted, so the guard cannot be a column and is
        // a cache key on the window's id instead.
        $job = new AnnounceMaintenanceCancelled(
            (string) Str::uuid(),
            (string) $page->id,
            'Database upgrade',
            ['Checkout API'],
        );

        $job->handle();
        $job->handle();

        Mail::assertQueued(ScheduledMaintenanceCancelled::class, 1);
    }

    public function test_the_mail_names_the_window_and_the_published_component(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $subscriber = $this->makeOptedInSubscriber($page, 'reader@example.com');

        $rendered = (new ScheduledMaintenanceCancelled(
            $page,
            'Database upgrade',
            $subscriber,
            ['Checkout API'],
        ))->render();

        $this->assertStringContainsString('Database upgrade', $rendered);
        // The label the PAGE publishes, not the internal monitor name.
        $this->assertStringContainsString('Checkout API', $rendered);
        $this->assertStringContainsString($subscriber->unsubscribe_token, $rendered);
    }

    public function test_a_component_the_page_hides_is_never_named(): void
    {
        Mail::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team);
        $monitor = $this->makeMonitor($team, 'Internal queue');
        // Attached to the page but not published on it: its readers were never
        // told this component exists.
        $this->publishOnPage($page, $monitor, visible: false);
        $this->makeOptedInSubscriber($page, 'reader@example.com');

        $window = $this->makeWindow($team, $page, $monitor, announced: true);

        $this->deleteJson("/api/v1/scheduled-maintenances/{$window->id}")
            ->assertStatus(204);

        Mail::assertQueued(
            ScheduledMaintenanceCancelled::class,
            fn (ScheduledMaintenanceCancelled $mail): bool => $mail->componentNames === [],
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function makeWindow(
        Team $team,
        StatusPage $page,
        Monitor $monitor,
        bool $announced = false,
    ): ScheduledMaintenance {
        $window = ScheduledMaintenance::query()->create([
            'team_id' => $team->id,
            'status_page_id' => $page->id,
            'title' => 'Database upgrade',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'announced_at' => $announced ? now() : null,
        ]);
        $window->monitors()->attach($monitor->id);

        return $window;
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
        $user = User::query()->create([
            'name' => 'Window Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $team = Team::query()->create(['user_id' => $user->id, 'name' => 'Cancel Team']);
        $team->users()->attach($user->id, ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $team->id])->save();
        Sanctum::actingAs($user);

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
     * and tokenless, exactly like a completed public opt-in.
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
