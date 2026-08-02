<?php

namespace App\Services\StatusPages;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Http\ViewModels\StatusPageViewModel;
use App\Jobs\BustStatusPageCacheForMaintenanceBoundaries;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Builds the public status page's read model from a {@see StatusPage}.
 *
 * Every visibility and secrecy decision is made HERE, at the query and the
 * mapping, not in Blade:
 *   - Components are scoped in the query (shown, not paused, and not hidden
 *     by `only_show_if_degraded` while healthy), so a paused or hidden monitor
 *     never reaches the template.
 *   - The 90-day strips are batch-loaded in one query (no N+1 per component).
 *   - The overall banner rolls the visible components up the severity ladder.
 *   - Only PUBLIC incident updates are loaded, grouped by day (Cachet pattern).
 *   - Maintenance windows are scoped to the page they were announced on AND to
 *     its visible monitors, so planned work can never publish a component the
 *     page hides.
 *
 * The result is a {@see StatusPageViewModel}: a field-allowlisted object that
 * carries no monitor url / auth_config / internal id / team_id.
 */
class StatusPageAssembler
{
    /**
     * Trailing window (in days) of incidents surfaced on the public page. An
     * incident is shown when it is either still active or carries a public
     * update within this window.
     */
    protected const int RECENT_INCIDENT_DAYS = 14;

    /**
     * Forward horizon (in days) of maintenance windows surfaced on the public
     * page. A window is shown while it is open, and from this far ahead of its
     * start.
     *
     * "Open now or starting soon" needs a definite bound, and a week is the one
     * this surface earns. It is the span an operator schedules inside and the
     * span a visitor can still act on: rescheduling a deployment, holding off an
     * import, warning their own users. A window a month out changes nobody's
     * afternoon, and this section sits ABOVE the components, so anything that
     * cannot be acted on yet is pushing the actual health of the service further
     * down the page. Nothing is lost by waiting: a window beyond the horizon
     * simply appears once it comes within a week of starting.
     */
    public const int UPCOMING_MAINTENANCE_DAYS = 7;

    /**
     * Overall status for a page with no published components.
     *
     * Deliberately NOT on the severity ladder: it is the absence of a verdict,
     * not a rung on it. Kept as a constant because the Blade banner matches on
     * it to pick a neutral dot rather than a green one.
     */
    public const string STATUS_UNKNOWN = 'unknown';

    public function __construct(
        protected ComponentDailyUptimeService $uptime = new ComponentDailyUptimeService,
    ) {}

