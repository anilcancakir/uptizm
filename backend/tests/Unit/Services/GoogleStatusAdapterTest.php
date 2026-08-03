<?php

namespace Tests\Unit\Services;

use App\Enums\ServiceStatusSource;
use App\Services\Services\FeedReading;
use App\Services\Services\GoogleStatusAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the Google `incidents.json` parse: component state derives from
 * `affected_products[].id` and openness (`end` is null), nothing is bound to a
 * display name, no severity Google did not publish is invented, and no
 * malformed payload throws.
 *
 * TWO fixtures on purpose. `google-cloud-incidents.json` and
 * `google-workspace-incidents.json` come from different hosts
 * ({@see ServiceStatusSource::GoogleCloud} and
 * {@see ServiceStatusSource::GoogleWorkspace}), and the point of one adapter
 * serving both is only proven by reading both.
 *
 * No test here touches the network: the adapter takes an already-decoded array.
 */
class GoogleStatusAdapterTest extends TestCase
{
    /**
     * Components are the STABLE product ids of open incidents, de-duplicated,
     * and a product whose only incident is closed is absent.
     */
    public function test_components_derive_from_the_affected_product_ids_of_open_incidents(): void
    {
        $reading = $this->read('google-cloud-incidents.json');

        $this->assertSame([
            // From the open networking incident.
            '5MpKAZk3gY4xhLtE1jjR',
            'L4Vfz9dCbaFdvJp4qhTX',
            // From the open incident that has no description, no uri and no id:
            // the product is affected whether or not the prose parsed.
            'dataflowKeyGY4xhLtE1jj',
        ], array_column($reading->components, 'label'));

        // The closed Cloud Storage incident contributes nothing.
        $this->assertNotContains('storagekeyGY4xhLtE1jjR', array_column($reading->components, 'label'));
    }

    /**
     * Nothing in the reading is keyed on a display name.
     *
     * Google's own fallback documentation classifies id fields as Stable and
     * display names as Unstable ("might change without warning"), so a parse
     * bound to `title` or `service_name` breaks silently the day Google renames
     * a product. This is the assertion that fails if somebody later reaches for
     * the friendlier field, and it is scoped to the COMPONENT labels plus the
     * incident titles' provenance: an `external_desc` naturally contains
     * product words in prose, which is Google's own sentence and not a binding.
     */
    public function test_no_component_is_bound_to_a_display_name(): void
    {
        $payload = $this->payload('google-cloud-incidents.json');
        $labels = array_column($this->read('google-cloud-incidents.json')->components, 'label');

        $displayNames = [];
        foreach (array_filter($payload, 'is_array') as $incident) {
            foreach (array_filter((array) ($incident['affected_products'] ?? []), 'is_array') as $product) {
                $displayNames[] = (string) ($product['title'] ?? '');
            }
            $displayNames[] = (string) ($incident['service_name'] ?? '');
        }

        foreach (array_filter($displayNames) as $displayName) {
            $this->assertNotContains(
                $displayName,
                $labels,
                "The component label '{$displayName}' came from a display-name field.",
            );
        }

        // Every incident title is verbatim an `external_desc`, which is where
        // Google's own words live, rather than any name field.
        $descriptions = array_column(array_filter($payload, 'is_array'), 'external_desc');
        foreach ($this->read('google-cloud-incidents.json')->incidents as $incident) {
            $this->assertContains($incident['title'], $descriptions);
        }
    }

    /**
     * A component's status is UNKNOWN, never a bucket Google did not publish.
     *
     * `incidents.json` carries no per-product health field at all, so choosing
     * between degraded / partial / major would be this catalog inventing a
     * severity and attributing it to Google.
     */
    public function test_a_component_status_is_unknown_rather_than_an_invented_severity(): void
    {
        foreach ($this->read('google-cloud-incidents.json')->components as $component) {
            $this->assertNull($component['status']);
        }
    }

    /**
     * Incident impact stays null for the same reason: Google's `status_impact`
     * and `severity` vocabularies are not this repo's, and mapping across two
     * different vocabularies is a translation the Statuspage adapter is only
     * spared because its vocabulary is byte-identical.
     */
    public function test_an_incident_carries_no_translated_impact(): void
    {
        $reading = $this->read('google-cloud-incidents.json');

        $this->assertNotSame([], $reading->incidents);

        foreach ($reading->incidents as $incident) {
            $this->assertNull($incident['impact']);
        }
    }

    /** Only open incidents are read, and only ones with a readable description. */
    public function test_it_reads_only_open_incidents_with_a_description(): void
    {
        $titles = array_column($this->read('google-cloud-incidents.json')->incidents, 'title');

        $this->assertContains(
            'Increased error rates for Cloud Networking egress in multiple regions',
            $titles,
        );
        $this->assertContains('An open incident whose affected_products is null', $titles);
        $this->assertContains('An open incident with no uri, no begin and no id', $titles);

        // Closed: `end` is set.
        $this->assertNotContains('Cloud Storage requests failed in europe-west1', $titles);
    }

