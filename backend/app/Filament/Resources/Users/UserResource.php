<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Support\Services\SystemTeam;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The staff panel's user directory: read-mostly, name/email/locale editable,
 * every credential surface (password, two-factor secret, recovery codes, API
 * tokens) deliberately absent from the form ({@see UserForm}).
 *
 * WHY NO CREDENTIAL FIELD ON THE FORM
 *
 * A staff member who needs to change a user's password triggers the
 * product's own reset flow instead of typing a new one here, which keeps the
 * one credential path this product already has, and audits, the only one
 * that exists. Exposing `password`, `two_factor_secret` or
 * `two_factor_recovery_codes` here would open a second, unaudited one.
 * `tests/Feature/Admin/UserResourceTest.php` asserts their absence from the
 * RESOLVED schema, not from rendered HTML.
 *
 * THE ONE DELETE GUARD THAT MATTERS
 *
 * {@see SystemTeam::resolve()} names a user row as `teams.user_id` for the
 * internal team that owns every service monitor. That FK CASCADES
 * (`database/migrations/2026_07_10_000004_create_teams_table.php:15`), so
 * deleting that user row would cascade the system team, and that in turn
 * cascades every service monitor, check and incident it owns, wiping the
 * whole subsystem's history from one click on a user row.
 *
 * {@see self::isSystemTeamOwner()} is the single predicate, and
 * {@see self::guardDeleteAction()} is the single place both delete surfaces
 * wire it: the row action in {@see UsersTable} and the header action in
 * {@see EditUser}. The guard is a `DeleteAction::before()` hook that calls
 * `$action->cancel()`, not a hidden or disabled button, because a `before()`
 * hook runs whether or not the button was ever rendered: it is what makes the
 * refusal hold against a crafted Livewire action call, not just an absent
 * one. `UserResourceTest` exercises exactly that: it calls the delete action
 * directly against the system-team owner row rather than only checking the
 * button's visibility.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Whether `$user` is the owner of the internal system team, the one row
     * whose deletion would cascade the whole service-catalog subsystem. See
     * the class docblock for the cascade this guards against.
     */
    public static function isSystemTeamOwner(User $user): bool
    {
        return $user->getKey() === SystemTeam::resolve()->user_id;
    }

    /**
     * Wire the load-bearing delete guard onto a {@see DeleteAction}.
     *
     * Shared by the table row action and the edit-page header action so the
     * rule lives in exactly one place rather than being restated, and
     * unavoidably restated wrong, on a second surface.
     */
    public static function guardDeleteAction(DeleteAction $action): DeleteAction
    {
        return $action->before(function (DeleteAction $action, User $record): void {
            if (! static::isSystemTeamOwner($record)) {
                return;
            }

            Notification::make()
                ->danger()
                ->title('This user cannot be deleted')
                ->body('It owns the internal team behind the service catalog. Deleting it would cascade every service monitor, check and incident that team owns.')
                ->send();

            $action->cancel();
        });
    }
}
