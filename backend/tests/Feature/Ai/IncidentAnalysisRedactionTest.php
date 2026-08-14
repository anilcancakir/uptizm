<?php

namespace Tests\Feature\Ai;

use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\IncidentAnalysisPayload;
use App\Services\Ai\IncidentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The monitor URL never reaches the analysis model.
 *
 * `IncidentDraftTest::test_the_monitor_url_never_reaches_the_model()` has
 * asserted this for the DRAFT since the day a monitor address of the shape
 * `https://host/api/v1/<32 hex>/status`, where the path segment IS the
 * credential, landed in a published postmortem. The fix took the URL out of
 * `IncidentDraftPayload` and stopped there, and the ANALYSIS payload kept
 * sending it: `IncidentAnalysisService` put `$monitor->url` in the roster and
 * `IncidentAnalysisPayload::renderMonitors()` wrote `, url: <full url>` into the
 * TRUSTED half of the prompt.
 *
 * It surfaced the way it was always going to. The model copied the address into
 * its summary, and an operator read the whole credential back on their own
 * incident page under a High confidence badge.
 *
 * The severity is not that page, though. `IncidentDraftService` hands the STORED
 * ANALYSIS to the draft model as the settled cause, so a credential in the
 * analysis text reaches the draft anyway, and the draft is what becomes a public
 * status update or a postmortem. Closing one door and leaving the other open is
 * worth less than it looks, which is why the last test here is a sweep rather
 * than a third copy of the same assertion.
 */
class IncidentAnalysisRedactionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The credential-shaped path segment, matching the draft test's fixture so
     * the two read as one rule.
     */
    private const SECRET = '9a22cd0fba16edf9ab09e5c4';

    public function test_the_monitor_url_never_reaches_the_model(): void
    {
        $incident = $this->makeIncident('https://example.test/api/v1/'.self::SECRET.'/status');

        $message = $this->composeMessage($incident);

        $this->assertStringNotContainsString(self::SECRET, $message);
        $this->assertStringNotContainsString('https://', $message);
    }

    public function test_the_monitor_name_still_reaches_the_model(): void
    {
        // The other half, and the reason the roster exists at all: the payload
        // used to send ids alone and the model wrote "the monitor
        // a27cd1e4-3795-41b6-9527-dbbda45e51da" because it had nothing else to
        // call it. Redaction that takes the name with it would trade one bad
        // output for another.
        $incident = $this->makeIncident('https://example.test/health');

        $message = $this->composeMessage($incident);

        $this->assertStringContainsString('API Uptime', $message);
    }

    public function test_no_ai_payload_renders_a_monitor_url(): void
    {
        // The gate, and it earned itself immediately: this was the second surface
        // to render the same value and the sweep found a third
        // (`AssistantPayload`, whose roster is JSON-encoded whole).
        //
        // Two payloads are exempt and the line between them and the rest is
        // about WHOSE question the URL is. Both exemptions belong to the monitor
        // SETUP flow, where the operator supplied the address in that same
        // request and is asking a question about that address; nothing either one
        // produces is ever published. Everywhere else the URL is an incidental
        // detail about a monitor, and the output is stored, shown back, or
        // published.
        //
        // Worth knowing before trusting the exemption: both route through
        // `displayUrl()`, which drops the query string and any userinfo and KEEPS
        // THE PATH. That is not a full redaction, and the path is exactly where
        // this credential lives.
        $exempt = [
            'AnalysisPayload.php',
            'MetricDiscoveryPayload.php',
        ];

        $offenders = [];

        foreach (File::files(app_path('Services/Ai')) as $file) {
            if (! str_ends_with($file->getFilename(), 'Payload.php')) {
                continue;
            }

            if (in_array($file->getFilename(), $exempt, true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, "'url'") || str_contains($source, 'url: ')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'a payload renders a monitor URL; the path segment is often the credential',
        );
    }

    public function test_the_fingerprint_ignores_the_order_of_the_monitor_roster(): void
    {
        // Raised in review: `monitorRoster()` had no `orderBy`, so the same two
        // monitors could come back in either order and hash twice, missing the
        // store and re-spending a budget unit on an incident whose evidence never
        // moved. Invisible until now because every check so far ran against a
        // single-monitor incident, where nothing could reorder.
        //
        // Narrowed to the ROSTER on purpose. The first version of this asserted
        // order-independence for the checks too, and
        // `EvidenceFingerprintTest::test_a_recovery_reads_differently_from_an_onset`
        // caught it: the check list is newest-first, so an `up` above a `down` is
        // a recovery and the reverse is the failure starting, and the distinct set
        // is identical either way. Order IS evidence there, so those reads get a
        // deterministic tiebreaker in the query instead of a sort here. Which
        // monitor is listed first says nothing about the incident.
        $one = $this->payloadWith(
            monitors: [
                ['monitor_id' => 'm-1', 'name' => 'API'],
                ['monitor_id' => 'm-2', 'name' => 'Web'],
            ],
            checks: [
                ['check_id' => 'c-1', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'down', 'status_code' => 500],
            ],
        );

        $two = $this->payloadWith(
            monitors: [
                ['monitor_id' => 'm-2', 'name' => 'Web'],
                ['monitor_id' => 'm-1', 'name' => 'API'],
            ],
            checks: [
                ['check_id' => 'c-1', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'down', 'status_code' => 500],
            ],
        );

        $this->assertSame(
            $one->evidenceFingerprint(),
            $two->evidenceFingerprint(),
            'which monitor is listed first is not a change in the evidence',
        );
    }

    public function test_the_check_sequence_still_moves_the_fingerprint(): void
    {
        // The boundary of the fix above, asserted here as well as in
        // `EvidenceFingerprintTest` because this is the test that would be edited
        // if somebody decided to sort the checks after all.
        $recovery = $this->payloadWith(
            monitors: [['monitor_id' => 'm-1', 'name' => 'API']],
            checks: [
                ['check_id' => 'c-2', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'up', 'status_code' => 200],
                ['check_id' => 'c-1', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'down', 'status_code' => 500],
            ],
        );

        $onset = $this->payloadWith(
            monitors: [['monitor_id' => 'm-1', 'name' => 'API']],
            checks: [
                ['check_id' => 'c-1', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'down', 'status_code' => 500],
                ['check_id' => 'c-2', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'up', 'status_code' => 200],
            ],
        );

        $this->assertNotSame(
            $recovery->evidenceFingerprint(),
            $onset->evidenceFingerprint(),
            'a recovery and an onset are the same set in a different order',
        );
    }

    public function test_different_evidence_still_produces_a_different_fingerprint(): void
    {
        // The guard on the guard: sorting the inputs must not flatten the hash
        // into something that ignores the evidence itself.
        $up = $this->payloadWith(
            monitors: [['monitor_id' => 'm-1', 'name' => 'API']],
            checks: [['check_id' => 'c-1', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'up', 'status_code' => 200]],
        );

        $down = $this->payloadWith(
            monitors: [['monitor_id' => 'm-1', 'name' => 'API']],
            checks: [['check_id' => 'c-1', 'monitor' => 'API', 'region' => 'eu-central', 'status' => 'down', 'status_code' => 500]],
        );

        $this->assertNotSame($up->evidenceFingerprint(), $down->evidenceFingerprint());
    }

    /**
     * A payload carrying just the two collections this asserts about.
     *
     * @param  list<array<string, mixed>>  $monitors
     * @param  list<array<string, mixed>>  $checks
     */
    protected function payloadWith(array $monitors, array $checks): IncidentAnalysisPayload
    {
        return new IncidentAnalysisPayload(
            incidentId: 'i-1',
            severity: 'critical',
            impact: 'critical',
            lifecycle: 'detected',
            signalSource: 'user_threshold',
            aiOwned: false,
            startedAt: '2026-08-14T00:00:00+00:00',
            resolvedAt: null,
            timeline: [],
            checks: $checks,
            bodies: [],
            knownCheckIds: array_column($checks, 'check_id'),
            knownMonitorIds: array_column($monitors, 'monitor_id'),
            monitors: $monitors,
        );
    }

    /**
     * The analysis prompt for an incident, read through a closure bound to the
     * service because `composePayload()` is protected and stays that way.
     */
    protected function composeMessage(Incident $incident): string
    {
        return (fn (): string => $this->composePayload($incident)->buildUserMessage())
            ->call(app(IncidentAnalysisService::class));
    }

    protected function makeIncident(string $url): Incident
    {
        $user = User::query()->create([
            'name' => 'Redaction Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Redaction Team',
            'plan' => 'pro',
        ]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => $url,
            'check_interval_sec' => 60,
            'incident_threshold' => 2,
            'consecutive_fails' => 0,
        ]);

        $incident = Incident::query()->create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now()->subMinutes(5),
        ]);

        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => 'down',
            'component_status_current' => 'down',
        ]);

        return $incident;
    }
}
