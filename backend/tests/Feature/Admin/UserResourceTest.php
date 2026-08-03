<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Team;
use App\Models\User;
use App\Support\Services\SystemTeam;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff panel's user directory: {@see UserResource}.
 *
 * TWO THINGS THIS FILE GOES DEEP ON
 *
 * First, that the form has no reachable credential field. The assertion reads
 * the RESOLVED schema (`assertSchemaComponentDoesNotExist()` walks the
 * component tree Filament actually built), not rendered HTML, so a field that
 * was merely hidden in a Blade view would still fail this test.
 *
 * Second, that the system-team owner user cannot be deleted, and that an
 * ordinary user still can be. `test_deleting_the_system_team_owner_is_refused()`
 * calls the delete action directly rather than checking whether a button is
 * present, because a hidden button is not a refusal: the guard lives in
 * `DeleteAction::before()` ({@see UserResource::guardDeleteAction()}), which
 * runs whether or not the row's button was ever rendered. The mirror control,
 * `test_deleting_an_ordinary_user_is_permitted()`, is what keeps the first
 * assertion honest: a guard that refused every delete would pass the first
 * test on its own.
 */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_exposes_no_password_or_two_factor_secret_fields(): void
    {
        $staff = $this->staffUser();
        $subject = User::factory()->create();

        Livewire::actingAs($staff)
            ->test(EditUser::class, ['record' => $subject->getKey()])
            ->assertSchemaComponentExists('name')
            ->assertSchemaComponentExists('email')
            ->assertSchemaComponentExists('locale')
            ->assertSchemaComponentDoesNotExist('password')
            ->assertSchemaComponentDoesNotExist('two_factor_secret')
            ->assertSchemaComponentDoesNotExist('two_factor_recovery_codes');
    }

    public function test_the_create_form_also_exposes_no_credential_fields(): void
    {
        $staff = $this->staffUser();

        Livewire::actingAs($staff)
            ->test(CreateUser::class)
            ->assertSchemaComponentDoesNotExist('password')
            ->assertSchemaComponentDoesNotExist('two_factor_secret')
            ->assertSchemaComponentDoesNotExist('two_factor_recovery_codes');
    }

    public function test_changing_an_address_from_the_panel_invalidates_its_verification(): void
    {
        /*
         * The product's own profile action nulls `email_verified_at` on any address
         * change (`UpdateUserProfile.php:112-120`). Without the same rule here a panel
         * save produced a state the product guarantees cannot exist: an unconfirmed
         * address wearing a verified stamp.
         *
         * The reason it is an access-control test and not a data-hygiene one:
         * `User::canAccessPanel()` grants the console on
         * `email IN staff_emails AND hasVerifiedEmail() AND 2FA`, so moving an
         * allowlisted address onto another verified, 2FA-enrolled account would have
         * granted that account the panel at runtime, with no deploy. The second half
         * of this test is that exact scenario.
         */
        $staff = $this->staffUser();
        $subject = User::factory()->create([
            'email' => 'before@example.com',
            'email_verified_at' => now(),
            'locale' => 'en',
        ]);

        $this->assertTrue($subject->hasVerifiedEmail());

        Livewire::actingAs($staff)
            ->test(EditUser::class, ['record' => $subject->getKey()])
            ->fillForm([
                'name' => $subject->name,
                'email' => 'after@example.com',
                'locale' => $subject->locale,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $subject->refresh();

        $this->assertSame('after@example.com', $subject->email);
        $this->assertNull($subject->email_verified_at);
        $this->assertFalse($subject->hasVerifiedEmail());
    }

    public function test_moving_an_allowlisted_address_onto_another_account_does_not_hand_it_the_panel(): void
    {
        /*
         * The address moved to must be allowlisted AND held by no existing row.
         *
         * The first version of this test moved the acting staff member's OWN address
         * onto the other account, which `UserForm`'s `->unique(ignoreRecord: true)`
         * rejects outright: the save failed, the address never moved, and the
         * assertion passed just as happily with the `EditUser` hook reverted. It
         * asserted the unique rule, not the fix. A second allowlisted address that no
         * row holds is what actually exercises the takeover path.
         */
        $vacant = 'ops-second@uptizm.com';
        $staff = $this->staffUser();
        config()->set('uptizm.staff_emails', [$staff->email, $vacant]);

        // A second account already satisfying the other two gate conditions, so the
        // allowlisted address is the only thing it still needs.
        $other = User::factory()->create(['email' => 'outsider@example.com', 'locale' => 'en']);
        $other->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('x'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->assertFalse($other->canAccessPanel(Filament::getPanel(User::STAFF_PANEL_ID)));

        Livewire::actingAs($staff)
            ->test(EditUser::class, ['record' => $other->getKey()])
            ->fillForm([
                'name' => $other->name,
                'email' => $vacant,
                'locale' => $other->locale,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $other->refresh();

        // The address DID move, which is what makes the rest of this meaningful.
        $this->assertSame($vacant, $other->email);
        // And it arrived unverified, so the gate still refuses.
        $this->assertNull($other->email_verified_at);
        $this->assertFalse($other->canAccessPanel(Filament::getPanel(User::STAFF_PANEL_ID)));
    }

    public function test_editing_a_users_name_persists_and_leaves_the_password_hash_untouched(): void
    {
        $staff = $this->staffUser();
        // `locale` carries a DB-level default rather than a factory value, so
        // the in-memory model would otherwise read null until refreshed; set
        // it explicitly instead of leaning on a refresh no reader would expect.
        $subject = User::factory()->create(['name' => 'Original Name', 'locale' => 'en']);
        $hashBefore = $subject->password;

        Livewire::actingAs($staff)
            ->test(EditUser::class, ['record' => $subject->getKey()])
            ->fillForm([
                'name' => 'Updated Name',
                'email' => $subject->email,
                'locale' => $subject->locale,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $subject->refresh();

        $this->assertSame('Updated Name', $subject->name);
        // The form never carries a `password` field, so this is the property
        // that a crafted submission cannot slip a re-hash past: the stored
        // hash must be byte-identical to what it was before the edit.
        $this->assertSame($hashBefore, $subject->password);
        $this->assertTrue(Hash::check('password', $subject->password));
    }

    public function test_creating_a_user_through_the_panel_never_types_a_password_by_hand(): void
    {
        $staff = $this->staffUser();

        Livewire::actingAs($staff)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Fresh User',
                'email' => 'fresh.user@uptizm.test',
                'locale' => 'en',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'fresh.user@uptizm.test')->sole();

        $this->assertSame('Fresh User', $created->name);
        // No form field ever carried a password, so the stored hash cannot
        // decode back to anything staff typed; it can only be the random,
        // unrecorded value `CreateUser::mutateFormDataBeforeCreate()` set.
        $this->assertFalse(Hash::check('password', $created->password));
        $this->assertFalse(Hash::check('', $created->password));
    }

    public function test_deleting_the_system_team_owner_is_refused(): void
    {
        $staff = $this->staffUser();
        $systemOwner = User::query()->find(SystemTeam::resolve()->user_id);

        $this->assertTrue(UserResource::isSystemTeamOwner($systemOwner));

        Livewire::actingAs($staff)
            ->test(ListUsers::class)
            ->callTableAction(DeleteAction::class, $systemOwner)
            ->assertNotified();

        $this->assertNotNull($systemOwner->fresh(), 'The system-team owner user must survive the delete attempt.');
        $this->assertNotNull(Team::query()->where('is_system', true)->first(), 'The system team itself must survive too.');
    }

    public function test_deleting_the_system_team_owner_through_the_edit_page_header_action_is_also_refused(): void
    {
        // The table row action is one surface; the edit page's own header
        // action is another, and both must go through
        // `UserResource::guardDeleteAction()`. This is the mirror case for
        // that second surface.
        $staff = $this->staffUser();
        $systemOwner = User::query()->find(SystemTeam::resolve()->user_id);

        Livewire::actingAs($staff)
            ->test(EditUser::class, ['record' => $systemOwner->getKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified();

        $this->assertNotNull($systemOwner->fresh());
    }

    public function test_deleting_an_ordinary_user_is_permitted(): void
    {
        // The mirror control for the two cases above: without this, a guard
        // refusing every delete unconditionally would pass them both.
        $staff = $this->staffUser();
        $ordinary = User::factory()->create();

        $this->assertFalse(UserResource::isSystemTeamOwner($ordinary));

        Livewire::actingAs($staff)
            ->test(ListUsers::class)
            ->callTableAction(DeleteAction::class, $ordinary);

        $this->assertNull($ordinary->fresh(), 'An ordinary user must actually be deletable.');
    }

    /**
     * A user allowlisted, verified and second-factor confirmed, so it can
     * reach the panel the same way `StaffGateTest::staffUser()` builds one.
     * Kept local rather than shared: widening that test's protected helper
     * across suites is a worse trade than restating nine lines here.
     */
    protected function staffUser(): User
    {
        config()->set('uptizm.staff_emails', ['ops@uptizm.test']);

        $user = User::factory()->create([
            'email' => 'ops@uptizm.test',
            'email_verified_at' => now(),
        ]);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        Filament::setCurrentPanel(Filament::getPanel(User::STAFF_PANEL_ID));

        return $user;
    }
}
