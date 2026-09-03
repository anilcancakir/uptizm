<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\IncidentEscalated;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The notification-type headings on `/settings/notifications` follow the
 * account's language.
 *
 * `AppServiceProvider` registers each type's `label` as a translation KEY, and
 * `magic-starter-laravel` 0.0.6 resolves it inside the request. Before that
 * release it handed the registered value straight to the response, so a
 * finished English sentence was the only thing a registry could carry and a
 * Turkish operator read "Incident opened" on a screen whose every other
 * string was Turkish. Measured in a browser at the time, not inferred.
 *
 * Two things have to hold together for this to work, and each fails silently
 * on its own, which is why one test asserts both:
 *
 * - The registry has to carry a KEY. Registering a sentence still renders
 *   fine, just always in one language, so nothing complains.
 * - The published package has to be new enough to translate it. On 0.0.5 the
 *   key travels to the client verbatim and the screen shows the raw
 *   `notifications.incident_opened_preference_label`, which is WORSE than the
 *   English it replaced. That is why `backend/composer.json` moved to `^0.0.6`
 *   in the same commit: Composer's caret locks the patch on the `0.0.x` rail,
 *   so `^0.0.5` would never have picked the release up on its own.
 */
class PreferenceLabelLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The three registered types and their expected headings per locale.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function labels(): array
    {
        return [
            'incident opened' => ['incident_opened', 'Olay açıldı'],
            'incident escalated' => ['incident_escalated', 'Olay kötüleşti'],
            'incident resolved' => ['incident_resolved', 'Olay çözüldü'],
        ];
    }

    #[DataProvider('labels')]
    public function test_a_turkish_account_reads_turkish_headings(string $slug, string $expected): void
    {
        $this->actingAsUserWithLocale('tr');

        $response = $this->getJson('/api/v1/notification-preferences');

        $response->assertStatus(200);
        $this->assertSame($expected, $response->json("data.$slug.label"));
    }

    public function test_an_english_account_reads_english_headings(): void
    {
        $this->actingAsUserWithLocale('en');

        $response = $this->getJson('/api/v1/notification-preferences');

        $response->assertStatus(200);
        $this->assertSame('Incident opened', $response->json('data.incident_opened.label'));
        $this->assertSame('Incident escalated', $response->json('data.incident_escalated.label'));
        $this->assertSame('Incident resolved', $response->json('data.incident_resolved.label'));
    }

    public function test_no_heading_reaches_a_client_as_a_raw_translation_key(): void
    {
        // The failure mode this pair of changes could produce if only half of
        // it landed: the registry carries a key, the resolver does not run, and
        // the screen shows the dotted path. Asserted as a shape rather than
        // per label so a fourth registered type is covered the day it is added.
        $this->actingAsUserWithLocale('tr');

        $matrix = $this->getJson('/api/v1/notification-preferences')->json('data');

        $this->assertNotEmpty($matrix);

        foreach ($matrix as $slug => $type) {
            $this->assertIsString($type['label']);
            $this->assertStringNotContainsString(
                'notifications.',
                $type['label'],
                "the heading for \"$slug\" reached the client as a raw key",
            );
        }
    }

    public function test_every_registered_type_declares_a_key_rather_than_a_sentence(): void
    {
        // The other half, read off the registry itself. A sentence renders
        // perfectly and always in one language, so the response assertions
        // above would pass for `en` and fail only for `tr`; this says WHICH
        // side is wrong when they do.
        foreach ([IncidentOpened::class, IncidentEscalated::class, IncidentResolved::class] as $class) {
            $entry = NotificationPreferenceRegistry::get($class);

            $this->assertNotNull($entry, "$class is not registered");
            $this->assertStringStartsWith(
                'notifications.',
                $entry['label'],
                "$class registers a finished sentence, which cannot follow a locale",
            );
        }
    }

    /**
     * Signs in a user whose stored locale is [$locale].
     *
     * The locale is set BEFORE `actingAs`, deliberately: Sanctum binds a user
     * INSTANCE, and both `SetApiLocale` and the package's own
     * `preferredLocale()` read the attribute off that instance, so writing the
     * column afterwards leaves the bound copy carrying the old value and the
     * response comes back in the wrong language.
     */
    protected function actingAsUserWithLocale(string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale]);

        Sanctum::actingAs($user);

        return $user;
    }
}
