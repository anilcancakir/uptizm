<?php

namespace App\Filament\Resources\Teams;

use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Filament\Resources\Teams\Tables\TeamsTable;
use App\Models\Team;
use App\Support\Services\SystemTeam;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The cross-team staff resource for {@see Team}.
 *
 * Two things are deliberately NOT editable here, both enforced in code rather
 * than merely hidden from the form:
 *
 *  - The system team ({@see SystemTeam::resolve()}) can
 *    never be deleted. `teams.user_id` is a NOT NULL cascading FK
 *    (`database/migrations/2026_07_10_000004_create_teams_table.php:15`) and
 *    `monitors.team_id` cascades too
 *    (`database/migrations/2026_07_11_000001_create_monitors_table.php:28-30`),
 *    so removing this one row would silently take every service monitor, check
 *    and incident behind the public status catalog with it.
 *  - `plan`, `plan_status`, `stripe_id`, `pm_type` and `pm_last_four` are
 *    Cashier's write surface ({@see Team::entitledPlan()}, `Billable`), never a
 *    form field on this resource: a staff-typed plan would desynchronise from
 *    Stripe on the very next webhook.
 *
 * WHY THE DELETE GUARD OVERRIDES THE AUTHORIZATION RESPONSE, NOT JUST THE ACTION
 *
 * Filament resolves both the header `DeleteAction` on `EditTeam` and the
 * table's row/bulk delete actions through
 * {@see self::getDeleteAuthorizationResponse()}
 * (`vendor/filament/filament/src/Resources/Pages/Page.php:313,329`), and
 * `Action::isDisabled()` / `isHidden()` re-evaluate that response on every
 * request rather than once at render time. So a crafted `mountAction('delete')`
 * call against the system team's record is refused server-side, not merely
 * absent from the rendered HTML. Pinned by
 * `tests/Feature/Admin/TeamResourceTest.php`.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    /**
     * Skip Gate/policy authorization for every ability except the delete
     * guard this class defines explicitly below.
     *
     * `App\Policies\TeamPolicy` (a magic-starter-laravel publishable stub) is
     * auto-discovered by Laravel's naming convention for EVERY `Team`
     * authorization check, this resource included, even though nothing
     * registers it on purpose. Its `update`/`delete` both gate on
     * `$user->ownsTeam($team)`, which is the CUSTOMER self-service rule (can a
     * team's own owner edit it), not a staff rule. Left unskipped, a staff
     * member could edit or delete only the teams they personally happen to
     * own, which defeats the purpose of a cross-team back office and
     * contradicts this resource's own "no tenancy, no `team_id` scope" design.
     * `getDeleteAuthorizationResponse()` below still runs BEFORE this flag can
     * apply, so the system-team guard is unaffected either way.
     */
    protected static bool $shouldSkipAuthorization = true;

    public static function form(Schema $schema): Schema
    {
        return TeamForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }

    /**
     * Refuse to delete the system team; see the class docblock for the two
     * cascades that make this load-bearing rather than defensive.
     *
     * The guard sits on `getAuthorizationResponse()` rather than on the narrower
     * `getDeleteAuthorizationResponse()` so that EVERY entry point honours it.
     * That is not tidiness, it closes a real divergence: `can()` and
     * `authorize()` call this method DIRECTLY
     * (`vendor/filament/filament/src/Resources/Resource/Concerns/HasAuthorization.php:41-52`),
     * where `$shouldSkipAuthorization` short-circuits to `Response::allow()`
     * before any narrower override could run. Guarding only the delete-specific
     * method left `TeamResource::can('delete', $systemTeam)` answering TRUE while
     * `canDelete()` answered FALSE on the same row. No Filament delete path used
     * the permissive one, so nothing was exploitable, but a future caller
     * reaching for the canonical `can('delete')` would have been told yes.
     *
     * Every real path still routes through here:
     * `canDelete()` -> `getDeleteAuthorizationResponse()` -> this method, and the
     * table's `DeleteBulkAction` resolves per record through
     * `getDeleteAuthorizationResponse()` too
     * (`vendor/filament/filament/src/Resources/Pages/Page.php:329`), so the one
     * guard covers the header action, the bulk action and any direct `can()` or
     * `authorize()` call. Pinned by `tests/Feature/Admin/TeamResourceTest.php`.
     */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        if ($action === 'delete' && $record !== null && $record->is_system) {
            return Response::deny(
                'The system team owns every service monitor, check and incident behind the public '
                .'status catalog. Deleting it would cascade all of them through the `user_id` and '
                .'`team_id` foreign keys, so it cannot be deleted from the panel.',
            );
        }

        return parent::getAuthorizationResponse($action, $record);
    }
}
