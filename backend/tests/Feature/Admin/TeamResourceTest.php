<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\TeamResource;
use App\Models\Team;
use App\Models\User;
use App\Support\Services\SystemTeam;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Teams Filament resource: {@see TeamResource}.
 *
 * Two properties matter beyond the ordinary List+Create+Edit shape.
 *
 * The system team ({@see SystemTeam::resolve()}) must never be deletable, panel
 * or not: its owner FK and every service monitor's `team_id` FK cascade
 * (`database/migrations/2026_07_10_000004_create_teams_table.php:15`,
 * `2026_07_11_000001_create_monitors_table.php:28-30`), so one click would wipe
 * the whole service-catalog subsystem's history.
 *
 * The billing columns (`plan`, `plan_status`, `stripe_id`, `pm_type`,
 * `pm_last_four`) are Cashier's write surface and must never be reachable from
 * this resource's form, or a staff edit would desynchronise the row from
 * Stripe on the next webhook.
 */
class TeamResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('uptizm.staff_emails', ['ops@uptizm.com']);

        Filament::setCurrentPanel(Filament::getPanel(User::STAFF_PANEL_ID));
    }

    public function test_a_delete_attempt_on_the_system_team_is_refused(): void
    {
        // A crafted `mountAction('delete')` call, not merely a check that the
        // button is hidden: `TeamResource::getDeleteAuthorizationResponse()` is
        // re-evaluated server-side on every request
        // (`vendor/filament/filament/src/Resources/Pages/Page.php:313`), so this
        // is what actually stands between a staff member and the cascade.
        $systemTeam = SystemTeam::resolve();

        $this->actingAs($this->staffUser());

        Livewire::test(EditTeam::class, ['record' => $systemTeam->getKey()])
            ->mountAction('delete');

        Notification::assertNotified();

        $this->assertModelExists($systemTeam);
        $this->assertFalse(TeamResource::canDelete($systemTeam->fresh()));
    }

    public function test_a_delete_attempt_on_an_ordinary_team_is_permitted(): void
    {
        $team = $this->createOrdinaryTeam();

        $this->actingAs($this->staffUser());

        Livewire::test(EditTeam::class, ['record' => $team->getKey()])
            ->callAction('delete');

        $this->assertModelMissing($team);
        $this->assertTrue(TeamResource::canDelete($this->createOrdinaryTeam()));
    }

    public function test_the_form_edits_only_the_team_name(): void
    {
        $team = $this->createOrdinaryTeam();

        $this->actingAs($this->staffUser());

        Livewire::test(EditTeam::class, ['record' => $team->getKey()])
            ->assertFormFieldExists('name')
            ->assertFormFieldDoesNotExist('plan')
            ->assertFormFieldDoesNotExist('plan_status')
            ->assertFormFieldDoesNotExist('stripe_id')
            ->assertFormFieldDoesNotExist('pm_type')
            ->assertFormFieldDoesNotExist('pm_last_four')
            ->assertFormFieldDoesNotExist('is_system')
            ->assertFormFieldDoesNotExist('user_id');
    }

    public function test_editing_a_team_never_writes_a_billing_or_plan_column(): void
    {
        // The disabled/absent field is not itself a validation boundary
        // (`wisdom.md` item 9 records the same gap for `Service::canPublish()`),
        // so this proves the write path rather than the form's appearance: a
        // save with a crafted `plan` key in the payload must leave the stored
        // plan untouched.
        $team = $this->createOrdinaryTeam();
        // `plan` carries a DB-level default ('free') that Eloquent does not
        // hydrate onto the in-memory instance `create()` returns, only onto
        // a re-fetched row. Refresh first so `$originalPlan` reflects the
        // actual stored value rather than the pre-insert null.
        $team->refresh();
        $originalPlan = $team->plan;

        $this->actingAs($this->staffUser());

        Livewire::test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['name' => 'Renamed Ops'])
            ->call('save');

        $team->refresh();

        $this->assertSame('Renamed Ops', $team->name);
        $this->assertSame($originalPlan, $team->plan);
        $this->assertNull($team->stripe_id);
    }

    public function test_the_table_marks_the_system_team_and_reports_member_and_monitor_counts(): void
    {
        $systemTeam = SystemTeam::resolve();
        $ordinaryTeam = $this->createOrdinaryTeam();
        $ordinaryTeam->users()->attach(User::factory()->create());

        $this->actingAs($this->staffUser());

        Livewire::test(ListTeams::class)
            ->assertCanSeeTableRecords([$systemTeam, $ordinaryTeam])
            ->assertTableColumnStateSet('is_system', true, record: $systemTeam)
            ->assertTableColumnStateSet('is_system', false, record: $ordinaryTeam)
            ->assertTableColumnStateSet('users_count', 0, record: $systemTeam)
            ->assertTableColumnStateSet('users_count', 1, record: $ordinaryTeam);
    }

    public function test_the_three_team_pages_are_reachable(): void
    {
        $team = $this->createOrdinaryTeam();

        Livewire::test(ListTeams::class)->assertSuccessful();
        Livewire::test(CreateTeam::class)->assertSuccessful();
        Livewire::test(EditTeam::class, ['record' => $team->getKey()])->assertSuccessful();
    }

    /**
     * An ordinary (non-system) customer team, matching the shape
     * `tests/Feature/Broadcasting/BroadcastAuthTest::createTeamFor()` already
     * uses elsewhere in this suite.
     */
    protected function createOrdinaryTeam(): Team
    {
        return Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Ops',
            'personal_team' => false,
        ]);
    }

    /**
     * A user satisfying `User::canAccessPanel()`, mirroring
     * `tests/Feature/Admin/StaffGateTest::staffUser()`.
     */
    protected function staffUser(): User
    {
        $user = User::factory()->create([
            'email' => 'ops@uptizm.com',
            'email_verified_at' => now(),
        ]);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user;
    }
}
