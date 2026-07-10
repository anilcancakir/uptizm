<?php

namespace App\Enums;

/**
 * Discriminator for realtime incidents. Kept as an enum so future
 * incident flavors (e.g. security, compliance) can slot in without
 * reshaping the column.
 */
enum IncidentKind: string
{
    case Incident = 'incident';
}
