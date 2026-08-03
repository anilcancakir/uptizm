<?php

namespace App\Services\Services;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Models\ServiceFeedSnapshot;

/**
 * One provider's official status, parsed out of its own feed into the single
 * shape every adapter normalizes to and {@see ServiceFeedSnapshot} stores.
 *
 * Three fields, and each carries a rule the whole subsystem depends on:
 *
 *  - `indicator` is the provider's OWN top-level word (`none`, `minor`,
 *    `major`, `critical`, `maintenance`, ...) and is carried verbatim. It is
 *    never translated into this product's `MonitorStatus`: their `minor` can
 *    mean one sub-product is slow, which is not this product's `degraded`. The
 *    public page quotes it as their claim.
 *  - `components` are the provider's own component rows. A `status` of NULL
 *    means UNKNOWN, never operational: an unrecognised vocabulary word (or a
 *    provider that publishes no per-component health at all, which is Google's
 *    case) renders as the existing no-data treatment. Painting an unparsed
 *    status healthy is the exact defect class this repo already removed once
 *    when it deleted a fabricated SLO.
 *  - `incidents` are the provider's OPEN incidents only. A resolved incident is
 *    history, and this catalog publishes present state.
 *
 * The value object is `readonly` because it is a parse RESULT: the adapters
 * hand it to {@see FeedFetcher}, which persists it and throws it away.
 *
 * Pinned by `tests/Unit/Services/StatuspageV2AdapterTest.php` and
 * `tests/Unit/Services/GoogleStatusAdapterTest.php`.
 */
readonly class FeedReading
{
    /**
     * Longest indicator this reading may carry, matching the width of
     * `service_feed_snapshots.indicator` (string 32) in
     * `database/migrations/2026_08_03_000003_create_service_feed_snapshots_table.php`.
     *
     * An adapter that meets a longer value discards it rather than truncating
     * it: the indicator is QUOTED on the public page as the provider's own
     * word, and half a word attributed to them is a misquote. It is also the
     * only field where the parse and the column width can disagree, so the
     * bound lives here (on the shape both the hash and the column are built
     * from) rather than in the adapter or at the write.
     */
    public const int MAX_INDICATOR_LENGTH = 32;

    /**
     * @param  string|null  $indicator  The provider's own overall status word, verbatim, or null when it publishes none.
     * @param  list<array{label: string, status: ComponentStatus|null}>  $components  Provider components; a null status means unknown.
     * @param  list<array{title: string, impact: IncidentImpact|null, started_at: string|null, url: string|null}>  $incidents  The provider's OPEN incidents.
     */
    public function __construct(
        public ?string $indicator = null,
        public array $components = [],
        public array $incidents = [],
    ) {}

    /**
     * A reading that asserts nothing: no indicator, no components, no
     * incidents.
     *
     * This is what every adapter returns for a payload it cannot make sense
     * of, which is why it must never be confused with "everything is fine".
     * Downstream, an empty reading renders as no data.
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * The components in the shape the `service_feed_snapshots.components`
     * jsonb column stores: enum cases flattened to their backing values so a
     * `ComponentStatus::from()` on the way back out is lossless.
     *
     * Provider ORDER is preserved here (the public page renders the provider's
     * own grouping); it is deliberately NOT preserved in
     * {@see self::normalizedHash()}.
     *
     * @return list<array{label: string, status: string|null}>
     */
    public function componentsToArray(): array
    {
        return array_map(
            static fn (array $component): array => [
                'label' => $component['label'],
                'status' => $component['status']?->value,
            ],
            $this->components,
        );
    }

    /**
     * The open incidents in the shape the `service_feed_snapshots.incidents`
     * jsonb column stores.
     *
     * @return list<array{title: string, impact: string|null, started_at: string|null, url: string|null}>
     */
    public function incidentsToArray(): array
    {
        return array_map(
            static fn (array $incident): array => [
                'title' => $incident['title'],
                'impact' => $incident['impact']?->value,
                'started_at' => $incident['started_at'],
                'url' => $incident['url'],
            ],
            $this->incidents,
        );
    }

    /**
     * A stable sha256 over the PARSED reading, used to decide whether this
     * fetch changed anything.
     *
     * Two properties of this hash are load-bearing, and both exist because
     * `services.content_changed_at` is the sole input to the public sitemap's
     * `lastmod`, which Google discounts sitewide once it is untrustworthy:
     *
     *  1. It hashes the PARSE, not the response bytes. A provider reformatting
     *     its JSON, reordering a key, or bumping an `updated_at` field this
     *     catalog never reads must not register as a content change.
     *  2. It hashes a SORTED projection. Statuspage reorders `components` when
     *     an operator drags one in their admin, and Google's `incidents.json`
     *     is ordered by recency; neither is a change in what the page says.
     *
     * 64 hex characters, which is exactly what
     * `service_feed_snapshots.content_hash_normalized` is sized for.
     */
    public function normalizedHash(): string
    {
        $components = $this->componentsToArray();
        usort($components, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        $incidents = $this->incidentsToArray();
        usort($incidents, static fn (array $a, array $b): int => strcmp(
            ($a['started_at'] ?? '').'|'.$a['title'],
            ($b['started_at'] ?? '').'|'.$b['title'],
        ));

        return hash('sha256', (string) json_encode([
            'indicator' => $this->indicator,
            'components' => $components,
            'incidents' => $incidents,
        ]));
    }
}
