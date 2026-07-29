<?php

namespace Tests\Unit\Models;

use App\Enums\StatusPagePreviewStatus;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see StatusPage} relation shapes, the public-visibility scope,
 * the slug-based route binding, and the preview-token hiding: a status page
 * must never leak `preview_token` in array/JSON output regardless of
 * `is_public`.
 *
 * It also locks the preview-render lifecycle the model owns: `preview_token`
 * generation on create (the gate that lets a headless renderer read a PRIVATE
 * page at all), the render-column casts, and the stored-PNG cleanup on delete.
 */
class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitors_relation_returns_attached_monitors_in_display_order(): void
    {
        $team = $this->makeTeam();
        $statusPage = $this->makeStatusPage($team);
        $first = $this->makeMonitor($team, 'API');
        $second = $this->makeMonitor($team, 'Website');

        // Attach out of creation order to prove display_order drives the sort, not insert order.
        // The pivot uses a composite (status_page_id, monitor_id) primary key, so attach()
        // needs no synthetic id.
        $statusPage->monitors()->attach($second->id, ['display_order' => 1]);
        $statusPage->monitors()->attach($first->id, ['display_order' => 0]);

        $ordered = $statusPage->monitors()->get();

        $this->assertSame([$first->id, $second->id], $ordered->pluck('id')->all());
    }

    public function test_scope_public_excludes_non_public_status_pages(): void
    {
        $team = $this->makeTeam();
        $this->makeStatusPage($team, isPublic: true);
        $this->makeStatusPage($team, isPublic: false);

        $publicPages = StatusPage::query()->public()->get();

        $this->assertCount(1, $publicPages);
        $this->assertTrue($publicPages->first()->is_public);
    }

    public function test_route_key_name_is_slug(): void
    {
        $statusPage = $this->makeStatusPage($this->makeTeam());

        $this->assertSame('slug', $statusPage->getRouteKeyName());
    }

    public function test_preview_token_is_hidden_from_array_output(): void
    {
        $statusPage = $this->makeStatusPage($this->makeTeam());

        $this->assertArrayNotHasKey('preview_token', $statusPage->toArray());
    }

    public function test_creating_a_status_page_generates_a_preview_token(): void
    {
        $statusPage = $this->makeStatusPage($this->makeTeam());

        // 40 chars, matching the seeder's existing shape. Without this every
        // page created through the API carries a NULL token, and the public
        // controller fails closed on an empty stored token, so a private page
        // could never be read by anything (including the renderer).
        $this->assertSame(40, strlen((string) $statusPage->preview_token));
        $this->assertSame($statusPage->preview_token, $statusPage->fresh()->preview_token);
    }

    public function test_an_explicitly_set_preview_token_survives_creation(): void
    {
        // `preview_token` is guarded (hidden, non-fillable), so a caller assigns
        // it directly. The generator must fill ONLY an empty token, otherwise it
        // would silently rotate a token a caller deliberately chose.
        $statusPage = new StatusPage([
            'team_id' => $this->makeTeam()->id,
            'name' => 'Explicit Token',
            'slug' => Str::uuid().'-status',
        ]);

        $statusPage->preview_token = 'CHOSEN-BY-THE-CALLER';
        $statusPage->save();

        $this->assertSame('CHOSEN-BY-THE-CALLER', $statusPage->fresh()->preview_token);
    }

    public function test_the_migration_backfills_a_missing_preview_token(): void
    {
        $statusPage = $this->makeStatusPage($this->makeTeam());

        // Reproduce a pre-generator row: every page created through the API
        // before this migration has a NULL token.
        DB::table('status_pages')
            ->where('id', $statusPage->id)
            ->update(['preview_token' => null]);

        $this->previewColumnsMigration()->backfillMissingPreviewTokens();

        $this->assertSame(40, strlen((string) $statusPage->fresh()->preview_token));
    }

    public function test_deleting_a_status_page_deletes_its_stored_preview_image(): void
    {
        Storage::fake(StatusPage::PREVIEW_DISK);

        $statusPage = $this->makeStatusPage($this->makeTeam());
        $path = $statusPage->previewImageStoragePath();
        Storage::disk(StatusPage::PREVIEW_DISK)->put($path, 'fake-png-bytes');
        $statusPage->preview_image_path = $path;
        $statusPage->save();

        $statusPage->delete();

        Storage::disk(StatusPage::PREVIEW_DISK)->assertMissing($path);
    }

    public function test_deleting_a_status_page_with_no_stored_preview_leaves_other_pages_files_alone(): void
    {
        Storage::fake(StatusPage::PREVIEW_DISK);

        $team = $this->makeTeam();
        $rendered = $this->makeStatusPage($team);
        Storage::disk(StatusPage::PREVIEW_DISK)->put($rendered->previewImageStoragePath(), 'keep-me');
        $rendered->preview_image_path = $rendered->previewImageStoragePath();
        $rendered->save();

        $neverRendered = $this->makeStatusPage($team);
        $neverRendered->delete();

        Storage::disk(StatusPage::PREVIEW_DISK)->assertExists($rendered->previewImageStoragePath());
    }

    public function test_deleting_a_status_page_whose_preview_file_is_already_gone_does_not_throw(): void
    {
        Storage::fake(StatusPage::PREVIEW_DISK);

        $statusPage = $this->makeStatusPage($this->makeTeam());
        // The column points at a file that was never written (a failed render,
        // or a disk wiped between deploys). Cleanup must not turn that into an
        // exception that blocks the delete.
        $statusPage->preview_image_path = $statusPage->previewImageStoragePath();
        $statusPage->save();

        $statusPage->delete();

        $this->assertDatabaseMissing('status_pages', ['id' => $statusPage->id]);
    }

    /**
     * A team delete takes the PNG with it, even though the row itself goes
     * through a database cascade.
     *
     * `status_pages.team_id` is `cascadeOnDelete()` and the starter's
     * `DeleteTeam` action just calls `$team->delete()`, so the pages disappear
     * without any Eloquent event and this model's own `deleted` hook never
     * fires. Nothing on StatusPage can close that: the cleanup has to happen on
     * the side that DOES get an event, which is why {@see Team::booted()} deletes
     * the files in a `deleting` hook before the cascade removes the rows that
     * name them.
     *
     * Left unclosed this leaks one PNG per page per deleted team, permanently:
     * the row that held the path is gone, so nothing can ever locate the file
     * again.
     */
    public function test_deleting_the_owning_team_also_removes_the_stored_preview_image(): void
    {
        Storage::fake(StatusPage::PREVIEW_DISK);

        $team = $this->makeTeam();
        $statusPage = $this->makeStatusPage($team);
        $path = $statusPage->previewImageStoragePath();
        Storage::disk(StatusPage::PREVIEW_DISK)->put($path, 'fake-png-bytes');
        $statusPage->preview_image_path = $path;
        $statusPage->save();

        $team->delete();

        $this->assertDatabaseMissing('status_pages', ['id' => $statusPage->id]);
        Storage::disk(StatusPage::PREVIEW_DISK)->assertMissing($path);
    }

    public function test_preview_render_columns_cast_to_their_declared_types(): void
    {
        $statusPage = $this->makeStatusPage($this->makeTeam());
        $statusPage->preview_render_status = StatusPagePreviewStatus::Completed;
        $statusPage->preview_rendered_at = '2026-07-29 10:00:00';
        $statusPage->save();

        $fresh = $statusPage->fresh();

        $this->assertSame(StatusPagePreviewStatus::Completed, $fresh->preview_render_status);
        $this->assertInstanceOf(CarbonImmutable::class, $fresh->preview_rendered_at);
    }

    public function test_the_preview_render_columns_are_not_mass_assignable(): void
    {
        // The render columns are written by the renderer and the job, never by a
        // request payload. Mass assignment must drop them.
        $statusPage = StatusPage::query()->create([
            'team_id' => $this->makeTeam()->id,
            'name' => 'Guarded Columns',
            'slug' => Str::uuid().'-status',
            'preview_image_path' => 'status-page-previews/injected.png',
            'preview_rendered_at' => '2026-07-29 10:00:00',
            'preview_render_status' => 'completed',
        ]);

        $this->assertNull($statusPage->preview_image_path);
        $this->assertNull($statusPage->preview_rendered_at);
        $this->assertNull($statusPage->preview_render_status);
    }

    /**
     * The anonymous migration instance that adds the render columns, so the
     * data-migration half (the token backfill) is testable on its own.
     */
    protected function previewColumnsMigration(): object
    {
        return require database_path(
            'migrations/2026_07_29_000000_add_preview_render_columns_to_status_pages_table.php'
        );
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Status Page Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Status Page Team',
        ]);
    }

    /**
     * Creates a persisted status page for the given team.
     */
    protected function makeStatusPage(Team $team, bool $isPublic = false): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Public Status',
            'slug' => Str::uuid().'-status',
            'is_public' => $isPublic,
        ]);
    }

    /**
     * Creates a persisted monitor for the given team.
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
}
