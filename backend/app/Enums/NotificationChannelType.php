<?php

namespace App\Enums;

use App\Models\NotificationChannel;

/**
 * The delivery driver a team-scoped {@see NotificationChannel}
 * sends through.
 */
enum NotificationChannelType: string
{
    case Slack = 'slack';
    case Webhook = 'webhook';
}
