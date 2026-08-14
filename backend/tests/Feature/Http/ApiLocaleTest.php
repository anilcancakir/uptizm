<?php

namespace Tests\Feature\Http;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The API answers in the caller's language.
 *
 * Until this existed it did not, and the gap was invisible because the Flutter
 * client localizes itself from `assets/lang/*.json`: every string the API sent
 * was either a key the client translated or a value it formatted. The one place
 * that breaks down is text GENERATED on this side, which is the whole AI
 * surface. An operator whose interface was entirely Turkish read an English
 * incident analysis, and `Accept-Language: tr` on the draft endpoint returned
 * English too, measured against the live provider.
 *
 * `RequestLocaleDetector` was already in the codebase but only ran at
 * REGISTRATION, writing `users.locale` once; nothing applied it per request.
 */
class ApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A probe route inside the api group: the assertion is about what the
        // middleware stack did, so it has to be read DURING the request. Reading
        // `app()->getLocale()` afterwards would pass on a stack that never set
        // it, because the test container keeps whatever the config default was.
        Route::middleware('api')->get('/api/v1/testing/locale', fn (): array => [
            'locale' => app()->getLocale(),
        ]);
    }

    public function test_an_authenticated_request_is_answered_in_the_users_language(): void
    {
        $user = $this->makeUser('tr');

        $response = $this->actingAs($user)->getJson('/api/v1/testing/locale');

        $response->assertOk()->assertJson(['locale' => 'tr']);
    }

    public function test_the_stored_preference_outranks_the_browser_header(): void
    {
        // The header is the device's; the stored value is the operator's own
        // choice in the settings screen. A phone left on English must not
        // override the language its owner deliberately picked.
        $user = $this->makeUser('tr');

        $response = $this->actingAs($user)
            ->getJson('/api/v1/testing/locale', ['Accept-Language' => 'en-US,en;q=0.9']);

        $response->assertOk()->assertJson(['locale' => 'tr']);
    }

    public function test_a_guest_falls_back_to_the_negotiated_header(): void
    {
        // Register and the public endpoints have no user yet, and the header is
        // the only signal there is. Negotiated through
        // `RequestLocaleDetector`, so quality weights and a region subtag both
        // resolve rather than being pattern-matched here.
        $response = $this->getJson('/api/v1/testing/locale', [
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
        ]);

        $response->assertOk()->assertJson(['locale' => 'tr']);
    }

    public function test_an_unsupported_language_falls_back_rather_than_being_applied(): void
    {
        // A locale the product does not ship has no catalogue and no
        // `PromptLanguage` name, so applying it blindly would produce a half
        // translated page and ask a model to write in a language nobody
        // reviewed.
        $response = $this->getJson('/api/v1/testing/locale', [
            'Accept-Language' => 'de-DE,de;q=0.9',
        ]);

        $response->assertOk()->assertJson(['locale' => config('app.locale')]);
    }

    public function test_an_empty_stored_preference_falls_through_to_the_header(): void
    {
        // An EMPTY string, not null: the column is NOT NULL with an `'en'`
        // default, so null is unreachable and empty is the value that actually
        // arrives. It matters because `??` would treat `''` as a preference and
        // hand the model an empty language, while `?:` falls through. The same
        // distinction cost this project a blank page title once.
        $user = $this->makeUser('');

        $response = $this->actingAs($user)
            ->getJson('/api/v1/testing/locale', ['Accept-Language' => 'tr']);

        $response->assertOk()->assertJson(['locale' => 'tr']);
    }

    protected function makeUser(string $locale): User
    {
        $user = User::query()->create([
            'name' => 'Locale Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
            'locale' => $locale,
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Locale Team',
            'plan' => 'pro',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user;
    }
}
