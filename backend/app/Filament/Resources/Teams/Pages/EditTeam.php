<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\TeamResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // `authorizationNotification()` turns a refused mount into a visible
            // danger notification carrying the deny message from
            // `TeamResource::getDeleteAuthorizationResponse()`, instead of just
            // hiding the button: the panel's own QA scenario is "attempt the
            // delete and see why it was refused", not "notice the button is gone".
            DeleteAction::make()
                ->authorizationNotification(),
        ];
    }
}
