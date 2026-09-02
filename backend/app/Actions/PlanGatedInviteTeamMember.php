<?php

namespace App\Actions;

use App\Models\Team;
use App\Providers\AppServiceProvider;
use App\Services\Billing\PlanGate;
use FlutterSdk\MagicStarter\Actions\InviteTeamMember;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Wraps the starter's {@see InviteTeamMember} with the plan's responder cap:
 * a team cannot invite past its allowance (current distinct members plus any
 * outstanding invitations), so a Free team (1 responder = the owner) cannot
 * grow its roster without upgrading.
 *
 * Bound over the {@see InvitesTeamMembers}
 * contract in {@see AppServiceProvider} (the contract-action
 * override pattern), leaving the starter package untouched.
 */
class PlanGatedInviteTeamMember extends InviteTeamMember
{
    public function invite(Authenticatable $user, Model $team, string $email, string $role): Model
    {
        if ($team instanceof Team) {
            $gate = new PlanGate;
            $limit = $gate->responderLimit($team);
            if ($limit !== null) {
                // Committed responders: current distinct members plus outstanding
                // (unaccepted) invitations, so a team cannot over-invite the cap.
                $committed = $gate->respondersUsed($team) + $team->invitations()->count();
                if ($committed >= $limit) {
                    $key = $limit === 1
                        ? 'guards.team.responder_limit_reached_singular'
                        : 'guards.team.responder_limit_reached_plural';

                    throw ValidationException::withMessages([
                        'email' => __($key, ['plan' => $gate->planLabel($team), 'limit' => $limit]),
                    ]);
                }
            }
        }

        return parent::invite($user, $team, $email, $role);
    }
}
