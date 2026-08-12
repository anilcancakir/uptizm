<?php

namespace Tests\Unit\Services\Monitoring;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the incident-title seam: the triple a writer persists, the per-locale
 * render six surfaces read, the bare-key rule the Flutter enum depends on, and
 * the truncation that stands between a monitored response body and a
 * `varchar(200)` column.
 *
 * The Turkish assertions here read the catalogue through `__()` rather than
 * hardcoding a sentence twice, EXCEPT where the sentence itself is the contract
 * ({@see self::test_render_localizes_a_composed_title()}): a test that derived
 * the expectation from the same file the code reads would pass over an entry
 * left in English.
 */
class IncidentTitleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact shape a writer persists, key order included: every writer
     * spreads this into a create call, so a renamed slot is a silently dropped
     * column rather than an error.
     */
    public function test_compose_returns_the_stored_english_the_key_and_the_params(): void
    {
        $composed = IncidentTitle::compose(IncidentTitle::MONITOR_DOWN, ['monitor' => 'checkout']);

        $this->assertSame([
            'title' => 'checkout is down',
            'title_key' => 'incidents.monitor_down',
            'title_params' => ['monitor' => 'checkout'],
        ], $composed);
    }

    /**
     * Every one of the six keys resolves to a real sentence in both locales, and
     * the two locales never agree.
     *
     * This is the whole point of the PR stated as an assertion: a catalogue entry
     * left in English, or missing from `lang/tr` altogether (which falls through
     * to the English), fails here rather than shipping.
     */
    public function test_every_key_renders_a_real_sentence_in_both_locales(): void
    {
        foreach ($this->everyKeyWithParams() as $key => $params) {
            $composed = IncidentTitle::compose($key, $params);
            $incident = new Incident([
                'title' => $composed['title'],
                'title_key' => $composed['title_key'],
                'title_params' => $composed['title_params'],
            ]);

            $english = IncidentTitle::render($incident, 'en');
            $turkish = IncidentTitle::render($incident, 'tr');

            // 1. A missing catalogue entry renders its own dotted name, and an
            //    unreplaced placeholder keeps its colon.
            foreach ([$english, $turkish] as $rendered) {
                $this->assertStringNotContainsString('incidents.', $rendered, "[{$key}] rendered a raw key");
                $this->assertStringNotContainsString(':', $rendered, "[{$key}] left a placeholder unreplaced");
            }

            // 2. The defect this seam exists to prevent: Turkish that reads
            //    English.
            $this->assertNotSame($english, $turkish, "[{$key}] renders the same sentence in both locales");
        }
    }

    /**
     * A null `title_key` means a human authored the title, which is also what
     * every row written before this seam looks like. Both read the column back
     * untouched, in any locale, because there is nothing to render from.
     */
    public function test_render_hands_back_an_authored_title_untouched(): void
    {
        $incident = new Incident([
            'title' => 'Payments look slow on the EU edge',
            'title_key' => null,
            'title_params' => null,
        ]);

        $this->assertSame('Payments look slow on the EU edge', IncidentTitle::render($incident));
        $this->assertSame('Payments look slow on the EU edge', IncidentTitle::render($incident, 'en'));
        $this->assertSame('Payments look slow on the EU edge', IncidentTitle::render($incident, 'tr'));
    }

    /**
     * The sentence itself, spelled out rather than read from the catalogue: this
     * is the one assertion a wrong Turkish value has to fail, and reading
     * `lang/tr/incidents.php` to build the expectation would make it unfalsifiable.
     */
    public function test_render_localizes_a_composed_title(): void
    {
        $incident = $this->composedIncident(IncidentTitle::MONITOR_DOWN, ['monitor' => 'checkout']);

        $this->assertSame('checkout is down', IncidentTitle::render($incident, 'en'));
        $this->assertSame('checkout kesintide', IncidentTitle::render($incident, 'tr'));
    }

    /**
     * With no locale argument the render follows the ambient locale, which is
     * what makes the notification path correct: Laravel wraps each recipient's
     * channel build in `withLocale(preferredLocale(...))`, and a call inside a
     * channel method has to see that wrap rather than a language frozen at
     * dispatch.
     */
    public function test_render_follows_the_active_locale_when_none_is_given(): void
    {
        $incident = $this->composedIncident(IncidentTitle::MONITOR_DOWN, ['monitor' => 'checkout']);

        App::setLocale('tr');
        $this->assertSame('checkout kesintide', IncidentTitle::render($incident));

        App::setLocale('en');
        $this->assertSame('checkout is down', IncidentTitle::render($incident));
    }

    /**
     * The extracted value is cut BEFORE it becomes a parameter, so the persisted
     * params hold the already-cut text and no surface re-derives the rule. The
     * composed English is then cut again for the column, which PostgreSQL
     * enforces and SQLite does not.
     */
    public function test_a_long_extracted_value_is_cut_before_it_becomes_a_parameter(): void
    {
        $composed = IncidentTitle::compose(IncidentTitle::METRIC_STRING_VALUE, [
            'metric' => 'Redis state',
            'value' => str_repeat('a', 300),
        ]);

        $this->assertSame(81, mb_strlen($composed['title_params']['value']));
        $this->assertStringEndsWith('…', $composed['title_params']['value']);
        $this->assertLessThanOrEqual(200, mb_strlen($composed['title']));
        $this->assertStringContainsString('Redis state', $composed['title']);
    }

    /** A value that fits is not marked, so a short reported value stays verbatim. */
    public function test_a_short_extracted_value_is_left_alone(): void
    {
        $composed = IncidentTitle::compose(IncidentTitle::METRIC_STRING_VALUE, [
            'metric' => 'Redis state',
            'value' => 'DOWN',
        ]);

        $this->assertSame('DOWN', $composed['title_params']['value']);
        $this->assertSame('Redis state reported "DOWN"', $composed['title']);
    }

    /**
     * The bare-key rule, and the reason it is not negotiable: the persisted key
     * is what crosses the wire, and the Flutter enum has a member for
     * `incidents.ssl_expiring` and none for a suffixed form. The `_one` / `_other`
     * pair is a catalogue detail the resolver owns.
     */
    public function test_the_ssl_key_stays_bare_while_the_catalogue_inflects(): void
    {
        $oneDay = IncidentTitle::compose(IncidentTitle::SSL_EXPIRING, [
            'monitor' => 'api',
            'days' => 1,
        ]);
        $twoWeeks = IncidentTitle::compose(IncidentTitle::SSL_EXPIRING, [
            'monitor' => 'api',
            'days' => 14,
        ]);

        $this->assertSame('incidents.ssl_expiring', $oneDay['title_key']);
        $this->assertSame('incidents.ssl_expiring', $twoWeeks['title_key']);
        $this->assertSame('api SSL cert expires in 1 day', $oneDay['title']);
        $this->assertSame('api SSL cert expires in 14 days', $twoWeeks['title']);
    }

    /**
     * Turkish keeps the noun singular after a cardinal, so both entries carry one
     * sentence; the pair still has to EXIST on that side, because the resolver
     * asks for a suffixed key in every locale and a missing entry renders its own
     * dotted name.
     */
    public function test_the_ssl_pair_resolves_in_turkish_for_both_counts(): void
    {
        $oneDay = $this->composedIncident(IncidentTitle::SSL_EXPIRING, ['monitor' => 'api', 'days' => 1]);
        $twoWeeks = $this->composedIncident(IncidentTitle::SSL_EXPIRING, ['monitor' => 'api', 'days' => 14]);

        $this->assertSame('api sertifikası 1 gün içinde doluyor', IncidentTitle::render($oneDay, 'tr'));
        $this->assertSame('api sertifikası 14 gün içinde doluyor', IncidentTitle::render($twoWeeks, 'tr'));
    }

    /**
     * A key present in one catalogue and absent from the other falls through to
     * the fallback locale silently, so the sets are diffed rather than eyeballed.
     * The files are read directly: going through `__()` would resolve the very
     * fallback this asserts against.
     */
    public function test_both_catalogues_carry_the_same_keys(): void
    {
        $english = require base_path('lang/en/incidents.php');
        $turkish = require base_path('lang/tr/incidents.php');

        $englishKeys = array_keys($english);
        $turkishKeys = array_keys($turkish);
        sort($englishKeys);
        sort($turkishKeys);

        $this->assertSame($englishKeys, $turkishKeys);
        $this->assertSame([], array_diff($englishKeys, $turkishKeys));
    }

    /**
     * The `title_params` cast, asserted through a round trip rather than by
     * reading `$casts`: without it the params come back as a JSON string, `__()`
     * receives one scalar replacement instead of a set, and the render is a
     * sentence full of unreplaced placeholders. That failure is invisible on an
     * in-memory model, which is why this one goes through the database.
     */
    public function test_a_persisted_row_renders_from_its_params(): void
    {
        $composed = IncidentTitle::compose(IncidentTitle::METRIC_CRITICAL_BOUND, ['metric' => 'CPU']);
        $incident = Incident::query()->create([
            'team_id' => $this->makeTeam()->id,
            'impact' => IncidentImpact::Major,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'started_at' => now(),
            ...$composed,
        ]);

        $fresh = Incident::query()->findOrFail($incident->id);

        $this->assertSame('array', $fresh->getCasts()['title_params']);
        $this->assertSame(['metric' => 'CPU'], $fresh->title_params);
        $this->assertSame('CPU breached critical bound', IncidentTitle::render($fresh, 'en'));
        $this->assertSame('CPU kritik sınırını aştı', IncidentTitle::render($fresh, 'tr'));
    }

    /**
     * The six keys with a plausible parameter set for each, so a loop can hold
     * every key to the same standard instead of covering five and trusting the
     * sixth.
     *
     * @return array<string, array<string, string|int>>
     */
    protected function everyKeyWithParams(): array
    {
        return [
            IncidentTitle::MONITOR_DOWN => ['monitor' => 'checkout'],
            IncidentTitle::METRIC_WARN_BOUND => ['metric' => 'CPU'],
            IncidentTitle::METRIC_CRITICAL_BOUND => ['metric' => 'CPU'],
            IncidentTitle::METRIC_STRING_VALUE => ['metric' => 'Redis state', 'value' => 'DOWN'],
            IncidentTitle::SSL_EXPIRING => ['monitor' => 'api', 'days' => 14],
            IncidentTitle::AI_ANOMALY => ['monitor' => 'checkout'],
        ];
    }

    /**
     * An in-memory incident carrying a composed triple, which is what every
     * render assertion needs and none of them need persisted.
     *
     * @param  array<string, string|int>  $params
     */
    protected function composedIncident(string $key, array $params): Incident
    {
        return new Incident(IncidentTitle::compose($key, $params));
    }

    /** A team to own the one persisted incident this suite needs. */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Title Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Title Team',
        ]);
    }
}
