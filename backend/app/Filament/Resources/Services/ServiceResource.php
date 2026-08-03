<?php

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * The staff-only catalog resource over {@see Service}: List, Create and Edit,
 * the six-file shape `docs/03-resources/01-overview.md` describes.
 *
 * This is a cross-team resource by design: services are owned by the
 * catalog, not by any customer team, so it carries no tenancy and no
 * `team_id` scope (the plan's own Must NOT for this step).
 */
class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }

    /**
     * Refuse a submitted `is_published => true` unless both
     * {@see Service::canPublish()} preconditions are met, checked against
     * data that has NOT been persisted yet rather than the model itself.
     *
     * {@see ServiceForm} disables the `is_published` toggle client-side as a
     * courtesy, but a disabled Livewire field is not a validation boundary:
     * a request can still submit `is_published => true` directly, which is
     * exactly the shape `tests/Feature/Admin/ServiceResourceTest.php` exercises.
     * Both {@see CreateService} and
     * {@see EditService} call this from
     * their `mutateFormDataBefore{Create,Save}()` hook, BEFORE any row is
     * written, so the refusal happens before persistence rather than as a
     * revert afterwards.
     *
     * `$data['terms_reviewed_at']` is an ordinary dehydrated form field and
     * reflects the value about to be saved. `$selectedMonitorIds` cannot be
     * read the same way: the `monitors` field is a multiple relationship
     * `Select`, and Filament deliberately does not dehydrate a multiple
     * relationship select into form data (it is saved via
     * `saveRelationships()` instead, see
     * `vendor/filament/forms/src/Components/Select.php`'s
     * `dehydrated(fn ... => (! isMultiple()) && isSaved())`), so the
     * caller passes the raw, not-yet-attached selection straight off the
     * Livewire component's own `$data` property instead of a database round
     * trip that would not yet reflect it.
     *
     * @param  array<string, mixed>  $data  The dehydrated form data about to be persisted.
     * @param  list<mixed>  $selectedMonitorIds  The raw, not-yet-attached monitor selection.
     *
     * @throws ValidationException When `is_published` is true but either
     *                             precondition is unmet.
     */
    public static function assertPublishable(array $data, array $selectedMonitorIds): void
    {
        if (! ($data['is_published'] ?? false)) {
            return;
        }

        if (blank($data['terms_reviewed_at'] ?? null)) {
            throw ValidationException::withMessages([
                'data.is_published' => 'Cannot publish: terms have not been reviewed.',
            ]);
        }

        if ($selectedMonitorIds === []) {
            throw ValidationException::withMessages([
                'data.is_published' => 'Cannot publish: no monitor is attached.',
            ]);
        }
    }
}
