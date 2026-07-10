<?php

namespace App\Enums;

/**
 * Authentication scheme applied to outbound monitor check requests.
 *
 * The concrete credentials live in `monitors.auth_config` JSON; this enum
 * only selects which auth flow the relay worker should apply.
 */
enum HttpAuthType: string
{
    case None = 'none';
    case Basic = 'basic';
    case Bearer = 'bearer';
    case ApiKey = 'api_key';
}
