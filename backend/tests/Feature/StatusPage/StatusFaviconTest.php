<?php

namespace Tests\Feature\StatusPage;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Support\StatusPages\StatusPresentation;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The browser tab is a status light: the favicon and the theme colour carry the
 * page's current status, so a pinned tab tells an operator what happened without
 * being read.
 *
 * The assertions that matter are about AGREEMENT. The banner dot, the favicon and
 * the theme colour are three expressions of one status, and a tab whose colour
 * contradicts the banner above it is worse than no colour at all. So each case
 * pins all three together, and one case pins that every status the presentation
 * map knows has an SVG on disk to point at.
 *
 * The dot is a design token and the tab is a hex, which is not a contradiction: a
 * `<meta>` and an SVG file can neither read a custom property nor be handed a light
 * and a dark value from here, so the tab keeps one hex per status while the page
 * follows the reader's colour scheme. They agree on the status and its family. What
 * this pins is that the pairing per status stays intact.
 */
class StatusFaviconTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: MonitorStatus, 1: string, 2: string, 3: string}>
     */
    public static function statuses(): array
    {
        return [
            'healthy monitor' => [MonitorStatus::Up, 'operational', '#30A556', 'bg-up'],
            'down monitor' => [MonitorStatus::Down, 'major_outage', '#DF202E', 'bg-down'],
            'degraded monitor' => [MonitorStatus::Degraded, 'degraded', '#E69825', 'bg-degraded'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_tab_and_the_banner_agree_on_the_colour(
        MonitorStatus $monitorStatus,
        string $expectedStem,
        string $expectedHex,
        string $expectedDotClass,
    ): void {
        $this->makePage('tab', $monitorStatus);

        $response = $this->get('/s/tab');

        $response->assertOk();
        $response->assertSee('favicon/'.$expectedStem.'-16.svg', escape: false);
        $response->assertSee('<meta name="theme-color" content="'.$expectedHex.'">', escape: false);
        $response->assertSee($expectedDotClass, escape: false);
    }

    public function test_a_page_with_nothing_published_gets_a_neutral_tab(): void
    {
        // The empty page has no verdict, so its tab must not be green either. A
        // green dot in the tab would make the claim the banner refuses to make.
        $page = $this->makePage('neutral', MonitorStatus::Up);
        $page->monitors()->first()->forceFill(['show_on_status_page' => false])->save();
        Cache::forget("status-page:{$page->slug}");

        $response = $this->get('/s/neutral');

        $response->assertOk();
        $response->assertSee('favicon/unknown-16.svg', escape: false);
        $response->assertSee('<meta name="theme-color" content="#79828A">', escape: false);
        $response->assertDontSee('favicon/operational-16.svg', escape: false);
    }

    public function test_every_status_the_map_knows_has_an_svg_on_disk(): void
    {
        // A missing file would 404 the favicon rather than show a wrong colour,
        // which is the better failure, but it is still a failure and it is
        // invisible in the HTML. This is what catches a status added to the map
        // without its artwork.
        $statuses = ['operational', 'degraded', 'partial_outage', 'major_outage', 'unknown'];

        foreach ($statuses as $status) {
            $this->assertSame(
                $status,
                StatusPresentation::faviconStem($status),
                "`{$status}` is not in the presentation map."
            );

            foreach (["{$status}.svg", "{$status}-16.svg"] as $file) {
                $this->assertFileExists(
                    public_path("favicon/{$file}"),
                    "public/favicon/{$file} is missing."
                );
            }
        }
    }

    public function test_an_unrecognised_status_falls_back_to_the_neutral_tab(): void
    {
        // It used to fall back to `operational`, which was chosen for a file that
        // certainly exists. `unknown` has artwork on disk too, and it is the honest
        // answer: a status this map does not recognise is one nobody can vouch for,
        // so the tab must not go green for it. The absence-of-verdict entry is the
        // only fallback that is both safe and true.
        $this->assertSame('unknown', StatusPresentation::faviconStem('something-new'));
        $this->assertSame('#79828A', StatusPresentation::themeColor('something-new'));
        $this->assertSame('bg-paused', StatusPresentation::dotClass('something-new'));
    }

    protected function makePage(string $slug, MonitorStatus $monitorStatus): StatusPage
    {
        $user = User::factory()->create();
        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $page = new StatusPage([
            'team_id' => $team->id,
            'name' => 'Uptizm Status',
            'slug' => $slug,
            'is_public' => true,
            'subscriptions_enabled' => false,
        ]);
        $page->preview_token = null;
        $page->save();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'show_on_status_page' => true,
            'only_show_if_degraded' => false,
            'last_status' => $monitorStatus,
        ]);
        $page->monitors()->attach([$monitor->id => ['display_order' => 0]]);

        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $team->id,
            'date' => now()->format('Y-m-d'),
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

        return $page;
    }
}
