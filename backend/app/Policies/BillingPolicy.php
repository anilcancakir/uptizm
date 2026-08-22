<?php

namespace App\Policies;

use App\Http\Controllers\Api\V1\BillingController;
use App\Models\Team;
use App\Models\User;
use App\Providers\AppServiceProvider;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Who may spend the team's money.
 *
 * This policy answers ONE question, and the reason it is a single question is
 * that the billing surface splits cleanly in two. The five read endpoints
 * (plan, catalog, usage, invoices, card) are open to any member on purpose: a
 * member is who the upgrade nudges are shown to, and a member who cannot see
 * the usage a nudge is derived from cannot act on it. The four write endpoints
 * (`checkout`, `swap`, `cancel`, `portal`) move money, end a paid period, or
 * open a session that can do both, and those belong to the one person who
 * agreed to be charged: the team OWNER.
 *
 * Ownership is {@see HasTeams::ownsTeam()}, which is
 * `users.id === teams.user_id`, and none of the trait's three membership
 * predicates is interchangeable with it. {@see HasTeams::belongsToTeam()} reads
 * `ownedTeams` MERGED with `teams`, so it is true for owners and members alike
 * and would authorize exactly the caller this policy exists to refuse;
 * {@see HasTeams::hasTeamRole()} is the opposite trap, since it reads the pivot
 * only and its own comment records that owners are deliberately not checked
 * there, so an owner with no pivot row would be refused their own billing.
 *
 * There is no `before()` admin escape hatch. A support operator acting on a
 * customer's card is a decision with a paper trail attached, and it belongs to
 * the admin panel where that trail exists, not to a silent bypass on the
 * customer-facing API.
 *
 * NOT registered as the Team model's policy, and that is not an oversight:
 * `MagicStarterServiceProvider` already binds `Gate::policy(Team::class,
 * TeamPolicy::class)` for the team-management abilities, so a second
 * `Gate::policy()` on the same model would silently REPLACE it and unguard
 * member management, invitations, and team deletion. This policy is reached
 * through a named ability instead; the binding and the reasoning live in
 * {@see AppServiceProvider::boot()}, and {@see BillingController} is the only
 * caller.
 */
class BillingPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user may make a billing change for the team.
     *
     * @param  User  $user  The acting user.
     * @param  Team  $team  The team whose billing is being changed.
     */
    public function manage(User $user, Team $team): bool
    {
        return $user->ownsTeam($team);
    }
}
