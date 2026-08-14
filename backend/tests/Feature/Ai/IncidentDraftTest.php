<?php

namespace Tests\Feature\Ai;

use App\Enums\IncidentDraftKind;
use App\Enums\IncidentImpact;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\FakeIncidentDraftGateway;
use App\Services\Ai\IncidentDraftGateway;
use App\Services\Ai\IncidentDraftPayload;
use App\Services\Ai\IncidentDraftResult;
use App\Services\Ai\IncidentDraftService;
use App\Services\Ai\LaravelAiIncidentDraftGateway;
use App\Services\Ai\NonConformingAnalysisException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the drafting surface: what reaches the model, what comes back, and the
 * two rules that are enforced rather than requested.
 */
class IncidentDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_monitor_url_never_reaches_the_model(): void
    {
        // The defect this test exists for, caught on a live draft. The monitor
        // address was `https://example.test/api/v1/<32 hex>/status`, where the
        // path segment IS the credential, and it landed in a postmortem: a
        // document the operator PUBLISHES to a page that cannot be unpublished.
        [$monitor] = $this->makeMonitor(
            url: 'https://example.test/api/v1/9a22cd0fba16edf9ab09e5c4/status',
        );
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor);

        $message = $this->composeMessage($incident, IncidentDraftKind::Postmortem);

        $this->assertStringNotContainsString('9a22cd0fba16edf9ab09e5c4', $message);
        $this->assertStringNotContainsString('https://', $message);
        $this->assertStringContainsString('API Uptime', $message, 'the name is what a reader needs');
    }

    public function test_the_checks_that_opened_the_incident_are_in_the_evidence(): void
    {
        // Measured on the running system. The window ran from `started_at`, and
        // the failures that TRIPPED the threshold are all before it, so every
        // analysis was blind to the thing it was analysing. Worse at open: the
        // autonomous job runs seconds after, and the first live analysis said
        // "No checks were recorded and no probe response body was provided",
        // which is the sentence the customer-facing draft was then built from.
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);

        // A failure from before the incident opened: one of the ones that caused
        // it, since the threshold needs consecutive failures to trip.
        $before = $this->makeCheck($monitor, ms: 9000);
        $before->forceFill([
            'checked_at' => $incident->started_at->copy()->subMinutes(2),
            'status_code' => 503,
        ])->save();

        $message = $this->composeMessage($incident, IncidentDraftKind::Update);

        $this->assertStringContainsString(
            'HTTP 503',
            $message,
            'the check that opened the incident is evidence about it',
        );
    }

    public function test_an_update_is_not_shown_the_response_body(): void
    {
        // A public status note quoting an internal check path leaks the inside
        // of a system to a reader who cannot act on it. The postmortem may see
        // it; the update may not.
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, body: '{"status":"degraded","checks":{"storage":{"status":"degraded"}}}');

        $update = $this->composeMessage($incident, IncidentDraftKind::Update);
        $postmortem = $this->composeMessage($incident, IncidentDraftKind::Postmortem);

        $this->assertStringNotContainsString('checks.storage.status', $update);
        $this->assertStringContainsString('checks.storage.status', $postmortem);
    }

    public function test_repeated_checks_collapse_to_one_row_with_a_latency_range(): void
    {
        // Latency in the collapse key deduped nothing, because no two checks
        // answer in the same millisecond: a live dump printed twenty-two rows of
        // "eu-central up HTTP 200" differing only in the number of ms.
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor, ms: 436);
        $this->makeCheck($monitor, ms: 1984);
        $this->makeCheck($monitor, ms: 3389);

        $message = $this->composeMessage($incident, IncidentDraftKind::Update);

        $this->assertStringContainsString('x3, 436-3389ms', $message);
        $this->assertSame(1, substr_count($message, 'eu-central'));
    }

    public function test_the_incident_headline_travels_so_the_draft_need_not_repeat_it(): void
    {
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor);

        $message = $this->composeMessage($incident, IncidentDraftKind::Update);

        $this->assertStringContainsString('headline (already shown to readers): API Uptime is down', $message);
    }

    public function test_the_endpoint_answers_a_draft(): void
    {
        $this->app->bind(IncidentDraftGateway::class, FakeIncidentDraftGateway::class);
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/draft-update");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['draft', 'degrade_reason']]);
        $this->assertStringContainsString('investigating', $response->json('data.draft'));
        $this->assertNull($response->json('data.degrade_reason'));
    }

    public function test_a_degrade_answers_a_null_draft_and_a_reason(): void
    {
        // Null and not a server-composed fallback: the client owns a localized
        // template for both surfaces, written by a person in both locales, and
        // it is better than anything a degraded backend could compose.
        $this->app->bind(IncidentDraftGateway::class, fn () => new class implements IncidentDraftGateway
        {
            public function draft(IncidentDraftPayload $payload): IncidentDraftResult
            {
                throw new NonConformingAnalysisException('Untrusted.');
            }
        });
        [$monitor, $user] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        $this->makeCheck($monitor);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/draft-postmortem");

        $response->assertStatus(200);
        $response->assertJsonPath('data.draft', null);
        $response->assertJsonPath('data.degrade_reason', 'output_untrusted');
    }

    public function test_another_teams_incident_is_masked_as_404(): void
    {
        $this->app->bind(IncidentDraftGateway::class, FakeIncidentDraftGateway::class);
        [$monitor] = $this->makeMonitor();
        $incident = $this->makeIncident($monitor);
        [, $stranger] = $this->makeMonitor();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/incidents/{$incident->id}/draft-update")
            ->assertStatus(404);
    }

    public function test_a_third_sentence_is_cut_from_a_public_update(): void
    {
        // Measured: two live runs on one incident returned a clean two-sentence
        // update and a three-sentence one that also named the internal cause the
        // instructions forbid. Length is the part that can be enforced.
        $gateway = new LaravelAiIncidentDraftGateway;

        $this->assertSame(
            'This incident has been resolved. The service is operating normally again.',
            $gateway->capSentences(
                'This incident has been resolved. The service is operating normally again. '
                .'No further action is required from customers.',
                2,
            ),
        );
    }

    public function test_an_invented_reporter_is_taken_out_of_a_public_update(): void
    {
        // The first autonomous post on the running system went out reading "We
        // are currently investigating REPORTS OF degraded service". Nobody
        // reported anything: a probe crossed a threshold the operator set. The
        // instructions forbid it in plain terms and the model wrote it anyway,
        // which is why this path has enforcement behind the rule.
        $gateway = new LaravelAiIncidentDraftGateway;

        $this->assertSame(
            'We are currently investigating degraded service. We will provide an update as soon as possible.',
            $gateway->dropInventedReporters(
                'We are currently investigating reports of degraded service. '
                .'We will provide an update as soon as possible.',
            ),
        );
    }

    public function test_a_claim_that_makes_the_reporter_its_subject_is_re_attributed(): void
    {
        // Deleting the phrase here would leave a broken sentence, so these two
        // are re-attributed to the thing that actually saw it, and the sentence
        // keeps the capital it started with.
        $gateway = new LaravelAiIncidentDraftGateway;

        $this->assertSame(
            'Our monitoring shows checkout is failing.',
            $gateway->dropInventedReporters('We have received reports that checkout is failing.'),
        );
        $this->assertSame(
            'Our monitoring shows slow responses on the API.',
            $gateway->dropInventedReporters('Customers have reported slow responses on the API.'),
        );
    }

    public function test_an_honest_update_is_left_exactly_as_written(): void
    {
        // The half a rewrite can quietly break: prose that never made the claim
        // must come through untouched.
        $gateway = new LaravelAiIncidentDraftGateway;

        foreach ([
            'We are investigating degraded performance on Checkout.',
            'This incident has been resolved.',
            'A fix has been implemented and we are monitoring the results.',
        ] as $honest) {
            $this->assertSame($honest, $gateway->dropInventedReporters($honest));
        }
    }

    public function test_a_fabricated_identifier_is_removed_from_a_draft(): void
    {
        // The payload carries no uuid at all, so one in the answer was invented,
        // and an invented identifier in customer-facing prose looks authoritative.
        $gateway = new LaravelAiIncidentDraftGateway;

        $this->assertSame(
            'We are investigating errors on the checkout service.',
            $gateway->sanitizeDraft(
                'We are investigating errors on the checkout service (a26c03f7-f8ab-49f9-876e-704061929a65).',
            ),
        );
    }

    /**
     * Build the prompt the model would be sent, without sending it.
     */
    protected function composeMessage(Incident $incident, IncidentDraftKind $kind): string
    {
        $service = app(IncidentDraftService::class);
        $method = new \ReflectionMethod($service, 'composePayload');
        $method->setAccessible(true);

        return $method->invoke($service, $incident, $kind, 'en')->buildUserMessage();
    }

    /**
     * @return array{0: Monitor, 1: User}
     */
    protected function makeMonitor(string $url = 'https://example.com/health'): array
    {
        $user = User::query()->create([
            'name' => 'Draft Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Draft Team',
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

        return [$monitor, $user];
    }

    protected function makeIncident(Monitor $monitor): Incident
    {
        return Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Uptime is down',
            'impact' => IncidentImpact::Critical,
            'severity' => IncidentSeverity::Critical,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'started_at' => now()->subMinutes(30),
        ]);
    }

    protected function makeCheck(Monitor $monitor, int $ms = 500, ?string $body = null): MonitorCheck
    {
        return MonitorCheck::query()->create([
            'team_id' => $monitor->team_id,
            'monitor_id' => $monitor->id,
            'region' => 'eu-central',
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => $ms,
            'response_body_preview' => $body,
            'checked_at' => now(),
        ]);
    }
}
