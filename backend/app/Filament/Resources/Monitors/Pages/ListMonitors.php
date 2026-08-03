<?php

namespace App\Filament\Resources\Monitors\Pages;

use App\Filament\Resources\Monitors\MonitorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Every monitor in the product, across every team.
 *
 * The cross-team query is the point; see {@see MonitorResource} for why there is
 * no tenant scope and why the team control in the table is a filter rather than
 * a boundary.
 */
class ListMonitors extends ListRecords
{
    protected static string $resource = MonitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
