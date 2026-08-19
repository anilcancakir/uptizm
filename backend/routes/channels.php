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

/*
|--------------------------------------------------------------------------
| Private per-user channel
|--------------------------------------------------------------------------
|
| Notifications are personal, so they never ride the team channel above: every
| teammate is subscribed to that one. The name is `User::receivesBroadcastNotificationsOn()`,
| written down there rather than left to Laravel's default (the notifiable's class
| name with dots plus its key) so a namespace move cannot silently rename a channel
| the client and this file both hardcode.
|
| Compared as strings because the key is a UUID: `===` on two UUID strings is the
| intended check, and a loose compare between a string and an int id is the kind of
| thing that authorises the wrong row.
|
| The `sanctum` guard is load-bearing. The channel-auth request from the Flutter
| client carries a bearer token, not a session cookie, so the default `web` guard
| would deny every subscription and the bell would silently stay on polling.
|
*/
Broadcast::channel(
    'App.Models.User.{userId}',
    function (User $user, string $userId): bool {
        return (string) $user->id === (string) $userId;
    },
    ['guards' => ['sanctum']],
);

Broadcast::channel(
    'teams.{teamId}',
    function (User $user, string $teamId): bool {
        $team = Team::find($teamId);

        return $team !== null && $user->belongsToTeam($team);
    },
    ['guards' => ['sanctum']],
);
