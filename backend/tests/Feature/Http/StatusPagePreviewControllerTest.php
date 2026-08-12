<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorType;
use App\Enums\StatusPagePreviewStatus;
use App\Http\Controllers\Api\V1\StatusPagePreviewImageController;
use App\Jobs\RenderStatusPagePreview;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusPagePreviewRenderer;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the preview-render trigger, the signed image route
 * ({@see StatusPagePreviewImageController}), the three preview fields the
 * status-page resource emits, and the automatic dispatch every
 * layout-changing write makes.
 *
 * The two tests that matter most are the URL-stability pair. The signed URL is
 * handed to a Flutter `Image.network()`, whose cache is keyed on the URL string,
 * so a URL that churns per read reloads the pane on every poll tick while a URL
 * that never changes serves the PREVIOUS render's bytes from cache (the storage
 * path is overwritten in place). The URL must therefore be byte-identical
 * between renders and different after one.
 *
 * There is deliberately NO test claiming a valid signature for another team's
 * page 404s. The signature covers the URL it was minted for, so changing the
 * page id invalidates it, and only the server can mint a valid one. The
 * signature is the sole authorisation on that route, which is why the trigger
 * endpoint's own cross-team mask is asserted here instead.
 *
 * The write-path group at the bottom carries one assertion that is easy to
 * mistake for an afterthought: `store` must enqueue NOTHING. A freshly created
 * page has no components, so its artefact would be an empty page presented as a
 * customer view. That negative is paired with the same request shape once a
 * component exists, so it can never pass merely because the queue, the route or
 * the actor was misconfigured.
 */
class StatusPagePreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Anchor inside a signing bucket, so a bucket boundary can never fall
     * between two reads and make the stability assertions flaky.
     */
    protected const ANCHOR = '2026-07-29 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        // The render double writes a real PNG; keep it out of the repo's own
        // storage directory.
        Storage::fake(StatusPage::PREVIEW_DISK);
    }

    public function test_preview_trigger_enqueues_a_render_for_the_current_teams_page(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->postJson("/api/v1/status-pages/{$page->id}/preview");

        $response->assertStatus(202);
        Queue::assertPushed(
            RenderStatusPagePreview::class,
            static fn (RenderStatusPagePreview $job): bool => $job->statusPage->is($page),
        );
    }

    public function test_preview_trigger_masks_a_cross_team_page_as_404_and_enqueues_nothing(): void
    {
        Queue::fake();

        $this->actingAsTeamMember();
        $foreignTeam = $this->makeForeignTeam();
        $foreignPage = $this->makeStatusPage($foreignTeam->id, 'theirs');

        $response = $this->postJson("/api/v1/status-pages/{$foreignPage->id}/preview");

        $response->assertStatus(404);
        Queue::assertNothingPushed();
    }

    public function test_preview_trigger_is_rate_limited(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        // The named limiter is the ONLY bound on this endpoint: `api/v1` never
        // calls throttleApi(), and the render job's uniqueness lock releases as
        // soon as processing starts, so it caps nothing.
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->postJson("/api/v1/status-pages/{$page->id}/preview")->getStatusCode();
        }

        $this->assertContains(429, $statuses);
    }

    public function test_the_preview_render_limiter_buckets_per_actor_and_not_per_address(): void
    {
        Queue::fake();

        [$firstTeam, $firstToken] = $this->makeTokenHolder('First Ops');
        $firstPage = $this->makeStatusPage($firstTeam->id, 'first');
        [$secondTeam, $secondToken] = $this->makeTokenHolder('Second Ops');
        $secondPage = $this->makeStatusPage($secondTeam->id, 'second');

        // 1. Spend the first operator's whole budget.
        $exhausted = null;
        for ($i = 0; $i < 12; $i++) {
            $exhausted = $this->withToken($firstToken)
                ->postJson("/api/v1/status-pages/{$firstPage->id}/preview");
        }
        $exhausted?->assertStatus(429);

        // 2. A different operator, same source address, own page: still served.
        //    This is what proves the actor key resolves to a user rather than
        //    silently falling back to the address, which would make one office
        //    NAT share a single render budget. Both callers authenticate with a
        //    REAL bearer token rather than through Sanctum::actingAs, since
        //    actingAs sets the default guard eagerly and would prove nothing
        //    about a token request.
        //
        //    forgetGuards() is required and is a harness detail, not a
        //    production one: a test reuses ONE application instance across
        //    requests, and the resolved guard user is memoized on it, so without
        //    this the second token is never looked up and the first user answers
        //    for it. Every production request starts from a fresh instance.
        $this->app['auth']->forgetGuards();

        $this->withToken($secondToken)
            ->postJson("/api/v1/status-pages/{$secondPage->id}/preview")
            ->assertStatus(202);
    }

    public function test_show_emits_the_same_signed_image_url_across_two_reads_without_a_new_render(): void
    {
        $page = $this->renderedPage();

        $first = $this->readPreviewImageUrl($page);

        // Five minutes later, still inside the same signing bucket and with no
        // new render: an expiry computed from the raw clock would churn here,
        // and every churn is a cache miss on the client.
        $this->travelTo(Carbon::parse(self::ANCHOR)->addMinutes(5));

        $second = $this->readPreviewImageUrl($page);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    public function test_show_emits_a_different_signed_image_url_after_a_new_render(): void
    {
        $page = $this->renderedPage();

        $before = $this->readPreviewImageUrl($page);

        // Re-render inside the SAME signing bucket, so only the render version
        // can account for the difference.
        $this->travelTo(Carbon::parse(self::ANCHOR)->addMinutes(5));
        $this->postJson("/api/v1/status-pages/{$page->id}/preview")->assertStatus(202);

        $after = $this->readPreviewImageUrl($page);

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
        $this->assertSame(
            $this->queryParameter($before, 'expires'),
            $this->queryParameter($after, 'expires'),
            'The expiry is bucketed, so it must not be what changed.',
        );
        $this->assertNotSame(
            $this->queryParameter($before, 'v'),
            $this->queryParameter($after, 'v'),
            'The render version is what changes, so a re-render cannot be served from cache.',
        );
    }

    public function test_index_omits_the_signed_image_url(): void
    {
        $page = $this->renderedPage();

        $response = $this->getJson('/api/v1/status-pages');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $page->id);
        // The capability must not be multiplied across list responses and the
        // log payloads they end up in.
        $response->assertJsonMissingPath('data.0.preview_image_url');
        // The render state itself is not a capability, so the list may carry it.
        $response->assertJsonPath('data.0.preview_render_status', StatusPagePreviewStatus::Completed->value);
    }

    public function test_neither_index_nor_show_leaks_the_preview_token(): void
    {
        $page = $this->renderedPage();

        $this->getJson('/api/v1/status-pages')->assertJsonMissingPath('data.0.preview_token');
        $this->getJson("/api/v1/status-pages/{$page->id}")->assertJsonMissingPath('data.preview_token');
    }

    public function test_show_reports_the_render_state_and_timestamp(): void
    {
        $page = $this->renderedPage();

        $response = $this->getJson("/api/v1/status-pages/{$page->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.preview_render_status', StatusPagePreviewStatus::Completed->value);
        $response->assertJsonPath(
            'data.preview_rendered_at',
            $page->fresh()->preview_rendered_at?->toIso8601String(),
        );
    }

    public function test_a_page_that_never_rendered_carries_a_null_image_url(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->getJson("/api/v1/status-pages/{$page->id}");

        $response->assertStatus(200);
        // The key is PRESENT and null, not absent: `show` always states the
        // render's state, and an absent key would be indistinguishable from the
        // deliberate omission on `index`.
        $this->assertArrayHasKey('preview_image_url', $response->json('data'));
        $response->assertJsonPath('data.preview_image_url', null);
        $response->assertJsonPath('data.preview_rendered_at', null);
        $response->assertJsonPath('data.preview_render_status', null);
    }

    public function test_the_signed_image_url_serves_the_stored_png(): void
    {
        $page = $this->renderedPage();
        $url = $this->readPreviewImageUrl($page);

        // No bearer token: Image.network() cannot send one, which is the whole
        // reason the signature is the authorisation.
        $response = $this->get($url);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame(
            Storage::disk(StatusPage::PREVIEW_DISK)->get($page->fresh()->preview_image_path),
            $response->streamedContent(),
        );
    }

    public function test_the_image_route_rejects_an_unsigned_url(): void
    {
        $page = $this->renderedPage();

        $response = $this->get("/api/v1/status-pages/{$page->id}/preview-image");

        $response->assertStatus(403);
    }

    public function test_the_image_route_rejects_a_tampered_render_version(): void
    {
        $page = $this->renderedPage();
        $url = $this->readPreviewImageUrl($page);

        $tampered = preg_replace('/([?&]v=)\d+/', '${1}1', (string) $url);

        $this->assertNotSame($url, $tampered);
        $this->get((string) $tampered)->assertStatus(403);
    }

    public function test_the_image_route_rejects_an_expired_url(): void
    {
        $page = $this->renderedPage();
        $url = $this->readPreviewImageUrl($page);

        // Past the ceiling of the bucketed window.
        $this->travelTo(Carbon::parse(self::ANCHOR)->addMinutes(31));

        $this->get((string) $url)->assertStatus(403);
    }

    public function test_the_image_route_404s_when_the_stored_file_is_missing(): void
    {
        $page = $this->renderedPage();
        $url = $this->readPreviewImageUrl($page);

        // Reachable: a `completed` row whose file was removed out of band. It
        // must not surface as a 500.
        Storage::disk(StatusPage::PREVIEW_DISK)->delete($page->fresh()->preview_image_path);

        $this->get((string) $url)->assertStatus(404);
    }

    public function test_the_image_response_carries_the_cors_header_for_a_cross_origin_request(): void
    {
        $page = $this->renderedPage();
        $url = $this->readPreviewImageUrl($page);

        // Flutter web fetches image bytes through `fetch`, so the route must be
        // under `api/` where config/cors.php applies. This is the assertion that
        // catches a move into routes/status.php.
        $response = $this->get((string) $url, ['Origin' => 'http://localhost:5000']);

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_updating_a_page_enqueues_a_render(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->pageWithOneComponent($team->id);

        $this->putJson("/api/v1/status-pages/{$page->id}", ['name' => 'Renamed Status'])
            ->assertStatus(200);

        $this->assertRenderQueuedFor($page);
    }

    public function test_attaching_a_monitor_enqueues_a_render(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $monitor = $this->makeMonitor($team->id);

        $this->postJson("/api/v1/status-pages/{$page->id}/monitors", ['monitor_id' => $monitor->id])
            ->assertStatus(200);

        $this->assertRenderQueuedFor($page);
    }

    public function test_detaching_a_monitor_enqueues_a_render(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->pageWithOneComponent($team->id);
        $monitorId = $page->monitors()->pluck('monitors.id')->first();

        $this->deleteJson("/api/v1/status-pages/{$page->id}/monitors/{$monitorId}")
            ->assertStatus(204);

        $this->assertRenderQueuedFor($page);
    }

    public function test_reordering_monitors_enqueues_a_render(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');
        $first = $this->makeMonitor($team->id);
        $second = $this->makeMonitor($team->id);
        $page->monitors()->attach([
            $first->id => ['display_order' => 0],
            $second->id => ['display_order' => 1],
        ]);

        // A reorder writes no column the API returns, so it is the write most
        // easily mistaken for one that needs no render. It changes the ORDER of
        // the components in the image, which is exactly what the artefact
        // claims to show.
        $this->patchJson("/api/v1/status-pages/{$page->id}/monitors/reorder", [
            'order' => [
                ['id' => $first->id, 'display_order' => 1],
                ['id' => $second->id, 'display_order' => 0],
            ],
        ])->assertStatus(204);

        $this->assertRenderQueuedFor($page);
    }

    public function test_creating_a_page_enqueues_nothing_until_it_has_a_component(): void
    {
        Queue::fake();

        $team = $this->actingAsTeamMember();

        // 1. A create has nothing worth rendering: no components, so the
        //    artefact would be an empty page carrying a customer-view label.
        $created = $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);

        $created->assertStatus(201);
        Queue::assertNotPushed(RenderStatusPagePreview::class);

        // 2. The same actor and the same page, one component later, DOES render.
        //    Without this half the assertion above would also pass with a broken
        //    route, an unauthenticated actor or a queue that records nothing,
        //    which is the whole failure mode a negative assertion invites.
        $page = StatusPage::findOrFail($created->json('data.id'));
        $monitor = $this->makeMonitor($team->id);

        $this->postJson("/api/v1/status-pages/{$page->id}/monitors", ['monitor_id' => $monitor->id])
            ->assertStatus(200);

        $this->assertRenderQueuedFor($page);
    }

    public function test_a_write_dispatches_the_render_after_the_public_cache_is_invalidated(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->pageWithOneComponent($team->id);
        Cache::put('status-page:mine:en', ['stale' => true], 60);

        // `phpunit.xml` sets QUEUE_CONNECTION=sync, so the render runs INLINE at
        // the moment of dispatch and the capture seam observes exactly the view a
        // worker would read. A dispatch placed above the invalidation would find
        // the stale entry still there, and the stored PNG would then be a
        // pre-write page carrying a post-write timestamp.
        $observed = new ArrayObject;
        $this->bindObservingRenderer($observed, 'status-page:mine:en');

        $this->putJson("/api/v1/status-pages/{$page->id}", ['name' => 'Renamed Status'])
            ->assertStatus(200);

        $this->assertTrue(
            $observed->offsetExists('cached'),
            'The render never ran, so this test observed no ordering at all.',
        );
        $this->assertFalse(
            $observed['cached'],
            'The render read a cached page the write had not busted yet.',
        );
    }

    /**
     * Assert exactly one render was queued, for this page, in the after-commit
     * form.
     *
     * The `afterCommit` half is a structural pin rather than an observable
     * behaviour here: none of the four write paths holds an open transaction at
     * the point of dispatch (`reorderMonitors` closes its own before it), and
     * under RefreshDatabase the framework's testing transaction manager skips the
     * wrapping test transaction when deciding whether to defer an after-commit
     * callback, so the dispatch is immediate either way. What it guarantees is
     * that wrapping
     * any of these actions in a transaction later cannot start feeding the
     * renderer a view that a rollback is about to discard.
     */
    protected function assertRenderQueuedFor(StatusPage $page): void
    {
        Queue::assertPushed(
            RenderStatusPagePreview::class,
            static fn (RenderStatusPagePreview $job): bool => $job->statusPage->is($page),
        );

        Queue::assertPushed(
            RenderStatusPagePreview::class,
            static fn (RenderStatusPagePreview $job): bool => $job->afterCommit === true,
        );

        // One write, one render: a second dispatch on the same path would be
        // dropped by the per-page uniqueness lock in production and would
        // therefore never show up as a bug, only as a wasted browser.
        $this->assertCount(1, Queue::pushed(RenderStatusPagePreview::class));
    }

    /**
     * Bind a renderer whose capture records whether a given cache key was still
     * present at the exact moment the render read the page.
     *
     * Overrides the browserless double {@see TestCase::setUp()} installs, which
     * that class explicitly invites for a test that needs to observe a capture.
     */
    protected function bindObservingRenderer(ArrayObject $observed, string $cacheKey): void
    {
        $placeholder = (string) base64_decode(self::PLACEHOLDER_PNG_BASE64, true);

        $this->app->instance(
            StatusPagePreviewRenderer::class,
            new class($observed, $cacheKey, $placeholder) extends StatusPagePreviewRenderer
            {
                public function __construct(
                    protected ArrayObject $observed,
                    protected string $cacheKey,
                    protected string $placeholderPng,
                ) {}

                protected function capture(string $url, array $headers, string $timezone, string $temporaryPath): void
                {
                    $this->observed['cached'] = Cache::has($this->cacheKey);

                    file_put_contents($temporaryPath, $this->placeholderPng);
                }
            },
        );
    }

    /**
     * A page owned by the acting user's team that has been rendered once, at
     * the anchor time.
     */
    protected function renderedPage(): StatusPage
    {
        $this->travelTo(Carbon::parse(self::ANCHOR));

        $team = $this->actingAsTeamMember();
        $page = $this->pageWithOneComponent($team->id);

        // The real trigger, running the real job through the sync queue against
        // the browserless renderer bound in Tests\TestCase.
        $this->postJson("/api/v1/status-pages/{$page->id}/preview")->assertStatus(202);

        $this->assertSame(StatusPagePreviewStatus::Completed, $page->fresh()->preview_render_status);

        return $page;
    }

    /**
     * A page carrying a single attached component, which is the state where an
     * artefact is worth rendering at all.
     */
    protected function pageWithOneComponent(string $teamId): StatusPage
    {
        $page = $this->makeStatusPage($teamId, 'mine');
        $monitor = $this->makeMonitor($teamId);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        return $page;
    }

    /**
     * Read the page's signed preview URL through the API the client uses.
     */
    protected function readPreviewImageUrl(StatusPage $page): ?string
    {
        $response = $this->getJson("/api/v1/status-pages/{$page->id}");

        $response->assertStatus(200);

        return $response->json('data.preview_image_url');
    }

    /**
     * Pull one query parameter out of a URL.
     */
    protected function queryParameter(?string $url, string $key): ?string
    {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $parameters);

        return isset($parameters[$key]) ? (string) $parameters[$key] : null;
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
     * A team whose owner carries a real Sanctum bearer token, for the cases that
     * must not depend on Sanctum::actingAs shortcutting the guard.
     *
     * @return array{0: Team, 1: string} The team and the plain-text token.
     */
    protected function makeTokenHolder(string $name): array
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => $name,
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        return [$team, $user->createToken('preview-render-tests')->plainTextToken];
    }

    /**
     * Builds a persisted foreign team, owned by a fresh user, unrelated to the
     * acting user.
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
     * Build a persisted monitor for the given team.
     */
    protected function makeMonitor(string $teamId): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }
}
