<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Services\ServicePageAssembler;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Seeder;

/**
 * Seeds the database: the demo account in local/dev, and the reference data
 * every environment needs.
 *
 * Creates a single demo user, reachable through the same registration
 * action the api/v1/auth/register endpoint uses (so a personal team is
 * created via CreatePersonalTeamListener), for the live Flutter-web
 * verification pass.
 *
 * The environment guard is a positive branch rather than an early return so
 * {@see SystemTeamSeeder} and {@see ServiceCatalogSeeder} can sit OUTSIDE it and
 * still run last. Both halves of that are load-bearing: the system team and its
 * catalog are reference data the service pages cannot work without, so they must
 * exist in production too, and they must be created AFTER the demo seeders,
 * because {@see StatusPageSeeder} picks its demo team with an unordered
 * `Team::query()->first()` and would otherwise be free to hang the demo status
 * page off uptizm's own internal team.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * The demo user's email address, kept stable for the Flutter-web check.
     */
    private const DEMO_EMAIL = 'demo@uptizm.test';

    /**
     * The demo user's password, dev-only, never a real secret.
     */
    private const DEMO_PASSWORD = 'Password123';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Never create the fixed-credential demo account outside local/dev.
        //    Its password is a known constant, so seeding it on a real host
        //    would ship a login-able account; guard before doing any work.
        if (app()->environment('local', 'testing')) {
            // 2. Skip creating the demo user when it already exists, guarding
            //    against duplicate emails on repeated seed runs. The status page
            //    seeder still runs below, since it guards its own slug uniqueness.
            if (! User::query()->where('email', self::DEMO_EMAIL)->exists()) {
                // 3. Create the demo user through the same action the registration
                //    endpoint uses, so behavior (locale defaults, etc.) matches.
                $user = app(CreatesUsers::class)->create([
                    'name' => 'Demo User',
                    'email' => self::DEMO_EMAIL,
                    'password' => self::DEMO_PASSWORD,
                    'password_confirmation' => self::DEMO_PASSWORD,
                ]);

                // 4. Fire Registered so CreatePersonalTeamListener creates the
                //    demo user's personal team, mirroring the register endpoint.
                event(new Registered($user));
            }

            // 5. Seed a public status page for the demo team, behind the same
            //    local/testing guard (see StatusPageSeeder for the detail).
            $this->call(StatusPageSeeder::class);

            // 6. Seed a pending AI suggestion so the AI inbox renders end-to-end,
            //    behind the same local/testing guard (see AiSuggestionSeeder).
            $this->call(AiSuggestionSeeder::class);
        }

        // 7. Reference data, in EVERY environment: the internal team that owns
        //    the public service catalog's monitors. Last on purpose, so the demo
        //    seeders above cannot pick it up as "the first team".
        $this->call(SystemTeamSeeder::class);

        // 8. The catalog itself, also in every environment, and necessarily
        //    AFTER the team above: every service's own-measurement monitor
        //    belongs to it. Each service is seeded UNPUBLISHED with its terms
        //    unreviewed, so this creates nothing publicly visible.
        //
        //    The catalog seeder REFUSES to run without at least two proxy regions
        //    carrying a source, because a monitor seeded below the outage quorum can
        //    never reach consensus. That refusal is correct when someone asks for a
        //    catalog directly, and wrong as a reason to take down the whole seed:
        //    `migrate:fresh --seed` is the documented way to reset a dev database and
        //    most developers here are not working on the catalog at all.
        //
        //    So the precondition is ASKED rather than caught. A try/catch around the
        //    call would have degraded every RuntimeException from anywhere inside the
        //    seeder into a console warning, in every environment, which is the silent
        //    swallow this codebase's conventions forbid. Asking answers the same need
        //    and hides nothing.
        if (ServiceCatalogSeeder::canSeed()) {
            $this->call(ServiceCatalogSeeder::class);
        } else {
            $this->command?->warn(
                'Skipped the service catalog: fewer than '.ServicePageAssembler::MIN_AGREEING_REGIONS
                .' proxy regions carry a source in config(\'proxy.sources\'), so a catalog monitor '
                .'seeded now could never reach outage consensus.',
            );
        }
    }
}
