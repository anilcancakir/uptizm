<?php

namespace App\Filament\Resources\Teams\Schemas;

use App\Models\Team;
use App\Support\Services\SystemTeam;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Edits ONLY {@see Team::$name}.
 *
 * `plan`, `plan_status`, `stripe_id`, `pm_type` and `pm_last_four` are Cashier's
 * write surface and never a field here: a hand-typed plan would desynchronise
 * from Stripe on the next webhook. `user_id`, `personal_team` and `is_system`
 * are ownership/provisioning facts fixed at creation time
 * ({@see SystemTeam::provision()} for the system row, the
 * app's own registration flow for a customer's team), not something staff edits
 * after the fact.
 */
class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
