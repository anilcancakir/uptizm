<?php

namespace Tests\Feature\Ai;

use App\Jobs\DispatchWeeklyDigests;
use App\Jobs\GenerateWeeklyDigest;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the weekly-digest fan-out: the scheduler had no dispatcher at all, so
 * {@see GenerateWeeklyDigest} was reachable only from a test and
 * `GET /incidents/digest` answered 404 forever on a paying Business plan.
 *
 * The eligibility check belongs at dispatch rather than inside the digest job:
 * a digest generated for a team below the auto tier spends a unit of that team's
 * shared daily AI budget (the same budget triage and the assistant draw on) and
 * persists a row the read endpoint refuses to serve.
 *
 * The handler is invoked directly rather than through `dispatchSync()`: for a
 * `ShouldQueue` job that routes through the `sync` connection, which a queue or
 * bus fake intercepts, so the fan-out would never run and the assertions would
 * pass over an empty list.
 */
class DispatchWeeklyDigestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_one_digest_per_auto_tier_team(): void
    {
        // Business is the only Plan case that reaches the auto tier; `custom`
        // belongs to a contact-sales tier with no enum case behind it.
        $first = $this->makeTeam('business');
        $second = $this->makeTeam('business');

        Bus::fake([
            GenerateWeeklyDigest::class,
        ]);

        (new DispatchWeeklyDigests)->handle(new PlanGate);

        Bus::assertDispatchedTimes(GenerateWeeklyDigest::class, 2);
        foreach ([$first, $second] as $team) {
            Bus::assertDispatched(
                GenerateWeeklyDigest::class,
                fn (GenerateWeeklyDigest $job): bool => $job->teamId === (string) $team->id,
            );
        }
    }

    public function test_it_skips_a_team_below_the_auto_tier(): void
    {
        $this->makeTeam('free');
        $this->makeTeam('pro');

        Bus::fake([
            GenerateWeeklyDigest::class,
        ]);

        (new DispatchWeeklyDigests)->handle(new PlanGate);

        Bus::assertNotDispatched(GenerateWeeklyDigest::class);
    }

    public function test_it_dispatches_only_the_entitled_team_in_a_mixed_roster(): void
    {
        $this->makeTeam('free');
        $entitled = $this->makeTeam('business');
        $this->makeTeam('pro');

        Bus::fake([
            GenerateWeeklyDigest::class,
        ]);

        (new DispatchWeeklyDigests)->handle(new PlanGate);

        Bus::assertDispatchedTimes(GenerateWeeklyDigest::class, 1);
        Bus::assertDispatched(
            GenerateWeeklyDigest::class,
            fn (GenerateWeeklyDigest $job): bool => $job->teamId === (string) $entitled->id,
        );
    }

    public function test_the_dispatched_digest_lands_on_the_ai_queue(): void
    {
        $this->makeTeam('business');

        Bus::fake([
            GenerateWeeklyDigest::class,
        ]);

        (new DispatchWeeklyDigests)->handle(new PlanGate);

        // The `ai` supervisor is the only one Horizon runs these on; a digest
        // pushed to `default` would sit unworked behind the monitoring queues.
        Bus::assertDispatched(
            GenerateWeeklyDigest::class,
            fn (GenerateWeeklyDigest $job): bool => $job->queue === 'ai',
        );
    }

    protected function makeTeam(string $plan): Team
    {
        $user = User::query()->create([
            'name' => 'Digest Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Digest Team',
            'plan' => $plan,
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $team;
    }
}
