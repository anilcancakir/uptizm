<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\DigestGateway;
use App\Services\Ai\DigestPayload;
use App\Services\Ai\DigestResult;
use App\Services\Ai\FakeDigestGateway;
use App\Services\Ai\LaravelAiDigestGateway;
use Tests\TestCase;

/**
 * Pins the honest-AI-boundary of the weekly-digest gateway: the same
 * owned-citation allowlist as {@see IncidentAnalysisGatewayTest},
 * cloned for a team's week of uptime/incident stats instead of a single
 * incident's timeline. Unlike the triage/analysis triads, the digest payload
 * carries no probe-controlled (untrusted) fields: its inputs are our own
 * aggregated uptime numbers and incident records, so there is no
 * prompt-injection fence to test here.
 *
 * No real API is exercised: the payload builder, the allowlist scan, and the
 * fake are pure and framework-light. The real {@see LaravelAiDigestGateway}
 * prompt path is covered by `php -l` + a verify-at-execute marker, never a
 * network call in CI.
 */
class DigestGatewayTest extends TestCase
{
    // ---------------------------------------------------------------------
    // (1) Owned-citation allowlist
    // ---------------------------------------------------------------------

    public function test_out_of_catalog_citation_is_stripped_from_the_summary(): void
    {
        $gateway = new LaravelAiDigestGateway;
        $payload = $this->payload(knownIncidentIds: ['incident-1'], knownMonitorIds: ['monitor-1']);

        $result = $gateway->sanitizeText(
            'Uptime dipped after incident_id:incident-1; unrelated incident_id:phantom noise.',
            $payload,
        );

        // The out-of-catalog citation is nulled out.
        $this->assertStringNotContainsString('incident_id:phantom', $result['text']);
        $this->assertContains('incident_id:phantom', $result['stripped']);

        // Known citations survive untouched.
        $this->assertStringContainsString('incident_id:incident-1', $result['text']);
    }

    public function test_known_citations_are_never_stripped(): void
    {
        $gateway = new LaravelAiDigestGateway;
        $payload = $this->payload(knownIncidentIds: ['incident-1'], knownMonitorIds: ['monitor-1']);

        $result = $gateway->sanitizeText(
            'Traced to monitor_id:monitor-1 with no fabricated references.',
            $payload,
        );

        $this->assertSame([], $result['stripped']);
        $this->assertStringContainsString('monitor_id:monitor-1', $result['text']);
    }

    // ---------------------------------------------------------------------
    // (2) Deterministic fake, bound in place of the real gateway
    // ---------------------------------------------------------------------

    public function test_fake_gateway_yields_a_deterministic_result(): void
    {
        $this->app->bind(DigestGateway::class, FakeDigestGateway::class);

        $gateway = $this->app->make(DigestGateway::class);
        $result = $gateway->summarize($this->payload());
        $again = $gateway->summarize($this->payload());

        $this->assertInstanceOf(DigestResult::class, $result);
        // Determinism: the same payload yields byte-identical results.
        $this->assertEquals($result->toArray(), $again->toArray());
    }

    public function test_real_gateway_resolves_behind_the_digest_gateway_contract(): void
    {
        $this->assertInstanceOf(
            LaravelAiDigestGateway::class,
            $this->app->make(DigestGateway::class),
        );
    }

    /**
     * Build a digest payload with an overridable owned-citation catalog.
     */
    private function payload(
        array $knownIncidentIds = ['incident-1'],
        array $knownMonitorIds = ['monitor-1'],
    ): DigestPayload {
        return new DigestPayload(
            teamId: 'team-1',
            weekStart: '2026-07-05',
            weekEnd: '2026-07-12',
            uptimePercent: 99.5,
            previousUptimePercent: 98.0,
            incidentCount: 1,
            incidents: [
                [
                    'incident_id' => 'incident-1',
                    'title' => 'API Uptime is down',
                    'severity' => 'critical',
                    'impact' => 'major_outage',
                    'started_at' => '2026-07-06T00:00:00+00:00',
                    'resolved_at' => '2026-07-06T00:10:00+00:00',
                ],
            ],
            knownIncidentIds: $knownIncidentIds,
            knownMonitorIds: $knownMonitorIds,
        );
    }
}
