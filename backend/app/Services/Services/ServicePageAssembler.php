<?php

namespace App\Services\Services;

use App\Enums\ComponentStatus;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\StatusProvenance;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Service;
use App\Models\ServiceFeedSnapshot;
use App\Services\StatusPages\ComponentDailyUptimeService;
use App\Services\StatusPages\StatusPageAssembler;
use App\Support\StatusPages\StatusPresentation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the read model behind a public service page, and owns every honesty
 * rule that page publishes under.
 *
 * The page carries TWO separately-labelled blocks and never a third answer
 * synthesized from them:
 *
 *   - `own` is uptizm's OWN measurement, from the system-team monitors attached
 *     through the `service_monitor` pivot: the latest reading per region, the
 *     response time, and the 90-day daily strip.
 *   - `feed` is the PROVIDER's own published state, read from
 *     {@see Service::latestFeedSnapshot()}: their indicator quoted verbatim,
 *     their components, their open incidents, and how old that publication is.
 *
 * Each block carries its {@see StatusProvenance}, and `divergence` says whether
 * the two disagree. Nothing here averages them, ranks one above the other, or
 * suppresses one because the other looks more authoritative: when they disagree
 * the page renders both and says so, because uptizm is an external observer of
 * one endpoint while the provider can see its own internals, and those two
 * honest answers legitimately differ.
 *
 * ## The three rules that decide what this page may say
 *
 *  1. NO THIRD-PARTY UPTIME PERCENTAGE, AND NO SLA FIGURE. uptizm probes one
 *     endpoint of one product, so a percentage would imply coverage it does not
 *     have. This class therefore computes no percentage at all: it is not
 *     rendered, not cached, and not available to a template that might be
 *     tempted to label it as the provider's uptime. That deliberate absence is
 *     why {@see StatusPageAssembler::uptimePercent()} is not reused here; the
 *     90-day strip travels as day-by-day statuses labelled as uptizm's
 *     reachability of the NAMED endpoint. This is the same defect class as the
 *     fabricated SLO this repo already removed once.
 *  2. EVERY READING IS FIRST PERSON, ENDPOINT-NAMED AND TIMESTAMPED. Each
 *     endpoint carries the host that was probed and when, and past
 *     {@see self::STALE_AFTER_SECONDS} it becomes
 *     {@see StatusPageAssembler::STATUS_UNKNOWN} rather than freezing on its
 *     last known value: checks older than the bound are never read at all, so
 *     there is no last-known value in the model to fall back to.
 *  3. THE PUBLIC HEADLINE FLIPS ON STRICTER EVIDENCE THAN A CUSTOMER ALERT.
 *     `reportsProblem` requires the monitor's own `incident_threshold` streak
 *     AND at least {@see self::MIN_AGREEING_REGIONS} regions agreeing. That is
 *     deliberately the inverse of customer tuning, where speed wins: a customer
 *     wants to know fast, a public page contradicting the provider's own status
 *     page needs to be right. It also bounds the known open defect that the edge
 *     worker ignores `auth_config` and `assertion_rules`, which is exactly the
 *     class of bug that produces a single-region false positive.
 *
 * Everything returned is plain scalars and arrays, never an object: the
 * controller caches this payload for 60 seconds and the cache store runs with
 * `serializable_classes => false`, the same constraint
 * {@see StatusPageAssembler} works under.
 *
 * `tests/Feature/Marketing/ServiceStatusPageTest.php` pins each rule, and pins
 * each refusal branch in isolation: a fixture that trips two of them at once
 * would prove neither.
 */
class ServicePageAssembler
{
    /**
     * Age, in seconds, past which one of uptizm's own readings stops being
     * current and becomes unknown.
     *
     * Public and referenced BY NAME from the test rather than duplicated as a
     * literal, because the number is a published commitment: ten times the
     * fastest feed poll and well past a single missed check at the one-minute
     * cadence this catalog's monitors run on, so a reading goes unknown only
     * when something is actually wrong rather than when a tick was late.
     */
    public const int STALE_AFTER_SECONDS = 600;

