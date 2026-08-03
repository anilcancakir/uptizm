<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\ServiceResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create a catalog service.
 *
 * Enforces {@see ServiceResource::assertPublishable()} before the record is
 * written at all: {@see ServiceForm}
 * disables the `is_published` toggle client-side only as a UI courtesy, and
 * a disabled Livewire field is not a validation boundary, so a request that
 * crafts `is_published => true` directly (bypassing the disabled control
 * entirely) must be refused here the same way.
 * `tests/Feature/Admin/ServiceResourceTest.php` proves this with a request
 * built that way rather than through the form's own toggle state.
 */
class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The `monitors` field is a multiple relationship Select, which
        // Filament deliberately does not dehydrate into $data (it attaches
        // via `saveRelationships()`, called AFTER the record is created), so
        // its not-yet-attached selection is read off the Livewire
        // component's own raw `data` property instead.
        ServiceResource::assertPublishable($data, $this->data['monitors'] ?? []);

        return $data;
    }
}
