<?php

namespace Database\Seeders;

use App\Models\User;
use FlutterSdk\MagicStarter\Contracts\CreatesUsers;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Seeder;

/**
 * Seeds the local/dev database.
 *
 * Creates a single demo user, reachable through the same registration
 * action the api/v1/auth/register endpoint uses (so a personal team is
 * created via CreatePersonalTeamListener), for the live Flutter-web
 * verification pass.
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
        if (! app()->environment('local', 'testing')) {
            return;
        }

        // 2. Skip when the demo user already exists, guarding against
        //    duplicate emails on repeated seed runs.
        if (User::query()->where('email', self::DEMO_EMAIL)->exists()) {
            return;
        }

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
}
