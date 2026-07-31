<?php

/*
|--------------------------------------------------------------------------
| SEO Tools
|--------------------------------------------------------------------------
|
| Defaults for artesaos/seotools. These are what a page gets when it calls
| `SEO::generate()` without setting anything itself; the landing controller
| overrides the specifics.
|
| SCOPE: the marketing surface only. The public status pages under /s/{slug}
| deliberately keep their own hand-written meta block in
| resources/views/status/layout.blade.php, because their title, description and
| brand name are TENANT-CONTROLLED input. That block escapes through Blade's
| `{{ }}`, and its per-status favicon and canonical URL are derived from
| configuration rather than from the request. Routing that through a package
| would buy nothing and would put third-party string handling between tenant
| input and our `<head>`.
|
| @see https://github.com/artesaos/seotools
|
*/

$name = env('APP_NAME', 'Uptizm');
$tagline = 'uptime, incident and status-page monitoring';
$description = 'Uptime monitoring that refuses to guess. HTTP and TCP checks from pinned '
    .'regions at the edge, incidents that open on repeated failure rather than the first '
    .'blip, and status pages your customers can subscribe to.';

return [

    'inertia' => false,

    'meta' => [
        'defaults' => [
            'title' => $name.': '.$tagline,

            // Titles are written in full rather than composed, so a long section
            // name can never push the brand out of a search result.
            'titleBefore' => false,
            'separator' => ': ',

            'description' => $description,

            /*
             * Keywords are ignored by every major engine and have been for
             * years. Left empty rather than filled with a list whose only real
             * signal is that whoever wrote it stopped reading in 2011.
             */
            'keywords' => [],

            /*
             * 'current' uses Url::current(), which drops the query string.
             * 'full' would keep it, which is how one shared link carrying a
             * tracking parameter becomes a second, competing canonical.
             */
            'canonical' => 'current',

            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
        ],

        /*
         * Only emitted when set. An empty string would still render the meta
         * tag, with no value in it.
         */
        'webmaster_tags' => [
            'google' => env('SEO_GOOGLE_SITE_VERIFICATION'),
            'bing' => null,
            'alexa' => null,
            'pinterest' => null,
            'yandex' => env('SEO_YANDEX_VERIFICATION'),
            'norton' => null,
        ],

        'add_notranslate_class' => false,
    ],

    'opengraph' => [
        'defaults' => [
            'title' => $name.': '.$tagline,
            'description' => $description,
            'url' => null,
            'type' => 'website',
            'site_name' => $name,
            'images' => [],
        ],
    ],

    'twitter' => [
        'defaults' => [
            // `summary_large_image` is the only card worth using with a 1200x630
            // asset; `summary` crops it to a square thumbnail.
            'card' => 'summary_large_image',
        ],
    ],

    'json-ld' => [
        'defaults' => [
            'title' => $name.': '.$tagline,
            'description' => $description,
            'url' => null,
            'type' => 'WebPage',
            'images' => [],
        ],
    ],

];
