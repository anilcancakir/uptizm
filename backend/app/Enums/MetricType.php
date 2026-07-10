<?php

namespace App\Enums;

/**
 * Classification of a custom monitor metric.
 *
 *   - Numeric: latency, throughput, or any number with a unit.
 *   - Status:  maps to a MonitorStatus value.
 *   - String:  opaque text captured for inspection.
 */
enum MetricType: string
{
    case Numeric = 'numeric';
    case Status = 'status';
    case String = 'string';
}
