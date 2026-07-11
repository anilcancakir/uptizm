<?php

namespace Tests\Feature\StatusPage;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\StatusPages\StatusPageCache;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
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

        Cache::put('status-page:ops', ['stale'], 60);

        $this->drivePersist($monitor, MonitorStatus::Down);

        $this->assertFalse(Cache::has('status-page:ops'));
    }

    public function test_resolving_an_incident_forgets_the_containing_page_cache(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, incidentThreshold: 1);
        $this->makePage($team, 'ops', $monitor);

        // 1. Open an incident, then re-prime the cache as if a fresh public hit
        //    re-cached the page while the incident was live.
        $this->drivePersist($monitor, MonitorStatus::Down);
        Cache::put('status-page:ops', ['stale-during-outage'], 60);

        // 2. A recovering probe resolves the incident and must forget the key.
        $this->drivePersist($monitor, MonitorStatus::Up);

        $this->assertFalse(Cache::has('status-page:ops'));
    }

    public function test_a_monitor_on_two_pages_forgets_both_page_caches(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team, incidentThreshold: 1);
        $this->makePage($team, 'page-a', $monitor);
        $this->makePage($team, 'page-b', $monitor);

        Cache::put('status-page:page-a', ['stale'], 60);
        Cache::put('status-page:page-b', ['stale'], 60);

        $this->drivePersist($monitor, MonitorStatus::Down);

        $this->assertFalse(Cache::has('status-page:page-a'));
        $this->assertFalse(Cache::has('status-page:page-b'));
    }

    public function test_invalidate_for_monitors_forgets_only_containing_pages(): void
    {
        $team = $this->makeTeam();
        $shown = $this->makeMonitor($team);
        $other = $this->makeMonitor($team);
        $this->makePage($team, 'shown-a', $shown);
        $this->makePage($team, 'shown-b', $shown);
        $this->makePage($team, 'unrelated', $other);

        Cache::put('status-page:shown-a', ['stale'], 60);
        Cache::put('status-page:shown-b', ['stale'], 60);
        Cache::put('status-page:unrelated', ['fresh'], 60);

        app(StatusPageCache::class)->invalidateForMonitors([$shown->id]);

        $this->assertFalse(Cache::has('status-page:shown-a'));
        $this->assertFalse(Cache::has('status-page:shown-b'));
        // A page that does NOT show the monitor keeps its cache untouched.
        $this->assertTrue(Cache::has('status-page:unrelated'));
    }

    public function test_invalidate_for_monitors_is_a_no_op_for_an_empty_list(): void
    {
        $team = $this->makeTeam();
        $monitor = $this->makeMonitor($team);
        $this->makePage($team, 'ops', $monitor);

        Cache::put('status-page:ops', ['fresh'], 60);

        app(StatusPageCache::class)->invalidateForMonitors([]);

        $this->assertTrue(Cache::has('status-page:ops'));
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
     * Creates a public status page that shows the given monitor.
     */
    protected function makePage(Team $team, string $slug, Monitor $monitor): StatusPage
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

        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

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
