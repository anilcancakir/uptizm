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
        //    in PHP below. Kept DB-agnostic: no TimescaleDB `time_bucket`,
        //    just a floor-division of the epoch timestamp per driver.
        $driver = DB::connection()->getDriverName();
        $epochExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%s', checked_at) AS INTEGER)",
            'pgsql' => 'EXTRACT(EPOCH FROM checked_at)::bigint',
            default => 'UNIX_TIMESTAMP(checked_at)',
        };
        $bucketExpr = "(({$epochExpr}) / {$bucketSeconds}) * {$bucketSeconds}";

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
     * Resolve a range key to its `now()`-relative boundary. Unknown
     * ranges raise so the controller can coerce to a sane default
     * before calling in; the service itself does not silently accept
     * garbage input.
     */
    protected function rangeBoundary(string $range): DateTimeInterface
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
        $hours = match ($unit) {
            'hours' => $amount,
            'days' => $amount * 24,
            default => throw new InvalidArgumentException("Unsupported unit: {$unit}"),
        };

        return now()->modify("-{$hours} hours");
    }
}
