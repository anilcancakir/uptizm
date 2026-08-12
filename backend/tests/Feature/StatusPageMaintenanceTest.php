<?php

namespace Tests\Feature;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusPageAssembler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the planned-maintenance section of the PUBLIC status page: which
 * windows reach a visitor, which never do, and where the section sits.
 *
 * The visibility rule is the whole point of this surface. A window's title and
 * description describe work on a named component ("Upgrading the payments
 * database"), so publishing one is publishing the existence of that component.
 * Three scopes therefore have to hold at once, and each has a named case here:
 *
 *   - the window is announced on THIS page (`status_page_id`), so a sibling page
 *     of the same team never inherits it;
 *   - at least one of its monitors is VISIBLE on this page, so a window whose
 *     components are all hidden or paused publishes nothing;
 *   - which together make a cross-team window unreachable, since neither the
 *     page id nor a single monitor id can belong to another tenant.
 *
 * The time bound is pinned on both edges against
 * {@see StatusPageAssembler::UPCOMING_MAINTENANCE_DAYS} rather than against a
 * literal, so the horizon can be retuned in one place without this suite
 * quietly certifying the old value.
 */
class StatusPageMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_window_open_right_now_renders_with_its_title_description_and_window_times(): void
    {
        $page = $this->makePageWithMonitor('maintenance-open');
        $monitor = $page->monitors()->first();

        $window = $this->makeWindow($page, [$monitor], [
            'title' => 'Upgrading the payments database',
            'description' => 'Checkout will reject new orders for up to ten minutes.',
            'starts_at' => CarbonImmutable::now()->subHour(),
            'ends_at' => CarbonImmutable::now()->addHour(),
        ]);

        $response = $this->get('/s/maintenance-open');

        $response->assertOk();
        $response->assertSee('Upgrading the payments database');
        $response->assertSee('Checkout will reject new orders for up to ten minutes.');

        // Both bounds travel as machine-readable `<time datetime>`, which is what
        // the layout's rewrite converts to the VISITOR's local time. A window
        // rendered only as server-side text would read in the server's zone.
        $response->assertSee('<time datetime="'.$window->starts_at->toIso8601String().'"', escape: false);
        $response->assertSee('<time datetime="'.$window->ends_at->toIso8601String().'"', escape: false);
    }

    public function test_a_finished_window_does_not_render(): void
    {
        $page = $this->makePageWithMonitor('maintenance-finished');

        $this->makeWindow($page, [$page->monitors()->first()], [
            'title' => 'Finished database upgrade',
            'starts_at' => CarbonImmutable::now()->subDays(2),
            'ends_at' => CarbonImmutable::now()->subDay(),
        ]);

        $response = $this->get('/s/maintenance-finished');

        $response->assertOk();
        $response->assertDontSee('Finished database upgrade');
    }

    public function test_a_window_whose_monitors_are_all_hidden_from_the_page_does_not_render(): void
    {
        $page = $this->makePageWithMonitor('maintenance-hidden');

        // A second component of the SAME team, attached to the page but not
        // published on it. Its name never reaches the page, so neither may the
        // planned work that names it.
        $hidden = $this->makeMonitor($page->team, ['show_on_status_page' => false]);
        $page->monitors()->attach([$hidden->id => ['display_order' => 1]]);

        $this->makeWindow($page, [$hidden], [
            'title' => 'Rebuilding the hidden internal queue',
        ]);

        $response = $this->get('/s/maintenance-hidden');

        $response->assertOk();
        $response->assertDontSee('Rebuilding the hidden internal queue');
    }

    public function test_a_window_belonging_to_another_team_never_reaches_this_page(): void
    {
        $page = $this->makePageWithMonitor('maintenance-tenant-a');
        $otherPage = $this->makePageWithMonitor('maintenance-tenant-b');

        $this->makeWindow($otherPage, [$otherPage->monitors()->first()], [
            'title' => 'Another tenant private migration',
        ]);

        $response = $this->get('/s/maintenance-tenant-a');

        $response->assertOk();
        $response->assertDontSee('Another tenant private migration');

        // Guard against a vacuous pass: the window really is renderable, just not
        // here.
        $this->get('/s/maintenance-tenant-b')
            ->assertOk()
            ->assertSee('Another tenant private migration');
    }

    public function test_a_window_announced_on_a_sibling_page_does_not_render_on_this_one(): void
    {
        // Same team, same monitor, a different page: the operator chose where the
        // work is announced, and a monitor shared between two pages must not drag
        // the window onto both.
        $page = $this->makePageWithMonitor('maintenance-primary');
        $monitor = $page->monitors()->first();

        $sibling = $this->makePage($page->team, 'maintenance-sibling');
        $sibling->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $this->makeWindow($sibling, [$monitor], [
            'title' => 'Sibling page announced work',
        ]);

        $this->get('/s/maintenance-primary')
            ->assertOk()
            ->assertDontSee('Sibling page announced work');

        $this->get('/s/maintenance-sibling')
            ->assertOk()
            ->assertSee('Sibling page announced work');
    }

    public function test_a_window_starting_just_inside_the_horizon_renders(): void
    {
        $page = $this->makePageWithMonitor('maintenance-inside-horizon');
        $startsAt = CarbonImmutable::now()
            ->addDays(StatusPageAssembler::UPCOMING_MAINTENANCE_DAYS)
            ->subMinutes(5);

        $this->makeWindow($page, [$page->monitors()->first()], [
            'title' => 'Work just inside the horizon',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(2),
        ]);

        $this->get('/s/maintenance-inside-horizon')
            ->assertOk()
            ->assertSee('Work just inside the horizon');
    }

    public function test_a_window_starting_just_beyond_the_horizon_does_not_render_yet(): void
    {
        $page = $this->makePageWithMonitor('maintenance-beyond-horizon');
        $startsAt = CarbonImmutable::now()
            ->addDays(StatusPageAssembler::UPCOMING_MAINTENANCE_DAYS)
            ->addMinutes(5);

        $this->makeWindow($page, [$page->monitors()->first()], [
            'title' => 'Work just beyond the horizon',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(2),
        ]);

        $this->get('/s/maintenance-beyond-horizon')
            ->assertOk()
            ->assertDontSee('Work just beyond the horizon');
    }

    public function test_a_window_that_does_not_suppress_alerts_is_still_announced(): void
    {
        // `suppress_alerts` is a PAGING switch. A window created with it off still
        // describes planned work, so the public announcement must not be gated on
        // it.
        $page = $this->makePageWithMonitor('maintenance-no-suppression');

        $this->makeWindow($page, [$page->monitors()->first()], [
            'title' => 'Planned work that still pages',
            'suppress_alerts' => false,
        ]);

        $this->get('/s/maintenance-no-suppression')
            ->assertOk()
            ->assertSee('Planned work that still pages');
    }

    public function test_the_maintenance_section_renders_above_the_components_section(): void
    {
        $page = $this->makePageWithMonitor('maintenance-order');

        $this->makeWindow($page, [$page->monitors()->first()], [
            'title' => 'Planned work above the fold',
        ]);

        $html = $this->get('/s/maintenance-order')->assertOk()->getContent();

        $maintenanceAt = strpos($html, 'Planned work above the fold');
        $componentsAt = strpos($html, 'Components');

        $this->assertIsInt($maintenanceAt, 'The maintenance section must render on the page.');
        $this->assertIsInt($componentsAt, 'The components section must still render on the page.');
        $this->assertLessThan(
            $componentsAt,
            $maintenanceAt,
            'Planned work must be visible before the components section, not below it.',
        );
    }

    public function test_a_window_reaches_the_read_model_with_only_its_five_public_fields(): void
    {
        // The recursive allowlist assertion in StatusPageAssemblerTest seeds no
        // window, so it walks no maintenance entry. This pins the same guarantee
        // for this branch: a window carries internal ids, the tenant, the page it
        // belongs to, the paging switch and the announce-once stamp, and none of
        // them is a visitor's business.
        $page = $this->makePageWithMonitor('maintenance-allowlist');
        $this->makeWindow($page, [$page->monitors()->first()], ['title' => 'Allowlisted work']);

        $maintenances = (new StatusPageAssembler)->build($page)->maintenances;

        $this->assertCount(1, $maintenances);
        $this->assertSame(
            [
                'title',
                'title_original',
                'title_provenance',
                'description',
                'description_original',
                'description_provenance',
                'startsAt',
                'endsAt',
                'state',
            ],
            array_keys($maintenances[0]),
        );
    }

    public function test_a_page_with_no_window_renders_no_maintenance_section(): void
    {
        // Unlike components and incidents, planned maintenance has no standing
        // empty state: an empty card would tell a visitor nothing and would push
        // the components further down every day of the year nothing is planned.
        $this->makePageWithMonitor('maintenance-none');

        $this->get('/s/maintenance-none')
            ->assertOk()
            ->assertDontSee('Scheduled maintenance');
    }

    /**
     * Creates a status page for a fresh team with one published monitor attached.
     */
    protected function makePageWithMonitor(string $slug): StatusPage
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team, $slug);

        $page->monitors()->attach([
            $this->makeMonitor($team)->id => ['display_order' => 0],
        ]);

        return $page;
    }

    /**
     * Creates a public status page owned by the given team.
     */
    protected function makePage(Team $team, string $slug): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'is_public' => true,
        ]);
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Maintenance Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Maintenance Team',
        ]);
    }

    /**
     * Creates a monitor owned by the given team, published on the status page
     * unless the overrides say otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeMonitor(Team $team, array $attributes = []): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://secret-internal-host.example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
            ...$attributes,
        ]);
    }

    /**
     * Creates a maintenance window announced on the given page and attached to
     * the given monitors. Defaults to a window that is open right now.
     *
     * @param  array<int, Monitor>  $monitors
     * @param  array<string, mixed>  $attributes
     */
    protected function makeWindow(StatusPage $page, array $monitors, array $attributes = []): ScheduledMaintenance
    {
        $window = ScheduledMaintenance::factory()->create([
            'team_id' => $page->team_id,
            'status_page_id' => $page->id,
            'starts_at' => CarbonImmutable::now()->subMinutes(30),
            'ends_at' => CarbonImmutable::now()->addMinutes(30),
            ...$attributes,
        ]);

        $window->monitors()->attach(array_map(
            static fn (Monitor $monitor): mixed => $monitor->id,
            $monitors,
        ));

        return $window;
    }
}
