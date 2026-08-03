<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Monitor;
use App\Models\Team;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists every {@see Team}: name, personal-team flag, member count, monitor
 * count and created date.
 *
 * `monitors_count` cannot be built with `TextColumn::counts()`, which requires
 * an existing relationship method on the model
 * (`vendor/filament/tables/src/Columns/Concerns/InteractsWithTableQuery.php:18-25`
 * calls `$query->withCount($relationship)`). `Team` (and its base
 * `FlutterSdk\MagicStarter\Models\Team`) declares no `monitors()` relation, and
 * adding one is out of this step's Files list (it is not `app/Models/Team.php`'s
 * subject here). So the count is a raw correlated subquery added in
 * `modifyQueryUsing()` below, selected under the alias the column reads.
 */
class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('personal_team')
                    ->label('Personal')
                    ->boolean(),
                IconColumn::make('is_system')
                    ->label('System')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedShieldCheck)
                    ->trueColor('warning')
                    ->tooltip(fn (Team $record): ?string => $record->is_system
                        ? 'Owns every service monitor behind the public status catalog. Cannot be deleted.'
                        : null),
                TextColumn::make('users_count')
                    ->label('Members')
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('monitors_count')
                    ->label('Monitors')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'monitors_count' => Monitor::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('monitors.team_id', 'teams.id'),
            ]))
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // `authorizeIndividualRecords()` routes each selected record
                    // through `TeamResource::getDeleteAuthorizationResponse()`
                    // (`vendor/filament/filament/src/Resources/Pages/Page.php:329`),
                    // so a bulk selection that happens to include the system team
                    // fails on that record instead of deleting it silently.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords()
                        ->authorizationNotification(),
                ]),
            ]);
    }
}
