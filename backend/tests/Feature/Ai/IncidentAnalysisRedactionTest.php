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
