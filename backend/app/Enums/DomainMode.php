<?php

namespace App\Enums;

/**
 * How a status page is served to the public.
 *
 * `Path` and `Subdomain` are both served by this app; `Custom` points a
 * tenant-owned hostname at it and needs that hostname terminated upstream, so
 * it is accepted on the wire without this app registering a route for it.
 *
 * Mirrored in the Flutter client at `lib/app/enums/domain_mode.dart`. Changing
 * a case here is a two-sided contract change.
 */
enum DomainMode: string
{
    /** `<host>/s/<slug>`. Always available, regardless of the selected mode. */
    case Path = 'path';

    /** `<slug>.<host>`, gated by `config('status_pages.subdomain_host')`. */
    case Subdomain = 'subdomain';

    /** A tenant-owned hostname stored in `custom_domain`. */
    case Custom = 'custom';
}
