<?php

namespace Tests\Unit\Services\StatusPages;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\ViewModels\StatusPageViewModel;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Services\StatusPages\StatusPageAssembler;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the security-critical read core of the public status page: the
 * assembler must scope visible components at the query, roll overall status
 * up the ladder while excluding paused monitors, expose only public incident
 * updates, and produce a view-model that provably carries no secret monitor
 * fields (url / auth_config) anywhere in its array form.
 */
class StatusPageAssemblerTest extends TestCase
{
    use RefreshDatabase;

    public function test_overall_status_is_worst_of_visible_components_and_excludes_paused(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $up = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $degraded = $this->makeMonitor($team, ['last_status' => MonitorStatus::Degraded]);
        $paused = $this->makeMonitor($team, ['last_status' => MonitorStatus::Paused]);

        $page->monitors()->attach([
            $up->id => ['display_order' => 0],
            $degraded->id => ['display_order' => 1],
            $paused->id => ['display_order' => 2],
        ]);

        $viewModel = (new StatusPageAssembler)->build($page);

        // Paused monitors are dropped at the query, so they neither appear as
        // components nor influence the overall roll-up.
        $this->assertCount(2, $viewModel->components);
        $this->assertSame('degraded', $viewModel->overallStatus);
    }

    /**
     * A monitor paused through the product is excluded from the public page.
     *
     * The exclusion above passes because its fixture writes `paused` straight
     * into `last_status`, a value no write path produces: `pause()` flips the
     * administrative `status` column and leaves the final reading alone. So the
     * filter ran against a state that cannot occur while the state that does
     * occur went through, and a paused monitor kept publishing its last reading
     * on the customer's public page (a `down` reading rolled the whole page up
     * to a major outage) for an endpoint nothing was probing.
     */
    public function test_a_monitor_paused_through_the_product_is_excluded(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $up = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $pausedWhileDown = $this->makeMonitor($team, [
            'status' => 'paused',
            'last_status' => MonitorStatus::Down,
            'next_check_at' => null,
        ]);

        $page->monitors()->attach([
            $up->id => ['display_order' => 0],
            $pausedWhileDown->id => ['display_order' => 1],
        ]);

        $viewModel = (new StatusPageAssembler)->build($page);

        $this->assertCount(1, $viewModel->components);
        $this->assertSame('operational', $viewModel->overallStatus);
    }

    /**
     * A component whose monitor was DELETED disappears from the page instead of
     * rendering a hole or, worse, keeping its last verdict.
     *
     * The pivot row survives the delete (the monitor is soft-deleted, and nothing
     * detaches it), so the only thing standing between a deleted monitor and the
     * customer-facing page is the relation's own `SoftDeletes` scope. That is
     * exactly the kind of guarantee that holds until someone reaches for
     * `withTrashed()` or a raw join, so it is pinned here.
     */
    public function test_a_deleted_monitor_leaves_the_page_rather_than_a_hole(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $survivor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $deletedWhileDown = $this->makeMonitor($team, ['last_status' => MonitorStatus::Down]);

        $page->monitors()->attach([
            $survivor->id => ['display_order' => 0],
            $deletedWhileDown->id => ['display_order' => 1],
        ]);

        $deletedWhileDown->delete();

        $viewModel = (new StatusPageAssembler)->build($page->fresh());

        $this->assertCount(1, $viewModel->components);
        $this->assertSame(
            'operational',
            $viewModel->overallStatus,
            'a deleted monitor must not keep publishing the outage it was in',
        );
    }

    /**
     * A page with nothing visible on it publishes UNKNOWN, never "operational".
     *
     * The default used to be operational, which is the worst false claim a status
     * page can make: it tells every customer the service is fine on the strength
     * of zero measurements. Both shapes of empty are covered, because they arrive
     * for different reasons: a page nobody attached anything to, and a page whose
     * only component is unpublished.
     */
    public function test_a_page_with_nothing_visible_publishes_unknown(): void
    {
        $team = $this->makeTeam();

        $bare = $this->makePage($team);
        $this->assertSame('unknown', (new StatusPageAssembler)->build($bare)->overallStatus);
        $this->assertCount(0, (new StatusPageAssembler)->build($bare)->components);

        $hiddenOnly = $this->makePage($team);
        $unpublished = $this->makeMonitor($team, [
            'last_status' => MonitorStatus::Up,
            'show_on_status_page' => false,
        ]);
        $hiddenOnly->monitors()->attach([$unpublished->id => ['display_order' => 0]]);

        $viewModel = (new StatusPageAssembler)->build($hiddenOnly->fresh());
        $this->assertCount(0, $viewModel->components);
        $this->assertSame('unknown', $viewModel->overallStatus);
    }

