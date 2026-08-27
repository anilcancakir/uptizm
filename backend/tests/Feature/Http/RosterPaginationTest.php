<?php

namespace Tests\Feature\Http;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Http\Controllers\Concerns\PagesCollections;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The five team rosters page by CURSOR, and every one of them orders by a
 * column that is not unique.
 *
 * Each roster answered `paginate()` with no `per_page`, so it shipped Laravel's
 * default page and the client treated that page as the whole collection: a Pro
 * team with fifty monitors saw fifteen, its list header said "15 of 15", and
 * the plan gate that compares the cached length against the limit opened at the
 * cap.
 *
 * Every case here is written so it FAILS under the old shape rather than merely
 * passing under the new one. The tie cases matter most: `cursorPaginate` builds
 * its token out of the order-by columns, so two rows sharing a timestamp are a
 * boundary the cursor cannot resolve, and it skips or repeats there with
 * nothing in the response to say so.
 *
 * @see PagesCollections
 */
class RosterPaginationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each roster: its index path, and a maker that persists [$count] rows for
     * a team, all sharing one instant in the ordering column.
     *
     * The third value is the roster's `per_page` ceiling. Incidents cap lower
     * than the rest on purpose: their rows carry eager-loaded relations, so a
     * page of them is far heavier than a page of monitors.
     *
     * @return array<string, array{0: string, 1: callable(string, int): void, 2: int}>
     */
    public static function rosters(): array
    {
        return [
            'monitors' => ['monitors', [self::class, 'makeMonitors'], 200],
            'incidents' => ['incidents', [self::class, 'makeIncidents'], 100],
            'status pages' => ['status-pages', [self::class, 'makeStatusPages'], 200],
            'escalation policies' => ['escalation-policies', [self::class, 'makePolicies'], 200],
            'maintenance' => ['scheduled-maintenances', [self::class, 'makeWindows'], 200],
        ];
    }

    #[DataProvider('rosters')]
    public function test_a_roster_answers_a_cursor_rather_than_page_numbers(
        string $path,
        callable $make,
        int $ceiling,
    ): void {
        unset($ceiling);

        $team = $this->actingAsTeamMember();
        $make($team->id, 5);

        $response = $this->getJson("/api/v1/{$path}?per_page=2");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $this->assertNotNull(
            $response->json('meta.next_cursor'),
            'the client pages by cursor, so the token has to be in the envelope',
        );
        $this->assertNull(
            $response->json('meta.last_page'),
            'an offset envelope beside a cursor one would let a client page by '.
            'a number the cursor does not honour',
        );
    }

    #[DataProvider('rosters')]
    public function test_following_the_cursor_returns_the_rest_without_overlap(
        string $path,
        callable $make,
        int $ceiling,
    ): void {
        unset($ceiling);

        $team = $this->actingAsTeamMember();
        $make($team->id, 5);

        $first = $this->getJson("/api/v1/{$path}?per_page=2");
        $cursor = $first->json('meta.next_cursor');
        $second = $this->getJson("/api/v1/{$path}?per_page=2&cursor={$cursor}");

        $firstIds = collect($first->json('data'))->pluck('id')->all();
        $secondIds = collect($second->json('data'))->pluck('id')->all();

        $this->assertSame([], array_intersect($firstIds, $secondIds));
    }

    #[DataProvider('rosters')]
    public function test_rows_sharing_one_timestamp_still_page_as_distinct_rows(
        string $path,
        callable $make,
        int $ceiling,
    ): void {
        unset($ceiling);

        // The makers above deliberately give every row the SAME value in the
        // ordering column, which is the case the tiebreaker exists for. Without
        // `orderByDesc(id)` beside it this walks the same rows twice and never
        // reaches the rest.
        $team = $this->actingAsTeamMember();
        $make($team->id, 4);

        $ids = [];
        $cursor = null;

        // Bounded rather than `while (true)`: a broken cursor is an infinite
        // loop, and a test that hangs says less than one that fails.
        for ($page = 0; $page < 4; $page++) {
            $query = $cursor === null ? '' : "&cursor={$cursor}";
            $response = $this->getJson("/api/v1/{$path}?per_page=2{$query}");
            $ids = array_merge($ids, collect($response->json('data'))->pluck('id')->all());
            $cursor = $response->json('meta.next_cursor');

            if ($cursor === null) {
                break;
            }
        }

        $this->assertCount(
            4,
            array_unique($ids),
            'four rows sharing one timestamp must page as four distinct rows',
        );
    }

    #[DataProvider('rosters')]
    public function test_per_page_is_bounded(string $path, callable $make, int $ceiling): void
    {
        $team = $this->actingAsTeamMember();
        $make($team->id, 3);

        // Without a ceiling a client asks for one page of everything and the
        // pagination is decoration. Without a floor a `per_page=0` is an
        // endless walk of empty pages.
        $this->getJson("/api/v1/{$path}?per_page=100000")
            ->assertJsonPath('meta.per_page', $ceiling);
        $this->getJson("/api/v1/{$path}?per_page=0")
            ->assertJsonPath('meta.per_page', 1);
    }

    #[DataProvider('rosters')]
    public function test_a_roster_never_pages_into_another_team(
        string $path,
        callable $make,
        int $ceiling,
    ): void {
        unset($ceiling);

        $team = $this->actingAsTeamMember();
        $make($team->id, 2);

        $foreign = Team::query()->create([
            'user_id' => User::query()->create([
                'name' => 'Other Owner',
                'email' => Str::uuid().'@example.com',
                'password' => 'irrelevant',
            ])->id,
            'name' => 'Other Team',
        ]);
        $make($foreign->id, 3);

        $ids = [];
        $cursor = null;

        for ($page = 0; $page < 5; $page++) {
            $query = $cursor === null ? '' : "&cursor={$cursor}";
            $response = $this->getJson("/api/v1/{$path}?per_page=2{$query}");
            $ids = array_merge($ids, collect($response->json('data'))->pluck('id')->all());
            $cursor = $response->json('meta.next_cursor');

            if ($cursor === null) {
                break;
            }
        }

        $this->assertCount(
            2,
            array_unique($ids),
            'paging must not walk out of the team scope the first page applied',
        );
    }

    // -------------------------------------------------------------------------
    // Per-roster: what moved off the client
    // -------------------------------------------------------------------------

    public function test_monitors_filter_by_status_server_side(): void
    {
        $team = $this->actingAsTeamMember();
        self::makeMonitor($team->id, MonitorStatus::Up);
        self::makeMonitor($team->id, MonitorStatus::Down);
        self::makeMonitor($team->id, MonitorStatus::Down);

        $response = $this->getJson('/api/v1/monitors?status=down');

        $response->assertJsonCount(2, 'data');
    }

    public function test_the_monitor_counts_describe_the_fleet_not_the_page(): void
    {
        // The list header says "7 up, 2 down". That is a claim about the FLEET,
        // and a client holding one page of a paginated roster cannot make it:
        // it used to count the rows it had, which were all of them only because
        // the roster was small enough to fit in one accidental page.
        $team = $this->actingAsTeamMember();
        foreach (range(1, 3) as $ignored) {
            self::makeMonitor($team->id, MonitorStatus::Up, 100);
        }
        self::makeMonitor($team->id, MonitorStatus::Down, 400);
        self::makeMonitor($team->id, MonitorStatus::Paused, 999);

        $response = $this->getJson('/api/v1/monitors?per_page=1');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.counts.up', 3);
        $response->assertJsonPath('meta.counts.down', 1);
        $response->assertJsonPath('meta.counts.paused', 1);
        $response->assertJsonPath('meta.counts.degraded', 0);
        // Paused is a switch, not a reading, so its stale response time is not
        // part of the fleet's current latency. (100 * 3 + 400) / 4 = 175.
        $response->assertJsonPath('meta.avg_response_ms', 175);
    }

    public function test_the_monitor_counts_ignore_the_status_filter(): void
    {
        $team = $this->actingAsTeamMember();
        self::makeMonitor($team->id, MonitorStatus::Up);
        self::makeMonitor($team->id, MonitorStatus::Down);

        // The header answers "how many are up", not "how many of what you are
        // looking at are up", so filtering the list must not move it.
        $this->getJson('/api/v1/monitors?status=down')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.counts.up', 1);
    }

    public function test_incidents_search_by_title_server_side(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = self::makeMonitor($team->id, MonitorStatus::Down);
        self::makeIncident($team->id, $monitor, 'Checkout is returning 503s');
        self::makeIncident($team->id, $monitor, 'Marketing site is slow');

        $this->getJson('/api/v1/incidents?q=checkout')
            ->assertJsonCount(1, 'data');
    }

    public function test_an_incident_search_treats_a_percent_sign_as_a_literal(): void
    {
        // A percentage in an incident title is ordinary on this product
        // ("Uptime fell below 99%"), and an unescaped `%` in a LIKE term
        // matches every row, so the search would silently stop filtering.
        $team = $this->actingAsTeamMember();
        $monitor = self::makeMonitor($team->id, MonitorStatus::Down);
        self::makeIncident($team->id, $monitor, 'Uptime fell below 99%');
        self::makeIncident($team->id, $monitor, 'Latency spike on the API');

        $this->getJson('/api/v1/incidents?q=%25')
            ->assertJsonCount(1, 'data');
    }

    public function test_incidents_filter_to_the_open_ones(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = self::makeMonitor($team->id, MonitorStatus::Down);
        self::makeIncident($team->id, $monitor, 'Still burning', IncidentStatus::Detected);
        self::makeIncident($team->id, $monitor, 'Handled', IncidentStatus::Resolved);

        $this->getJson('/api/v1/incidents?open=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.open_total', 1);
    }

    // -------------------------------------------------------------------------
    // Makers. Every row shares one instant in the ordering column on purpose:
    // that is the tie the cursor's tiebreaker exists to resolve.
    // -------------------------------------------------------------------------

    protected function actingAsTeamMember(): Team
    {
        $user = User::query()->create([
            'name' => 'Roster Owner',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);
        $team = Team::query()->create(['user_id' => $user->id, 'name' => 'Roster Team']);
        $team->users()->attach($user->id, ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $team->id])->save();
        Sanctum::actingAs($user);

        return $team;
    }

    public static function makeMonitor(
        string $teamId,
        MonitorStatus $status = MonitorStatus::Up,
        ?int $responseMs = null,
    ): Monitor {
        return Monitor::query()->create([
            'team_id' => $teamId,
            'name' => 'Monitor '.Str::uuid(),
            'type' => 'http',
            'url' => 'https://example.com/'.Str::uuid(),
            'check_interval_sec' => 60,
            'last_status' => $status->value,
            'last_response_ms' => $responseMs,
            'created_at' => '2026-08-01 12:00:00',
        ]);
    }

    public static function makeMonitors(string $teamId, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            self::makeMonitor($teamId);
        }
    }

    public static function makeIncident(
        string $teamId,
        Monitor $monitor,
        string $title,
        IncidentStatus $lifecycle = IncidentStatus::Detected,
    ): Incident {
        return Incident::query()->create([
            'team_id' => $teamId,
            'primary_monitor_id' => $monitor->id,
            'title' => $title,
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => $lifecycle,
            'ai_owned' => false,
            'started_at' => '2026-08-01 12:00:00',
        ]);
    }

    public static function makeIncidents(string $teamId, int $count): void
    {
        $monitor = self::makeMonitor($teamId, MonitorStatus::Down);

        foreach (range(1, $count) as $sequence) {
            self::makeIncident($teamId, $monitor, "Incident {$sequence}");
        }
    }

    public static function makeStatusPages(string $teamId, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            StatusPage::query()->create([
                'team_id' => $teamId,
                'name' => 'Status '.Str::uuid(),
                'slug' => Str::uuid().'-status',
                'created_at' => '2026-08-01 12:00:00',
            ]);
        }
    }

    public static function makePolicies(string $teamId, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            EscalationPolicy::query()->create([
                'team_id' => $teamId,
                'name' => 'Policy '.Str::uuid(),
                'created_at' => '2026-08-01 12:00:00',
            ]);
        }
    }

    public static function makeWindows(string $teamId, int $count): void
    {
        $page = StatusPage::query()->create([
            'team_id' => $teamId,
            'name' => 'Status '.Str::uuid(),
            'slug' => Str::uuid().'-status',
        ]);

        foreach (range(1, $count) as $ignored) {
            ScheduledMaintenance::query()->create([
                'team_id' => $teamId,
                'status_page_id' => $page->id,
                'title' => 'Window '.Str::uuid(),
                'starts_at' => '2026-08-01 12:00:00',
                'ends_at' => '2026-08-01 13:00:00',
            ]);
        }
    }
}
