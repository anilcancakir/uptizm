<?php

namespace App\Enums;

use App\Models\NotificationChannel;

/**
 * Which incidents a {@see NotificationChannel} fires for.
 *
 * - `All`: every incident, regardless of severity.
 * - `Critical`: only incidents classified as critical severity.
 */
enum NotificationChannelSeverity: string
{
    case All = 'all';
    case Critical = 'critical';
}
