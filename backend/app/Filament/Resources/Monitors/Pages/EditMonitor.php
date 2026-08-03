<?php

namespace App\Filament\Resources\Monitors\Pages;

use App\Filament\Resources\Monitors\MonitorResource;
use App\Filament\Resources\Monitors\Schemas\MonitorForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit a monitor from the staff panel, without ever putting its credential on
 * the wire.
 *
 * THE LEAK THIS PAGE EXISTS TO CLOSE
 *
 * Omitting `auth_config` from the form is NOT enough, and that is the whole
 * reason this override is here. Filament fills the form from
 * `$record->attributesToArray()`
 * (`vendor/filament/filament/src/Resources/Pages/EditRecord.php:118-124`), which
 * applies the `encrypted:array` cast and therefore hands over the DECRYPTED
 * credential. `Schema::fill()` then assigns that whole array to the page's
 * public `$data` property in one `data_set()`
 * (`vendor/filament/schemas/src/Concerns/HasState.php:331` calling `rawState()`
 * at `:44-56`), keeping every key including the ones no component claims. A
 * public Livewire property is serialised into the page's snapshot, so the
 * plaintext token would ship to the browser on a page that renders no auth field
 * at all. Verified empirically rather than inferred: the `_credential_never...`
 * case in `tests/Feature/Admin/MonitorResourceTest.php` fails on the rendered
 * output the moment this override is removed.
 *
 * WHY THE SAVE PATH NEEDS NO EQUIVALENT
 *
 * `Schema::getState()` builds its result from the validated data plus the
 * DEHYDRATED components only, so a column with no component and no rule is
 * absent from the array handed to `handleRecordUpdate()`, the attribute is never
 * touched, and `save()` leaves the stored ciphertext byte-identical. A second
 * `unset()` in `mutateFormDataBeforeSave()` would look like belt-and-braces and
 * behave like a trap: it would silently swallow the value if someone later adds
 * a deliberate credential-replacement field. The guarantee is asserted instead,
 * by comparing the raw column before and after a save.
 */
class EditMonitor extends EditRecord
{
    protected static string $resource = MonitorResource::class;

    /**
     * Drop the decrypted credential before it reaches the form state.
     *
     * The read-only summary in {@see MonitorForm} reads the record directly, so
     * removing the key here costs the page nothing it renders.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['auth_config']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
