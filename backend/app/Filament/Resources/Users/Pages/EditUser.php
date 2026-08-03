<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The header delete action is wired through
 * {@see UserResource::guardDeleteAction()}, the same guard the table row
 * action uses, so the system-team owner user cannot be deleted from either
 * surface. See {@see UserResource} for why.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::guardDeleteAction(DeleteAction::make()),
        ];
    }
}
