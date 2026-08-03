<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\ServiceResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit a catalog service.
 *
 * Enforces {@see ServiceResource::assertPublishable()} before the record is
 * saved: {@see ServiceForm} disables
 * the `is_published` toggle client-side only as a UI courtesy, and a
 * disabled Livewire field is not a validation boundary, so a request that
 * crafts `is_published => true` directly (bypassing the disabled control
 * entirely) must be refused here the same way.
 * `tests/Feature/Admin/ServiceResourceTest.php` proves this with a request
 * built that way rather than through the form's own toggle state.
 */
class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Same reasoning as CreateService::mutateFormDataBeforeCreate(): the
        // `monitors` multiple relationship Select is not dehydrated into
        // $data, so its selection is read off the Livewire component's own
        // raw `data` property instead.
        ServiceResource::assertPublishable($data, $this->data['monitors'] ?? []);

        return $data;
    }
}
