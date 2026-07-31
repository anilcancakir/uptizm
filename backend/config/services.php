<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],

    /*
    | Cloudflare Turnstile, the CAPTCHA on the public contact form. Both keys
    | are absent by default and the widget is DORMANT while `secret_key` is:
    | `SendContactMessageController` adds the verification rule only when the
    | secret is filled, and the page loads Cloudflare's script only when
    | `site_key` is. So a deployment that has not signed up is not rejecting
    | every message, and this deployment loads no third-party script at all.
    |
    | Turnstile and not reCAPTCHA on purpose: reCAPTCHA sets cookies that are
    | not strictly necessary and adds Google as a recipient of personal data,
    | which would break the marketing site's no-consent-banner position. Cloudflare
    | is already a subprocessor (the regional probe relay), so Turnstile adds no
    | new recipient. Note for whoever turns it on: the widget IS a third-party
    | script, so the Privacy page's cookie section has to be revisited with it.
    */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    /*
    | Browsershot shells out to Node, which it locates on PATH by default. A
    | queue worker started by a process manager often has a narrower PATH than
    | the web process, so these are the explicit escape hatch. Leave them null
    | to keep PATH resolution.
    */
    'browsershot' => [
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary' => env('BROWSERSHOT_NPM_BINARY'),
    ],

];
