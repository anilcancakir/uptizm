<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Traits\HasGuestSupport;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasLocalePreference, MustVerifyEmailContract
{
    use ConditionallyUsesUuids;
    use HasApiTokens;
    use HasFactory;
    use HasGuestSupport;
    use HasNotifications;
    use HasProfilePhoto;
    use HasTeams;
    use MustVerifyEmail;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The id of the Filament panel this gate speaks for.
     *
     * Declared by `App\Providers\Filament\AdminPanelProvider::panel()` through
     * `->id('admin')`; repeated here because `canAccessPanel()` receives the panel
     * and must not answer for one it knows nothing about. The two are kept in step
     * by `StaffGateTest::test_the_staff_panel_id_matches_the_registered_panel()`,
     * so renaming the panel fails that test instead of silently locking every
     * member of staff out.
     */
    public const STAFF_PANEL_ID = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_country',
        'locale',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_guest' => 'boolean',
        ];
    }

    /**
     * Get the user's preferred locale for queued notifications and
     * mailables (see {@see HasLocalePreference}).
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Determine whether this user may reach the staff back-office panel.
     *
     * This method is the ONLY control in front of `config('uptizm.admin_host')`.
     * The panel's login page is publicly reachable on that host and the console
     * behind it carries cross-team CRUD over every user, team and monitor in the
     * product, so a false returned here is the difference between a password and a
     * password plus an allowlist plus a second factor. Every branch below is
     * pinned by `tests/Feature/Admin/StaffGateTest.php`.
     *
     * WHY THE CONTRACT ITSELF MATTERS
     *
     * Without `FilamentUser` on this model, `Filament\Http\Middleware\Authenticate`
     * (`vendor/filament/filament/src/Http/Middleware/Authenticate.php:34-41`) falls
     * back to `config('app.env') !== 'local'`, which admits EVERY authenticated
     * user on a developer machine and denies everyone elsewhere with no allowlist
     * involved either way. Implementing it replaces a guess about the environment
     * with a decision about the person.
     *
     * FAIL-CLOSED IN EVERY DIRECTION
     *
     * An empty allowlist denies everyone, which is why membership is asserted
     * rather than absence being treated as permissive, and why an address that
     * normalises to an empty string is rejected before the comparison: `null`
     * would otherwise match an empty entry a hand-edited config could carry.
     * `config/uptizm.php` already lower-cases and trims the list at load, but a
     * test injecting the array through `config()` bypasses that entirely, so both
     * sides are normalised again here. That is deliberate duplication, not
     * distrust of the config file.
     *
     * NO SECOND FACTOR, AND WHAT THAT COSTS
     *
     * This gate required a CONFIRMED second factor until 2026-08-15. It was
     * removed deliberately, not lost, while the account surface is reworked.
     *
     * Be clear about the trade. This panel's login page is reachable from the
     * public internet on its own subdomain, and behind it is cross-team CRUD
     * over every user and every monitor. What is left in front of that is the
     * allowlist, a verified address and a password, so a single leaked or
     * reused password is now enough by itself: the allowlist says WHO may try,
     * and nothing says the person trying is them. A second factor was the only
     * control here that survived a password compromise.
     *
     * Restoring it is one condition:
     *
     *     return $this->hasVerifiedEmail() && $this->hasEnabledTwoFactorAuthentication();
     *
     * and flipping `StaffGateTest::test_a_confirmed_second_factor_is_not_required()`
     * back. If it is restored, read `two_factor_confirmed_at` and nothing else:
     * `EnableTwoFactorAuthentication` writes `two_factor_secret` and NULLS the
     * confirmation, and only `ConfirmTwoFactorAuthentication` sets it, so a
     * secret on its own describes a setup that was started and abandoned.
     *
     * THE ONE HOLE IN "VERIFIED", AND WHAT CLOSES IT
     *
     * `hasVerifiedEmail()` comes from the starter's `MustVerifyEmail` trait, which
     * returns true unconditionally for a guest because a guest has no address to
     * verify. So that condition is vacuous for guests and cannot be the thing
     * keeping one out. What keeps one out is step 2: `CreateGuestUser` force-fills
     * `email` to `null`, so a guest can never normalise to an allowlisted address,
     * and guest auth is not even enabled here (`config/magic-starter.php:47`).
     * Both halves of that reasoning are pinned, so neither can rot unnoticed.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // 1. Answer for the staff panel alone. There is exactly one panel today,
        //    so this branch is unreachable in practice and is here for the day it
        //    is not: a second panel must state its own rule rather than silently
        //    inherit staff semantics, and denial is the safe way to force that.
        if ($panel->getId() !== self::STAFF_PANEL_ID) {
            return false;
        }

        // 2. The candidate address, normalised on this side too. An empty result
        //    (a guest, or a user row with no address) is refused here so it can
        //    never meet an empty allowlist entry further down.
        $email = mb_strtolower(trim((string) $this->email));

        if ($email === '') {
            return false;
        }

        // 3. Allowlist membership. `(array)` guards a scalar override of the config
        //    value; a strict `in_array()` guards the usual loose-comparison traps.
        $allowlist = array_map(
            static fn (mixed $entry): string => mb_strtolower(trim((string) $entry)),
            (array) config('uptizm.staff_emails', []),
        );

        if (! in_array($email, $allowlist, true)) {
            return false;
        }

        // 4. A verified address. NOT a second factor: see the docblock.
        return $this->hasVerifiedEmail();
    }
}
