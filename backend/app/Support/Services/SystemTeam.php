<?php

namespace App\Support\Services;

use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves the single internal {@see Team} that owns every monitor behind the
 * public service catalog, provisioning it (and its owner user row) when absent.
 *
 * ## The two ownership columns are NOT the same thing
 *
 * `teams` carries an owner FK and a membership pivot, and conflating them
 * either breaks the insert or re-arms the pager:
 *
 *  - `teams.user_id` is NOT NULL and cascades on delete
 *    (`database/migrations/2026_07_10_000004_create_teams_table.php:15`), so the
 *    team cannot exist without a user row. In production the `users` table may
 *    be empty when this first runs, so the row is created here rather than
 *    borrowed from a human account.
 *  - `Team::users()` is a `belongsToMany` through the `team_user` PIVOT
 *    (`vendor/fluttersdk/magic-starter-laravel/src/Models/Team.php:76-82`), and
 *    that pivot is what every paging path reads.
 *
 * So the shape is: a dedicated owner user (address from
 * `config('uptizm.system_team_email')`, a random password nothing records, no
 * verified email, deliberately absent from `config('uptizm.staff_emails')`) set
 * as `teams.user_id`, and NO `team_user` row at all.
 *
 * "No members" therefore means zero `team_user` rows, and the owner FK does NOT
 * contradict it. Do not "fix" the missing pivot row: it is the safety mechanism,
 * not an oversight.
 *
 * ## Why the empty membership is the safety mechanism
 *
 * A service outage opens an ordinary {@see Incident} on this team, and every
 * paging path already no-ops on an empty relation or a missing policy:
 *
 *  1. `IncidentDispatcher.php:96`, `Notification::send($incident->team->users, ...)`
 *     on an open: an empty collection sends nothing.
 *  2. `IncidentDispatcher.php:105`, the same send on a recovery.
 *  3. `EscalationDispatcher.php:179-183`, the team-default escalation policy
 *     lookup returns null, so `escalate()` returns before queueing a step. This
 *     path is UNGATED by `alert_on_down`, so it is the one the empty team
 *     actually stops.
 *  4. `EscalationDispatcher.php:201-211`, `pageOnCall()` returns without a
 *     schedule, and again without a resolved responder.
 *
 * `IncidentDispatcher::dispatchChannels()` is a fifth, team-scoped rather than
 * relation-scoped: it queries `NotificationChannel` by `team_id` and finds none.
 * Which is why nothing here may ever create a notification channel, an
 * escalation policy or an on-call schedule for this team.
 *
 * The empty pivot is also what keeps the authenticated API away from this team:
 * every team-scoped controller resolves scope through the acting user's
 * `current_team_id` (see `MonitorController`), and no user can have this team
 * there, because the owner cannot authenticate (a random unrecorded password
 * under the reserved `.invalid` TLD, so neither login nor password reset can
 * reach it) and no other user is attached.
 *
 * Pinned by `tests/Feature/Services/SystemTeamTest.php`, which asserts the
 * emptiness AND the paging consequence, each against a mirror control on an
 * ordinary team so a green assertion cannot come from a broken query.
 */
class SystemTeam
{
    /**
     * The system team, provisioned on first call and returned unchanged after.
     *
     * Idempotent: the row is reference data the service catalog cannot work
     * without, so `SystemTeamSeeder` creates it in EVERY environment and this
     * method's create path is the self-heal for a database that never got
     * seeded. Resolution is deploy-time in practice, so no lock is taken; a
     * duplicate would surface immediately in the "exactly one row" assertion.
     */
    public static function resolve(): Team
    {
        $team = Team::query()
            ->where('is_system', true)
            ->orderBy('created_at')
            ->first();

        if ($team !== null) {
            return $team;
        }

        return static::provision();
    }

    /**
     * Create the owner user and the team it owns, in one transaction so a
     * failure can never leave a stray user row behind for the next attempt to
     * trip over.
     */
    protected static function provision(): Team
    {
        return DB::transaction(static function (): Team {
            $owner = static::provisionOwner();

            $team = new Team;

            // `is_system` is deliberately not fillable (it buys unlimited plan
            // caps, see PlanGate::limits()), so this is the one write that may
            // set it. `personal_team` is false: nothing about this team is a
            // person's own workspace, and `HasTeams::personalTeam()` must not
            // hand it back for the owner row.
            $team->forceFill([
                'user_id' => $owner->getKey(),
                'name' => (string) config('uptizm.system_team_name'),
                'personal_team' => false,
                'is_system' => true,
            ])->save();

            return $team;
        });
    }

    /**
     * The user row `teams.user_id` points at, reused when the address already
     * exists so a re-run never collides with the unique email index.
     *
     * The address is lower-cased and trimmed the same way `config/uptizm.php`
     * normalises the staff allowlist, so a stray-whitespace override cannot
     * create a second owner that also fails to match the allowlist comparison.
     *
     * The password is random, hashed by the model cast, and returned to nobody:
     * there is no plaintext to leak and no recovery path, because the default
     * address sits under the reserved `.invalid` TLD and can receive no reset
     * mail. `email_verified_at` stays null, which Step 4's panel gate also
     * refuses on.
     */
    protected static function provisionOwner(): User
    {
        $email = mb_strtolower(trim((string) config('uptizm.system_team_email')));

        $owner = User::query()->where('email', $email)->first();

        if ($owner !== null) {
            return $owner;
        }

        $owner = new User;

        $owner->forceFill([
            'name' => (string) config('uptizm.system_team_name'),
            'email' => $email,
            'password' => Str::password(64),
            'email_verified_at' => null,
        ])->save();

        return $owner;
    }
}
