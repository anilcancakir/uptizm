<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staff panel's access gate: {@see User::canAccessPanel()}.
 *
 * The panel's login page answers publicly on `config('uptizm.admin_host')` and the
 * console behind it carries cross-team CRUD over every user, team and monitor, so
 * this gate is the whole of the access control. Three conditions must ALL hold: the
 * address is in `config('uptizm.staff_emails')`, the address is verified, and a
 * second factor is CONFIRMED.
 *
 * WHY THE ALLOW CASE COMES FIRST IN THIS FILE
 *
 * Six of the cases below assert a denial, and `canAccessPanel()` returning `false`
 * unconditionally would satisfy every one of them. The first test is therefore the
 * positive control that gives the other six their meaning: break the gate open in
 * either direction and this suite goes red, rather than only in the direction that
 * locks staff out.
 *
 * WHAT IS PINNED BEYOND THE FOUR REQUIRED BRANCHES
 *
 * Three properties that are easy to lose silently and expensive to lose at all:
 * that an unverified address is refused (none of the four branches covers the
 * second condition on its own); that a two-factor SECRET without a confirmation is
 * not a second factor, which pins the meaning of the sibling package's
 * `hasEnabledTwoFactorAuthentication()` rather than the fact that it is called; and
 * that a user with no address is refused even when the allowlist carries an empty
 * entry, which is the branch keeping a guest out given that `hasVerifiedEmail()`
 * returns true for one.
 */
class StaffGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The allowlisted address every case below is built around.
     */
    protected const STAFF_EMAIL = 'ops@uptizm.com';

    public function test_an_allowlisted_verified_user_may_access_the_panel(): void
    {
        $this->allowlist([self::STAFF_EMAIL]);

        $this->assertTrue($this->staffUser()->canAccessPanel($this->panel()));
    }

    /**
     * A second factor is NOT required, and this test is the record of that being
     * a decision rather than an omission.
     *
     * The gate asked for a confirmed second factor until 2026-08-15 and no
     * longer does. What remains in front of a console with cross-team CRUD, on a
     * login page reachable from the public internet, is the allowlist, a
     * verified address and a password, so a leaked password is now enough on its
     * own. That is accepted while the account surface is reworked, and restoring
     * it is one condition on the gate plus this test flipping back.
     */
    public function test_a_confirmed_second_factor_is_not_required(): void
    {
        $this->allowlist([self::STAFF_EMAIL]);

        $user = $this->staffUser();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->assertTrue($user->canAccessPanel($this->panel()));
    }

    public function test_a_user_absent_from_the_allowlist_is_denied(): void
    {
        // The branch the system user from the service-catalog work relies on: it is
        // verified by nothing and allowlisted by nobody, and this is the property
        // that keeps it out. Asserted as a property rather than against that row,
        // because the row is created by a step running in parallel with this one.
        $this->allowlist(['someone.else@uptizm.com']);

        $this->assertFalse($this->staffUser()->canAccessPanel($this->panel()));
    }

    public function test_an_empty_allowlist_denies_an_otherwise_perfect_user(): void
    {
        // The fail-closed direction, and the reason the gate asserts membership
        // instead of treating an absent list as "no restriction configured".
        // `UPTIZM_STAFF_EMAILS` is unset by default, so this is the shipped state.
        $this->allowlist([]);

        $this->assertFalse($this->staffUser()->canAccessPanel($this->panel()));
    }

    public function test_an_unverified_address_is_denied(): void
    {
        $this->allowlist([self::STAFF_EMAIL]);

        $user = $this->staffUser();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_the_allowlist_matches_case_insensitively_and_tolerates_whitespace(): void
    {
        /*
         * `config/uptizm.php` normalises the env list at load, so this array is
         * injected in its RAW form on purpose: that is exactly the shape a test
         * (or a hand-edited config cache) hands the gate, and it is why the gate
         * normalises both sides again instead of trusting what it was given.
         */
        $this->allowlist([
            '  OPS@Uptizm.COM ',
        ]);

        $user = $this->staffUser(['email' => 'Ops@UPTIZM.com']);

        $this->assertTrue($user->canAccessPanel($this->panel()));
    }

    public function test_a_user_with_no_address_is_denied_even_against_an_empty_allowlist_entry(): void
    {
        /*
         * `hasVerifiedEmail()` returns true unconditionally for a guest, so the
         * "verified" condition cannot be what keeps one out. This is what does:
         * a guest's `email` is force-filled to `null` by `CreateGuestUser`, and a
         * null address must not match an empty allowlist entry through the loose
         * comparison a hand-edited list makes reachable.
         */
        $this->allowlist(['']);

        $user = $this->staffUser([
            'email' => null,
            // Null, so the guest bypass below is the only thing that can make
            // `hasVerifiedEmail()` true and the control asserts something.
            'email_verified_at' => null,
        ]);
        $user->forceFill(['is_guest' => true])->save();

        $this->assertTrue($user->hasVerifiedEmail(), 'The guest bypass this case exists for is gone.');
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_the_gate_answers_only_for_the_staff_panel(): void
    {
        // A second panel must state its own rule rather than inherit staff
        // semantics, so an unknown panel is refused even for a perfect staff user.
        $this->allowlist([self::STAFF_EMAIL]);

        $other = Panel::make()->id('customer');

        $this->assertFalse($this->staffUser()->canAccessPanel($other));
    }

    public function test_the_staff_panel_id_matches_the_registered_panel(): void
    {
        /*
         * `User::STAFF_PANEL_ID` repeats the id `AdminPanelProvider` declares. If
         * the panel is ever renamed, the gate would refuse every member of staff
         * on a panel it no longer recognises, which reads as a login problem
         * rather than as a constant left behind. This is the coupling made visible.
         */
        $this->assertSame(User::STAFF_PANEL_ID, Filament::getDefaultPanel()->getId());
        $this->assertSame(User::STAFF_PANEL_ID, $this->panel()->getId());
    }

    public function test_the_panel_http_path_actually_consults_the_gate(): void
    {
        /*
         * Everything above proves the METHOD decides correctly. This pair proves
         * the panel asks it, which is the failure a unit-level suite cannot see: a
         * gate that returns false and is never consulted reads exactly like a gate
         * that works. The refusal is Filament's own
         * `abort_if(! $user->canAccessPanel($panel), 403)`
         * (`vendor/filament/filament/src/Http/Middleware/Authenticate.php:34-41`),
         * whose fallback WITHOUT this contract on the model is
         * `config('app.env') !== 'local'`: it would admit every authenticated user
         * with no allowlist consulted at all.
         *
         * The 200 is the control. Without it the 403 could be coming from any
         * other middleware on the panel, or from a route that stopped existing.
         */
        $this->allowlist(['someone.else@uptizm.com']);

        $this->actingAs($this->staffUser())
            ->get($this->panelUrl('/dashboard'))
            ->assertForbidden();

        $this->allowlist([self::STAFF_EMAIL]);

        $this->actingAs($this->staffUser(['email' => 'second.'.self::STAFF_EMAIL]))
            ->get($this->panelUrl('/dashboard'))
            ->assertForbidden();

        $this->actingAs(User::query()->where('email', self::STAFF_EMAIL)->sole())
            ->get($this->panelUrl('/dashboard'))
            ->assertOk();
    }

    public function test_the_panel_refuses_an_unauthenticated_request_by_sending_it_to_its_own_login(): void
    {
        // The panel's login lives on the panel host, so a redirect to the apex
        // would send an operator to the marketing landing page instead.
        $this->get($this->panelUrl('/dashboard'))
            ->assertRedirect($this->panelUrl('/login'));
    }

    /**
     * An absolute URL on the panel host, which is where the panel's routes live.
     *
     * A relative path would be resolved against `APP_URL` (the marketing apex),
     * where the panel deliberately does not answer at all.
     */
    protected function panelUrl(string $path): string
    {
        return 'http://'.config('uptizm.admin_host').$path;
    }

    /**
     * The registered staff panel, resolved by id.
     *
     * Through `Filament::getPanel()` and never `app(Panel::class)`: the container
     * has no binding for that class name, so an auto-resolve hands back a blank
     * panel whose `getId()` throws, and a gate checked against one would prove
     * nothing about the panel that actually serves the host.
     */
    protected function panel(): Panel
    {
        return Filament::getPanel(User::STAFF_PANEL_ID);
    }

    /**
     * Inject a staff allowlist in its raw, un-normalised form.
     *
     * @param  list<string>  $emails
     */
    protected function allowlist(array $emails): void
    {
        config()->set('uptizm.staff_emails', $emails);
    }

    /**
     * A user that satisfies all three conditions, before the case at hand breaks
     * exactly one of them.
     *
     * `two_factor_confirmed_at` is force-filled because it is not mass assignable,
     * which is the same reason `ConfirmTwoFactorAuthentication` force-fills it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function staffUser(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => self::STAFF_EMAIL,
            'email_verified_at' => now(),
        ], $attributes));

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user;
    }
}
