<?php

namespace Tests\Feature\StatusPage;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Http\Controllers\StatusPage\ShowStatusPageController;
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
        $this->assertFalse(Cache::has('status-page:priv:en'));
    }

    public function test_valid_preview_token_in_a_header_opens_a_private_page_and_never_writes_the_cache(): void
    {
        $this->makePageWithMonitor('priv', isPublic: false, previewToken: 'RIGHT');

        // The renderer sends the token as a header so it never reaches an access
        // log; the gate must accept that transport exactly like the query one.
        $this->get('/s/priv', [ShowStatusPageController::PREVIEW_TOKEN_HEADER => 'RIGHT'])->assertOk();

        $this->assertFalse(Cache::has('status-page:priv:en'));
    }

    public function test_private_slug_with_a_wrong_header_preview_token_is_a_four_oh_four(): void
    {
        $this->makePageWithMonitor('priv', isPublic: false, previewToken: 'RIGHT');

        $this->get('/s/priv', [ShowStatusPageController::PREVIEW_TOKEN_HEADER => 'WRONG'])->assertNotFound();
    }

    public function test_tokenless_private_page_cannot_be_opened_with_an_empty_header_token(): void
    {
        // Same hash_equals('','') trap as the query transport: a page with NO
        // preview_token must stay closed for an empty supplied header too.
        $page = $this->makePageWithMonitor('priv', isPublic: false, previewToken: null);

        // Pin the premise: the page genuinely carries no stored token. Token
        // generation moved into the model, so without this the guard's test
        // could go vacuous silently.
        $this->assertNull($page->preview_token);

        $this->get('/s/priv', [ShowStatusPageController::PREVIEW_TOKEN_HEADER => ''])->assertNotFound();
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
        $this->assertTrue(Cache::has('status-page:pub:en'));
        $this->assertIsArray(Cache::get('status-page:pub:en'));
    }

    public function test_a_valid_token_renders_a_public_page_fresh_instead_of_the_cached_body(): void
    {
        $page = $this->makePageWithMonitor('pub', isPublic: true, previewToken: 'RIGHT');

        // 1. Seed the 60s read-through cache with the page as it is now.
        $this->get('/s/pub')->assertOk()->assertSee('Uptizm Status');

        // 2. Mutate the page behind the cache. An ordinary visitor keeps seeing
        //    the cached body, which is the whole point of the cache.
        $page->update(['name' => 'Renamed Behind The Cache']);
        $this->get('/s/pub')->assertOk()->assertDontSee('Renamed Behind The Cache');

        // 3. A token holder is a preview or a render, so it must see the page as
        //    it is NOW: a public page with a valid token bypasses the cache too,
        //    otherwise a render stamps up to 60s of stale state with the current
        //    time.
        $this->get('/s/pub?preview_token=RIGHT')
            ->assertOk()
            ->assertSee('Renamed Behind The Cache');

        // 4. The bypass runs in both directions: the token path must not write
        //    the shared cache either, so the visitor's entry is left untouched.
        $this->assertSame('Uptizm Status', Cache::get('status-page:pub:en')['page']['name']);
    }

    public function test_the_token_path_is_never_stored_by_a_shared_cache(): void
    {
        $this->makePageWithMonitor('pub', isPublic: true, previewToken: 'RIGHT');

        // The token path renders private-or-fresh content under the SAME public
        // URL, so an intermediary that keys on neither the header nor the query
        // must be told not to store it at all.
        $token = $this->get('/s/pub?preview_token=RIGHT')->assertOk();
        $this->assertSame('no-store, private', $token->headers->get('Cache-Control'));

        // The ordinary public path stays CDN-cacheable; the directive is scoped
        // to the token path alone.
        $public = $this->get('/s/pub')->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $public->headers->get('Cache-Control'));
    }

    public function test_a_token_holder_is_not_throttled_by_ordinary_visitor_traffic(): void
    {
        $this->makePageWithMonitor('pub', isPublic: true, previewToken: 'RIGHT');

        // Every render fetches the app's own origin, so it shares one per-IP
        // enumeration bucket with real visitors. Exhaust that bucket.
        for ($i = 0; $i < 30; $i++) {
            $this->get('/s/pub')->assertOk();
        }

        $this->get('/s/pub')->assertStatus(429);

        // The token is the only credential the renderer holds, so the relief is
        // keyed on it and not on the source address.
        $this->get('/s/pub?preview_token=RIGHT')->assertOk();
        $this->get('/s/pub', [ShowStatusPageController::PREVIEW_TOKEN_HEADER => 'RIGHT'])->assertOk();
    }

    public function test_the_token_path_gets_its_own_bucket_rather_than_no_limit_at_all(): void
    {
        $this->makePageWithMonitor('pub', isPublic: true, previewToken: 'RIGHT');

        // Relief is a separate bucket, not an exemption: the token path rebuilds
        // the whole read model per request (it bypasses the 60s cache) behind a
        // token that is never rotated, so it must stay bounded.
        for ($i = 0; $i < 30; $i++) {
            $this->get('/s/pub', [ShowStatusPageController::PREVIEW_TOKEN_HEADER => 'RIGHT'])->assertOk();
        }

        $this->get('/s/pub', [ShowStatusPageController::PREVIEW_TOKEN_HEADER => 'RIGHT'])->assertStatus(429);

        // And that bucket is the token path's own: a visitor did not pay for it.
        $this->get('/s/pub')->assertOk();
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

        // The model generates a token on create when none was supplied, so a
        // genuinely tokenless page (the subject of the hash_equals('','') guard)
        // has to be written back as NULL afterwards. A mass update fires no
        // model events, so nothing refills it.
        if ($previewToken === null) {
            StatusPage::query()->whereKey($page->getKey())->update(['preview_token' => null]);
            $page->refresh();
        }

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
