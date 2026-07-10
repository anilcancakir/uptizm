<?php

namespace App\Enums;

/**
 * Lifecycle state of a monitor at the moment a check was recorded.
 *
 * Wire values are snake_case strings to match the Flutter client's JSON
 * contract (see lib/app/enums/monitor_status.dart).
 */
enum MonitorStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';
    case Paused = 'paused';
}