    /**
     * A titleless open incident still contributes its affected product, and
     * contributes no incident row.
     *
     * Isolated on its own single-entry payload rather than read off the shared
     * fixture, so the assertion cannot pass because another entry supplied the
     * component.
     */
    public function test_a_titleless_open_incident_still_contributes_its_component(): void
    {
        $reading = (new GoogleStatusAdapter)->read([
            [
                'end' => null,
                'affected_products' => [
                    [
                        'title' => 'Google Cloud Run',
                        'id' => 'runKeyGY4xhLtE1jjR',
                    ],
                ],
            ],
        ], 'https://status.cloud.google.com/incidents.json');

        $this->assertSame(['runKeyGY4xhLtE1jjR'], array_column($reading->components, 'label'));
        $this->assertSame([], $reading->incidents);
    }

    /** The relative `uri` resolves against the FEED's host, and `begin` is the start. */
    public function test_it_resolves_the_incident_link_against_the_feed_host(): void
    {
        $reading = $this->read('google-cloud-incidents.json');

        $byTitle = [];
        foreach ($reading->incidents as $incident) {
            $byTitle[$incident['title']] = $incident;
        }

        $networking = $byTitle['Increased error rates for Cloud Networking egress in multiple regions'];
        $this->assertSame(
            'https://status.cloud.google.com/incidents/ow5Y7cKLPFEjMuFHnrCs',
            $networking['url'],
        );
        $this->assertSame('2026-08-03T08:14:00+00:00', $networking['started_at']);

        $noUri = $byTitle['An open incident with no uri, no begin and no id'];
        $this->assertNull($noUri['url']);
        $this->assertNull($noUri['started_at']);
    }

    /**
     * A `uri` that names its own host or scheme is refused.
     *
     * The link host must come from the URL an operator reviewed and stored on
     * the service row, never from the payload: this catalog links out to
     * providers by name, so a body that could choose the host could put a
     * phishing link on a uptizm page.
     *
     * @param  string  $uri  The hostile `uri` value under test.
     */
    #[DataProvider('hostileIncidentUris')]
    public function test_it_refuses_a_uri_that_names_its_own_host(string $uri): void
    {
        $reading = (new GoogleStatusAdapter)->read([
            [
                'end' => null,
                'external_desc' => 'An incident whose uri points elsewhere',
                'uri' => $uri,
            ],
        ], 'https://status.cloud.google.com/incidents.json');

        $this->assertNull($reading->incidents[0]['url']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileIncidentUris(): array
    {
        return [
            'an absolute https url' => ['https://evil.test/incidents/1'],
            'a protocol-relative url' => ['//evil.test/incidents/1'],
            'a javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    /** Google publishes no overall status word, so none is invented. */
    public function test_it_carries_no_indicator(): void
    {
        $this->assertNull($this->read('google-cloud-incidents.json')->indicator);
    }

    /**
     * The SAME adapter reads the Workspace host's document, which is the whole
     * reason there is one Google adapter instead of two.
     */
    public function test_the_same_adapter_reads_the_workspace_host(): void
    {
        $reading = (new GoogleStatusAdapter)->read(
            $this->payload('google-workspace-incidents.json'),
            'https://www.google.com/appsstatus/dashboard/incidents.json',
        );

        $this->assertSame(['gmailKey3gY4xhLtE1jjR'], array_column($reading->components, 'label'));
        $this->assertSame(
            ['Some users are unable to send attachments in Gmail'],
            array_column($reading->incidents, 'title'),
        );
        $this->assertSame(
            'https://www.google.com/incidents/wsOpen7cKLPFEjMuFHnrC',
            $reading->incidents[0]['url'],
        );
        // The closed Drive incident contributes neither a component nor a row.
        $this->assertNotContains('driveKey3gY4xhLtE1jjR', array_column($reading->components, 'label'));
    }

    /**
     * Every malformed shape yields an empty reading and throws nothing: an empty
     * document, a null where the incident array was expected, entries that are
     * not objects, and an object where a top-level array was expected.
     *
     * @param  array<mixed>  $payload
     */
    #[DataProvider('malformedPayloads')]
    public function test_a_malformed_payload_yields_an_empty_reading(array $payload): void
    {
        $reading = (new GoogleStatusAdapter)->read($payload, 'https://status.cloud.google.com/incidents.json');

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
            'null entries' => [[
                null,
                null,
            ]],
            'scalar entries' => [[
                'unavailable',
                7,
            ]],
            'an object where an array of incidents was expected' => [[
                'incidents' => [
                    [
                        'external_desc' => 'Wrapped in an object Google does not send',
                        'end' => null,
                    ],
                ],
            ]],
            'an open incident with no products and no description' => [[
                [
                    'end' => null,
                    'affected_products' => null,
                ],
            ]],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function payload(string $fixture): array
    {
        return (array) json_decode(
            (string) file_get_contents(base_path('tests/fixtures/feeds/'.$fixture)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function read(string $fixture): FeedReading
    {
        return (new GoogleStatusAdapter)->read(
            $this->payload($fixture),
            'https://status.cloud.google.com/incidents.json',
        );
    }
}
