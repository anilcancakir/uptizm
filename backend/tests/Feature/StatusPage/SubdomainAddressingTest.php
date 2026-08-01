<?php

namespace Tests\Feature\StatusPage;

use App\Enums\DomainMode;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks status-page addressing at `{slug}.<host>` alongside `<host>/s/{slug}`,
 * and the reserved-label boundary that makes the wildcard host route safe.
 *
 * A slug doubles as a hostname label under `status_pages.subdomain_host`, so
 * without the reserved list a tenant could claim `api` and have `api.<host>`
 * resolve to their page. Three properties are pinned here:
 *
 *   - The subdomain route inherits the SAME fail-closed privacy gate as the
 *     path route (private and unknown are both 404).
 *   - A reserved label 404s on the subdomain route even when the row exists,
 *     and never reaches a database lookup.
 *   - That refusal is deliberately asymmetric: the same row still resolves on
 *     the path route, so adding a word to the list later cannot break a
 *     customer's already-published `<host>/s/<word>` link.
 *
 * The host comes from `STATUS_PAGE_SUBDOMAIN_HOST` in phpunit.xml
 * (`uptizm.test`), because routes are registered at boot, before a test body
 * could set config().
 */
class SubdomainAddressingTest extends TestCase
{
    use RefreshDatabase;

    protected const HOST = 'uptizm.test';

    public function test_public_page_resolves_on_its_subdomain(): void
    {
        $this->makePage('acme', isPublic: true);

        $this->get('http://acme.'.self::HOST.'/')->assertOk();
    }

    public function test_subdomain_route_serves_the_same_page_as_the_path_route(): void
    {
        $page = $this->makePage('acme', isPublic: true);

        $viaSubdomain = $this->get('http://acme.'.self::HOST.'/');
        $viaPath = $this->get('http://'.self::HOST.'/s/acme');

        $viaSubdomain->assertOk();
        $viaPath->assertOk();
        $viaSubdomain->assertSee($page->name);
        $viaPath->assertSee($page->name);
    }

    public function test_both_hosts_advertise_one_canonical_url(): void
    {
        // Two hosts serving byte-identical content is a duplicate: without a
        // request-independent canonical a customer's page competes with itself.
        $this->makePage('acme', isPublic: true);

        $expected = rtrim((string) config('app.url'), '/').'/s/acme';

        $this->get('http://acme.'.self::HOST.'/')
            ->assertSee('<link rel="canonical" href="'.$expected.'">', escape: false);

        $this->get('http://'.self::HOST.'/s/acme')
            ->assertSee('<link rel="canonical" href="'.$expected.'">', escape: false);
    }

    public function test_subdomain_mode_makes_the_subdomain_url_canonical(): void
    {
        $page = $this->makePage('acme', isPublic: true);
        $page->forceFill(['domain_mode' => DomainMode::Subdomain])->save();

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $expected = $scheme.'://acme.'.self::HOST.'/';

        $this->get('http://'.self::HOST.'/s/acme')
            ->assertSee('<link rel="canonical" href="'.$expected.'">', escape: false);
    }

    public function test_unknown_subdomain_is_a_four_oh_four(): void
    {
        $this->get('http://nope.'.self::HOST.'/')->assertNotFound();
    }

    public function test_the_apex_root_is_the_landing_page_not_a_status_page(): void
    {
        // The wildcard host route and the landing page both answer `GET /`. The
        // landing route carries no host constraint, so this pins which one wins
        // on the apex: a page whose slug happened to match would otherwise
        // shadow the marketing site, and a regression here is invisible (both
        // answer 200).
        $this->makePage('acme', isPublic: true);

        $apex = $this->get('http://'.self::HOST.'/');

        $apex->assertOk();
        $apex->assertDontSee('Uptizm Status');
    }

    public function test_the_landing_page_does_not_shadow_a_status_subdomain(): void
    {
        // The mirror of the assertion above: the host-constrained route must win
        // on a subdomain even though an unconstrained `GET /` also exists (the
        // landing page, in routes/marketing.php). This does NOT rest on which
        // file loads first: `RouteCollection` keeps host-constrained routes in
        // their own `$domainRoutes` bucket and matches that bucket before the
        // unconstrained one, so registration order never decided it.
        //
        $page = $this->makePage('acme', isPublic: true);

        $this->get('http://acme.'.self::HOST.'/')->assertSee($page->name);
    }

    public function test_the_compiled_route_collection_keeps_the_subdomain_too(): void
    {
        /*
         * The gap the test above genuinely had: it runs against an UNCACHED collection,
         * and production runs a cached one, because `artisan optimize` is part of every
         * deploy. Those are two different matchers, so a suite that only ever exercises
         * the first cannot speak for the second.
         *
         * It was once reported that the cached matcher goes by registration order and lets
         * the apex route swallow every subdomain. It does not, and this is the assertion
         * that settles it rather than leaving the claim in a comment. The report came from
         * a machine with `status_pages.subdomain_host` unset, where the subdomain route is
         * never registered at all and the apex answers by default: a configuration
         * difference wearing a routing defect's clothes.
         *
         * Compiled in memory rather than through `route:cache`, so the run leaves no
         * artefact behind for the next test or the next developer.
         */
        $this->assertNotNull(
            config('status_pages.subdomain_host'),
            'Subdomain addressing is switched off in this environment, so this test would prove nothing.',
        );

        $compiled = app('router')->getRoutes()->toCompiledRouteCollection(app('router'), app());

        $subdomain = $compiled->match(Request::create('http://acme.'.self::HOST.'/'));
        $apex = $compiled->match(Request::create('http://'.self::HOST.'/'));

        $this->assertSame('status.show.subdomain', $subdomain->getName());
        $this->assertSame('landing', $apex->getName());
    }

