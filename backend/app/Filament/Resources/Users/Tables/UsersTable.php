<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The staff user directory: name, email, verification state, team count and
 * created date, searchable on name and email.
 *
 * NO BULK DELETE. A single crafted table-row delete is already a deliberate
 * act; a bulk action that could sweep the system-team owner user in with an
 * ordinary batch is not a convenience worth the cascade it risks (see
 * {@see UserResource} for the cascade this whole guard exists against).
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean(),
                TextColumn::make('teams_count')
                    ->label('Teams')
                    ->counts('teams')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('is_system_team_owner')
                    ->label('System')
                    ->state(fn (User $record): bool => UserResource::isSystemTeamOwner($record))
                    ->boolean()
                    ->trueColor('warning')
                    ->tooltip('Owns the internal team behind the service catalog. Cannot be deleted.'),
            ])
            ->recordActions([
                EditAction::make(),
                UserResource::guardDeleteAction(DeleteAction::make()),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
