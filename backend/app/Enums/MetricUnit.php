<?php

namespace App\Enums;

/**
 * Display unit paired with a numeric metric.
 *
 * The backend only stores the enum value. All scaling and number
 * formatting happens in the Flutter app via MetricUnitFormatter so the
 * API stays agnostic to locale and typography concerns. This enum exists
 * server-side for validation, resource serialisation, and as a source of
 * truth both repos can map to.
 *
 * The `*_auto` variants ask the UI to pick the most readable scale for
 * each sample (900 B, 1.2 MB, 7.8 GB). Fixed variants always render at
 * the chosen magnitude. `Custom` falls back to the freeform `unit`
 * column which predates this enum.
 */
enum MetricUnit: string
{
    case BytesAuto = 'bytes_auto';
    case Byte = 'byte';
    case Kilobyte = 'kilobyte';
    case Megabyte = 'megabyte';
    case Gigabyte = 'gigabyte';
    case Terabyte = 'terabyte';

    case DurationAuto = 'duration_auto';
    case Millisecond = 'millisecond';
    case Second = 'second';
    case Minute = 'minute';
    case Hour = 'hour';

    case Percent = 'percent';
    case Ratio = 'ratio';
    case Count = 'count';
    case CountShort = 'count_short';
    case Custom = 'custom';

    /**
     * High-level grouping used by the form UI to group options in the
     * picker and by the formatter to choose a scaling strategy.
     */
    public function kind(): MetricUnitKind
    {
        return match ($this) {
            self::BytesAuto, self::Byte, self::Kilobyte, self::Megabyte, self::Gigabyte, self::Terabyte => MetricUnitKind::Size,
            self::DurationAuto, self::Millisecond, self::Second, self::Minute, self::Hour => MetricUnitKind::Duration,
            self::Percent => MetricUnitKind::Percent,
            self::Ratio => MetricUnitKind::Ratio,
            self::Count, self::CountShort => MetricUnitKind::Count,
            self::Custom => MetricUnitKind::Custom,
        };
    }

    /**
     * Static suffix advertised to the Flutter formatter. `null` means
     * the formatter decides (auto variants) or the user-supplied
     * freetext suffix wins (custom).
     */
    public function defaultSuffix(): ?string
    {
        return match ($this) {
            self::Byte => 'B',
            self::Kilobyte => 'KB',
            self::Megabyte => 'MB',
            self::Gigabyte => 'GB',
            self::Terabyte => 'TB',
            self::Millisecond => 'ms',
            self::Second => 's',
            self::Minute => 'min',
            self::Hour => 'h',
            self::Percent, self::Ratio => '%',
            self::Count => '',
            default => null,
        };
    }
}
