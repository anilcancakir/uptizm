<?php

namespace Tests\Unit\Models;

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see StatusPage} relation shapes, the public-visibility scope,
 * the slug-based route binding, and the preview-token hiding: a status page
 * must never leak `preview_token` in array/JSON output regardless of
 * `is_public`.
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
            'preview_token' => Str::random(64),
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
