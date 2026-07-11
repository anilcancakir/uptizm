<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Private per-team channel. Authorization uses the CANONICAL membership
| primitive belongsToTeam() from the magic-starter HasTeams trait (owned +
| member teams), NOT the mutable current_team_id UI-state column: a
| multi-team user is authorized on each of their own teams and no other.
| The null-guard makes a non-existent teamId deny uniformly (false => 403)
| instead of throwing.
|
*/

Broadcast::channel(
    'teams.{teamId}',
    function (User $user, string $teamId): bool {
        $team = Team::find($teamId);

        return $team !== null && $user->belongsToTeam($team);
    },
    ['guards' => ['sanctum']],
);
