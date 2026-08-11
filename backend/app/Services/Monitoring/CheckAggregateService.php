<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Windowed read-model for the monitor dashboard charts. Owns the
 * fixed range -> relative-offset table that drives the uptime and
 * response-time aggregations so the HTTP controller stays a thin
 * translator from request to resource.
 */
class CheckAggregateService
{
    /**
     * Supported range keys and their `DateTime::modify` offsets.
     *
     * @var array<string, string>
     */
    public const RANGE_WINDOWS = [
        '24h' => '-24 hours',
        '7d' => '-7 days',
        '30d' => '-30 days',
        '90d' => '-90 days',
    ];

    /**
     * Aggregation bucket size (seconds) per range. Produces ~1440 dots
     * max for 24h, ~1008 for 7d, ~1440 for 30d, ~1080 for 90d.
     *
     * @var array<string, int>
     */
    protected const RESPONSE_TIME_BUCKET_SECONDS = [
        '24h' => 60,
        '7d' => 600,
        '30d' => 1800,
        '90d' => 7200,
    ];

    /**
     * Aggregate check outcomes for a monitor into a single uptime
     * snapshot for the given range.
     *
     * Returns a plain object (not a dedicated readonly DTO) so the
     * range/total/up/down/degraded/uptime_ratio shape stays directly
     * resource-friendly without introducing a third file beyond the
     * two services this step owns.
     */
    public function uptimeSummary(Monitor $monitor, string $range): object
    {
        $since = $this->rangeBoundary($range);

        // Bucket counts by status so we can expose up/down/degraded separately.
        $counts = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->where('checked_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $up = (int) ($counts[MonitorStatus::Up->value] ?? 0);
        $down = (int) ($counts[MonitorStatus::Down->value] ?? 0);
        $degraded = (int) ($counts[MonitorStatus::Degraded->value] ?? 0);
        $total = $up + $down + $degraded;

        return (object) [
            'range' => $range,
            'total' => $total,
            'up' => $up,
            'down' => $down,
            'degraded' => $degraded,
            'uptime_ratio' => $total > 0 ? round($up / $total, 4) : 0.0,
        ];
    }

    /**
     * Reliability read-model for the SLO error-budget surface: the down
     * minutes that actually happened, the coverage we could have measured,
     * and the minutes nobody measured at all.
     *
     * It exists beside {@see self::uptimeSummary()} rather than inside it
     * because a RATIO cannot carry downtime. The card used to multiply
     * `1 - uptime_ratio` by the full window, so a monitor with 2 down
     * minutes out of 767 checks reported 26 down minutes on 7d and 112 on
     * 30d. A row count cannot carry it either: N regions report the same
     * outage N times. Every count here is therefore a DISTINCT bucket on
     * the monitor's own `check_interval_sec` grid, which collapses the
     * regions and keeps a 5-minute cadence from reading as 4 missing
     * minutes out of every 5.
     *
     * Fields:
     * - `window_minutes`: the FULL nominal window (7d = 10080), so a 99.9%
     *   target stays a 43-minute budget regardless of monitor age.
     * - `observed_minutes`: elapsed minutes from the later of the range
     *   boundary and `created_at`. Time before the monitor existed is not
     *   ours to account for.
     * - `measured_minutes`: minutes we hold a check for. The ONLY field
     *   that separates "never measured" from "measured and fine", since a
     *   30-day-old monitor that recorded nothing has a full window of
     *   observed time and zero downtime.
     * - `down_minutes`: buckets holding at least one `down` check. A bucket
     *   where regions disagree folds to worst-seen, exactly like
     *   {@see self::responseTimeSamples()} one method away and exactly like
     *   what pages a human, because `ThresholdEvaluator` opens an incident
     *   on a single region's failure with no quorum anywhere in the
     *   product. `degraded` is excluded: graceful degradation is a separate
     *   quality objective and does not spend the availability budget.
     * - `gap_minutes`: observed minutes with no check at all, reported on
     *   its own rather than folded into either column. Counting them down
     *   invents downtime; counting them up forgives our own blind spot.
     *
     * Two quantisation notes, both deliberate. The four measured fields are
     * floats because a paid plan probes every 30 seconds, so one bucket is
     * half a minute and an int field would round real downtime to zero. And
     * the grid includes the slot holding the coverage start and the slot
     * holding `now`, so `measured_minutes + gap_minutes` can exceed
     * `observed_minutes` by up to one bucket per edge; they are a coverage
     * signal at cadence resolution, not a clock.
     */
    public function reliabilitySummary(Monitor $monitor, string $range): object
    {
        $now = now();
        $coverageStart = $this->coverageStart($monitor, $range);

        // 1. The bucket is the monitor's OWN cadence, so every region
        //    reporting one interval collapses into a single slot.
        $bucketSeconds = max(1, (int) $monitor->check_interval_sec);
        $bucketExpr = $this->bucketExpression($bucketSeconds);

        // 2. Count distinct buckets, not rows. The conditional inside the
        //    `distinct` is what folds a disagreeing bucket to down: any
        //    bucket with one `down` row contributes exactly once.
        $counts = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->where('checked_at', '>=', $coverageStart)
            ->selectRaw("count(distinct {$bucketExpr}) as measured_buckets")
            ->selectRaw(
                "count(distinct case when status = ? then {$bucketExpr} end) as down_buckets",
                [MonitorStatus::Down->value],
            )
            ->first();

        $measuredBuckets = (int) ($counts->measured_buckets ?? 0);
        $downBuckets = (int) ($counts->down_buckets ?? 0);

        // 3. Expected buckets come off the SAME epoch-aligned grid as the
        //    measured ones. Derived from elapsed seconds instead, the two
        //    disagree whenever coverage starts mid-slot and the gap turns
        //    negative; the clamp below is the second guard, not the first.
        $expectedBuckets = $this->gridSlots($coverageStart, $now, $bucketSeconds);
        $minutesPerBucket = $bucketSeconds / 60;

        $observedMinutes = round(max(0, $now->getTimestamp() - $coverageStart->getTimestamp()) / 60, 2);

        // 4. The gap is capped at the coverage it is a gap in. Both edge slots
        //    of the grid are expected (see gridSlots()), so an unmeasured
        //    window can otherwise report one bucket MORE than it observed: a
        //    30-day monitor with no checks read 43201 unmeasured minutes out of
        //    43200 observed. That surfaces to the operator, and a gap larger
        //    than its own window is read as a broken number rather than as a
        //    cadence-resolution artefact.
        $gapMinutes = round(max(0, $expectedBuckets - $measuredBuckets) * $minutesPerBucket, 2);

        return (object) [
            'range' => $range,
            'window_minutes' => $this->rangeHours($range) * 60,
            'observed_minutes' => $observedMinutes,
            'measured_minutes' => round($measuredBuckets * $minutesPerBucket, 2),
            'down_minutes' => round($downBuckets * $minutesPerBucket, 2),
            'gap_minutes' => min($observedMinutes, $gapMinutes),
        ];
    }

    /**
     * Time-bucketed response-time samples (oldest-first) for the chart.
     * Each bucket collapses every check whose `checked_at` falls inside
     * the bucket window into one dot: response_ms is averaged, status is
     * the worst seen (down > degraded > up) so a single failing region
     * still visibly flips the dot color. Bucket size scales with the
     * range so dot density stays bounded regardless of probe frequency
     * (multi-region monitors hit the same endpoint every 30s across N
     * regions, which quickly blows past a per-sample chart).
     *
     * @return Collection<int, MonitorCheck>
     */
    public function responseTimeSamples(Monitor $monitor, string $range): Collection
    {
        $since = $this->rangeBoundary($range);
        $bucketSeconds = self::RESPONSE_TIME_BUCKET_SECONDS[$range]
            ?? self::RESPONSE_TIME_BUCKET_SECONDS['24h'];

        // 1. Aggregate per bucket on the DB side so we never pull the
        //    full raw row set for wide windows on busy monitors. Status
        //    is expanded into conditional counts and folded to "worst"
        //    in PHP below.
        $bucketExpr = $this->bucketExpression($bucketSeconds);

        $rows = MonitorCheck::query()
            ->where('monitor_id', $monitor->id)
            ->where('checked_at', '>=', $since)
            ->whereNotNull('response_ms')
            ->selectRaw("{$bucketExpr} as bucket_ts")
            ->selectRaw('AVG(response_ms) as avg_ms')
            ->selectRaw("SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as downs")
            ->selectRaw("SUM(CASE WHEN status = 'degraded' THEN 1 ELSE 0 END) as degrades")
            ->groupByRaw($bucketExpr)
            ->orderByRaw("{$bucketExpr} ASC")
            ->get();

        // 2. Hydrate synthetic MonitorCheck rows so the resource layer
        //    keeps its existing shape. region stays null because an
        //    aggregated dot doesn't belong to a single region.
        return $rows->map(function ($row) use ($bucketSeconds): MonitorCheck {
            $bucketTs = (int) $row->bucket_ts;
            $status = ((int) $row->downs) > 0
                ? MonitorStatus::Down
                : (((int) $row->degrades) > 0 ? MonitorStatus::Degraded : MonitorStatus::Up);

            return (new MonitorCheck)->forceFill([
                'checked_at' => (new DateTimeImmutable)
                    ->setTimestamp($bucketTs + (int) ($bucketSeconds / 2)),
                'response_ms' => (int) round((float) $row->avg_ms),
                'status' => $status,
                'region' => null,
            ]);
        });
    }

    /**
     * SQL expression that floors `checked_at` onto a `$bucketSeconds` grid
     * anchored at the epoch, one branch per driver.
     *
     * Kept DB-agnostic on purpose: no TimescaleDB `time_bucket` and no
     * PostgreSQL `date_bin`, because the suite runs on sqlite `:memory:`
     * while production is PostgreSQL, so either would make the tests error
     * rather than assert. Shared by {@see self::responseTimeSamples()} and
     * {@see self::reliabilitySummary()}, which must agree on the grid: the
     * latter compares buckets counted by this expression against slots
     * counted in PHP by {@see self::gridSlots()}, and a second, subtly
     * different expression would put the two on different grids.
     */
    protected function bucketExpression(int $bucketSeconds): string
    {
        $epochExpr = match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(strftime('%s', checked_at) AS INTEGER)",
            'pgsql' => 'EXTRACT(EPOCH FROM checked_at)::bigint',
            default => 'UNIX_TIMESTAMP(checked_at)',
        };

        return "(({$epochExpr}) / {$bucketSeconds}) * {$bucketSeconds}";
    }

