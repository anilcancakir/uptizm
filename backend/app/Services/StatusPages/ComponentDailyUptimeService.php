<?php

namespace App\Services\StatusPages;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write + read facade for the `monitor_daily_uptime` rollup. The write
 * path (`aggregateDay`) scans `monitor_checks` for a single UTC calendar
 * day and upserts one row; the read path (`last90Days`) returns a
 * 90-length window, oldest-first, synthesizing an `operational` entry for
 * dates without a rollup row so the dashboard's uptime bar always has
 * exactly 90 rectangles.
 *
 * Rollup writes are idempotent (unique `[monitor_id, date]` + upsert) so a
 * nightly aggregation job or an ad-hoc backfill can re-run without
 * collisions.
 */
class ComponentDailyUptimeService
{
    /**
     * Public severity ladder, weakest first. Shared by the daily rollup and
     * the status-page roll-up so both order outages identically:
     * operational < degraded < partial_outage < major_outage.
     *
     * @var array<int, string>
     */
    public const array STATUS_LADDER = [
        'operational',
        'degraded',
        'partial_outage',
        'major_outage',
    ];

    /**
     * Aggregate a single UTC day of check outcomes for a monitor and
     * persist the worst observed status + counts.
     */
    public function aggregateDay(Monitor $monitor, CarbonInterface $date): void
    {
        // 1. Collapse the input to a UTC calendar day so the boundary
        //    math is timezone-agnostic and idempotent per date key.
        $day = CarbonImmutable::parse($date)->utc()->startOfDay();

        $counts = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [
                $day,
                $day->endOfDay(),
            ])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $up = (int) ($counts[MonitorStatus::Up->value] ?? 0);
        $down = (int) ($counts[MonitorStatus::Down->value] ?? 0);
        $degraded = (int) ($counts[MonitorStatus::Degraded->value] ?? 0);
        $total = $up + $down + $degraded;

        // 2. Skip persistence entirely on empty days so the read path's
        //    gap-fill remains the single source of the "no data yet" case.
        if ($total === 0) {
            return;
        }

        $now = CarbonImmutable::now();

        $row = [
            'monitor_id' => $monitor->id,
            'team_id' => $monitor->team_id,
            'date' => $day->format('Y-m-d'),
            'uptime_percent' => round(($up / $total) * 100, 2),
            'total_checks' => $total,
            'failed_checks' => $down,
            'worst_status' => $this->worstStatus($total, $down, $degraded),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // 3. `monitor_daily_uptime.id` is a plain uuid column with no DB
        //    default (see MigrationHelper::primaryKey); generate it here,
        //    same as Eloquent's ConditionallyUsesUuids would on create.
        if (MigrationHelper::usesUuids()) {
            $row['id'] = (string) Str::orderedUuid();
        }

        DB::table('monitor_daily_uptime')->upsert(
            [$row],
            ['monitor_id', 'date'],
            [
                'uptime_percent',
                'total_checks',
                'failed_checks',
                'worst_status',
                'updated_at',
            ],
        );
    }

    /**
     * Return the trailing 90-day strip for a monitor, oldest-first,
     * gap-filled. Each entry:
     *
     *   array{
     *     date: string,              // Y-m-d
     *     worst_status: string,      // operational|degraded|partial_outage|major_outage
     *     uptime_percent: float,
     *     total_checks: int,
     *     failed_checks: int,
     *   }
     *
     * Gap-fill rule: days without a rollup row default to `operational`
     * (no news is good news). This keeps the strip reading as a confident
     * "healthy" baseline even for monitors whose checks haven't been
     * aggregated yet, while any day with actual degraded / down data still
     * paints its real color.
     *
     * @return array<int, array<string, mixed>>
     */
    public function last90Days(Monitor $monitor): array
    {
        [$oldest, $today] = $this->windowBounds();

        $rows = DB::table('monitor_daily_uptime')
            ->where('monitor_id', $monitor->id)
            ->whereBetween('date', [
                $oldest->format('Y-m-d'),
                $today->format('Y-m-d'),
            ])
            ->get()
            ->keyBy(static fn (object $row): string => (string) $row->date);

        return $this->fillWindow($rows, $oldest);
    }

