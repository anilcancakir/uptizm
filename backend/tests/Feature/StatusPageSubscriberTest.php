<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\StatusPageController;
use App\Mail\StatusPageSubscribeConfirmation;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the team-scoped admin subscriber surface on
 * {@see StatusPageController}: listing a page's
 * subscribers, adding one through double opt-in (an UNCONFIRMED row plus one
 * queued confirmation mail), removing one, and the 404-mask on cross-team
 * access. Routes are the real `api/v1/status-pages/{statusPage}/subscribers`
 * surface.
 *
 * The consent contract is pinned here rather than only on the public flow: an
 * operator add proves nothing about the address it was typed for, so it may
 * write neither `confirmed_at` nor the `opt_in_confirmed_at` provenance column
 * the announcement path selects on. Only the public confirm link sets those,
 * and that is asserted here too so the two halves of the contract cannot drift
 * apart in separate files.
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

    public function test_store_mints_an_unconfirmed_subscriber_and_queues_one_confirmation(): void
    {
        Mail::fake();
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
            'email' => 'new@example.com',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.email', 'new@example.com');
        $response->assertJsonPath('data.confirmed', false);

        $this->assertDatabaseHas('status_page_subscribers', [
            'status_page_id' => $page->id,
            'email' => 'new@example.com',
        ]);

        $subscriber = StatusPageSubscriber::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertNull($subscriber->confirmed_at);
        $this->assertNull($subscriber->opt_in_confirmed_at);
        $this->assertNotNull($subscriber->confirmed_token);
        $this->assertNotNull($subscriber->unsubscribe_token);

        // Queued, not sent: an inline third-party HTTP call would block the
        // Octane worker that is still answering this request.
        Mail::assertNothingSent();
        Mail::assertQueued(StatusPageSubscribeConfirmation::class, 1);
        Mail::assertQueued(
            StatusPageSubscribeConfirmation::class,
            fn (StatusPageSubscribeConfirmation $mail): bool => $mail->hasTo('new@example.com'),
        );
    }

    public function test_store_dedupes_an_existing_subscriber_without_a_second_mail(): void
    {
        Mail::fake();
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $this->makeSubscriber($page, 'existing@example.com', confirmed: false);

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.email', 'existing@example.com');
        $this->assertSame(1, $page->subscribers()->where('email', 'existing@example.com')->count());

        // Dedupe is also a mail bound: re-adding an address must not be a way to
        // re-send confirmation mail to it.
        Mail::assertNothingQueued();
    }

    public function test_store_at_the_plan_cap_queues_no_confirmation(): void
    {
        Mail::fake();
        // Shrink the Free cap so the test seeds one subscriber, not 100.
        config()->set('plans.tiers', collect(config('plans.tiers'))->map(function (array $tier): array {
            if ($tier['id'] === 'free') {
                $tier['limits']['subscribers'] = 1;
            }

            return $tier;
        })->all());

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $this->makeSubscriber($page, 'first@example.com', confirmed: false);

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
            'email' => 'second@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_confirming_the_public_link_sets_both_confirmed_at_and_the_provenance_column(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $subscriber = $this->makeSubscriber($page, 'clicker@example.com', confirmed: false);

        $this->get("/s/mine/subscribe/confirm/{$subscriber->confirmed_token}")->assertOk();

        $subscriber->refresh();
        $this->assertNotNull($subscriber->confirmed_at);
        $this->assertNotNull($subscriber->opt_in_confirmed_at);
        $this->assertNull($subscriber->confirmed_token);
    }

    public function test_the_provenance_column_is_null_for_every_row_no_confirm_click_created(): void
    {
        $this->assertTrue(Schema::hasColumn('status_page_subscribers', 'opt_in_confirmed_at'));

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        // Both pre-change populations: an operator add (confirmed_at set, no
        // token) and a completed public opt-in (confirmed_at set, token burned)
        // are byte-identical on the old columns, and neither carries provenance.
        // The migration adds the column as NULL and never backfills, so nothing
        // that predates the confirm-path write can ever read as consented.
        $legacyAdminAdd = $this->makeSubscriber($page, 'legacy-admin@example.com', confirmed: true);
        $legacyConfirmed = $this->makeSubscriber($page, 'legacy-public@example.com', confirmed: true);

        $this->assertNull($legacyAdminAdd->opt_in_confirmed_at);
        $this->assertNull($legacyConfirmed->opt_in_confirmed_at);
        $this->assertSame(
            0,
            StatusPageSubscriber::query()->whereNotNull('opt_in_confirmed_at')->count(),
        );
    }

    public function test_the_add_route_carries_a_throttle_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.status-pages.subscribers.store');

        $this->assertNotNull($route);
        $this->assertNotEmpty(array_filter(
            $route->gatherMiddleware(),
            fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:'),
        ));
    }

    public function test_the_add_route_is_rate_limited_and_a_refused_add_mails_nobody(): void
    {
        Mail::fake();
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        // The named limiter is the only bound on the RATE here: `api/v1` never
        // calls throttleApi(), and the plan cap bounds the total a page may hold
        // (100 on Free), not how fast mail leaves for it. Distinct addresses, so
        // the dedupe branch is not what answers.
        $accepted = 0;
        $refused = 0;
        for ($i = 0; $i < 12; $i++) {
            $status = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
                'email' => "burst{$i}@example.com",
            ])->getStatusCode();

            $status === 429 ? $refused++ : $accepted++;
        }

        $this->assertGreaterThan(0, $refused);
        // Not just "a 429 appeared": a throttled request must cost no mail, which
        // is the property the limiter exists for.
        Mail::assertQueued(StatusPageSubscribeConfirmation::class, $accepted);
    }

    public function test_an_array_email_payload_is_a_422_and_never_a_500(): void
    {
        Mail::fake();
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        // `email[]=x` reaching a limiter key through a cast was a free 500 plus a
        // stack trace on the public write paths once. The named limiter on this
        // route must never touch the submitted value.
        $response = $this->postJson("/api/v1/status-pages/{$page->id}/subscribers", [
            'email' => ['x@example.com'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        Mail::assertNothingQueued();
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