    /**
     * A monitor that has never been probed reads UNKNOWN, not operational.
     *
     * The absence of a measurement is not evidence of health, and this is the
     * state every monitor is in for its first interval, so the claim would be
     * made on every newly published component.
     */
    public function test_a_never_probed_monitor_publishes_unknown_rather_than_health(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $neverProbed = $this->makeMonitor($team, [
            'last_status' => null,
            'next_check_at' => null,
        ]);
        $page->monitors()->attach([$neverProbed->id => ['display_order' => 0]]);

        $viewModel = (new StatusPageAssembler)->build($page->fresh());

        $this->assertCount(1, $viewModel->components);
        $this->assertSame('unknown', $viewModel->components[0]['status']);
        $this->assertSame('unknown', $viewModel->overallStatus);
    }

    /**
     * And the other side of that rule: ONE measured component earns the page a
     * verdict, decided among the measured ones. The unmeasured component still
     * reads `unknown` on its own row, so letting what is known speak hides
     * nothing.
     */
    public function test_one_measured_component_decides_the_banner(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $down = $this->makeMonitor($team, ['last_status' => MonitorStatus::Down]);
        $neverProbed = $this->makeMonitor($team, [
            'last_status' => null,
            'next_check_at' => null,
        ]);
        $page->monitors()->attach([
            $down->id => ['display_order' => 0],
            $neverProbed->id => ['display_order' => 1],
        ]);

        $viewModel = (new StatusPageAssembler)->build($page->fresh());

        $this->assertCount(2, $viewModel->components);
        $this->assertSame('major_outage', $viewModel->overallStatus);
        $this->assertSame('unknown', $viewModel->components[1]['status']);
    }

    public function test_view_model_never_carries_secret_monitor_fields(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, [
            'last_status' => MonitorStatus::Up,
            'url' => 'https://secret.internal/health?token=abc123',
            'auth_config' => [
                'type' => 'bearer',
                'token' => 'super-secret-token',
            ],
        ]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $keys = $this->collectKeys((new StatusPageAssembler)->build($page)->toArray());

        // Fail-closed allowlist: the secret and internal-identifier keys must
        // not surface anywhere in the recursive array form.
        $this->assertNotContains('url', $keys);
        $this->assertNotContains('auth_config', $keys);
        $this->assertNotContains('team_id', $keys);
    }