    /**
     * Assemble the public read model for the given status page.
     */
    public function build(StatusPage $page): StatusPageViewModel
    {
        // 1. Scope visible components at the DB level (fail-closed presentation).
        //    This id set is the ONLY set anything else on this page may draw
        //    from: the strips, the incidents and the maintenance windows are all
        //    keyed on it, so a monitor the page hides cannot surface through a
        //    side channel.
        $monitors = $this->visibleMonitors($page);
        $monitorIds = $monitors->pluck('id')->all();

        // 2. Batch-load every 90-day strip in a single rollup query.
        $strips = $this->uptime->last90DaysForMonitors($monitorIds);

        // 3. Shape components and roll their statuses up the severity ladder.
        //
        // A page with NOTHING published does not get a health verdict. `worstOf`
        // returns the bottom of the ladder for an empty set, which would put
        // "All Systems Operational" above a components card reading "No
        // components are currently published on this page" -- a claim about
        // systems the page is not tracking, next to the admission that it is
        // tracking none. On a status page that is not a fail-safe default, it is
        // the one lie that costs the most trust.
        $components = $this->buildComponents($monitors, $strips);
        $overallStatus = $components === []
            ? self::STATUS_UNKNOWN
            : $this->uptime->worstOf(array_column($components, 'status'));

        return new StatusPageViewModel(
            page: $this->buildPage($page),
            overallStatus: $overallStatus,
            overallLabel: $this->overallLabel($overallStatus),
            // Planned work first in the read model as well as on the page: a
            // visitor arriving during a window should read "this was announced"
            // before reading the red it explains.
            maintenances: $this->buildMaintenances($page, $monitorIds),
            components: $components,
            // Scope incidents to the VISIBLE monitors only: an incident title
            // embeds the monitor name ("{name} is down"), so pulling incidents
            // for a hidden/paused component would leak its name even though its
            // row is hidden from the page.
            incidents: $this->buildIncidents($monitorIds),
            // Assembly time travels in the cached array, so "updated Xm ago"
            // reflects the age of the cached snapshot, not the render time.
            generatedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }

    /**
     * Monitors shown on the page, scoped in the query: shown on the status
     * page, not paused, and not hidden by `only_show_if_degraded` while up.
     *
     * @return Collection<int, Monitor>
     */
    protected function visibleMonitors(StatusPage $page): Collection
    {
        return $page->monitors()
            ->where('show_on_status_page', true)
            ->where(function (Builder $query): void {
                // "Not paused": a never-checked (null) monitor still shows.
                $query->whereNull('last_status')
                    ->orWhere('last_status', '!=', MonitorStatus::Paused->value);
            })
            ->whereNot(function (Builder $query): void {
                // Degraded-only components disappear from the page while healthy.
                $query->where('only_show_if_degraded', true)
                    ->where('last_status', MonitorStatus::Up->value);
            })
            ->get();
    }

    /**
     * Map each visible monitor to its allowlisted component shape.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  array<int|string, array<int, array<string, mixed>>>  $strips
     * @return array<int, array{
     *     label: string,
     *     status: string,
     *     uptimePercent: float|null,
     *     strip: array<int, array{date: string, status: string}>,
     * }>
     */
    /**
     * The labels `$page` actually PUBLISHES for the given monitors: the visible
     * set intersected with `$monitorIds`, each rendered as the page's own
     * `custom_label` when it has one.
     *
     * Public, because the subscriber announcement mail needs the same answer the
     * page renders and got it wrong by reading the window's raw pivot instead:
     * that named a component the operator had hidden, and used the internal
     * monitor name where the page publishes a friendly one. Visibility is a
     * presentation-time decision over one authoritative id set, so every
     * surface that shows a component name to the public resolves it here.
     *
     * @param  list<string>  $monitorIds  Candidates to intersect with the visible set.
     * @return list<string>
     */
    public function publicComponentLabels(StatusPage $page, array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        return $this->visibleMonitors($page)
            ->whereIn('id', $monitorIds)
            ->map(static fn (Monitor $monitor): string => $monitor->pivot?->custom_label ?? $monitor->name)
            ->values()
            ->all();
    }

    protected function buildComponents(Collection $monitors, array $strips): array
    {
        $components = [];

        foreach ($monitors as $monitor) {
            $strip = $strips[$monitor->id] ?? [];

            $components[] = [
                'label' => $monitor->pivot?->custom_label ?? $monitor->name,
                'status' => $this->componentStatus($monitor->last_status),
                'uptimePercent' => $this->uptimePercent($strip),
                'strip' => array_map(
                    static fn (array $day): array => [
                        'date' => $day['date'],
                        'status' => $day['worst_status'],
                    ],
                    $strip,
                ),
            ];
        }

        return $components;
    }

    /**
     * Maintenance windows this page announces that are open right now or start
     * within {@see self::UPCOMING_MAINTENANCE_DAYS}, earliest start first (so an
     * in-progress window always heads the list).
     *
     * Two scopes, and on a public surface both are load-bearing:
     *
     *   - `status_page_id`. An operator picks the page a window is announced on.
     *     A monitor can be published on several pages, so without this scope one
     *     team's internal-facing page would drag its wording onto the
     *     customer-facing one.
     *   - An intersection with the page's VISIBLE monitors. A window's title and
     *     description describe work on a named component ("Upgrading the
     *     payments database"), so announcing one announces that the component
     *     exists. A window whose monitors are all hidden or paused therefore
     *     publishes nothing at all.
     *
     * A window from ANOTHER TEAM can satisfy neither: the page id belongs to one
     * tenant, and so does every id in the visible set.
     *
     * The state is derived here rather than in Blade, because the payload is
     * cached for 60 seconds and its boundaries are busted by
     * {@see BustStatusPageCacheForMaintenanceBoundaries}; a template asking the
     * clock would disagree with the snapshot it was rendered from.
     *
     * @param  array<int, int|string>  $monitorIds  The page's visible monitor ids.
     * @return array<int, array{
     *     title: string,
     *     description: string|null,
     *     startsAt: string,
     *     endsAt: string,
     *     state: 'in_progress'|'scheduled',
     * }>
     */
    protected function buildMaintenances(StatusPage $page, array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        $now = CarbonImmutable::now();

        return ScheduledMaintenance::query()
            ->where('status_page_id', $page->getKey())
            // Open or upcoming: it has not finished, and it starts inside the
            // horizon. A finished window is history; the incidents section is
            // where any consequence of it lives.
            ->where('ends_at', '>=', $now)
            ->where('starts_at', '<=', $now->addDays(self::UPCOMING_MAINTENANCE_DAYS))
            ->whereHas('monitors', fn (Builder $monitors) => $monitors->whereIn('monitors.id', $monitorIds))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ScheduledMaintenance $window): array => [
                'title' => $window->title,
                'description' => $window->description,
                'startsAt' => $window->starts_at->toIso8601String(),
                'endsAt' => $window->ends_at->toIso8601String(),
                'state' => $window->starts_at->lessThanOrEqualTo($now) ? 'in_progress' : 'scheduled',
            ])
            ->all();
    }

    /**
     * Recent public incidents affecting the page's monitors, each carrying only
     * its `is_public` updates, grouped by the day the incident started.
     *
     * @return array<int, array{
     *     day: string,
     *     entries: array<int, array<string, mixed>>,
     * }>
     */
    protected function buildIncidents(array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        $since = CarbonImmutable::now()->subDays(self::RECENT_INCIDENT_DAYS);

        $incidents = Incident::query()
            ->whereHas('monitors', fn (Builder $query) => $query->whereIn('monitors.id', $monitorIds))
            ->where('started_at', '>=', $since)
            ->where(function (Builder $query): void {
                // Public when it carries a public update, is still active, or has
                // a published postmortem (publishing IS the act of making the
                // incident's write-up customer-visible, so it must list the
                // incident even when no public update was ever posted).
                $query->whereHas('updates', fn (Builder $updates) => $updates->where('is_public', true))
                    ->orWhere('lifecycle', '!=', IncidentStatus::Resolved->value)
                    ->orWhereNotNull('postmortem_published_at');
            })
            ->with(['updates' => fn (Relation $updates) => $updates->where('is_public', true)])
            ->orderByDesc('started_at')
            ->get();

        return $this->groupIncidentsByDay($incidents);
    }

    /**
     * Group incidents by their `started_at` calendar day into the public shape.
     *
     * @param  Collection<int, Incident>  $incidents
     * @return array<int, array{day: string, entries: array<int, array<string, mixed>>}>
     */
    protected function groupIncidentsByDay(Collection $incidents): array
    {
        $groups = [];

        foreach ($incidents as $incident) {
            $day = $incident->started_at->format('Y-m-d');
            $groups[$day][] = $this->buildIncidentEntry($incident);
        }

        return array_map(
            static fn (string $day, array $entries): array => [
                'day' => $day,
                'entries' => $entries,
            ],
            array_keys($groups),
            $groups,
        );
    }

    /**
     * Allowlisted shape for a single incident and its public updates.
     *
     * The postmortem is gated on its PUBLICATION STAMP, not on the body: an
     * operator's internal draft (body set, `postmortem_published_at` null) is
     * omitted entirely, so "publish" is the single switch that makes it
     * customer-visible and an unpublished draft can never reach the page.
     *
     * @return array<string, mixed>
     */
    protected function buildIncidentEntry(Incident $incident): array
    {
        return [
            'title' => $incident->title,
            'lifecycle' => $incident->lifecycle->value,
            'impact' => $incident->impact->value,
            'startedAt' => $incident->started_at->toIso8601String(),
            'postmortem' => $incident->postmortemIsPublished()
                ? [
                    'body' => $incident->postmortem_body,
                    'publishedAt' => $incident->postmortem_published_at->toIso8601String(),
                ]
                : null,
            'updates' => $incident->updates
                ->map(static fn (IncidentUpdate $update): array => [
                    'message' => $update->message,
                    'actor' => $update->actor,
                    'displayAt' => $update->display_at->toIso8601String(),
                    'status' => $update->status->value,
                ])
                ->all(),
        ];
    }

    /**
     * Allowlisted page branding + addressing fields.
     *
     * @return array{
     *     name: string,
     *     brand_color: string|null,
     *     logo_text: string|null,
     *     description: string|null,
     *     subscriptions_enabled: bool,
     *     slug: string,
     * }
     */
    protected function buildPage(StatusPage $page): array
    {
        return [
            'name' => $page->name,
            'brand_color' => $this->safeBrandColor($page->brand_color),
            'logo_text' => $page->logo_text,
            'description' => $page->description,
            'subscriptions_enabled' => (bool) $page->subscriptions_enabled,
            'slug' => $page->slug,
        ];
    }

    /**
     * A brand colour safe to interpolate into a CSS declaration, or null.
     *
     * Both status partials render this value inside an inline
     * `style="background-color: {{ ... }}"`. Blade escapes HTML entities, which
     * stops attribute break-out, but a value like
     * `red; background-image: url(https://host/x.png)` contains nothing
     * HTML-special and would survive intact as a SECOND CSS declaration. That
     * would make a tenant-controlled string issue a remote fetch from inside the
     * headless browser that renders the preview PNG, which is the exact SSRF
     * shape the render-safety test exists to forbid.
     *
     * The write paths do validate this pattern (Store and
     * UpdateStatusPageRequest both anchor a hex regex), so a hostile value is
     * not reachable through the API today. This guard is deliberately here
     * anyway: the column carries no constraint, a seeder, an import or a console
     * command bypasses the request rules entirely, and the render-safety
     * control must not silently depend on validation staying correct forever.
     *
     * Anything that is not a 6 or 8 digit hex literal becomes null, which both
     * partials already render as their neutral default.
     */
    protected function safeBrandColor(?string $brandColor): ?string
    {
        if ($brandColor === null) {
            return null;
        }

        return preg_match('/^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/', $brandColor) === 1
            ? $brandColor
            : null;
    }

    /**
     * Map a monitor's health to a component status on the severity ladder.
     * Paused monitors never reach here (they are scoped out of the query).
     */
    protected function componentStatus(?MonitorStatus $status): string
    {
        return match ($status) {
            MonitorStatus::Down => 'major_outage',
            MonitorStatus::Degraded => 'degraded',
            // A monitor nobody has probed yet has no verdict, and `default` used to
            // hand it `operational`: a component published as healthy on the strength
            // of nothing. It resolves to the neutral family instead, the same one the
            // unmeasured days in its strip use, so the row reads "not measured"
            // rather than "passed".
            null => self::STATUS_UNKNOWN,
            default => 'operational',
        };
    }

    /**
     * Mean daily uptime across the 90-day strip, rounded to two decimals.
     *
     * @param  array<int, array<string, mixed>>  $strip
     */
    protected function uptimePercent(array $strip): ?float
    {
        // Averaged over the days that were actually MEASURED, and null when none
        // were. Returning 100.0 for an empty strip published a figure nothing stood
        // behind, and averaging a gap-filled 100 alongside real days quietly pulled
        // a partial history up toward perfect. Null reaches the view as an em space
        // rather than a number, because "we do not know yet" and "no downtime" are
        // different claims and only one of them is ours to make.
        $percents = array_values(array_filter(
            array_column($strip, 'uptime_percent'),
            static fn (?float $percent): bool => $percent !== null,
        ));

        if ($percents === []) {
            return null;
        }

        return round(array_sum($percents) / count($percents), 2);
    }

    /**
     * Human banner label for an overall status on the severity ladder.
     */
    protected function overallLabel(string $status): string
    {
        return match ($status) {
            'major_outage' => 'Major System Outage',
            'partial_outage' => 'Partial System Outage',
            'degraded' => 'Degraded Performance',
            self::STATUS_UNKNOWN => 'No Components Published',
            default => 'All Systems Operational',
        };
    }
}
