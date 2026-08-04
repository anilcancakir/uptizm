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
 *  4. THE SAME BAR APPLIES TO THE AFFIRMATIVE CLAIM. Fewer than
 *     {@see self::MIN_AGREEING_REGIONS} fresh readings for an endpoint is not
 *     evidence that it was reached, only that one region happened to answer, so
 *     `endpoint()` withholds {@see self::VERDICT_REACHED} below that floor and
 *     reports {@see StatusPageAssembler::STATUS_UNKNOWN} instead. Reachable with
 *     a single configured region even on Cloudflare's region-pinned checkers, but
 *     rare there; once these monitors run over proxy exits that die routinely,
 *     "only one region answered this cycle" stops being an edge case.
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
     * problem in public, AND the floor below which it may not claim the endpoint
     * was reached either.
     *
     * Two, which is the whole of "more than one region". A single region having
     * a bad minute is a fact about that region, and the product already refuses
     * to page a customer on it (`CheckPersistenceService` resets the streak on
     * any non-down result); a public page contradicting a provider's own status
     * page has to clear a higher bar than a pager does.
     *
     * The same number gates the AFFIRMATIVE claim too, and for the identical
     * reason: one region's word is one region's word regardless of which
     * direction it points. See {@see self::endpoint()}'s final rung for what
     * happens below this floor.
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
     * Fresh readings exist, at least {@see self::MIN_AGREEING_REGIONS} of them
     * report the endpoint down, and the monitor's own streak has NOT crossed its
     * `incident_threshold`. Neither "we reached it normally" nor "we could not
     * reach it" is true of that state, so it gets its own rung.
     *
     * WITHOUT THIS RUNG THE PAGE PUBLISHED A FALSE POSITIVE
     *
     * The verdict used to fall through to {@see self::VERDICT_REACHED} whenever
     * `reportsProblem` was false, so a page could say "We reached github.com
     * normally" with a green dot while every fresh region was reporting down. The
     * two conditions behind `reportsProblem` are not independent, which is what
     * made that reachable rather than theoretical:
     * `CheckPersistenceService.php:305-315` resets `consecutive_fails` to 0 on ANY
     * non-down result from ANY region, so while even one region still succeeds the
     * streak cannot climb, the conjunction can never be satisfied, and a partial
     * or flapping outage stayed pinned at the affirmative claim for its whole
     * duration.
     *
     * `degraded` and not `partial_outage`, though the latter is the more literal
     * description: `partial_outage` shares `down`'s red
     * (`StatusPresentation.php:49`), and red would assert the outage this rung
     * exists to say we are NOT yet calling. Amber says what is true, that
     * something is wrong and uptizm is not willing to name it yet.
     *
     * ITS ONE FUNCTIONAL REACH, beyond the words on the page: {@see self::healthyFrom()}
     * maps it to null through the default arm, so a mixed own-block holds no opinion
     * and {@see self::diverges()} therefore stays silent. That is correct rather than
     * incidental. An amber own-block and a provider reporting a problem do not
     * disagree, and before this rung existed the page printed "they do not agree" on
     * top of an own claim that was itself false. Do NOT map this rung to `false`: it
     * would print a divergence on every two-region blip against an all-clear feed.
     *
     * Pinned by `tests/Feature/Marketing/ServiceStatusPageTest.php`, which asserts
     * what the page SAYS in this state rather than only what it does not say, and
     * asserts the divergence suppression. The absence-only assertion is how the false
     * positive shipped.
     */
    public const string VERDICT_MIXED = 'degraded';

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
     *     service: array{slug: string, name: string, category: string|null, monogram: string, brandColor: string|null, logo: string|null},
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
                'monogram' => $this->monogram($service->name),
                'brandColor' => $service->brand_color,
                'logo' => $this->logo($service->slug),
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

        // The service-level verdict is the worst of its endpoints', across FOUR
        // rungs: unreachable when an endpoint cleared both conditions, unknown
        // when nothing is fresh enough to speak for, MIXED when a fresh endpoint
        // is seeing enough regions fail to be a real signal without having
        // crossed its streak threshold, otherwise reached.
        //
        // The mixed rung is not decoration. This roll-up previously fell through
        // to `reached` in that case, so the hub and the headline both published
        // "we reached it normally" over an endpoint whose fresh regions were
        // failing. See VERDICT_MIXED for why the streak cannot be relied on to
        // catch a partial outage.
        $reportsProblem = $endpoints !== [] && in_array(true, array_column($endpoints, 'reportsProblem'), true);
        $fresh = array_values(array_filter($endpoints, static fn (array $endpoint): bool => ! $endpoint['stale']));
        $mixed = array_values(array_filter(
            $fresh,
            static fn (array $endpoint): bool => $endpoint['status'] === self::VERDICT_MIXED,
        ));

        $status = match (true) {
            $reportsProblem => self::VERDICT_UNREACHABLE,
            $fresh === [] => StatusPageAssembler::STATUS_UNKNOWN,
            $mixed !== [] => self::VERDICT_MIXED,
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
        $downRegions = count($down);
        $upRegions = count(array_filter(
            $readings,
            static fn (array $reading): bool => $reading['status'] === MonitorStatus::Up->value,
        ));
        $reportsProblem = $monitor->consecutive_fails >= $threshold
            && $downRegions >= self::MIN_AGREEING_REGIONS;

        // Computed ahead of the return array because `stale` reuses it: the new
        // floor rung below reclassifies a single fresh reading as
        // STATUS_UNKNOWN, and `stale` has to agree with that reclassification or
        // the view's stale-gated headline (the wording this rung reuses) never
        // fires for it. See the class docblock's rule 4.
        $status = match (true) {
            $readings === [] => StatusPageAssembler::STATUS_UNKNOWN,
            $reportsProblem => self::VERDICT_UNREACHABLE,
            // Enough fresh regions report down to be a real signal, but the
            // streak has not crossed the threshold. Neither claim is true, so
            // the middle rung says so rather than falling through to the
            // affirmative one. See VERDICT_MIXED for why this is reachable.
            $downRegions >= self::MIN_AGREEING_REGIONS => self::VERDICT_MIXED,
            $upRegions === 0 => self::VERDICT_MIXED,
            // The floor from the class docblock's rule 4: fewer than
            // MIN_AGREEING_REGIONS fresh readings is one region's word, in
            // either direction, and one region's word does not clear the bar
            // for the affirmative claim either. Below `default` so it only
            // catches what neither the outage nor the mixed rungs already
            // claimed a stronger opinion about.
            count($readings) < self::MIN_AGREEING_REGIONS => StatusPageAssembler::STATUS_UNKNOWN,
            // The affirmative rung has to earn itself. Failing the OUTAGE bar is
            // not evidence of normality: with a single fresh reading that says
            // down, `$downRegions` is 1, the quorum is not met, and the old
            // `default` published "we reached it normally" over a reading that
            // said the opposite. Reachable without a race, because the monitor
            // form lets an operator narrow a catalog monitor to one region.
            // MIN_AGREEING_REGIONS governs the outage claim only; the positive
            // claim needs at least one region that actually succeeded AND the
            // floor above must already have cleared, i.e. at least two fresh
            // regions answered.
            default => self::VERDICT_REACHED,
        };

        return [
            // The host that was actually probed, and the page names it in every
            // sentence about this reading: "we reached github.com" is a claim
            // uptizm can defend, "GitHub is up" is not.
            'label' => $this->endpointLabel($monitor),
            'regionCount' => count($readings),
            'regionsConfigured' => count((array) $monitor->regions),
            'checkIntervalSeconds' => $monitor->check_interval_sec,
            'incidentThreshold' => $threshold,
            // No fresh reading at all, OR too few of them to speak for the
            // endpoint (the floor rung above): either way there is no reliable
            // current value to show, so the view's "we do not know" wording
            // applies to both rather than only to the empty case.
            'stale' => $status === StatusPageAssembler::STATUS_UNKNOWN,
            'status' => $status,
            'reportsProblem' => $reportsProblem,
            'downRegions' => $downRegions,
            // Needed by the view, not just here: the mixed rung has three honest
            // wordings and only `upRegions` tells them apart. Without it the rung
            // said "from some regions and not others" over a reading where NO region
            // reached the endpoint, and over one where every region answered.
            'upRegions' => $upRegions,
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
     * Two letters standing in for the service, for the header tile.
     *
     * A MONOGRAM AND NEVER A LOGO, and that distinction is legal rather than
     * aesthetic. The plan forbids fetching, storing or serving provider artwork,
     * on *Toyota v. Tabari*: the same opinion that cleared plain-text use of
     * somebody else's mark refused stylised use of it. Initials rendered as text
     * are the plain-text half. An image of their logo is the half that was refused,
     * and on a page whose entire job is saying "this is not their official status
     * page" it would say the opposite louder than any disclaimer.
     *
     * The customer status page's tile works the same way
     * (`status/partials/brand-header.blade.php` renders `logo_text` or the first two
     * characters of the name), so this is the house pattern rather than a
     * workaround: that tile has never been an image either.
     *
     * First letters of the first two words when there are two, so "Google Cloud"
     * reads GC rather than Go; otherwise the first two characters, natural case.
     *
     * NO PER-SERVICE COLOUR ACCOMPANIES IT, and that was a considered reversal. A
     * deterministic accent keyed on the slug looked like the obvious way to tell
     * eight tiles apart, until the rendered page showed GitHub carrying
     * `bg-degraded`: this product's token palette is the brand colour plus the six
     * MONITORING STATUS families (up, down, degraded, paused, info, ai), so every
     * candidate accent is a status colour, and an amber tile beside a green banner
     * on a status page reads as a warning about the service. There is no neutral
     * accent family to draw from, and inventing one is a `DESIGN.md` change rather
     * than a view decision.
     *
     * So the tile takes `bg-primary text-on-primary`, which is exactly what
     * `status/partials/brand-header.blade.php` uses for a customer who has set no
     * brand colour of their own. Uniform tiles, and the NAME does the
     * distinguishing. If per-service colour is wanted later it belongs on the
     * service row as an operator-set value, with the trade-dress caveat that the
     * provider's own official colour is the one thing it must not be.
     *
     * MONOGRAMS CAN COLLIDE and that is accepted: the seeded catalog already has two
     * Cl (Claude, Cloudflare). Left alone because the tile never appears without the
     * full name beside it, on the page header and in the hub's section heading alike,
     * so the name does the identifying and the tile is decoration. Every scheme that
     * would disambiguate them (Cl/Cf, dropping vowels, a per-service override) needs
     * knowledge of the specific pair rather than a rule, and a rule nobody can state
     * is worse than two tiles that look alike next to two different names.
     */
    protected function monogram(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            // Two words, two initials, both capitalised: "Google Cloud" reads GC
            // rather than Go.
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        // One word keeps its natural case, so "GitHub" reads Gi and not GI. The
        // customer status page's tile does the same ("Fl" for FlutterSDK): shouting
        // two letters at somebody is not a brand mark.
        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1, 1);
    }

    /**
     * The service's own mark, as inline SVG, or null when this catalog does not ship
     * one for it.
     *
     * SELF-HOSTED AND INLINE, never a remote URL, and that is not a performance
     * choice. `resources/legal/privacy.en.md` publishes that the read-only public
     * surface reaches no third-party host at all, and
     * `ShowPrivacyController::thirdPartyScriptCount()` derives that claim from
     * configuration. Loading a mark from Clearbit, a favicon service or the
     * provider's own CDN would make that party a recipient of every visitor's IP
     * address and falsify a published statement, which is a worse outcome than
     * having no logo. Inline rather than an `<img src>` for the same reason it is
     * self-hosted: no second request, and it inherits `currentColor` so it works in
     * both colour schemes.
     *
     * Six of the eight seeded services have a file; OpenAI and Slack do not, and the
     * reason is worth keeping: the CC0 `simple-icons` dataset these were taken from
     * carries neither, because that project removes a brand when its owner asks. So
     * the two absences are the two objections, and hunting the marks down elsewhere
     * would be routing around a refusal. They render their monogram instead.
     *
     * The files are trusted repository content, which is what makes the unescaped
     * echo in the view safe; nothing on this path is visitor input, and a slug that
     * reached the filesystem is bounded by `ShowServiceStatusController::SLUG_PATTERN`
     * before it ever gets here.
     */
    protected function logo(string $slug): ?string
    {
        $path = resource_path('svg/brands/'.$slug.'.svg');

        return is_file($path) ? (string) file_get_contents($path) : null;
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
