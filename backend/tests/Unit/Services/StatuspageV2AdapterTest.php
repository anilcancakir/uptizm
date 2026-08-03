<?php

namespace Tests\Unit\Services;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Services\Services\FeedReading;
use App\Services\Services\StatuspageV2Adapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the Statuspage v2 parse: the two byte-identical vocabularies are read
 * with `tryFrom` and no lookup table, the one non-identical field
 * (`incidents[].status`) maps `postmortem` to resolved, an unrecognised value is
 * UNKNOWN rather than healthy, and no malformed payload throws.
 *
 * The payload under test is the committed fixture
 * `tests/fixtures/feeds/statuspage-summary.json`, which is a real `summary.json`
 * shape deliberately seeded with the awkward cases: all four component
 * statuses, all four incident impacts, Statuspage's fifth `under_maintenance`
 * status (which this repo's enum does not carry), a group rollup row, a null
 * entry, a row missing its status, a row missing its name, and an unexpected
 * extra field at both the entry and the document level.
 *
 * No test here touches the network: the adapter takes an already-decoded array.
 */
class StatuspageV2AdapterTest extends TestCase
{
    /**
     * All four {@see ComponentStatus} cases parse straight from the fixture,
     * and the two unparseable rows come back UNKNOWN rather than operational.
     */
    public function test_it_parses_all_four_component_statuses_and_leaves_the_rest_unknown(): void
    {
        $reading = $this->read();

        $byLabel = [];
        foreach ($reading->components as $component) {
            $byLabel[$component['label']] = $component['status'];
        }

        $this->assertSame([
            'Git Operations' => ComponentStatus::Operational,
            'API Requests' => ComponentStatus::DegradedPerformance,
            'Webhooks' => ComponentStatus::PartialOutage,
            'Actions' => ComponentStatus::MajorOutage,
            // Statuspage's fifth value. This repo's enum deliberately has no
            // maintenance case, so the honest answer is unknown.
            'Scheduled Window' => null,
            'Missing Status Field' => null,
            'Extra Field Component' => ComponentStatus::Operational,
        ], $byLabel);
    }

    /**
     * An unrecognised status becomes UNKNOWN, never `operational`.
     *
     * Stated as its own test with its own single-row payload so the assertion
     * cannot pass because some OTHER row in the fixture was unknown: the failing
     * edit here is exactly "default an unparsed status to operational".
     */
    public function test_an_unrecognised_component_status_is_unknown_and_not_operational(): void
    {
        $reading = (new StatuspageV2Adapter)->read([
            'components' => [
                [
                    'name' => 'Brand New Bucket',
                    'status' => 'a_status_statuspage_has_not_published_yet',
                ],
            ],
        ], 'https://status.example.com/api/v2/summary.json');

        $this->assertCount(1, $reading->components);
        $this->assertNull($reading->components[0]['status']);
        $this->assertNotSame(ComponentStatus::Operational, $reading->components[0]['status']);
    }

    /** A group rollup row is skipped, so one outage is not listed twice. */
    public function test_it_skips_group_rollup_rows(): void
    {
        $labels = array_column($this->read()->components, 'label');

        $this->assertNotContains('Platform', $labels);
    }

    /**
     * All four {@see IncidentImpact} cases parse straight from the fixture's
     * OPEN incidents, and an unrecognised impact stays null rather than `none`.
     */
    public function test_it_parses_all_four_incident_impacts(): void
    {
        $byTitle = [];
        foreach ($this->read()->incidents as $incident) {
            $byTitle[$incident['title']] = $incident['impact'];
        }

        $this->assertSame(IncidentImpact::None, $byTitle['Informational notice about a completed migration']);
        $this->assertSame(IncidentImpact::Minor, $byTitle['Elevated latency on API requests']);
        $this->assertSame(IncidentImpact::Major, $byTitle['Webhook deliveries are delayed']);
        $this->assertSame(IncidentImpact::Critical, $byTitle['Actions runners are unavailable']);
        $this->assertNull($byTitle['Status vocabulary this catalog has never seen']);
    }

    /**
     * The premise behind having no mapping table, asserted directly.
     *
     * If Statuspage ever renames one of these strings, this test reddens HERE,
     * where the cause is obvious, instead of silently turning every component on
     * every public page unknown.
     */
    public function test_the_fixture_uses_vocabularies_byte_identical_to_this_repos_enums(): void
    {
        $payload = $this->payload();

        $rawStatuses = array_column(array_filter($payload['components'], 'is_array'), 'status');
        foreach (ComponentStatus::cases() as $case) {
            $this->assertContains(
                $case->value,
                $rawStatuses,
                "Statuspage no longer publishes the component status '{$case->value}' verbatim.",
            );
        }

        $rawImpacts = array_column(array_filter($payload['incidents'], 'is_array'), 'impact');
        foreach (IncidentImpact::cases() as $case) {
            $this->assertContains(
                $case->value,
                $rawImpacts,
                "Statuspage no longer publishes the incident impact '{$case->value}' verbatim.",
            );
        }
    }

    /**
     * `postmortem` is treated as RESOLVED, so a months-old analysis does not
     * sit on the public page as a live incident.
     */
    public function test_a_postmortem_incident_is_treated_as_resolved(): void
    {
        $titles = array_column($this->read()->incidents, 'title');

        $this->assertNotContains('Post-incident analysis of the June packet loss', $titles);
        $this->assertNotContains('Resolved: cache eviction storm', $titles);
    }

    /**
     * The inverse of the postmortem choice: a status this catalog has never seen
     * is treated as OPEN, because hiding a possible live incident is the more
     * expensive error.
     */
    public function test_an_unrecognised_incident_status_is_treated_as_open(): void
    {
        $titles = array_column($this->read()->incidents, 'title');

        $this->assertContains('Status vocabulary this catalog has never seen', $titles);
        $this->assertContains('Incident with no started_at and no shortlink', $titles);
    }

    /** The provider's own indicator is carried verbatim, not translated. */
    public function test_it_carries_the_provider_indicator_verbatim(): void
    {
        $this->assertSame('major', $this->read()->indicator);
    }

    /** An indicator too long for the snapshot column is dropped, not truncated. */
    public function test_it_drops_an_indicator_longer_than_the_column(): void
    {
        $reading = (new StatuspageV2Adapter)->read([
            'status' => [
                'indicator' => str_repeat('x', FeedReading::MAX_INDICATOR_LENGTH + 1),
            ],
        ], 'https://status.example.com/api/v2/summary.json');

        $this->assertNull($reading->indicator);
    }

    /** Incident links, started-at values and their absences round trip. */
    public function test_it_reads_the_incident_link_and_started_at(): void
    {
        $incidents = [];
        foreach ($this->read()->incidents as $incident) {
            $incidents[$incident['title']] = $incident;
        }

        $this->assertSame(
            'https://stspg.io/n7v0dlwqfmqk',
            $incidents['Elevated latency on API requests']['url'],
        );
        $this->assertSame(
            '2026-08-03T08:40:00.000Z',
            $incidents['Elevated latency on API requests']['started_at'],
        );
        $this->assertNull($incidents['Incident with no started_at and no shortlink']['url']);
        $this->assertNull($incidents['Incident with no started_at and no shortlink']['started_at']);
    }

    /**
     * A `javascript:` shortlink is dropped rather than carried into an `href`.
     *
     * Blade escapes attribute VALUES; it does not neutralise a scheme, so this
     * guard is the one that stops a hostile or compromised feed body from
     * putting a script URL on a uptizm page.
     */
    public function test_it_drops_an_incident_link_that_is_not_http(): void
    {
        $reading = (new StatuspageV2Adapter)->read([
            'incidents' => [
                [
                    'name' => 'Hostile link',
                    'status' => 'investigating',
                    'impact' => 'minor',
                    'shortlink' => 'javascript:alert(1)',
                ],
            ],
        ], 'https://status.example.com/api/v2/summary.json');

        $this->assertNull($reading->incidents[0]['url']);
    }

    /**
     * Every malformed shape yields an empty reading and throws nothing.
     *
     * A feed is untrusted remote input; a throw here would either poison the
     * queue or lose the honest "we could not read their feed" state the page
     * needs. Each case is a distinct malformation: an entirely empty document, a
     * missing key, a null where an array was expected, a scalar where an array
     * was expected, and an unexpected extra field with nothing else.
     *
     * @param  array<mixed>  $payload
     */
    #[DataProvider('malformedPayloads')]
    public function test_a_malformed_payload_yields_an_empty_reading(array $payload): void
    {
        $reading = (new StatuspageV2Adapter)->read($payload, 'https://status.example.com/api/v2/summary.json');

        $this->assertNull($reading->indicator);
        $this->assertSame([], $reading->components);
        $this->assertSame([], $reading->incidents);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'an empty document' => [[]],
            'missing keys' => [[
                'page' => [
                    'name' => 'Example Provider',
                ],
            ]],
            'null where an array was expected' => [[
                'status' => null,
                'components' => null,
                'incidents' => null,
            ]],
            'a scalar where an array was expected' => [[
                'status' => 'operational',
                'components' => 'all good',
                'incidents' => 7,
            ]],
            'an unexpected field and nothing else' => [[
                'a_field_this_catalog_has_never_seen' => [
                    'nested' => true,
                ],
            ]],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function payload(): array
    {
        return (array) json_decode(
            (string) file_get_contents(base_path('tests/fixtures/feeds/statuspage-summary.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function read(): FeedReading
    {
        return (new StatuspageV2Adapter)->read(
            $this->payload(),
            'https://status.example.com/api/v2/summary.json',
        );
    }
}