    /**
     * Batch variant of {@see self::last90Days()} for the status page: given N
     * monitor ids, return a map `[monitorId => 90-entry strip]`, each strip
     * gap-filled exactly as the single-monitor path. This is the N+1 kill:
     * the rollup is read in ONE `whereIn` query, then grouped in PHP so every
     * id maps to its own window without a query per component.
     *
     * @param  array<int, int|string>  $monitorIds
     * @return array<int|string, array<int, array<string, mixed>>>
     */
    public function last90DaysForMonitors(array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        [$oldest, $today] = $this->windowBounds();

        // 1. Single read across every requested monitor, grouped by id so the
        //    per-monitor fill never re-touches the database.
        $grouped = DB::table('monitor_daily_uptime')
            ->whereIn('monitor_id', $monitorIds)
            ->whereBetween('date', [
                $oldest->format('Y-m-d'),
                $today->format('Y-m-d'),
            ])
            ->get()
            ->groupBy(static fn (object $row): string => (string) $row->monitor_id);

        // 2. Fill every requested id, defaulting absent monitors to a fully
        //    gap-filled (all-operational) strip so the caller always gets N strips.
        $strips = [];
        foreach ($monitorIds as $monitorId) {
            $rows = ($grouped->get((string) $monitorId) ?? new Collection)
                ->keyBy(static fn (object $row): string => (string) $row->date);

            $strips[$monitorId] = $this->fillWindow($rows, $oldest);
        }

        return $strips;
    }

    /**
     * Worst (highest-ranked) status across the given labels on the
     * {@see self::STATUS_LADDER}, defaulting to `operational` for an empty or
     * fully-healthy set. Unknown labels are ignored (fail-safe toward healthy).
     *
     * @param  array<int, string>  $statuses
     */
    public function worstOf(array $statuses): string
    {
        $worst = self::STATUS_LADDER[0];
        $worstRank = 0;

        foreach ($statuses as $status) {
            $rank = array_search($status, self::STATUS_LADDER, true);

            if ($rank !== false && $rank > $worstRank) {
                $worst = $status;
                $worstRank = $rank;
            }
        }

        return $worst;
    }

    /**
     * The trailing 90-day UTC window bounds as `[oldest, today]`, both at the
     * start of their calendar day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function windowBounds(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return [
            $today->subDays(89),
            $today,
        ];
    }

    /**
     * Gap-fill the 90-day window from a date-keyed collection of rollup rows,
     * oldest-first. Days without a row default to `operational` (no news is
     * good news), matching {@see self::last90Days()}'s contract.
     *
     * @param  Collection<string, object>  $rowsByDate
     * @return array<int, array<string, mixed>>
     */
    protected function fillWindow(Collection $rowsByDate, CarbonImmutable $oldest): array
    {
        $days = [];
        for ($i = 0; $i < 90; $i++) {
            $date = $oldest->addDays($i);
            $key = $date->format('Y-m-d');
            $row = $rowsByDate->get($key);

            $days[] = [
                'date' => $key,
                // A day with no row is a day nobody measured, and it says so. It used
                // to default to `operational` at 100%, which meant a monitor whose
                // first probe had not run yet published ninety green days and
                // "100.00%" on a page the customer's own users read. Null travels to
                // the view, which already resolves an unrecognised status to the
                // NEUTRAL family rather than borrowing `up`, so the cell renders as
                // "not measured" instead of "passed".
                'worst_status' => $row->worst_status ?? null,
                'uptime_percent' => $row === null ? null : (float) $row->uptime_percent,
                'total_checks' => (int) ($row->total_checks ?? 0),
                'failed_checks' => (int) ($row->failed_checks ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Collapse a day's count buckets to a single severity label using the
     * same ladder the public banner uses: majority down -> major outage,
     * any down -> partial outage, any degraded (no downs) -> degraded,
     * otherwise operational.
     */
    protected function worstStatus(int $total, int $down, int $degraded): string
    {
        if ($down > 0 && $down >= (int) ceil($total / 2)) {
            return 'major_outage';
        }

        if ($down > 0) {
            return 'partial_outage';
        }

        if ($degraded > 0) {
            return 'degraded';
        }

        return 'operational';
    }
}