    /**
     * Number of grid slots the `[$start, $end]` window touches on the same
     * epoch-anchored grid {@see self::bucketExpression()} floors onto.
     *
     * Both edge slots count, because a check anywhere inside a slot marks
     * that slot measured; counting whole elapsed intervals instead would
     * report fewer slots than the measurement can fill and drive the gap
     * negative.
     */
    protected function gridSlots(DateTimeInterface $start, DateTimeInterface $end, int $bucketSeconds): int
    {
        $firstSlot = intdiv($start->getTimestamp(), $bucketSeconds);
        $lastSlot = intdiv($end->getTimestamp(), $bucketSeconds);

        return max(0, $lastSlot - $firstSlot + 1);
    }

    /**
     * Instant from which the monitor could actually have been measured: the
     * later of the range boundary and its creation. Time before the monitor
     * existed is not an unmeasured gap, it is time that was never ours.
     */
    protected function coverageStart(Monitor $monitor, string $range): DateTimeInterface
    {
        $boundary = $this->rangeBoundary($range);
        $createdAt = $monitor->created_at;

        if ($createdAt === null || $createdAt->getTimestamp() <= $boundary->getTimestamp()) {
            return $boundary;
        }

        return $createdAt;
    }

    /**
     * Resolve a range key to its `now()`-relative boundary. Unknown
     * ranges raise so the controller can coerce to a sane default
     * before calling in; the service itself does not silently accept
     * garbage input.
     */
    protected function rangeBoundary(string $range): DateTimeInterface
    {
        return now()->modify("-{$this->rangeHours($range)} hours");
    }

    /**
     * Length of a range key in hours. Split out of
     * {@see self::rangeBoundary()} because {@see self::reliabilitySummary()}
     * needs the window as a DURATION rather than as a boundary, and a
     * second `'7d' => 10080` table would be a second source of one fact.
     */
    protected function rangeHours(string $range): int
    {
        if (! array_key_exists($range, self::RANGE_WINDOWS)) {
            throw new InvalidArgumentException("Unsupported range: {$range}");
        }

        // RANGE_WINDOWS entries look like "-24 hours" / "-7 days"; we
        // normalise to hours so the offset computation stays uniform
        // regardless of the source unit.
        $offset = self::RANGE_WINDOWS[$range];
        preg_match('/^-(\d+)\s+(\w+)$/', $offset, $matches);
        $amount = (int) $matches[1];
        $unit = $matches[2];

        return match ($unit) {
            'hours' => $amount,
            'days' => $amount * 24,
            default => throw new InvalidArgumentException("Unsupported unit: {$unit}"),
        };
    }
}
