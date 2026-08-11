<?php

namespace Tests\Feature\Http;

use App\Models\EscalationPolicy;
use App\Models\Monitor;
use App\Models\OnCallSchedule;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the `22P02: invalid input syntax for type uuid` production defect:
 * `routes/api.php` had no `whereUuid` and no `scopeBindings` anywhere, so
 * implicit route-model binding accepted any string for a parameter whose
 * underlying column is a PostgreSQL `uuid`, PostgreSQL raised on the
 * resulting query, and the caller saw a 500 where the correct answer is 404.
 *
 * `Route::pattern()` in `routes/api.php` now rejects a malformed segment
 * before the route matches at all, so the malformed value never reaches a
 * query. Covers implicit Eloquent binding (`{monitor}`, `{incident}`,
 * `{statusPage}`, `{schedule}`, `{policy}`, `{channel}`, `{maintenance}`, and
 * their nested children) and a manual `findOrFail()` on a plain string
 * parameter (`{suggestion}`).
 *
 * SQLite (the default suite connection) has no native `uuid` column and
 * never raises this error, so this class only proves anything against
 * PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=<db> php artisan test
 * --filter=RouteUuidGuardTest` (see `.claude/rules/backend.md`).
 *
 * IMPORTANT: `pgsql`-only, and this test proves it: a well-formed but
 * non-existent uuid on a PARENT segment resolves to a 404 via a normal
 * `ModelNotFoundException` before the child segment is even substituted, so
 * a nested case with a fake parent id would pass whether or not the CHILD
 * segment is constrained. Every nested case below therefore uses a real,
 * persisted parent so the malformed CHILD segment is what the assertion
 * actually exercises.
 */
class RouteUuidGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact malformed value from the production log line this fix
     * answers: `SQLSTATE[22P02]: invalid input syntax for type uuid: "new'e"`.
     */
    private const string MALFORMED_ID = "new'e";

    /**
     * The top-level cases: the malformed segment is the only id in the URI,
     * so no parent fixture is needed to isolate it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedTopLevelIdRouteProvider(): array
    {
        return [
            'monitor show' => ['GET', 'monitors/'.self::MALFORMED_ID],
            'incident show' => ['GET', 'incidents/'.self::MALFORMED_ID],
            'status page show' => ['GET', 'status-pages/'.self::MALFORMED_ID],
            'scheduled maintenance show' => ['GET', 'scheduled-maintenances/'.self::MALFORMED_ID],
            'on-call schedule show' => ['GET', 'on-call/schedules/'.self::MALFORMED_ID],
            'escalation policy show' => ['GET', 'escalation-policies/'.self::MALFORMED_ID],
            'notification channel show' => ['GET', 'notification-channels/'.self::MALFORMED_ID],
            'ai suggestion dismiss' => ['POST', 'ai-suggestions/'.self::MALFORMED_ID.'/dismiss'],
        ];
    }

    #[DataProvider('malformedTopLevelIdRouteProvider')]
    public function test_a_malformed_top_level_id_answers_404_instead_of_a_database_error(
        string $method,
        string $uri,
    ): void {
        $this->actingAsTeamMember();

        $response = $this->json($method, '/api/v1/'.$uri);

        $response->assertStatus(404);
    }

    public function test_a_malformed_check_id_under_a_real_monitor_answers_404(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $response = $this->getJson("/api/v1/monitors/{$monitor->id}/checks/".self::MALFORMED_ID);

        $response->assertStatus(404);
    }

    public function test_a_malformed_metric_id_under_a_real_monitor_answers_404(): void
    {
        $team = $this->actingAsTeamMember();
        $monitor = $this->makeMonitor($team->id);

        $response = $this->deleteJson("/api/v1/monitors/{$monitor->id}/metrics/".self::MALFORMED_ID);

        $response->assertStatus(404);
    }

    public function test_a_malformed_subscriber_id_under_a_real_status_page_answers_404(): void
    {
        $team = $this->actingAsTeamMember();
        $statusPage = $this->makeStatusPage($team->id);

        $response = $this->deleteJson("/api/v1/status-pages/{$statusPage->id}/subscribers/".self::MALFORMED_ID);

        $response->assertStatus(404);
    }

    public function test_a_malformed_rotation_id_under_a_real_schedule_answers_404(): void
    {
        $team = $this->actingAsTeamMember();
        $schedule = $this->makeSchedule($team->id);

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$schedule->id}/rotations/".self::MALFORMED_ID);

        $response->assertStatus(404);
    }

    public function test_a_malformed_override_id_under_a_real_schedule_answers_404(): void
    {
        $team = $this->actingAsTeamMember();
        $schedule = $this->makeSchedule($team->id);

        $response = $this->deleteJson("/api/v1/on-call/schedules/{$schedule->id}/overrides/".self::MALFORMED_ID);

        $response->assertStatus(404);
    }

    public function test_a_malformed_step_id_under_a_real_policy_answers_404(): void
    {
        $team = $this->actingAsTeamMember();
        $policy = $this->makePolicy($team->id);

        $response = $this->deleteJson("/api/v1/escalation-policies/{$policy->id}/steps/".self::MALFORMED_ID);

        $response->assertStatus(404);
    }

    /**
     * Authenticate as a user whose current team is a freshly created team,
     * matching every other controller test's own convention (there is no
     * shared trait for this helper, see `EscalationPolicyControllerTest` and
     * `NotificationChannelControllerTest`).
     */
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
            'name' => 'API Health',
            'type' => 'http',
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

    protected function makeStatusPage(string $teamId): StatusPage
    {
        return StatusPage::query()->create([
            'team_id' => $teamId,
            'name' => 'Public Status',
            'slug' => Str::uuid().'-status',
        ]);
    }

    protected function makeSchedule(string $teamId): OnCallSchedule
    {
        return OnCallSchedule::create([
            'team_id' => $teamId,
            'name' => 'Primary Ring',
            'timezone' => 'UTC',
        ]);
    }

    protected function makePolicy(string $teamId): EscalationPolicy
    {
        return EscalationPolicy::create([
            'team_id' => $teamId,
            'name' => 'Primary Escalation',
        ]);
    }
}
