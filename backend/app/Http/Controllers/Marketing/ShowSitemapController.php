<?php

namespace App\Http\Controllers\Marketing;

use Illuminate\Http\Response;

/**
 * `GET /sitemap.xml`.
 *
 * Lists OUR pages. It deliberately does NOT enumerate tenant status pages, and
 * that is a privacy decision rather than an oversight: a sitemap of every
 * `/s/{slug}` would publish our customer list to anyone who asked for one file,
 * and it would include pages whose owners chose a private or unlisted page. A
 * tenant's status page gets discovered the way they want it discovered, from
 * their own site.
 *
 * Built from `app.url` so it names the canonical host regardless of which
 * hostname the request arrived on.
 */
class ShowSitemapController
{
    public function __invoke(): Response
    {
        $base = rtrim((string) config('app.url'), '/');

        // One entry today. Kept as a list so adding a marketing page is a line
        // here rather than a new mechanism.
        $urls = [
            ['loc' => $base.'/', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ];

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            $xml[] = '    <url>';
            $xml[] = '        <loc>'.e($url['loc']).'</loc>';
            $xml[] = '        <changefreq>'.$url['changefreq'].'</changefreq>';
            $xml[] = '        <priority>'.$url['priority'].'</priority>';
            $xml[] = '    </url>';
        }

        $xml[] = '</urlset>';

        return response(implode("\n", $xml)."\n", 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
