<?php

namespace App\Enums;

/**
 * Allowed check-frequency presets expressed in seconds.
 *
 * The enum is int-backed so the raw value can be added directly to a
 * timestamp when scheduling the next check.
 */
enum CheckInterval: int
{
    case Seconds30 = 30;
    case Minute1 = 60;
    case Minutes5 = 300;
    case Minutes15 = 900;
    case Hour1 = 3600;
}
