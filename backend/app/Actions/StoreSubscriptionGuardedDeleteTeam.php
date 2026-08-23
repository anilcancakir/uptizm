<?php

namespace App\Actions;

use App\Enums\BillingProvider;
use App\Enums\Plan;
use App\Enums\PlanStatus;
use App\Http\Controllers\Api\V1\BillingController;
use App\Models\Team;
use App\Providers\AppServiceProvider;
use FlutterSdk\MagicStarter\Actions\DeleteTeam;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Wraps the starter's {@see DeleteTeam} with a refusal while a STORE is still
 * billing the team.
 *
 * Bound over the {@see DeletesTeams} contract in {@see AppServiceProvider} (the
 * contract-action override pattern, the same one the responder cap uses over the
 * invite contract), because team deletion is the starter's endpoint and uptizm
 * owns no team route to put a guard on.
 *
 * WHY DELETION IS DIFFERENT ON THIS RAIL
 *
 * On the card rail, deleting the team is the end of the story: we are the
 * merchant, so nothing keeps charging once the row is gone. A store subscription
 * lives in the customer's App Store or Play account, which this application
 * cannot cancel and cannot even see: RevenueCat's webhook is the only thing that
 * tells us anything about it. So deleting the team removes the entitlement while
 * the store keeps taking money every month, and the only person who can stop it
 * is the owner, in the store's own account surface. Refusing the deletion until
 * they have been there is the difference between an ex-customer and a customer
 * being charged for nothing.
 *
 * The refusal is thrown BEFORE `parent::delete()` runs, which is load-bearing:
 * the starter's action detaches every member and deletes every invitation before
 * it deletes the team, so a guard placed after it would leave a team that still
 * exists and that nobody belongs to.
 *
 * A {@see ValidationException} rather than a 409, matching the refusal the
 * starter's own endpoint already raises for a personal team: one endpoint
 * answering two refusals in two shapes would make the client's error handling
 * depend on which one it hit.
 */
class StoreSubscriptionGuardedDeleteTeam extends DeleteTeam
{
    /**
     * Delete the team, unless a store is still billing it.
     *
     * @param  Model  $team  The team to delete.
     *
     * @throws ValidationException When a live store subscription funds the team.
     */
    public function delete(Model $team): void
    {
        if (self::storeIsBilling($team)) {
            throw ValidationException::withMessages([
                'team' => 'A store subscription is still billing this team. Cancel it in the store account that bought it first: deleting the team now would remove the plan and leave the store charging you, and this app cannot cancel it for you.',
            ])->errorBag('deleteTeam');
        }

        parent::delete($team);
    }

    /**
     * Whether a store is billing this team RIGHT NOW.
     *
     * Three conditions, and every one of them matters:
     *
     * - the rail is a store, read through {@see BillingProvider::fromWire()}
     *   because `plan_provider` is an uncast column by design and a value this
     *   build has never heard of has to land on `None` instead of raising;
     * - the tier is above Free, and
     * - {@see PlanStatus::grants()} says the plan is still owed to them, which
     *   keeps a dunning subscription (`past_due`, `grace`) inside the guard: the
     *   rail is still trying to take the money, which is exactly the state where
     *   deleting the team strands a charge.
     *
     * `plan_provider` is PROVENANCE and it survives the subscription ending, so
     * a guard on the rail alone would refuse to delete every team that ever
     * bought in a store, forever.
     *
     * The tier half goes through {@see Team::entitledPlan()} on purpose. A
     * revoked team now stores NULL rather than `'free'`, and that reader answers
     * `Plan::Free` for both, so a team the store stopped billing is deletable
     * whichever of the two shapes its row carries. Reading the raw column here
     * would have to say what a NULL means all over again, and get the same
     * answer.
     *
     * It is `public static` and read from {@see BillingController} as well, which
     * is deliberate rather than convenient: that endpoint asks the same question
     * of the caller's OTHER teams, and two copies of "a store is billing this
     * team" would be two definitions of a rule that decides whether money keeps
     * moving. Its natural home is a method on {@see Team}, and that is where it
     * should move the next time that file is in scope.
     *
     * @param  Model  $team  Any team-shaped model; a non-uptizm one answers false.
     */
    public static function storeIsBilling(Model $team): bool
    {
        if (! $team instanceof Team) {
            return false;
        }

        if (! BillingProvider::fromWire($team->plan_provider)->isStore()) {
            return false;
        }

        return $team->entitledPlan() !== Plan::Free
            && PlanStatus::fromWire($team->plan_status)->grants();
    }
}
