<?php

namespace App\Http\Controllers\Marketing;

use Illuminate\Http\Response;

/**
 * `GET /robots.txt`.
 *
 * Served from a route rather than `public/robots.txt` so the `Sitemap:` line is
 * built from `app.url` instead of a hardcoded production hostname that no other
 * environment could be right about.
 *
 * One robots.txt answers for every hostname this app serves (the apex, the API,
 * and every status-page subdomain), because they share a document root. So it has
 * to be correct for all of them at once:
 *
 *   - `/` stays crawlable, because tenant status pages are MEANT to be findable
 *   - `/api/` and `/up` are disallowed as crawl-budget waste, not as a security
 *     measure; robots.txt is a request, and anything that actually needs
 *     protecting is protected by its own guard
 */
class ShowRobotsController
{
    public function __invoke(): Response
    {
        $sitemap = rtrim((string) config('app.url'), '/').'/sitemap.xml';

        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/',
            'Disallow: /up',
            '',
            'Sitemap: '.$sitemap,
            '',
        ];

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
