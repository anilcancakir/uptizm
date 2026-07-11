<?php

namespace Tests\Feature\StatusPage;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the security spine of the public status page: the privacy gate is
 * fail-closed (unknown and private pages both 404, never 403, with an
 * indistinguishable body), a valid preview token opens a private page WITHOUT
 * ever touching the public cache, and the public path caches the plain ARRAY
 * form of the view-model (never the object) so a 2nd hit within the TTL
 * rehydrates cleanly under `serializable_classes => false`.
 */
class PublicStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_slug_renders_with_a_two_hundred(): void
    {
        $this->makePageWithMonitor('pub', isPublic: true);

        $this->get('/s/pub')->assertOk();
    }

    public function test_unknown_slug_is_a_four_oh_four(): void
    {
        $this->get('/s/nope')->assertNotFound();
    }

    public function test_private_slug_is_a_four_oh_four(): void
    {
        $this->makePageWithMonitor('priv', isPublic: false, previewToken: 'RIGHT');

        $this->get('/s/priv')->assertNotFound();
    }

    public function test_private_slug_with_a_wrong_preview_token_is_a_four_oh_four(): void
    {
        $this->makePageWithMonitor('priv', isPublic: false, previewToken: 'RIGHT');

        $this->get('/s/priv?preview_token=WRONG')->assertNotFound();
    }

    public function test_tokenless_private_page_cannot_be_opened_with_an_empty_token(): void
    {
        // A private page with NO preview_token must stay closed even when the
        // request supplies an empty `?preview_token=` (hash_equals('','') trap).
        $this->makePageWithMonitor('priv', isPublic: false, previewToken: null);

        $this->get('/s/priv?preview_token=')->assertNotFound();
        $this->get('/s/priv')->assertNotFound();
    }

    public function test_valid_preview_token_opens_a_private_page_and_never_writes_the_cache(): void
    {
        $this->makePageWithMonitor('priv', isPublic: false, previewToken: 'RIGHT');

        $this->get('/s/priv?preview_token=RIGHT')->assertOk();

        // The preview path builds the DTO fresh; the public cache key must stay
        // untouched so a private page can never be seeded into the shared cache.
        $this->assertFalse(Cache::has('status-page:priv'));
    }

    public function test_unknown_and_private_return_an_indistinguishable_body(): void
    {
        $this->makePageWithMonitor('priv', isPublic: false, previewToken: 'RIGHT');

        $unknown = $this->get('/s/nope');
        $private = $this->get('/s/priv');

        $unknown->assertNotFound();
        $private->assertNotFound();

        // Enumeration defense: a private page must not be distinguishable from a
        // non-existent one by status OR body.
        $this->assertSame($unknown->getContent(), $private->getContent());
    }

    public function test_public_path_caches_the_array_form_not_the_object(): void
    {
        $this->makePageWithMonitor('pub', isPublic: true);

        // Two consecutive hits: the first seeds the cache, the second reads it.
        $this->get('/s/pub')->assertOk();
        $this->get('/s/pub')->assertOk();

        // The cache holds the toArray() form, never the StatusPageViewModel
        // object (a cached object fatals under serializable_classes => false).
        $this->assertTrue(Cache::has('status-page:pub'));
        $this->assertIsArray(Cache::get('status-page:pub'));
    }

    /**
     * Creates a status page for a fresh team with one attached, shown monitor
     * plus a daily-uptime row, so the assembler has real data to render.
     */
    protected function makePageWithMonitor(string $slug, bool $isPublic, ?string $previewToken = null): StatusPage
    {
        $team = $this->makeTeam();

        $page = new StatusPage([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => $isPublic,
            'subscriptions_enabled' => true,
        ]);

        // `preview_token` is guarded (hidden, non-fillable), so set it directly.
        $page->preview_token = $previewToken;
        $page->save();

        $monitor = $this->makeMonitor($team);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        $this->seedUptime($monitor, now()->format('Y-m-d'), 'operational');

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
     * Creates a monitor owned by the given team, shown on the status page.
     */
    protected function makeMonitor(Team $team): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => MonitorStatus::Up,
        ]);
    }

    /**
     * Inserts one daily-uptime rollup row for the monitor.
     */
    protected function seedUptime(Monitor $monitor, string $date, string $worst, float $percent = 100.0): void
    {
        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'date' => $date,
            'uptime_percent' => $percent,
            'total_checks' => 100,
            'failed_checks' => 0,
            'worst_status' => $worst,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->insert($row);
    }
}
