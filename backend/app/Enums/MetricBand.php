<?php

namespace App\Enums;

/**
 * Threshold band of a numeric metric at the moment it was recorded.
 *
 * Derived from `warn_bound` / `critical_bound` + `ThresholdDirection`
 * and persisted alongside the value so historical banding stays correct
 * even if thresholds are later edited.
 */
enum MetricBand: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Critical = 'critical';
}
