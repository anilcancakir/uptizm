<?php

namespace App\Services\Services;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\ServiceStatusSource;

/**
 * Reads a Google `incidents.json` body: one adapter for BOTH
 * {@see ServiceStatusSource::GoogleCloud} (`status.cloud.google.com`) and
 * {@see ServiceStatusSource::GoogleWorkspace} (the Workspace dashboard),
 * because the two hosts serve the identical document shape and a second class
 * differing only by URL would be copy-paste with variation. Both hosts have a
 * committed fixture for exactly that reason: the point of having two is proving
 * one adapter reads both.
 *
 * The document is a TOP-LEVEL ARRAY of incidents, each carrying `id`, `number`,
 * `begin`, `created`, `end`, `modified`, `external_desc`, `updates[]`,
 * `affected_products[]` and `uri`. There is no `components` collection and no
 * per-product status field anywhere in it.
 *
 * ## Everything binds to `affected_products[].id`, never to a title
 *
 * Google's own service-health fallback documentation
 * (https://docs.cloud.google.com/service-health/docs/service-health-fallback)
 * classifies id fields as Stable and display names as Unstable, saying they
 * "might change without warning". So `affected_products[].id` is the component
 * label, and `title` / `service_name` are never read at all.
 * `tests/Unit/Services/GoogleStatusAdapterTest.php` asserts the fixtures'
 * display names appear NOWHERE in the reading, which is the assertion that
 * fails if somebody later reaches for the friendlier field. The cost is
 * accepted knowingly: an opaque product id is a poor public label, and
 * resolving it to a name would mean ingesting Google's `products.json` as a
 * second feed, which is a deliberately deferred idea, not an oversight.
 *
 * ## What is deliberately NOT derived
 *
 * Component state comes from ONE fact: does an OPEN incident (`end` is null)
 * name this product id. The resulting {@see ComponentStatus} is therefore null =
 * UNKNOWN, and that is not laziness. Google publishes no per-product health
 * bucket, so choosing between `degraded_performance`, `partial_outage` and
 * `major_outage` would be this catalog inventing a severity Google never
 * published, on a page that attributes it to Google.
 *
 * For the same reason every incident's {@see IncidentImpact} stays null. Google
 * does publish an incident-level `status_impact`
 * (`SERVICE_INFORMATION`/`SERVICE_DISRUPTION`/`SERVICE_OUTAGE`) and a `severity`
 * (`low`/`medium`/`high`), but neither vocabulary is this repo's
 * `none|minor|major|critical`. Mapping across two different vocabularies is a
 * TRANSLATION, and the Statuspage adapter is only allowed its `tryFrom` because
 * that vocabulary is byte-identical to ours. What Google actually said travels
 * on the incident TITLE, in their words, with a link back to their page.
 */
class GoogleStatusAdapter implements ServiceStatusAdapter
{
    /**
     * Read an `incidents.json` payload.
     *
     * @param  array<mixed>  $payload  The decoded top-level incident array.
     * @param  string  $sourceUrl  The feed URL, whose scheme + host resolve the relative incident `uri`.
     */
    public function read(array $payload, string $sourceUrl): FeedReading
    {
        // 1. Keep only entries that are actually incident objects. A null entry,
        //    a scalar, or a whole document that decoded to something else is
        //    dropped without throwing.
        $incidents = array_values(array_filter($payload, 'is_array'));

        // 2. `end` is the only openness signal in this feed. Absent or null
        //    means the incident is still live.
        $open = array_values(array_filter(
            $incidents,
            static fn (array $incident): bool => ($incident['end'] ?? null) === null,
        ));

        return new FeedReading(
            // Google publishes no overall status word: there is nothing to
            // quote, and inventing one would be a claim they never made.
            indicator: null,
            components: $this->readAffectedProducts($open),
            incidents: $this->readIncidents($open, $sourceUrl),
        );
    }

    /**
     * Every distinct product id named by an open incident, in first-appearance
     * order, each with an UNKNOWN status.
     *
     * A product whose only incident is closed is absent entirely: that is the
     * "derive component state from whether an incident affecting that product
     * is open" rule, and absence is the honest encoding of "Google is not
     * currently reporting anything about this product".
     *
     * @param  list<array<mixed>>  $openIncidents
     * @return list<array{label: string, status: ComponentStatus|null}>
     */
    protected function readAffectedProducts(array $openIncidents): array
    {
        $ids = [];

        foreach ($openIncidents as $incident) {
            $products = $incident['affected_products'] ?? null;

            if (! is_array($products)) {
                continue;
            }

            foreach ($products as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $id = $product['id'] ?? null;

                if (! is_string($id) || trim($id) === '') {
                    continue;
                }

                $ids[trim($id)] = true;
            }
        }

        return array_map(
            // Stable id as the label, unknown as the status. Both halves are
            // constraints, not defaults; see the class docblock.
            static fn (string $id): array => [
                'label' => $id,
                'status' => null,
            ],
            array_keys($ids),
        );
    }

    /**
     * The open incidents, in Google's own words.
     *
     * An entry with no readable `external_desc` is skipped: there is nothing to
     * quote, and a blank row on a public page is worse than no row. Its
     * affected products are still counted as components above, because the
     * product IS affected whether or not the description parsed.
     *
     * @param  list<array<mixed>>  $openIncidents
     * @return list<array{title: string, impact: IncidentImpact|null, started_at: string|null, url: string|null}>
     */
    protected function readIncidents(array $openIncidents, string $sourceUrl): array
    {
        $reading = [];

        foreach ($openIncidents as $incident) {
            $title = $incident['external_desc'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                continue;
            }

            $startedAt = $incident['begin'] ?? $incident['created'] ?? null;

            $reading[] = [
                'title' => trim($title),
                // Null on purpose: Google's severity vocabulary is not this
                // repo's, and translating it would fabricate their claim.
                'impact' => null,
                'started_at' => is_string($startedAt) && $startedAt !== '' ? $startedAt : null,
                'url' => $this->resolveIncidentUrl($incident['uri'] ?? null, $sourceUrl),
            ];
        }

        return $reading;
    }

    /**
     * Resolve Google's relative incident `uri` against the feed's own host.
     *
     * The host comes from the URL an operator reviewed and stored on the
     * service row, NEVER from the payload. That is the whole point: a feed body
     * that could name its own host would let a compromised or spoofed response
     * put an arbitrary link on a uptizm page, and this catalog links out to
     * providers by name. So a `uri` carrying a scheme or a host is refused
     * rather than trusted.
     */
    protected function resolveIncidentUrl(mixed $uri, string $sourceUrl): ?string
    {
        if (! is_string($uri) || trim($uri) === '') {
            return null;
        }

        $uri = trim($uri);

        // A relative path only. `https://evil.test/x`, `//evil.test/x` and
        // `javascript:...` are all rejected here.
        if (parse_url($uri, PHP_URL_SCHEME) !== null
            || parse_url($uri, PHP_URL_HOST) !== null
            || str_starts_with($uri, '//')) {
            return null;
        }

        $scheme = parse_url($sourceUrl, PHP_URL_SCHEME);
        $host = parse_url($sourceUrl, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return null;
        }

        return $scheme.'://'.$host.'/'.ltrim($uri, '/');
    }
}
