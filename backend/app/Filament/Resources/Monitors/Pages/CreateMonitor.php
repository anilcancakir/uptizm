<?php

namespace App\Filament\Resources\Monitors\Pages;

use App\Filament\Resources\Monitors\MonitorResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create a monitor from the staff panel.
 *
 * WHY THIS PAGE SETS TWO COLUMNS THE FORM DOES NOT OFFER
 *
 * A monitor is only ever picked up by the scheduler through
 * `Monitor::scopeDue()`, which requires `status` = `active` AND a
 * `next_check_at` that has elapsed. `next_check_at` has no database default and
 * is not on the form, so a monitor created here without it would sit inert
 * forever: no checks, no history, no incident, and nothing on screen to explain
 * why. `app/Http/Controllers/Api/V1/MonitorController.php:64-71` arms both
 * columns the same way on the customer create path, and this mirrors it rather
 * than inventing a second convention. `status` comes from the model's own
 * `$attributes` default, so only the clock is set here.
 *
 * Pinned by the create case in `tests/Feature/Admin/MonitorResourceTest.php`,
 * which asserts the new row is returned by `scopeDue()`.
 */
class CreateMonitor extends CreateRecord
{
    protected static string $resource = MonitorResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['next_check_at'] = now();

        return $data;
    }
}
