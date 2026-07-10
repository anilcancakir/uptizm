<?php

namespace App\Enums;

/**
 * Coarse category a {@see MetricUnit} belongs to. Drives the form
 * grouping in the Flutter metric sheet and the scaling branch in
 * MetricUnitFormatter.
 */
enum MetricUnitKind: string
{
    case Size = 'size';
    case Duration = 'duration';
    case Percent = 'percent';
    case Ratio = 'ratio';
    case Count = 'count';
    case Custom = 'custom';
}