    /**
     * How many distinct regions must agree before the own-probe block reports a
     * problem in public.
     *
     * Two, which is the whole of "more than one region". A single region having
     * a bad minute is a fact about that region, and the product already refuses
     * to page a customer on it (`CheckPersistenceService` resets the streak on
     * any non-down result); a public page contradicting a provider's own status
     * page has to clear a higher bar than a pager does.
     */
    public const int MIN_AGREEING_REGIONS = 2;

    /**
     * The verdict when uptizm reached the endpoint, and the verdict when it did
     * not.
     *
     * These are rungs of {@see ComponentDailyUptimeService::STATUS_LADDER}, held
     * as named constants because {@see StatusPresentation} already owns a colour
     * for each and the view must not invent a second vocabulary. The third
     * possible verdict is {@see StatusPageAssembler::STATUS_UNKNOWN}, reused for
     * the same reason: `MonitorStatus` deliberately has no `unknown` case, and
     * that view-layer constant is where the concept already lives.
     */
    public const string VERDICT_REACHED = 'operational';

    public const string VERDICT_UNREACHABLE = 'major_outage';

    /**
     * Upper bound on the check rows read per endpoint while reducing to the
     * latest reading per region.
     *
     * The reduction happens in PHP rather than in SQL on purpose. `DISTINCT ON`
     * is PostgreSQL-only and a `GROUP BY` with non-aggregated columns is
     * rejected by PostgreSQL while SQLite accepts it, so either would be a query
     * this repo's SQLite test suite could not speak for (the `max(uuid)` defect
     * recorded on `Service::latestFeedSnapshot()` is the same lesson). The bound
     * keeps the read finite: at a one-minute cadence across five regions the
     * staleness window holds about fifty rows.
     */
    protected const int MAX_RECENT_CHECK_ROWS = 200;

    public function __construct(
        protected ComponentDailyUptimeService $uptime = new ComponentDailyUptimeService,
    ) {}

    /**
     * The full read model for one service's own page, strip included.
     *
     * @return array<string, mixed>
     */
    public function build(Service $service): array
    {
        return $this->assemble($service, withStrip: true);
    }

    /**
     * The same read model without the 90-day strips, for the hub, which lists
     * every published service and shows no strip.
     *
     * @return array<string, mixed>
     */
    public function summarize(Service $service): array
    {
        return $this->assemble($service, withStrip: false);
    }