    public function test_private_page_on_its_subdomain_is_a_four_oh_four(): void
    {
        $this->makePage('priv', isPublic: false, previewToken: 'RIGHT');

        $this->get('http://priv.'.self::HOST.'/')->assertNotFound();
    }

    public function test_a_valid_preview_token_opens_a_private_page_on_its_subdomain(): void
    {
        $this->makePage('priv', isPublic: false, previewToken: 'RIGHT');

        $this->get('http://priv.'.self::HOST.'/?preview_token=RIGHT')->assertOk();
    }

    public function test_a_reserved_label_is_a_four_oh_four_even_when_the_row_exists(): void
    {
        // The row is written past validation on purpose: the list can grow after
        // a slug was already claimed, and the hostname must stop resolving.
        $this->makePage('api', isPublic: true);

        $this->get('http://api.'.self::HOST.'/')->assertNotFound();
    }

    public function test_a_reserved_label_never_reaches_a_database_lookup(): void
    {
        $this->makePage('api', isPublic: true);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get('http://api.'.self::HOST.'/')->assertNotFound();

        $selects = array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_contains(mb_strtolower((string) $entry['query']), 'from "status_pages"')
                || str_contains(mb_strtolower((string) $entry['query']), 'from `status_pages`'),
        );

        $this->assertSame([], $selects, 'A reserved label must be refused before any status_pages query.');
    }

    public function test_a_reserved_slug_still_resolves_on_the_path_route(): void
    {
        // The asymmetry is the point: no hostname conflict exists on the path
        // route, so an already-published link keeps working.
        $this->makePage('api', isPublic: true);

        $this->get('http://'.self::HOST.'/s/api')->assertOk();
    }

    public function test_store_rejects_a_reserved_slug(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'api',
            'is_public' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');
    }

    public function test_update_rejects_a_reserved_slug(): void
    {
        $team = $this->actingAsTeamMember();
        $page = $this->makePage('acme', isPublic: true, team: $team);

        $response = $this->patchJson('/api/v1/status-pages/'.$page->id, [
            'slug' => 'admin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');
    }

    public function test_subdomain_is_an_accepted_domain_mode(): void
    {
        // Regression: the client's DomainMode enum has shipped `subdomain` as its
        // DEFAULT since it was written, while the backend accepted only
        // `path|custom`, so every subdomain selection was silently 422'd.
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'domain_mode' => 'subdomain',
            'is_public' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.domain_mode', 'subdomain');
    }

    public function test_custom_is_an_accepted_domain_mode(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'domain_mode' => 'custom',
            'custom_domain' => 'status.acme.example',
            'is_public' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.domain_mode', 'custom');
    }

    public function test_an_unknown_domain_mode_is_rejected(): void
    {
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'domain_mode' => 'wildcard',
            'is_public' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('domain_mode');
    }

    public function test_a_created_page_reports_the_schema_default_rather_than_null(): void
    {
        // Regression: the column default is applied by the database, so the model
        // read back null right after create and the API answered
        // `domain_mode: null`. The client's fallback for an unknown wire value is
        // `subdomain`, so a path-addressed page was displayed as
        // subdomain-addressed.
        $this->actingAsTeamMember();

        $response = $this->postJson('/api/v1/status-pages', [
            'name' => 'Acme Status',
            'slug' => 'acme-status',
            'is_public' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.domain_mode', DomainMode::Path->value);
    }

    /**
     * A persisted page with one attached monitor and a day of uptime, mirroring
     * {@see PublicStatusPageTest::makePageWithMonitor()} so both addressing
     * routes are exercised against a page the assembler can actually render.
     */
    protected function makePage(
        string $slug,
        bool $isPublic,
        ?string $previewToken = null,
        ?Team $team = null,
    ): StatusPage {
        $team ??= $this->makeTeam();

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

        $page->preview_token = $previewToken;
        $page->save();

        if ($previewToken === null) {
            StatusPage::query()->whereKey($page->getKey())->update(['preview_token' => null]);
            $page->refresh();
        }

        $monitor = $this->makeMonitor($team);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);
        $this->seedUptime($monitor, now()->format('Y-m-d'));

        return $page;
    }

    protected function makeTeam(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        return $team;
    }

    protected function actingAsTeamMember(): Team
    {
        $team = $this->makeTeam();

        Sanctum::actingAs($team->owner);

        return $team;
    }

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
     * One day of uptime for the 90-day bar, written straight to the table
     * because the rollup has no model.
     */
    protected function seedUptime(Monitor $monitor, string $date): void
    {
        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'date' => $date,
            'uptime_percent' => 100.0,
            'total_checks' => 100,
            'failed_checks' => 0,
            'worst_status' => 'operational',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->insert($row);
    }
}