    public function test_incidents_expose_only_public_updates(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Degraded]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API latency spike',
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::Manual,
            'lifecycle' => IncidentStatus::Investigating,
            'started_at' => now(),
        ]);
        $incident->monitors()->attach([
            $monitor->id => [
                'component_status_at_start' => 'degraded_performance',
                'component_status_current' => 'degraded_performance',
            ],
        ]);

        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'actor' => 'human',
            'author' => 'On-call',
            'status' => IncidentStatus::Investigating,
            'message' => 'Public: we are investigating.',
            'is_public' => true,
            'display_at' => now(),
        ]);
        IncidentUpdate::query()->create([
            'incident_id' => $incident->id,
            'actor' => 'human',
            'author' => 'On-call',
            'status' => IncidentStatus::Investigating,
            'message' => 'Internal: rotate the failing pod.',
            'is_public' => false,
            'display_at' => now(),
        ]);

        $viewModel = (new StatusPageAssembler)->build($page);

        $entries = [];
        foreach ($viewModel->incidents as $group) {
            foreach ($group['entries'] as $entry) {
                $entries[] = $entry;
            }
        }

        $this->assertCount(1, $entries);
        $this->assertCount(1, $entries[0]['updates']);
        $this->assertSame('Public: we are investigating.', $entries[0]['updates'][0]['message']);
    }

    public function test_each_component_strip_has_ninety_entries(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $viewModel = (new StatusPageAssembler)->build($page);

        $this->assertCount(90, $viewModel->components[0]['strip']);
    }

    public function test_view_model_round_trips_through_a_plain_array(): void
    {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        $monitor = $this->makeMonitor($team, ['last_status' => MonitorStatus::Up]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $original = (new StatusPageAssembler)->build($page);
        $rehydrated = StatusPageViewModel::fromArray($original->toArray());

        // The cache store runs with serializable_classes => false, so Step 5
        // persists toArray() and rehydrates via fromArray(); the round-trip must
        // reproduce the exact array form.
        $this->assertEquals($original->toArray(), $rehydrated->toArray());
    }

    /**
     * A brand colour that is not a plain hex literal never reaches the view.
     *
     * Both status partials interpolate this value into an inline
     * `style="background-color: {{ ... }}"`. Blade escapes HTML entities, so
     * attribute break-out is impossible, but `red; background-image: url(...)`
     * contains nothing HTML-special and would survive as a SECOND CSS
     * declaration, making a tenant-controlled string fetch a remote resource
     * from inside the headless browser that renders the preview PNG. That is the
     * exact SSRF shape StatusPageRenderTest forbids at the markup level.
     *
     * The write paths already validate the pattern, so this is not reachable
     * through the API. The guard exists because a seeder, an import or a console
     * command bypasses the request rules, and the render-safety control must not
     * depend on validation staying correct forever. The value is written with a
     * mass update here precisely to model those bypass paths.
     *
     * The payloads are all nine characters or shorter, and that is not arbitrary:
     * the column is `string('brand_color', 9)`, which PostgreSQL enforces and
     * SQLite ignores. So a long CSS injection cannot reach the column on the engine
     * production runs at all, and testing one here would only have proved that
     * SQLite is lax. What the sanitiser is actually for is a hostile value that
     * FITS, which is what these cases are.
     *
     * @param  string  $brandColor  The stored value.
     * @param  string|null  $expected  What the read model should carry.
     */
    #[DataProvider('brandColorProvider')]
    public function test_only_a_hex_brand_color_survives_into_the_read_model(
        string $brandColor,
        ?string $expected,
    ): void {
        $team = $this->makeTeam();
        $page = $this->makePage($team);

        DB::table('status_pages')
            ->where('id', $page->id)
            ->update(['brand_color' => $brandColor]);

        $viewModel = (new StatusPageAssembler)->build($page->fresh());

        $this->assertSame($expected, $viewModel->page['brand_color']);
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function brandColorProvider(): array
    {
        return [
            'six digit hex passes' => ['#008560', '#008560'],
            'eight digit hex passes' => ['#008560ff', '#008560ff'],
            'uppercase hex passes' => ['#00AB60', '#00AB60'],
            'a second declaration is dropped' => ['red;x:y', null],
            'a bare url is dropped' => ['url(#x)', null],
            'a css import is dropped' => ['#008560;', null],
            'a bare keyword is dropped' => ['inherit', null],
            'a named colour is dropped' => ['red', null],
            'a hex without the hash is dropped' => ['008560', null],
            'an empty string is dropped' => ['', null],
        ];
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
     * Creates a public status page for the given team.
     */
    protected function makePage(Team $team): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => 'uptizm-'.Str::random(6),
            'brand_color' => '#008560',
            'logo_text' => 'Uptizm',
            'description' => 'Live service status.',
            'is_public' => true,
            'subscriptions_enabled' => true,
        ]);
    }

    /**
     * Creates a monitor owned by the given team, shown on the status page by
     * default, with caller overrides merged over the baseline.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeMonitor(Team $team, array $overrides = []): Monitor
    {
        return Monitor::query()->create(array_merge([
            'team_id' => $team->id,
            'name' => 'Component '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
        ], $overrides));
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

    /**
     * Recursively collects every string array key so a scan can assert the
     * absence of secret / internal keys anywhere in the structure.
     *
     * @param  array<mixed>  $data
     * @return array<int, string>
     */
    protected function collectKeys(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->collectKeys($value));
            }
        }

        return $keys;
    }
}
