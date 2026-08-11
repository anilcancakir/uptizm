<?php

namespace Tests\Feature\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\IncidentTitle;
use App\Services\Monitoring\ThresholdEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The whole structured-title chain in one place: a writer opens an incident, the
 * three columns land, the seam renders them in two languages, and the API
 * resource hands the client everything it needs to render the same sentence
 * itself.
 *
 * Every link has its own test elsewhere (`IncidentTitleTest` for the seam,
 * `ThresholdEvaluatorTest` for the writers, `IncidentControllerTest` for the
 * payload shape), and that is exactly why this test exists: three green
 * suites can still leave a chain broken at a joint. A key persisted in a form the
 * wire drops, or params that arrive as a JSON string, breaks nothing until an
 * operator opens the app in Turkish.
 */
class IncidentTitleContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The composed path, end to end.
     *
     * The `assertNotSame` between the two renders is the assertion that makes
     * this a test of THIS change rather than of a column write: an implementation
     * that persisted the triple and then rendered the stored English for every
     * reader would satisfy every other line here.
     */
    public function test_an_evaluator_opened_incident_renders_in_both_locales_and_reaches_the_wire(): void
    {
        $monitor = $this->makeMonitor('checkout');
        $evaluator = new ThresholdEvaluator;

        // 1. The consecutive-fail threshold crosses, which is the `monitor_down`
        //    writer: the plainest of the six and the one every outage takes.
        $monitor->consecutive_fails = 2;
        $evaluator->evaluate($monitor, $this->makeCheck($monitor, MonitorStatus::Down), [], []);

        $incident = Incident::query()->sole();

        // 2. All three columns are persisted, and the params carry display-ready
        //    values only: the monitor NAME, not its id, so no reader has to load a
        //    relation to render a title.
        $this->assertSame('checkout is down', $incident->title);
        $this->assertSame(IncidentTitle::MONITOR_DOWN, $incident->title_key);
        $this->assertSame(['monitor' => 'checkout'], $incident->title_params);

        // 3. The seam renders the PERSISTED row (not an in-memory one) in both
        //    languages. Reading it back from the database is the point: without
        //    the `title_params` cast the params return as a JSON string and every
        //    render comes back full of unreplaced placeholders.
        $fresh = Incident::query()->findOrFail($incident->id);
        $english = IncidentTitle::render($fresh, 'en');
        $turkish = IncidentTitle::render($fresh, 'tr');

        $this->assertSame($fresh->title, $english);
        $this->assertSame($this->catalogueSentence('tr', 'monitor_down', ['monitor' => 'checkout']), $turkish);
        $this->assertNotSame($english, $turkish);
        $this->assertStringNotContainsString('incidents.', $turkish);
        $this->assertStringNotContainsString(':', $turkish);

        // 4. And the wire carries the three of them as FLAT keys, which is what
        //    lets the client render the same sentence from its own catalogue
        //    instead of displaying the backend's English.
        $payload = $this->serialize($fresh);

        $this->assertSame('checkout is down', $payload['title']);
        $this->assertSame(IncidentTitle::MONITOR_DOWN, $payload['title_key']);
        $this->assertSame(['monitor' => 'checkout'], $payload['title_params']);
    }

    /**
     * The authored path, end to end: a null key travels all the way to the client
     * as a null, and `title_params` crosses as an empty array rather than a null.
     *
     * That distinction is the reason the client's decode has two states instead of
     * three: absent, null and empty would otherwise all mean "no parameters" three
     * different ways.
     */
    public function test_an_authored_incident_crosses_the_wire_with_a_null_key(): void
    {
        $monitor = $this->makeMonitor('checkout');
        $evaluator = new ThresholdEvaluator;

        $incident = $evaluator->createIncident(
            monitor: $monitor,
            source: SignalSource::Manual,
            check: null,
            severity: IncidentSeverity::Critical,
            title: 'Ödemeler EU kenarında yavaş',
            triggerMetricKey: null,
        );

        $fresh = Incident::query()->findOrFail($incident->id);

        // A human chose these words in the language they chose them in, so every
        // locale hands the column back untouched.
        $this->assertNull($fresh->title_key);
        $this->assertSame('Ödemeler EU kenarında yavaş', IncidentTitle::render($fresh, 'en'));
        $this->assertSame('Ödemeler EU kenarında yavaş', IncidentTitle::render($fresh, 'tr'));

        $payload = $this->serialize($fresh);

        $this->assertSame('Ödemeler EU kenarında yavaş', $payload['title']);
        $this->assertNull($payload['title_key']);
        $this->assertSame([], $payload['title_params']);
        $this->assertArrayHasKey('title_key', $payload, 'A null key must be emitted, not omitted');
    }

    /**
     * The incident as {@see IncidentResource} serializes it for `GET /incidents`
     * and `GET /incidents/{id}` (one resource serves both, so one call covers the
     * pair).
     *
     * @return array<string, mixed>
     */
    private function serialize(Incident $incident): array
    {
        return (new IncidentResource($incident))->toArray(Request::create('/'));
    }

    /**
     * The sentence `lang/<locale>/incidents.php` spells for [$key], with its
     * `:placeholder` tokens filled from [$params].
     *
     * Read off the catalogue file rather than through `__()`: the file is one
     * layer away from the render path, so the expectation cannot be produced by
     * the same fallback it is meant to catch, and a wording edit cannot leave a
     * stale duplicate here.
     *
     * @param  array<string, string|int>  $params
     */
    private function catalogueSentence(string $locale, string $key, array $params): string
    {
        $sentence = (require base_path("lang/{$locale}/incidents.php"))[$key];

        foreach ($params as $name => $value) {
            $sentence = str_replace(":{$name}", (string) $value, $sentence);
        }

        return $sentence;
    }

    /** A monitor named [$name], owned by a fresh team, that opens on two fails. */
    private function makeMonitor(string $name): Monitor
    {
        $user = User::query()->create([
            'name' => 'Title Chain Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Title Chain Team',
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);
    }

    /** A persisted check row, which is what the evaluator sources `started_at` from. */
    private function makeCheck(Monitor $monitor, MonitorStatus $status): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'id' => (string) Str::orderedUuid(),
            'checked_at' => now(),
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'region' => 'us-east-1',
            'status' => $status,
        ]);
    }
}