    /**
     * @return array{
     *     service: array{slug: string, name: string, category: string|null},
     *     own: array<string, mixed>,
     *     feed: array<string, mixed>|null,
     *     divergence: bool,
     *     generatedAt: string,
     * }
     */
    protected function assemble(Service $service, bool $withStrip): array
    {
        $own = $this->ownMeasurement($service, $withStrip);
        $feed = $this->providerFeed($service);

        return [
            // Field-allowlisted, like the status page's own view model: nothing
            // internal (ids, team, the feed url, the terms note) reaches a
            // public template through this payload.
            'service' => [
                'slug' => $service->slug,
                'name' => $service->name,
                'category' => $service->category,
            ],
            'own' => $own,
            'feed' => $feed,
            'divergence' => $this->diverges($own['healthy'], $feed['healthy'] ?? null),
            // Assembly time, not render time: this payload is cached for 60
            // seconds and every age in it was computed here, so the page states
            // when it was assembled rather than implying the numbers are live to
            // the second.
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * Uptizm's own measurement: one entry per attached monitor, plus the single
     * public verdict rule 3 allows.
     *
     * @return array{
     *     provenance: string,
     *     status: string,
     *     healthy: bool|null,
     *     reportsProblem: bool,
     *     dissentingRegions: int,
     *     endpoints: list<array<string, mixed>>,
     * }
     */
    protected function ownMeasurement(Service $service, bool $withStrip): array
    {
        $monitors = $service->monitors;
        $strips = $withStrip
            ? $this->uptime->last90DaysForMonitors($monitors->pluck('id')->all())
            : [];

        $endpoints = [];
        foreach ($monitors as $monitor) {
            $endpoints[] = $this->endpoint($monitor, $withStrip ? ($strips[$monitor->id] ?? []) : null);
        }

        // The service-level verdict is the worst of its endpoints', and "worst"
        // here has only three rungs on purpose (see rule 3): unreachable when an
        // endpoint cleared BOTH conditions, unknown when nothing is fresh enough
        // to speak for, otherwise reached. A degraded or single-region failure is
        // visible in the per-region rows and in the strip, and is deliberately
        // not a headline claim.
        $reportsProblem = $endpoints !== [] && in_array(true, array_column($endpoints, 'reportsProblem'), true);
        $fresh = array_values(array_filter($endpoints, static fn (array $endpoint): bool => ! $endpoint['stale']));

        $status = match (true) {
            $reportsProblem => self::VERDICT_UNREACHABLE,
            $fresh === [] => StatusPageAssembler::STATUS_UNKNOWN,
            default => self::VERDICT_REACHED,
        };

        return [
            'provenance' => StatusProvenance::OwnProbe->value,
            'status' => $status,
            'healthy' => $this->healthyFrom($status),
            'reportsProblem' => $reportsProblem,
            // How many fresh regional readings across all endpoints are NOT up.
            // Rendered as the dissent it is ("one region of five could not reach
            // it, which we do not call an outage") rather than suppressed, so the
            // stricter public bar is visible instead of merely applied.
            'dissentingRegions' => array_sum(array_column($endpoints, 'dissentingRegions')),
            'endpoints' => $endpoints,
        ];
    }

    /**
     * One probed endpoint: what was probed, the latest reading per region, and
     * the 90-day strip when the caller wants it.
     *
     * @param  array<int, array<string, mixed>>|null  $strip  Null when the caller does not render one.
     * @return array<string, mixed>
     */
    protected function endpoint(Monitor $monitor, ?array $strip): array
    {
        $readings = $this->latestReadingPerRegion($monitor);

        $latencies = array_values(array_filter(
            array_column($readings, 'responseMs'),
            static fn (?int $ms): bool => $ms !== null,
        ));

        $down = array_values(array_filter(
            $readings,
            static fn (array $reading): bool => $reading['status'] === MonitorStatus::Down->value,
        ));

        // BOTH conditions, and neither on its own: the monitor's own streak has
        // to have crossed its `incident_threshold` AND the failure has to be
        // agreed by more than one region.
        $threshold = $monitor->incident_threshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD;
        $reportsProblem = $monitor->consecutive_fails >= $threshold
            && count($down) >= self::MIN_AGREEING_REGIONS;

        return [
            // The host that was actually probed, and the page names it in every
            // sentence about this reading: "we reached github.com" is a claim
            // uptizm can defend, "GitHub is up" is not.
            'label' => $this->endpointLabel($monitor),
            'regionCount' => count($readings),
            'regionsConfigured' => count((array) $monitor->regions),
            'checkIntervalSeconds' => $monitor->check_interval_sec,
            'incidentThreshold' => $threshold,
            // No fresh reading at all: unknown, and there is no older value in
            // this payload to fall back to.
            'stale' => $readings === [],
            'status' => match (true) {
                $readings === [] => StatusPageAssembler::STATUS_UNKNOWN,
                $reportsProblem => self::VERDICT_UNREACHABLE,
                default => self::VERDICT_REACHED,
            },
            'reportsProblem' => $reportsProblem,
            'dissentingRegions' => count(array_filter(
                $readings,
                static fn (array $reading): bool => $reading['status'] !== MonitorStatus::Up->value,
            )),
            // The mean across the regions that answered, labelled as an average
            // where it is rendered. Null when nothing answered, never zero: a
            // zero would read as an instant response.
            'responseMs' => $latencies === [] ? null : (int) round(array_sum($latencies) / count($latencies)),
            'checkedAt' => $readings[0]['checkedAt'] ?? null,
            'ageSeconds' => $readings[0]['ageSeconds'] ?? null,
            'readings' => $readings,
            // Day-by-day, and NO percentage: a day nobody measured carries a
            // null status, which every consumer of this repo's status vocabulary
            // already renders as the neutral "not measured" family rather than
            // borrowing green.
            'strip' => $strip === null ? null : array_map(
                static fn (array $day): array => [
                    'date' => $day['date'],
                    'status' => $day['worst_status'],
                ],
                $strip,
            ),
        ];
    }

    /**
     * The newest check per region inside the staleness window, newest region
     * first.
     *
     * The window IS the staleness rule: rows older than
     * {@see self::STALE_AFTER_SECONDS} are never selected, so a stale endpoint
     * has no last-known reading in the payload and cannot be rendered as one by
     * a careless template.
     *
     * @return list<array{region: string, label: string, status: string, ladder: string, responseMs: int|null, checkedAt: string, ageSeconds: int}>
     */
    protected function latestReadingPerRegion(Monitor $monitor): array
    {
        $now = CarbonImmutable::now();

        /** @var Collection<int, MonitorCheck> $checks */
        $checks = MonitorCheck::query()
            ->where('monitor_id', $monitor->getKey())
            ->where('checked_at', '>=', $now->subSeconds(self::STALE_AFTER_SECONDS))
            ->orderByDesc('checked_at')
            ->limit(self::MAX_RECENT_CHECK_ROWS)
            ->get();

        $readings = [];
        foreach ($checks as $check) {
            $region = (string) $check->region;

            // Newest first, so the first row seen for a region IS that region's
            // current reading and every later one is history.
            if (array_key_exists($region, $readings)) {
                continue;
            }

            $readings[$region] = [
                'region' => $region,
                'label' => MonitorRegion::tryFrom($region)?->label() ?? $region,
                'status' => $check->status?->value ?? StatusPageAssembler::STATUS_UNKNOWN,
                'ladder' => $this->ladderStatus($check->status),
                'responseMs' => $check->response_ms,
                'checkedAt' => $check->checked_at->toIso8601String(),
                'ageSeconds' => max(0, (int) $now->diffInSeconds($check->checked_at, absolute: true)),
            ];
        }

        return array_values($readings);
    }

    /**
     * A monitor health value on the public severity ladder, which is the only
     * vocabulary {@see StatusPresentation} has a colour for.
     *
     * This is the null-to-unknown mapping re-derived rather than borrowed:
     * {@see StatusPageAssembler}'s equivalent is `protected` and belongs to the
     * customer status page. The two rules that matter are the same as its:
     * a monitor nobody has probed yet resolves to UNKNOWN and never to
     * operational, because a green cell claims the checks ran and passed, and a
     * paused monitor has no verdict either.
     */
    protected function ladderStatus(?MonitorStatus $status): string
    {
        return match ($status) {
            MonitorStatus::Up => self::VERDICT_REACHED,
            MonitorStatus::Degraded => 'degraded',
            MonitorStatus::Down => self::VERDICT_UNREACHABLE,
            MonitorStatus::Paused, null => StatusPageAssembler::STATUS_UNKNOWN,
        };
    }

    /**
     * A provider component status on the same public ladder.
     *
     * An exhaustive match with no default arm, so a case added to
     * {@see ComponentStatus} is a failure here rather than a silently grey row.
     * Null (a word this repo has no case for, or a provider publishing no
     * per-component health at all) is UNKNOWN, never operational: this is the
     * rule the ingester already refuses to break on the way in, and it must not
     * be broken on the way out either.
     */
    protected function componentLadderStatus(?ComponentStatus $status): string
    {
        return match ($status) {
            ComponentStatus::Operational => self::VERDICT_REACHED,
            ComponentStatus::DegradedPerformance => 'degraded',
            ComponentStatus::PartialOutage => 'partial_outage',
            ComponentStatus::MajorOutage => self::VERDICT_UNREACHABLE,
            null => StatusPageAssembler::STATUS_UNKNOWN,
        };
    }

    /**
     * The host uptizm probed, which is what the page is allowed to make a claim
     * about.
     *
     * The pivot's `label` is the operator's own public wording for it (the
     * catalog seeder stores the probed host there). Falling back to the URL's
     * host and never to `Monitor::name`, because that name embeds the provider's
     * product name ("GitHub (github.com)") and this string is rendered inside
     * "we reached ___", where a product name would silently widen the claim from
     * one endpoint to a whole product.
     */
    protected function endpointLabel(Monitor $monitor): string
    {
        $label = $monitor->pivot?->label;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        $host = parse_url((string) $monitor->url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : (string) $monitor->url;
    }

    /**
     * The provider's own published state, or null when there is none to quote.
     *
     * Null covers three honest cases and they are deliberately indistinguishable
     * to the page, which simply renders no provider block: a `status_source` of
     * `none` (Slack and Stripe publish nothing this catalog can parse, so they
     * publish on uptizm's own measurement alone), and a service ingestion has
     * not reached yet.
     *
     * @return array{
     *     provenance: string,
     *     indicator: string|null,
     *     components: list<array{label: string, status: string|null, ladder: string}>,
     *     incidents: list<array<string, mixed>>,
     *     fetchedAt: string|null,
     *     ageSeconds: int|null,
     *     stale: bool,
     *     httpStatus: int|null,
     *     error: string|null,
     *     healthy: bool|null,
     * }|null
     */
    protected function providerFeed(Service $service): ?array
    {
        $snapshot = $service->latestFeedSnapshot;

        if (! $snapshot instanceof ServiceFeedSnapshot) {
            return null;
        }

        $age = $snapshot->fetched_at === null
            ? null
            : max(0, (int) CarbonImmutable::now()->diffInSeconds($snapshot->fetched_at, absolute: true));

        // The same bound uptizm's own readings answer to. A publication we
        // fetched twenty minutes ago is quoted with its timestamp and explicitly
        // NOT presented as their current state, and it stops contributing to the
        // divergence comparison, because a disagreement with a stale quote is
        // not a disagreement.
        $stale = $age === null || $age > self::STALE_AFTER_SECONDS;

        $components = $this->feedComponents($snapshot);
        $incidents = $this->feedIncidents($snapshot);

        return [
            'provenance' => StatusProvenance::ProviderFeed->value,
            // VERBATIM. Their `none|minor|major|critical` vocabulary is not
            // uptizm's `up|down|degraded|paused`, and their `minor` can mean one
            // sub-product is slow, so the page quotes the word and never
            // translates it into a MonitorStatus.
            'indicator' => $snapshot->indicator,
            'components' => $components,
            'incidents' => $incidents,
            'fetchedAt' => $snapshot->fetched_at?->toIso8601String(),
            'ageSeconds' => $age,
            'stale' => $stale,
            'httpStatus' => $snapshot->http_status,
            'error' => $snapshot->error,
            'healthy' => $stale
                ? null
                : $this->feedHealthy($snapshot->indicator, $components, $incidents, $snapshot->error),
        ];
    }

    /**
     * The provider's component rows, their own labels and their own status
     * words.
     *
     * A status of null stays null: an unrecognised vocabulary word, or a
     * provider that publishes no per-component health at all, is UNKNOWN and
     * renders as the no-data treatment. It is never promoted to operational.
     *
     * @return list<array{label: string, status: string|null, ladder: string}>
     */
    protected function feedComponents(ServiceFeedSnapshot $snapshot): array
    {
        return array_values(array_map(
            function (array $component): array {
                $status = is_string($component['status'] ?? null)
                    ? ComponentStatus::tryFrom($component['status'])
                    : null;

                return [
                    'label' => (string) ($component['label'] ?? ''),
                    // Their word, carried verbatim for display.
                    'status' => $status?->value,
                    // The same fact on the ladder, for the colour. This one IS
                    // safe to colour: `ComponentStatus`'s backing values are a
                    // byte-exact match for the vocabulary providers publish, so it
                    // is not a translation. The top-level indicator is different
                    // and the view leaves that one uncoloured on purpose.
                    'ladder' => $this->componentLadderStatus($status),
                ];
            },
            (array) $snapshot->components,
        ));
    }

    /**
     * The provider's OPEN incidents, as their claim.
     *
     * Read out of the snapshot and rendered as a quote with a link back to their
     * own page. No `Incident` row is ever opened on the provider's behalf from a
     * feed reading: uptizm's incidents come from uptizm's probes.
     *
     * @return list<array{title: string, impact: string|null, startedAt: string|null, url: string|null}>
     */
    protected function feedIncidents(ServiceFeedSnapshot $snapshot): array
    {
        return array_values(array_map(
            // NOT `static`: this closure calls an instance method below, and a static
            // one fatals with "Using $this when not in object context" the moment a
            // provider actually publishes an open incident. The whole test suite passed
            // over it, because a snapshot with components but no incidents never enters
            // this loop; the live QA walk is what found it, and
            // `test_a_provider_incident_is_quoted_with_a_safe_link_only` is what keeps
            // it found.
            fn (array $incident): array => [
                'title' => (string) ($incident['title'] ?? ''),
                'impact' => is_string($incident['impact'] ?? null) ? $incident['impact'] : null,
                'startedAt' => is_string($incident['started_at'] ?? null) ? $incident['started_at'] : null,
                // Only an http(s) link is carried through: this value came from a
                // remote document and lands in an `href`, where a `javascript:`
                // scheme would be a stored-XSS vector that Blade's escaping does
                // not touch.
                'url' => $this->safeProviderUrl($incident['url'] ?? null),
            ],
            (array) $snapshot->incidents,
        ));
    }

    /**
     * A provider-supplied URL safe to put in an `href`, or null.
     *
     * `str_starts_with` on the scheme after a `parse_url`, so a
     * `javascript:`/`data:` value from a remote feed cannot reach the markup.
     * Blade escapes the attribute's characters but not its meaning.
     */
    protected function safeProviderUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Whether the provider's own publication reads as healthy, for the
     * divergence comparison ONLY.
     *
     * This is not a translation of their vocabulary into uptizm's, and nothing
     * renders it as a status: the page quotes their indicator, lists their
     * components and lists their incidents. It is a three-state predicate over
     * what they published, so the page can tell whether the two blocks disagree:
     *
     *   - null, "they publish nothing useful right now": a failed fetch, or a
     *     document with no indicator, no components and no incidents.
     *   - false, "they are reporting something": an open incident, a component
     *     they marked as anything other than operational, or an indicator that
     *     is not their all-clear word.
     *   - true, "they are reporting fine": no such signal, and at least one
     *     positive one, so an empty document cannot pass for an all-clear.
     *
     * @param  list<array{label: string, status: string|null, ladder: string}>  $components
     * @param  list<array<string, mixed>>  $incidents
     */
    protected function feedHealthy(?string $indicator, array $components, array $incidents, ?string $error): ?bool
    {
        if ($error !== null) {
            return null;
        }

        $statuses = array_column($components, 'status');
        $problem = $incidents !== []
            || array_filter($statuses, static fn (?string $status): bool => $status !== null
                && $status !== ComponentStatus::Operational->value) !== []
            // `none` is Statuspage's own all-clear word. Any other word they
            // publish is treated as "they are saying something", without
            // deciding what.
            || ($indicator !== null && $indicator !== 'none');

        if ($problem) {
            return false;
        }

        $positive = $indicator === 'none'
            || in_array(ComponentStatus::Operational->value, $statuses, true);

        return $positive ? true : null;
    }

    /**
     * Whether the two blocks disagree about the service being healthy.
     *
     * Only when BOTH have an opinion. An unknown block does not disagree with
     * anything, which is what keeps the divergence sentence off a page where one
     * side simply has no data, and is why the sentence asserts something when it
     * IS there.
     */
    protected function diverges(?bool $own, ?bool $feed): bool
    {
        return $own !== null && $feed !== null && $own !== $feed;
    }

    /**
     * The tri-state healthy flag behind one of uptizm's own verdicts. Unknown is
     * null and never false: "we could not measure" is not "it is down".
     */
    protected function healthyFrom(string $status): ?bool
    {
        return match ($status) {
            self::VERDICT_REACHED => true,
            self::VERDICT_UNREACHABLE => false,
            default => null,
        };
    }
}
