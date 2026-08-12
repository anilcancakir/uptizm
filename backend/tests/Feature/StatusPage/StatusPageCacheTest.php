<?php

namespace Tests\Feature\StatusPage;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Jobs\BustStatusPageCacheForMaintenanceBoundaries;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\StatusPages\StatusPageCache;
use App\Support\Monitoring\CheckResult;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the pivot-boundary cache invalidation: opening or resolving an incident
 * on a monitor forgets the cached read model of EVERY status page that shows
 * that monitor, immediately, so an outage is never masked by a page that keeps
 * serving its stale (pre-incident) cache until the TTL expires.
 *
 * The invalidation hooks the pivot-attach boundary off-lock (in the check
 * persistence dispatch step and the SSL open path), NOT a model observer: an
 * `Incident::created` observer fires before `monitors()->attach()`, so it would
 * never see which pages contain the affected monitor.
 *
 * Every assertion below is made in BOTH languages, because the page is cached
 * per language: one entry per `(slug, locale)` pair. A bust that clears only the
 * language it happened to be written for leaves the other one serving the
 * pre-incident page for up to 60 seconds, which is the exact failure this whole
 * surface exists to prevent, and it would show on the language a customer's
 * non-English visitors read while the English page went red.
 *
 * The three sites that COMPOSE the key are covered one by one: the funnel every
 * write goes through ({@see StatusPageCache::invalidateForMonitors()}), the
 * maintenance-boundary sweep, and the page-update endpoint's own forget. The
 * five callers that funnel through the first are proven to funnel, not to
 * compose, which is the point of funnelling them.
 */
class StatusPageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the invalidation from the queued incident notifications; the
        // cache forget must run regardless of whether an alert is dispatched.
        Notification::fake();
    }

    public function test_opening_an_incident_forgets_the_containing_page_cache(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, incidentThreshold: 1);
        $this->makePage($team, 'ops', $monitor);

        $this->primeEveryLocale('ops');

        $this->drivePersist($monitor, MonitorStatus::Down);

        $this->assertEveryLocaleForgotten('ops');
    }

    public function test_resolving_an_incident_forgets_the_containing_page_cache(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, incidentThreshold: 1);
        $this->makePage($team, 'ops', $monitor);

        // 1. Open an incident, then re-prime the cache as if a fresh public hit
        //    re-cached the page in each language while the incident was live.
        $this->drivePersist($monitor, MonitorStatus::Down);
        $this->primeEveryLocale('ops', 'stale-during-outage');

        // 2. A recovering probe resolves the incident and must forget both keys.
        $this->drivePersist($monitor, MonitorStatus::Up);

        $this->assertEveryLocaleForgotten('ops');
    }

    public function test_a_monitor_on_two_pages_forgets_both_page_caches(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, incidentThreshold: 1);
        $this->makePage($team, 'page-a', $monitor);
        $this->makePage($team, 'page-b', $monitor);

        $this->primeEveryLocale('page-a');
        $this->primeEveryLocale('page-b');

        $this->drivePersist($monitor, MonitorStatus::Down);

        $this->assertEveryLocaleForgotten('page-a');
        $this->assertEveryLocaleForgotten('page-b');
    }

    public function test_invalidate_for_monitors_forgets_only_containing_pages(): void
    {
        $team = $this->makeTeam();
        $shown = $this->makeMonitor($team);
        $other = $this->makeMonitor($team);
        $this->makePage($team, 'shown-a', $shown);
        $this->makePage($team, 'shown-b', $shown);
        $this->makePage($team, 'unrelated', $other);

        $this->primeEveryLocale('shown-a');
        $this->primeEveryLocale('shown-b');
        $this->primeEveryLocale('unrelated', 'fresh');

        app(StatusPageCache::class)->invalidateForMonitors([$shown->id]);

        $this->assertEveryLocaleForgotten('shown-a');
        $this->assertEveryLocaleForgotten('shown-b');
        // A page that does NOT show the monitor keeps its cache untouched, in
        // every language: the widened fan-out must not widen the SLUG set too.
        $this->assertEveryLocaleKept('unrelated');
    }

    public function test_invalidate_for_monitors_is_a_no_op_for_an_empty_list(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team);
        $this->makePage($team, 'ops', $monitor);

        $this->primeEveryLocale('ops', 'fresh');

        app(StatusPageCache::class)->invalidateForMonitors([]);

        $this->assertEveryLocaleKept('ops');
    }

    public function test_the_maintenance_boundary_sweep_forgets_every_locale(): void
    {
        // The second of the three sites that compose the key. It resolves slugs
        // from a window that just crossed a boundary and forgets them directly,
        // so it fans out on its own rather than through the pivot funnel above.
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team);
        $page = $this->makePage($team, 'ops', $monitor);
        $this->makeWindow($team, $page, CarbonImmutable::now()->subSeconds(30), CarbonImmutable::now()->addHour());

        $this->primeEveryLocale('ops');

        BustStatusPageCacheForMaintenanceBoundaries::dispatchSync();

        $this->assertEveryLocaleForgotten('ops');
    }

    public function test_updating_a_page_with_no_components_forgets_every_locale(): void
    {
        /*
         * The third site. The page is deliberately left with NO monitors
         * attached, which is what isolates it: `invalidateForMonitors()` resolves
         * slugs THROUGH the pivot and returns early on an empty set, so the only
         * thing that can clear this page's keys is the endpoint's own forget.
         * `locale` is writable here, so the language the cached copy was rendered
         * in is exactly what this write can change.
         */
        $team = $this->makeTeam();
        $page = $this->makePage($team, 'ops');
        $this->actingAsOwnerOf($team);

        $this->primeEveryLocale('ops');

        $this->putJson("/api/v1/status-pages/{$page->id}", ['name' => 'Renamed Status'])->assertStatus(200);

        $this->assertEveryLocaleForgotten('ops');
    }

    /**
     * Drive one probe outcome through the real persistence path so the incident
     * lifecycle transition (and its off-lock invalidation) fires end to end.
     */
    protected function drivePersist(Monitor $monitor, MonitorStatus $status): void
    {
        $result = new CheckResult(
            monitorId: $monitor->id,
            region: 'us-east',
            checkedAt: new DateTimeImmutable,
            status: $status,
            statusCode: $status === MonitorStatus::Down ? 500 : 200,
            responseMs: 120,
            errorMessage: $status === MonitorStatus::Down ? 'boom' : null,
            timingDnsMs: 0,
            timingConnectMs: 0,
            timingTlsMs: 0,
            timingTtfbMs: 0,
            timingDownloadMs: 0,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: (string) Str::uuid(),
        );

        app(CheckPersistenceService::class)->persist($monitor, $result);
    }

    /**
     * Prime the cached read model of [$slug] in every language this deployment
     * publishes, as a real visitor hitting each language's URL would.
     *
     * The keys are SPELLED OUT rather than composed from
     * {@see ShowStatusPageController::cacheKey()} on purpose: a test that builds
     * its expectation with the same helper the code under test uses follows a key
     * change instead of catching one.
     *
     * @return array<string, string> Locale to the key primed for it.
     */
    protected function primeEveryLocale(string $slug, string $value = 'stale'): array
    {
        $keys = [
            'en' => "status-page:{$slug}:en",
            'tr' => "status-page:{$slug}:tr",
        ];

        foreach ($keys as $key) {
            Cache::put($key, [$value], 60);
        }

        return $keys;
    }

    /**
     * Assert the bust reached every language of [$slug].
     *
     * The Turkish message names the consequence rather than the key, because a
     * fan-out that covers only the language it was written for fails HERE and
     * nowhere else: the English page goes red on time and the Turkish one keeps
     * publishing the pre-incident state.
     */
    protected function assertEveryLocaleForgotten(string $slug): void
    {
        $this->assertFalse(
            Cache::has("status-page:{$slug}:en"),
            "The default-language read model of [{$slug}] survived the bust.",
        );

        $this->assertFalse(
            Cache::has("status-page:{$slug}:tr"),
            "The Turkish read model of [{$slug}] survived the bust: that URL keeps serving the pre-write page.",
        );
    }

    /**
     * Assert the bust left every language of [$slug] alone.
     */
    protected function assertEveryLocaleKept(string $slug): void
    {
        $this->assertTrue(Cache::has("status-page:{$slug}:en"));
        $this->assertTrue(Cache::has("status-page:{$slug}:tr"));
    }

    /**
     * Authenticate as the owner of [$team] so the team-scoped API accepts a
     * write against its pages.
     */
    protected function actingAsOwnerOf(Team $team): void
    {
        $owner = User::query()->findOrFail($team->user_id);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($owner);
    }

    /**
     * Creates a maintenance window on the page, with no components attached: the
     * boundary sweep resolves its slugs from the window's page, never from the
     * pivot.
     */
    protected function makeWindow(
        Team $team,
        StatusPage $page,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): ScheduledMaintenance {
        return ScheduledMaintenance::query()->create([
            'team_id' => $team->id,
            'status_page_id' => $page->id,
            'title' => 'Database failover rehearsal',
            'description' => 'Planned work; the component is expected to be unavailable.',
            'suppress_alerts' => true,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /**
     * Creates a public status page, showing the given monitor when there is one.
     *
     * The monitor is optional because one test needs a page with an EMPTY pivot:
     * that is what tells the endpoint's own forget apart from the pivot funnel.
     */
    protected function makePage(Team $team, string $slug, ?Monitor $monitor = null): StatusPage
    {
        $page = new StatusPage([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => true,
            'subscriptions_enabled' => true,
        ]);
        $page->save();

        if ($monitor instanceof Monitor) {
            $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        }

        return $page;
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Status Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Status Team',
        ]);
    }

    /**
     * Creates a monitor owned by the given team with alerts off so the persist
     * path exercises the invalidation without dispatching notifications.
     */
    protected function makeMonitor(Team $team, int $incidentThreshold = 2): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => $incidentThreshold,
            'consecutive_fails' => 0,
            'alert_on_down' => false,
            'alert_on_recover' => false,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
        ]);
    }
}
