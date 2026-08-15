<?php

namespace Tests\Unit\Models;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `Incident::scopeActive()` is the SQL half of
 * {@see IncidentStatus::isActive()}, and it has one failure mode that is
 * invisible from the outside: it compiles to `whereNotIn('lifecycle', ...)`, and
 * `whereNotIn` with an EMPTY list matches every row. So an enum change that left
 * `terminalValues()` returning nothing would turn this scope into a no-op, every
 * resolved incident would read as active, and the auto-resolve paths would go
 * looking for work among rows they already closed.
 *
 * Nothing else in the suite would catch that. Every caller of this scope filters
 * further, so a scope that over-matches produces a wrong row rather than an
 * error, which is the same shape as the defects these paths exist to fix.
 */
class IncidentActiveScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scope_keeps_open_incidents_and_drops_resolved_ones(): void
    {
        $monitor = $this->makeMonitor();

        $open = $this->makeIncident($monitor, IncidentStatus::Detected);
        $investigating = $this->makeIncident($monitor, IncidentStatus::Investigating);
        $this->makeIncident($monitor, IncidentStatus::Resolved);

        $this->assertEqualsCanonicalizing(
            [$open->getKey(), $investigating->getKey()],
            Incident::query()->active()->pluck('id')->all(),
        );
    }

    /**
     * Every non-terminal case, not just the two above: a lifecycle added to the
     * enum and forgotten here would be dropped from every active-incident query
     * in the product, which reads as an incident that closed itself.
     */
    public function test_every_non_terminal_lifecycle_survives_the_scope(): void
    {
        $monitor = $this->makeMonitor();
        $expected = [];

        foreach (IncidentStatus::cases() as $status) {
            $incident = $this->makeIncident($monitor, $status);

            if ($status->isActive()) {
                $expected[] = $incident->getKey();
            }
        }

        $this->assertEqualsCanonicalizing($expected, Incident::query()->active()->pluck('id')->all());
    }

    /**
     * The list the scope is built from must never be empty, because
     * `whereNotIn` with an empty list is not a narrower query, it is no query at
     * all. Asserted directly so the failure names the cause instead of surfacing
     * as a resolved incident being reopened somewhere far away.
     */
    public function test_the_terminal_set_is_never_empty(): void
    {
        $this->assertNotEmpty(
            IncidentStatus::terminalValues(),
            'An empty terminal set makes whereNotIn match every row, so the scope stops filtering.',
        );

        $this->assertSame([IncidentStatus::Resolved->value], IncidentStatus::terminalValues());
    }

    private function makeIncident(Monitor $monitor, IncidentStatus $lifecycle): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'Something happened',
            'impact' => IncidentImpact::Minor,
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => $lifecycle,
            'ai_owned' => false,
            'started_at' => now(),
            // Terminal rows carry the stamp the resolve path writes, so the
            // fixture cannot pass by being distinguishable some other way.
            'resolved_at' => $lifecycle->isTerminal() ? now() : null,
        ]);
    }

    private function makeMonitor(): Monitor
    {
        $user = User::query()->create([
            'name' => 'Scope Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Scope Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
        ]);
    }
}
