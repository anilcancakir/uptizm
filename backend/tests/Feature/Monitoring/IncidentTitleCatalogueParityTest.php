<?php

namespace Tests\Feature\Monitoring;

use Tests\TestCase;

/**
 * The incident-title sentence lives in FOUR catalogues, and nothing else compares
 * their values.
 *
 * `backend/lang/{en,tr}/incidents.php` is what the server renders from: the stored
 * English on `incidents.title`, the push body in both languages, the database
 * channel per recipient, the public status page. `assets/lang/{en,tr}.json` under
 * `uptizm.incidents.title_*` is what the operator app renders from, and the composer
 * has no reach into it. So one sentence exists twice per locale, on two sides of a
 * wire, maintained by hand.
 *
 * The existing guards do not cover this. `test/config/lang_parity_test.dart` compares
 * KEY SETS between the two Dart files only; the PHP catalogues have their own key-set
 * test; and one English widget assertion pins one key's value. Twelve sentences, one
 * pinned, and the failure is silent in exactly the direction that matters: a Turkish
 * copy fix on the server leaves the app rendering the old wording and nothing goes
 * red.
 *
 * This test is that guard. It fails when a value diverges on either side, and when a
 * seventh key is added to one half and not the other.
 */
class IncidentTitleCatalogueParityTest extends TestCase
{
    /**
     * The name mapping between the two halves.
     *
     * The server keys the file `incidents.php` and reads `incidents.monitor_down`;
     * the client namespaces everything under `uptizm.` and prefixes the entry with
     * `title_` so the incident catalogue can hold non-title copy too. Same sentence,
     * two naming conventions, which is why this cannot be a set comparison.
     */
    protected function clientKey(string $serverKey): string
    {
        return "title_{$serverKey}";
    }

    public function test_every_server_sentence_matches_the_client_one_in_both_locales(): void
    {
        foreach (['en', 'tr'] as $locale) {
            $server = require base_path("lang/{$locale}/incidents.php");
            $client = $this->clientCatalogue($locale);

            $this->assertNotEmpty($server, "lang/{$locale}/incidents.php is empty");

            foreach ($server as $key => $sentence) {
                $clientKey = $this->clientKey($key);

                $this->assertArrayHasKey(
                    $clientKey,
                    $client,
                    "assets/lang/{$locale}.json is missing uptizm.incidents.{$clientKey}, "
                    .'so the app would render that dotted key where a title belongs',
                );

                $this->assertSame(
                    $sentence,
                    $client[$clientKey],
                    "The {$locale} sentence for {$key} differs between the server "
                    ."catalogue and the app's. One of the two was edited alone, and "
                    .'the surfaces would disagree: the push and the status page read '
                    ."the server's, the operator app reads its own.",
                );
            }
        }
    }

    public function test_the_client_carries_no_title_key_the_server_does_not(): void
    {
        // The other direction: a client-only key is a sentence the backend can never
        // ask for, so it is either dead copy or a rename that landed on one side.
        foreach (['en', 'tr'] as $locale) {
            $server = require base_path("lang/{$locale}/incidents.php");
            $expected = array_map(
                fn (string $key): string => $this->clientKey($key),
                array_keys($server),
            );

            $actual = array_keys($this->clientCatalogue($locale));

            sort($expected);
            sort($actual);

            $this->assertSame($expected, $actual, "The {$locale} title key sets diverge");
        }
    }

    /**
     * The `uptizm.incidents.title_*` entries out of the app's shipped catalogue.
     *
     * Read as a FILE rather than through any translator, on both sides, so a defect
     * in either resolver cannot hide inside both halves of the comparison.
     *
     * @return array<string, string>
     */
    protected function clientCatalogue(string $locale): array
    {
        $path = base_path("../assets/lang/{$locale}.json");

        $this->assertFileExists($path, 'The app catalogue moved; this guard needs its new path');

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $incidents = $decoded['uptizm']['incidents'] ?? [];

        return array_filter(
            $incidents,
            fn (string $key): bool => str_starts_with($key, 'title_'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
