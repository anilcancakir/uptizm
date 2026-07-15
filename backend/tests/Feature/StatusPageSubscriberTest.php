<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\StatusPageController;
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
 * Covers the team-scoped admin subscriber surface on
 * {@see StatusPageController}: listing a page's
 * subscribers, direct-adding a confirmed subscriber (no double opt-in / no
 * confirmation mail), removing one, and the 404-mask on cross-team access.
 * Routes are the real `api/v1/status-pages/{statusPage}/subscribers` surface.
 */
class StatusPageSubscriberTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_the_pages_subscribers_in_the_pinned_shape(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $this->makeSubscriber($page, 'alice@example.com', confirmed: true);
        $this->makeSubscriber($page, 'bob@example.com', confirmed: false);

        $response = $this->getJson("/api/v1/status-pages/{$page->id}/subscribers");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'email',
                    'subscribed_at',
                    'confirmed',
                    'newsletter_opt_in',
                ],
            ],
        ]);

        $emails = array_column($response->json('data'), 'email');
        $this->assertContains('alice@example.com', $emails);
        $this->assertContains('bob@example.com', $emails);
    }

    public function test_index_never_exposes_the_opaque_tokens(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $this->makeSubscriber($page, 'alice@example.com', confirmed: true);

        $response = $this->getJson("/api/v1/status-pages/{$page->id}/subscribers");

        $response->assertStatus(200);
        $response->assertJsonMissing(['confirmed_token' => null]);
        $this->assertStringNotContainsString('confirmed_token', $response->getContent());
        $this->assertStringNotContainsString('unsubscribe_token', $response->getContent());
    }

    public function test_store_direct_adds_a_confirmed_subscriber_without_mail(): void
    {
        Mail::fake();
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
            'email' => 'new@example.com',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.email', 'new@example.com');
        $response->assertJsonPath('data.confirmed', true);

        $this->assertDatabaseHas('status_page_subscribers', [
            'status_page_id' => $page->id,
            'email' => 'new@example.com',
        ]);

        $subscriber = StatusPageSubscriber::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertNotNull($subscriber->confirmed_at);
        $this->assertNotNull($subscriber->unsubscribe_token);

        Mail::assertNothingSent();
    }

    public function test_store_dedupes_an_existing_subscriber(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $this->makeSubscriber($page, 'existing@example.com', confirmed: false);

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.email', 'existing@example.com');
        $this->assertSame(1, $page->subscribers()->where('email', 'existing@example.com')->count());
    }

    public function test_store_rejects_an_invalid_email(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_destroy_removes_a_subscriber(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $subscriber = $this->makeSubscriber($page, 'gone@example.com', confirmed: true);

        $response = $this->deleteJson("/api/v1/status-pages/{$page->id}/subscribers/{$subscriber->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('status_page_subscribers', [
            'id' => $subscriber->id,
        ]);
    }

    public function test_destroy_rejects_a_subscriber_from_another_page(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $otherPage = $this->makeStatusPage($team->id, 'other');
        $subscriber = $this->makeSubscriber($otherPage, 'elsewhere@example.com', confirmed: true);

        $response = $this->deleteJson("/api/v1/status-pages/{$page->id}/subscribers/{$subscriber->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('status_page_subscribers', [
            'id' => $subscriber->id,
        ]);
    }

    public function test_index_masks_a_cross_team_page_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignTeam = $this->makeForeignTeam();
        $foreignPage = $this->makeStatusPage($foreignTeam->id, 'theirs');

        $response = $this->getJson("/api/v1/status-pages/{$foreignPage->id}/subscribers");

        $response->assertStatus(404);
    }

    public function test_store_masks_a_cross_team_page_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignTeam = $this->makeForeignTeam();
        $foreignPage = $this->makeStatusPage($foreignTeam->id, 'theirs');

        $response = $this->postJson("/api/v1/status-pages/{$foreignPage->id}/subscribers", [
            'email' => 'intruder@example.com',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('status_page_subscribers', [
            'email' => 'intruder@example.com',
        ]);
    }

    public function test_destroy_masks_a_cross_team_page_as_404(): void
    {
        $this->actingAsTeamMember();
        $foreignTeam = $this->makeForeignTeam();
        $foreignPage = $this->makeStatusPage($foreignTeam->id, 'theirs');
        $subscriber = $this->makeSubscriber($foreignPage, 'foreign@example.com', confirmed: true);

        $response = $this->deleteJson("/api/v1/status-pages/{$foreignPage->id}/subscribers/{$subscriber->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('status_page_subscribers', [
            'id' => $subscriber->id,
        ]);
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    /**
     * Builds a persisted foreign team, owned by a fresh user, unrelated to
     * the acting user.
     */
    protected function makeForeignTeam(): Team
    {
        return Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign Team',
            'personal_team' => true,
        ]);
    }

    /**
     * Builds a persisted status page for the given team.
     */
    protected function makeStatusPage(string $teamId, string $slug): StatusPage
    {
        return StatusPage::create([
            'team_id' => $teamId,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'is_public' => true,
        ]);
    }

    /**
     * Builds a persisted subscriber for the given page.
     */
    protected function makeSubscriber(StatusPage $page, string $email, bool $confirmed): StatusPageSubscriber
    {
        return $page->subscribers()->create([
            'email' => $email,
            'confirmed_token' => $confirmed ? null : Str::random(48),
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now(),
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }
}
