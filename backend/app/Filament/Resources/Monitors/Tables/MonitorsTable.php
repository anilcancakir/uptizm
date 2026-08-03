<?php

namespace App\Filament\Resources\Monitors\Tables;

use App\Enums\MonitorStatus;
use App\Filament\Resources\Monitors\MonitorResource;
use App\Models\Monitor;
use App\Models\Team;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The cross-team monitor list.
 *
 * The team control is a `SelectFilter` and is ADVISORY: it narrows what the
 * operator is looking at and is not an authorization boundary. See
 * {@see MonitorResource} for the boundary that is.
 *
 * There is no bulk action. A monitor delete cascades its whole check history
 * (`monitor_checks` is FK'd on it) and, for a catalog monitor, silently removes
 * the own-measurement a published service page is required to carry, so the
 * per-row `EditAction` plus the delete on the edit page is as much destructive
 * reach as this list gets.
 */
class MonitorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Protocol')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => mb_strtoupper(
                        is_object($state) ? (string) $state->value : (string) $state,
                    )),
                /*
                 * `last_status` is the HEALTH cast (up/down/degraded/paused) and is
                 * null until the first check lands. Null renders as a dash rather
                 * than as a status, matching the no-data treatment the dashboard and
                 * the status pages already use: a monitor that has never been probed
                 * is not "up" and must not read as though it were.
                 */
                TextColumn::make('last_status')
                    ->label('Health')
                    ->badge()
                    ->placeholder('-')
                    ->color(fn (?MonitorStatus $state): string => match ($state) {
                        MonitorStatus::Up => 'success',
                        MonitorStatus::Down => 'danger',
                        MonitorStatus::Degraded => 'warning',
                        MonitorStatus::Paused, null => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('last_checked_at')
                    ->label('Last checked')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('regions')
                    ->label('Regions')
                    ->badge()
                    ->state(fn (Monitor $record): int => count($record->regions ?? [])),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('team_id')
                    ->label('Team')
                    ->options(fn (): array => Team::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
