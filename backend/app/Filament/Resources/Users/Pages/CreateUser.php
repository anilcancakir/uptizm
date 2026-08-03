<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\Services\SystemTeam;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

/**
 * `UserForm` carries no password field ({@see UserResource} for why), but
 * `users.password` is NOT NULL, so a staff-created row needs one from
 * somewhere. A random, unrecorded 64-character password, hashed by the
 * model's `password => 'hashed'` cast and never surfaced anywhere, is the
 * same shape {@see SystemTeam::provisionOwner()} uses for the same reason:
 * there is no plaintext to leak, and the person this row belongs to reaches
 * their own account through the product's own reset flow, not through a
 * value staff typed on their behalf.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Str::password(64);

        return $data;
    }
}
