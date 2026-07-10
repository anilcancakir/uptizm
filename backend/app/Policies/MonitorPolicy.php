<?php

namespace App\Policies;

use App\Http\Controllers\Api\V1\MonitorController;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Team-scoped authorization backstop for {@see Monitor}.
 *
 * {@see MonitorController} and its sibling controllers already guard team
 * ownership inline via `authorizeTeam()` (masking a foreign monitor as
 * 404), so this policy is not registered in a policy map; it exists as a
 * reusable `Gate::allows()` entry point should a future caller (a queued
 * job, an artisan command) need the same check outside the HTTP layer.
 */
class MonitorPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the monitor.
     */
    public function view(User $user, Monitor $monitor): bool
    {
        return $user->current_team_id === $monitor->team_id;
    }

    /**
     * Determine whether the user can update the monitor.
     */
    public function update(User $user, Monitor $monitor): bool
    {
        return $user->current_team_id === $monitor->team_id;
    }

    /**
     * Determine whether the user can delete the monitor.
     */
    public function delete(User $user, Monitor $monitor): bool
    {
        return $user->current_team_id === $monitor->team_id;
    }
}
