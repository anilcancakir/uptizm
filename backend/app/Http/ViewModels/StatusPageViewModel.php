<?php

namespace App\Http\ViewModels;

/**
 * Immutable, field-allowlisted read model for the public status page.
 *
 * Security contract: this object carries ONLY the presentation fields a
 * visitor may see. There is no monitor `url`, `auth_config`, internal id,
 * `team_id`, or any other secret / tenant identifier anywhere in its shape,
 * so it cannot leak them into a rendered page or a cached payload. The
 * assembler is the sole builder and picks every nested key explicitly.
 *
 * Cache contract: the redis / database cache store runs with
 * `serializable_classes => false` (`config/cache.php`), so caching the OBJECT
 * would rehydrate it as `__PHP_Incomplete_Class` and fatal on the next hit.
 * The cache layer therefore stores {@see self::toArray()} (a PLAIN ARRAY) and
 * rehydrates through {@see self::fromArray()}; the object never touches cache.
 *
 * Language contract: every string in here is already in ONE language, the one
 * the controller applied before the assembler ran, and the cache entry is keyed
 * by it. Each operator-authored free-text field carries two SIBLING keys beside
 * its existing one, `<field>_original` and `<field>_provenance`, so a partial
 * can label a machine translation and offer the original while every reader of
 * the original key keeps seeing a plain string. Nothing per VISITOR lives here:
 * the language offer negotiated from `Accept-Language` and the per-language URL
 * set travel as view data beside this object, because this payload is cached and
 * shared and neither of those is.
 */
readonly class StatusPageViewModel
{
    /**
     * @param array{
     *     name: string,
     *     brand_color: string|null,
     *     logo_text: string|null,
     *     description: string|null,
     *     description_original: string|null,
     *     description_provenance: string,
     *     subscriptions_enabled: bool,
     *     slug: string,
     * } $page                                                       Allowlisted page branding + addressing.
     * @param  string  $overallStatus  Rolled-up status on the severity ladder.
     * @param  string  $overallLabel  Human label for {@see $overallStatus}.
     * @param array<int, array{
     *     title: string,
     *     title_original: string,
     *     title_provenance: string,
     *     description: string|null,
     *     description_original: string|null,
     *     description_provenance: string,
     *     startsAt: string,
     *     endsAt: string,
     *     state: 'in_progress'|'scheduled',
     * }> $maintenances                                              Open and upcoming maintenance windows, earliest first.
     * @param array<int, array{
     *     label: string,
     *     status: string,
     *     uptimePercent: float,
     *     strip: array<int, array{date: string, status: string}>,
     * }> $components                                                Visible components, ordered for display.
     * @param array<int, array{
     *     day: string,
     *     entries: array<int, array{
     *         title: string,
     *         title_original: string,
     *         title_provenance: string,
     *         lifecycle: string,
     *         impact: string,
     *         startedAt: string,
     *         postmortem: array{
     *             body: string|null,
     *             body_original: string|null,
     *             body_provenance: string,
     *             publishedAt: string,
     *         }|null,
     *         updates: array<int, array{
     *             message: string,
     *             message_original: string,
     *             message_provenance: string,
     *             actor: string,
     *             displayAt: string,
     *             status: string,
     *         }>,
     *     }>,
     * }> $incidents                                                 Recent public incidents grouped by day.
     */
    public function __construct(
        public array $page,
        public string $overallStatus,
        public string $overallLabel,
        public array $maintenances,
        public array $components,
        public array $incidents,
        public string $generatedAt,
    ) {}

    /**
     * Plain-array form for caching and Blade. The top-level shape is fixed and
     * every value is already allowlisted by the assembler, so this is safe to
     * serialize and store.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'overallStatus' => $this->overallStatus,
            'overallLabel' => $this->overallLabel,
            'maintenances' => $this->maintenances,
            'components' => $this->components,
            'incidents' => $this->incidents,
            'generatedAt' => $this->generatedAt,
        ];
    }

    /**
     * Rehydrate from the {@see self::toArray()} form a cache hit returns.
     *
     * `maintenances` is read defensively because a cache hit can predate the
     * deploy that introduced it: the public page keeps its payload for 60
     * seconds, so for up to a minute after a release this method is handed an
     * array written by the previous version of the assembler. Every other key
     * has existed since the cache was introduced. This is a cache-shape
     * tolerance, not an optional field: the assembler always writes it.
     *
     * The per-field `<field>_original` / `<field>_provenance` siblings need no
     * tolerance of their own, and that is a property of the release rather than
     * of the shape: the cache key gained a `:{locale}` segment in the same
     * change, so a payload written before this deploy sits under a key nothing
     * looks up any more and cannot be handed to a reader expecting the siblings.
     * A later change that adds a nested key WITHOUT moving the key does not
     * inherit that, and has 60 seconds of pre-deploy payloads to answer for.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            page: $data['page'],
            overallStatus: $data['overallStatus'],
            overallLabel: $data['overallLabel'],
            maintenances: $data['maintenances'] ?? [],
            components: $data['components'],
            incidents: $data['incidents'],
            generatedAt: $data['generatedAt'],
        );
    }
}
