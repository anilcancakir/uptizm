<?php

namespace App\Filament\Resources\Monitors;

use App\Filament\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Filament\Resources\Monitors\Pages\ListMonitors;
use App\Filament\Resources\Monitors\Schemas\MonitorForm;
use App\Filament\Resources\Monitors\Tables\MonitorsTable;
use App\Models\Monitor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Staff-facing CRUD over EVERY monitor in the product, customer-owned ones
 * included.
 *
 * WHY IT IS NOT SCOPED TO A TEAM
 *
 * The service catalog's own monitors live on the system team while every other
 * row belongs to a paying customer, and an operator debugging a probe needs to
 * reach both from one list. So there is no `team_id` filter in the base query
 * and no Filament tenancy (`Panel::tenant()` scopes every query to a single
 * tenant, which is the exact opposite of this). Narrowing is a table FILTER,
 * which is advisory and operator-chosen, never a security boundary. The access
 * control for this whole surface is `User::canAccessPanel()` (config allowlist,
 * verified address, confirmed second factor); see
 * `tests/Feature/Admin/StaffGateTest.php`.
 *
 * WHY AUTHORIZATION IS SKIPPED, WHICH IS NOT THE SAME AS UNGATED
 *
 * `App\Policies\MonitorPolicy` exists and Laravel DISCOVERS it by convention
 * (`App\Models\Monitor` to `App\Policies\MonitorPolicy`), even though the class
 * docblock says it is not registered in a policy map. Its `view()`, `update()`
 * and `delete()` all read `$user->current_team_id === $monitor->team_id`,
 * because they were written as a backstop for the customer API. Filament checks
 * a policy method whenever one exists
 * (`vendor/filament/filament/src/helpers.php:60-64`), so leaving it in play
 * would 403 the edit page for every monitor outside the operator's own current
 * team, which is nearly all of them, while the list page (no `viewAny()` on the
 * policy) still rendered fine. That reads as a Filament bug rather than as an
 * inherited API policy, so the exemption is declared here and pinned by
 * `tests/Feature/Admin/MonitorResourceTest.php`, whose case asserts BOTH that
 * the resource permits the cross-team edit AND that the raw
 * `Gate::allows('update')` still refuses it. The second half is what keeps the
 * first from passing vacuously if the policy is ever widened.
 *
 * CREDENTIALS ARE NEVER RENDERED HERE
 *
 * `monitors.auth_config` is `encrypted:array`. The form shows only the auth TYPE
 * and whether a credential is present, mirroring the redaction
 * `app/Http/Resources/MonitorResource.php` already applies on the API. The
 * mechanics of keeping the plaintext out of the page live in
 * {@see EditMonitor::mutateFormDataBeforeFill()}, which is where the leak
 * actually happens, and the reasoning is written there.
 */
class MonitorResource extends Resource
{
    protected static ?string $model = Monitor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * See the class docblock: the discovered `MonitorPolicy` is team-scoped and
     * would refuse every cross-team edit this resource exists to allow.
     */
    protected static bool $shouldSkipAuthorization = true;

    public static function form(Schema $schema): Schema
    {
        return MonitorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MonitorsTable::configure($table);
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
            'index' => ListMonitors::route('/'),
            'create' => CreateMonitor::route('/create'),
            'edit' => EditMonitor::route('/{record}/edit'),
        ];
    }
}
