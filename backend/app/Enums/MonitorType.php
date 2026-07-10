<?php

namespace App\Enums;

/**
 * Supported check protocol for a monitor.
 *
 * HTTP monitors issue a request at the configured URL; TCP monitors open
 * a socket and measure connect/handshake timing only.
 */
enum MonitorType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
}
