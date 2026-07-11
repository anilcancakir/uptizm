<?php

namespace App\Services\StatusPages;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Http\ViewModels\StatusPageViewModel;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
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

    public function __construct(
        protected ComponentDailyUptimeService $uptime = new ComponentDailyUptimeService,
    ) {}

    /**
     * Assemble the public read model for the given status page.
     */
    public function build(StatusPage $page): StatusPageViewModel
    {
        // 1. Scope visible components at the DB level (fail-closed presentation).
        $monitors = $this->visibleMonitors($page);

        // 2. Batch-load every 90-day strip in a single rollup query.
        $strips = $this->uptime->last90DaysForMonitors(
            $monitors->pluck('id')->all(),
        );

        // 3. Shape components and roll their statuses up the severity ladder.
        $components = $this->buildComponents($monitors, $strips);
        $overallStatus = $this->uptime->worstOf(
            array_column($components, 'status'),
        );

        return new StatusPageViewModel(
            page: $this->buildPage($page),
            overallStatus: $overallStatus,
            overallLabel: $this->overallLabel($overallStatus),
            components: $components,
            // Scope incidents to the VISIBLE monitors only: an incident title
            // embeds the monitor name ("{name} is down"), so pulling incidents
            // for a hidden/paused component would leak its name even though its
            // row is hidden from the page.
            incidents: $this->buildIncidents($monitors->pluck('id')->all()),
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
     *     uptimePercent: float,
     *     strip: array<int, array{date: string, status: string}>,
     * }>
     */
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
                // Public when it either carries a public update or is still active.
                $query->whereHas('updates', fn (Builder $updates) => $updates->where('is_public', true))
                    ->orWhere('lifecycle', '!=', IncidentStatus::Resolved->value);
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
     * @return array<string, mixed>
     */
    protected function buildIncidentEntry(Incident $incident): array
    {
        return [
            'title' => $incident->title,
            'lifecycle' => $incident->lifecycle->value,
            'impact' => $incident->impact->value,
            'startedAt' => $incident->started_at->toIso8601String(),
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
            'brand_color' => $page->brand_color,
            'logo_text' => $page->logo_text,
            'description' => $page->description,
            'subscriptions_enabled' => (bool) $page->subscriptions_enabled,
            'slug' => $page->slug,
        ];
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
            default => 'operational',
        };
    }

    /**
     * Mean daily uptime across the 90-day strip, rounded to two decimals.
     *
     * @param  array<int, array<string, mixed>>  $strip
     */
    protected function uptimePercent(array $strip): float
    {
        if ($strip === []) {
            return 100.0;
        }

        $percents = array_column($strip, 'uptime_percent');

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
            default => 'All Systems Operational',
        };
    }
}
