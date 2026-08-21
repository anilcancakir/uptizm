<?php

namespace Tests\Feature\Http;

use App\Models\EscalationPolicy;
use App\Models\Monitor;
use App\Models\OnCallSchedule;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client-supplied id that is not a valid key must come back as a FIELD ERROR,
 * never as a 500.
 *
 * Laravel runs an `exists` or `unique` rule as a real query, and PostgreSQL
 * raises `SQLSTATE[22P02] invalid input syntax for type uuid` when a non-uuid
 * string is compared against a `uuid` column. So `{"user_id": "x"}` answered 500
 * on five of the six endpoints below, measured against the dev database:
 *
 *     select count(*) as "aggregate" from "team_user"
 *     where "user_id" = x and "team_id" = a26c03f7-...
 *
 * ON SQLITE THIS FILE IS A GUARD, NOT A DISCRIMINATOR. SQLite compares the same
 * input happily and returns no rows, so every case here passed before the fix
 * too; that is exactly why the defect reached production's engine unnoticed. It
 * fails without `App\Support\IdFormat` only under `DB_CONNECTION=pgsql`, which is
 * the job CI runs for this class of bug. Verified both ways against a real
 * PostgreSQL database before this file was committed.
 *
 * The sixth endpoint, `incidents/{id}/assign`, was already correct: it validates
 * with `Rule::in()` over an in-memory list and never puts the value in a query.
 */
class MalformedIdRejectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The malformed value every case sends. Not a uuid, not an integer, so it is
     * rejected under either setting of `magic-starter.use_uuids`.
     */
    private const BAD_ID = 'x';

    public function test_every_write_that_looks_up_an_id_rejects_a_malformed_one(): void
    {
        [$user, $team] = $this->actingAsTeamOwner();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => 'http',
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);
        $schedule = OnCallSchedule::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary',
            'timezone' => 'UTC',
        ]);
        $policy = EscalationPolicy::query()->create([
            'team_id' => $team->id,
            'name' => 'Primary policy',
        ]);
        $page = StatusPage::query()->create([
            'team_id' => $team->id,
            'name' => 'Acme Status',
            'slug' => 'acme-'.$team->id,
            'is_public' => true,
        ]);

        $cases = [
            'rotation user' => [
                "/api/v1/on-call/schedules/{$schedule->id}/rotations",
                ['user_id' => self::BAD_ID, 'position' => 0, 'shift_hours' => 8],
                'user_id',
            ],
            'override user' => [
                "/api/v1/on-call/schedules/{$schedule->id}/overrides",
                [
                    'user_id' => self::BAD_ID,
                    'starts_at' => now()->addHour()->toIso8601String(),
                    'ends_at' => now()->addDay()->toIso8601String(),
                ],
                'user_id',
            ],
            'escalation step target' => [
                "/api/v1/escalation-policies/{$policy->id}/steps",
                [
                    'position' => 0,
                    'delay_minutes' => 0,
                    'target_type' => 'user',
                    'target_id' => self::BAD_ID,
                ],
                'target_id',
            ],
            'manual incident monitor' => [
                '/api/v1/incidents',
                ['monitor_id' => self::BAD_ID, 'severity' => 'critical', 'title' => 'Probe'],
                'monitor_id',
            ],
            'maintenance page' => [
                '/api/v1/scheduled-maintenances',
                [
                    'title' => 'Probe',
                    'status_page_id' => self::BAD_ID,
                    'starts_at' => now()->addHour()->toIso8601String(),
                    'ends_at' => now()->addHours(2)->toIso8601String(),
                ],
                'status_page_id',
            ],
            'maintenance monitor list' => [
                '/api/v1/scheduled-maintenances',
                [
                    'title' => 'Probe',
                    'status_page_id' => $page->id,
                    'starts_at' => now()->addHour()->toIso8601String(),
                    'ends_at' => now()->addHours(2)->toIso8601String(),
                    'monitor_ids' => [self::BAD_ID],
                ],
                'monitor_ids.0',
            ],
            'monitor escalation policy' => [
                '/api/v1/monitors',
                [
                    'name' => 'Probe',
                    'type' => 'http',
                    'url' => 'https://example.com',
                    'method' => 'get',
                    'check_interval_sec' => 60,
                    'regions' => ['us-east'],
                    'escalation_policy_id' => self::BAD_ID,
                ],
                'escalation_policy_id',
            ],
        ];

        foreach ($cases as $label => [$uri, $payload, $field]) {
            $response = $this->postJson($uri, $payload);

            $this->assertSame(
                422,
                $response->status(),
                "{$label} answered {$response->status()} for a malformed id",
            );
            $response->assertJsonValidationErrors([$field], responseKey: 'errors');
        }

        // The monitor exists only so the maintenance case can attach a real one
        // beside the malformed one; asserting it survived keeps the fixture from
        // silently becoming decoration.
        $this->assertTrue($monitor->exists);
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * A team the acting user owns and belongs to, since every rule above scopes
     * its lookup to the caller's current team.
     *
     * @return array{0: User, 1: Team}
     */
    private function actingAsTeamOwner(): array
    {
        $user = User::factory()->create();
        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Probe Team',
            'personal_team' => true,
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($user);

        return [$user, $team];
    }
}
