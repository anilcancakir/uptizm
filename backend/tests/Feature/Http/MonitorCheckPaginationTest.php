<?php

namespace Tests\Feature\Http;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the pagination contract of the check-history endpoint.
 *
 * The client renders this list as an infinite scroll, so it walks the pages
 * while the monitor keeps writing new ones. That is exactly the workload OFFSET
 * pagination gets wrong: it addresses a page by counting from the start, so a
 * row inserted at the head between two requests shifts every later row down by
 * one and the second page repeats the last row of the first. On a monitor
 * checked every 60 seconds that is not a rare race, it is the normal case.
 *
 * A cursor names a position in the ordering instead, so it cannot drift. The
 * drift test below is the whole reason this endpoint changed, and it is written
 * so that it FAILS under `paginate()` rather than merely passing under
 * `cursorPaginate()`.
 */
class MonitorCheckPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_response_carries_a_cursor_rather_than_page_numbers(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeChecks($monitor, 5);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/checks?per_page=2");

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 2);
        $this->assertNotNull(
            $response->json('meta.next_cursor'),
            'the client pages by cursor, so the token has to be in the envelope',
        );
        $this->assertNull(
            $response->json('meta.current_page'),
            'an offset envelope beside a cursor one would let a client page by '.
            'number and drift without ever being told',
        );
    }

    public function test_following_the_cursor_returns_the_next_rows_without_overlap(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeChecks($monitor, 6);

        $first = $this->getJson("/api/v1/monitors/{$monitor->id}/checks?per_page=3");
        $cursor = $first->json('meta.next_cursor');

        $second = $this->getJson(
            "/api/v1/monitors/{$monitor->id}/checks?per_page=3&cursor={$cursor}"
        );

        $second->assertOk();
        $firstIds = collect($first->json('data'))->pluck('id')->all();
        $secondIds = collect($second->json('data'))->pluck('id')->all();

        $this->assertCount(3, $secondIds);
        $this->assertSame(
            [],
            array_intersect($firstIds, $secondIds),
            'the two pages must not share a row',
        );
    }

    public function test_a_check_written_between_two_pages_does_not_duplicate_a_row(): void
    {
        // The defect this endpoint had. Read page one, let the monitor record a
        // new check (which lands at the HEAD, because the order is newest
        // first), then read page two. Under `paginate()` the OFFSET 3 that page
        // two asks for now points one row earlier than it did, so the last row
        // of page one comes back again and the client renders it twice.
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeChecks($monitor, 6);

        $first = $this->getJson("/api/v1/monitors/{$monitor->id}/checks?per_page=3");
        $cursor = $first->json('meta.next_cursor');
        $firstIds = collect($first->json('data'))->pluck('id')->all();

        // The monitor keeps working while the reader scrolls.
        $this->makeCheck($monitor, MonitorStatus::Up, 120, now());

        $second = $this->getJson(
            "/api/v1/monitors/{$monitor->id}/checks?per_page=3&cursor={$cursor}"
        );
        $secondIds = collect($second->json('data'))->pluck('id')->all();

        $this->assertSame(
            [],
            array_intersect($firstIds, $secondIds),
            'a row inserted at the head must not push an old row into page two',
        );
    }

    public function test_rows_stay_newest_first_and_the_order_is_total(): void
    {
        // `checked_at` alone is not a unique ordering, and a cursor over a
        // non-unique order can skip or repeat. The tiebreaker is `id`, which
        // this table stores as an ordered UUID for exactly this kind of reason.
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $sameInstant = now()->subMinutes(5);
        foreach (range(1, 4) as $sequence) {
            $this->makeCheck(
                $monitor,
                MonitorStatus::Up,
                100 + $sequence,
                $sameInstant,
            );
        }

        $first = $this->getJson("/api/v1/monitors/{$monitor->id}/checks?per_page=2");
        $cursor = $first->json('meta.next_cursor');
        $second = $this->getJson(
            "/api/v1/monitors/{$monitor->id}/checks?per_page=2&cursor={$cursor}"
        );

        $ids = array_merge(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        );

        $this->assertCount(
            4,
            array_unique($ids),
            'four checks sharing one timestamp must still page as four distinct rows',
        );
    }

    public function test_per_page_is_still_bounded(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);
        $this->makeChecks($monitor, 3);

        $response = $this->getJson(
            "/api/v1/monitors/{$monitor->id}/checks?per_page=5000"
        );

        $response->assertOk();
        $response->assertJsonPath('meta.per_page', 200);
    }

    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }

    protected function makeMonitor(string $teamId): Monitor
    {
        return Monitor::create([
            'team_id' => $teamId,
            'name' => 'API Health '.Str::random(4),
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }

    /**
     * Records [$count] checks a minute apart, oldest first.
     */
    protected function makeChecks(Monitor $monitor, int $count): void
    {
        foreach (range(1, $count) as $offset) {
            $this->makeCheck(
                $monitor,
                MonitorStatus::Up,
                100 + $offset,
                now()->subMinutes($count - $offset + 1),
            );
        }
    }

    protected function makeCheck(
        Monitor $monitor,
        MonitorStatus $status,
        ?int $responseMs,
        mixed $checkedAt = null,
    ): MonitorCheck {
        return MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east',
            'checked_at' => $checkedAt ?? now()->subMinutes(2),
            'status' => $status,
            'status_code' => $status === MonitorStatus::Up ? 200 : 503,
            'response_ms' => $responseMs,
            'probe_run_id' => 'cp-'.Str::random(8),
        ]);
    }
}
