<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Tests\TestCase;

/**
 * Locks {@see User::preferredLocale()} as the mechanism queued
 * notifications/mailables use to auto-localize to the user's stored
 * `locale` column, per {@see HasLocalePreference}.
 *
 * Exercised against unsaved model instances: the `locale` column carries a
 * DB-level default of `en` (never actually NULL once persisted), so the
 * null-preference contract is a pure PHP-attribute concern here.
 */
class UserLocalePreferenceTest extends TestCase
{
    public function test_user_implements_has_locale_preference(): void
    {
        $this->assertInstanceOf(HasLocalePreference::class, new User);
    }

    public function test_preferred_locale_returns_the_stored_locale(): void
    {
        $user = new User(['locale' => 'tr']);

        $this->assertSame('tr', $user->preferredLocale());
    }

    public function test_preferred_locale_returns_null_when_locale_is_unset(): void
    {
        $user = new User;

        $this->assertNull($user->preferredLocale());
    }
}
