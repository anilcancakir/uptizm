<?php

namespace Tests\Feature\Http;

use App\Http\Controllers\Api\V1\StatusPageLogoImageController;
use App\Http\Requests\UpdateStatusPageLogoRequest;
use App\Jobs\RenderStatusPagePreview;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the brand-logo upload, its removal, and the signed route that serves
 * the bytes.
 *
 * The two assertions that carry the most weight are not about the happy path.
 *
 * The stored path must come from the page's own key, never from the upload's
 * filename, because `logo_path` is read back off a disk to stream a file: a
 * client that could write that column could name any path the process can open.
 * The column is therefore absent from the ordinary write requests, and that
 * absence is asserted here rather than left to a reviewer noticing it.
 *
 * Replacing a logo with a different image TYPE must not leave the old bytes
 * behind. The extension is part of the filename, so a PNG replaced by a WebP
 * writes a second file, and the row only ever references one of them: the
 * orphan would outlive every reference to it on the private disk of a page whose
 * whole design is that private content stays unreachable.
 */
class StatusPageLogoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StatusPage::LOGO_DISK);
        Queue::fake();
    }

    public function test_an_upload_stores_the_file_under_the_pages_own_key(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('../../../etc/passwd.png', 64, 64),
        ]);

        $response->assertOk();

        $expected = StatusPage::LOGO_DIRECTORY.'/'.$page->id.'.png';
        $this->assertSame($expected, $page->refresh()->logo_path);
        Storage::disk(StatusPage::LOGO_DISK)->assertExists($expected);
    }

    public function test_an_upload_returns_a_working_signed_url_and_removal_clears_it(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $uploaded = $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ]);

        $url = $uploaded->json('data.logo_url');
        $this->assertIsString($url);

        // The URL is the whole authorisation on that route, so it has to work
        // as handed over: a Flutter Image.network and a public page's <img> both
        // fetch it with no bearer token.
        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $removed = $this->deleteJson("/api/v1/status-pages/{$page->id}/logo");

        $removed->assertOk();
        $this->assertNull($removed->json('data.logo_url'));
        $this->assertNull($page->refresh()->logo_path);
    }

    public function test_the_raw_storage_path_never_reaches_the_client(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ]);

        $response->assertOk();
        $response->assertJsonMissingPath('data.logo_path');
    }

    public function test_the_ordinary_update_endpoint_cannot_set_the_storage_path(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->putJson("/api/v1/status-pages/{$page->id}", [
            'name' => 'Renamed',
            'logo_path' => '../../../.env',
        ]);

        $response->assertOk();
        $this->assertNull($page->refresh()->logo_path);
    }

    public function test_replacing_a_logo_with_another_type_removes_the_old_file(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ])->assertOk();

        $first = $page->refresh()->logo_path;

        $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.webp', 64, 64),
        ])->assertOk();

        $second = $page->refresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk(StatusPage::LOGO_DISK)->assertMissing($first);
        Storage::disk(StatusPage::LOGO_DISK)->assertExists($second);
    }

    public function test_deleting_the_page_deletes_its_logo(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ])->assertOk();

        $path = $page->refresh()->logo_path;

        $this->deleteJson("/api/v1/status-pages/{$page->id}")->assertSuccessful();

        Storage::disk(StatusPage::LOGO_DISK)->assertMissing($path);
    }

    public function test_removal_is_idempotent_on_a_page_with_no_logo(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        // A double tap must not be a 404 the client has to special-case.
        $this->deleteJson("/api/v1/status-pages/{$page->id}/logo")->assertOk();
        $this->deleteJson("/api/v1/status-pages/{$page->id}/logo")->assertOk();
    }

    public function test_an_upload_refreshes_the_preview_render(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ])->assertOk();

        // The preview PNG carries the brand mark, so a new logo that skipped the
        // re-render would be live in the editor and stale in the shared artefact.
        Queue::assertPushed(
            RenderStatusPagePreview::class,
            static fn (RenderStatusPagePreview $job): bool => $job->statusPage->is($page),
        );
    }

    /**
     * @return array<string, array{0: UploadedFile}>
     */
    public static function rejectedUploadProvider(): array
    {
        return [
            'an svg is a script container on a public page' => [
                UploadedFile::fake()->createWithContent(
                    'brand.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
            ],
            'a document is not an image' => [
                UploadedFile::fake()->create('brand.pdf', 12, 'application/pdf'),
            ],
            'over the byte ceiling' => [
                UploadedFile::fake()->image('brand.png', 64, 64)
                    ->size(UpdateStatusPageLogoRequest::MAX_KILOBYTES + 1),
            ],
            'over the dimension ceiling' => [
                UploadedFile::fake()->image(
                    'brand.png',
                    UpdateStatusPageLogoRequest::MAX_EDGE_PIXELS + 1,
                    64,
                ),
            ],
        ];
    }

    #[DataProvider('rejectedUploadProvider')]
    public function test_a_rejected_upload_writes_nothing(UploadedFile $file): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $response = $this->post(
            "/api/v1/status-pages/{$page->id}/logo",
            ['logo' => $file],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422);
        $this->assertNull($page->refresh()->logo_path);
        $this->assertEmpty(Storage::disk(StatusPage::LOGO_DISK)->allFiles());
    }

    public function test_another_teams_page_is_masked_as_absent(): void
    {
        $this->actingAsTeamMember();
        $foreignPage = $this->makeStatusPage($this->makeForeignTeam()->id, 'theirs');

        $this->post("/api/v1/status-pages/{$foreignPage->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ])->assertStatus(404);

        $this->deleteJson("/api/v1/status-pages/{$foreignPage->id}/logo")
            ->assertStatus(404);

        $this->assertNull($foreignPage->refresh()->logo_path);
    }

    public function test_the_image_route_refuses_an_unsigned_request(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ])->assertOk();

        // Without the signature there is no actor to compare a team against, so
        // an unsigned URL must not be servable at all.
        $this->get("/api/v1/status-pages/{$page->id}/logo")->assertForbidden();
    }

    public function test_the_image_route_is_a_404_when_the_row_names_a_missing_file(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ])->assertOk();

        $url = $this->signedLogoUrl($page->refresh());

        Storage::disk(StatusPage::LOGO_DISK)->delete($page->logo_path);

        // Streaming a file that vanished out of band would fail mid-response as
        // a 500; the client already renders an initials fallback for this state.
        $this->get($url)->assertNotFound();
    }

    public function test_the_image_route_is_a_404_for_a_page_with_no_logo(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'mine');

        $this->get($this->signedLogoUrl($page))->assertNotFound();
    }

    public function test_the_public_page_renders_the_logo_when_there_is_one(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makeStatusPage($team->id, 'brandy');

        $this->post("/api/v1/status-pages/{$page->id}/logo", [
            'logo' => UploadedFile::fake()->image('brand.png', 64, 64),
        ])->assertOk();

        // The whole point of the upload is this surface. The signed URL has to
        // be IN the markup, because a visitor's browser is the fetcher and it
        // carries no session with this application at all.
        $response = $this->get('/s/brandy');

        $response->assertOk();
        $response->assertSee('/status-pages/'.$page->id.'/logo', false);
        $response->assertSee('<img', false);
    }

    public function test_the_public_page_falls_back_to_initials_without_a_logo(): void
    {
        $team = $this->actingAsTeamMember();
        $this->makeStatusPage($team->id, 'plain');

        $response = $this->get('/s/plain');

        $response->assertOk();
        $response->assertDontSee('/logo?', false);
        // 'Uptizm Status' clipped to two characters by the header partial.
        $response->assertSee('Up', false);
    }

    /**
     * A validly signed URL for the page's logo, minted the way the server does.
     */
    protected function signedLogoUrl(StatusPage $page): string
    {
        return URL::temporarySignedRoute(
            StatusPageLogoImageController::ROUTE_NAME,
            now()->addMinutes(15),
            [
                'statusPage' => $page->getKey(),
                'v' => $page->updated_at?->getTimestamp(),
            ],
        );
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
     * A persisted team unrelated to the acting user.
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
     * A persisted, public status page for the given team.
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
}
