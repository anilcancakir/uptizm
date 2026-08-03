<?php

namespace App\Filament\Resources\Teams\Pages;

use App\Filament\Resources\Teams\Schemas\TeamForm;
use App\Filament\Resources\Teams\TeamResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;

    /**
     * `teams.user_id` is a NOT NULL cascading FK
     * (`database/migrations/2026_07_10_000004_create_teams_table.php:15`), and
     * {@see TeamForm} deliberately exposes
     * only `name`, so an owner is never collected from the operator. The team an
     * ops member creates from this panel is theirs to own, the same shape as the
     * system team's owner row: a real user, no membership pivot implied by it.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['personal_team'] = false;

        return $data;
    }
}
