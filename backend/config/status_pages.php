<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subdomain host for status pages
    |--------------------------------------------------------------------------
    |
    | When set, a status page is ALSO reachable at `{slug}.<host>` in addition
    | to the canonical `<host>/s/{slug}` path. Leave it null to register no
    | wildcard route at all: a wildcard host route is a real attack surface
    | (every unclaimed label under the domain becomes app-reachable), so it is
    | opt-in per environment rather than derived from APP_URL.
    |
    */

    'subdomain_host' => env('STATUS_PAGE_SUBDOMAIN_HOST'),

    /*
    |--------------------------------------------------------------------------
    | Reserved slugs
    |--------------------------------------------------------------------------
    |
    | A slug doubles as a hostname label under `subdomain_host`, so claiming
    | one is claiming a subdomain. Without this list a tenant could register
    | the slug `api` and have `api.<host>` resolve to their status page, or
    | `autodiscover` and intercept mail-client probing. That makes this a
    | security boundary, not a naming preference.
    |
    | Enforcement is deliberately asymmetric, see routes/status.php:
    |
    |   - On CREATE and UPDATE the list is rejected outright, on both modes.
    |   - On RESOLUTION it is enforced only on the subdomain route. Adding a
    |     word here later must not silently 404 a customer's already-working
    |     `<host>/s/<word>` page (where no hostname conflict exists), but it
    |     MUST stop `<word>.<host>` from shadowing infrastructure.
    |
    | The slug regex already forbids underscores and leading/trailing hyphens,
    | so DNS records like `_dmarc` and `cf2024-1._domainkey` cannot collide.
    |
    */

    'reserved_slugs' => [
        // Hostnames this deployment actually serves or may serve.
        'www', 'api', 'app', 'admin', 'panel', 'console', 'dashboard',
        'status', 'uptizm',

        // The service catalog (a staff-curated public marketing surface):
        // `services`/`service` are its public route prefixes, and `filament`
        // names the admin-panel package itself, so none of the three can be
        // claimed as a status-page subdomain label.
        'services', 'service', 'filament',

        // Mail. `autodiscover` and `autoconfig` are probed by mail clients;
        // `postmaster`, `hostmaster`, `webmaster` and `abuse` are the addresses
        // a certificate authority will accept for domain-control validation.
        'mail', 'smtp', 'imap', 'pop', 'pop3', 'mx', 'email', 'webmail',
        'autodiscover', 'autoconfig', 'postmaster', 'hostmaster', 'webmaster',
        'abuse', 'noreply', 'no-reply', 'mailer', 'bounce',

        // DNS and network infrastructure.
        'ns', 'ns1', 'ns2', 'ns3', 'dns', 'vpn', 'proxy', 'gateway', 'router',

        // Asset and delivery hosts.
        'cdn', 'static', 'assets', 'img', 'images', 'media', 'files',
        'download', 'downloads', 'upload', 'uploads',

        // Auth, billing and account surfaces.
        'auth', 'login', 'logout', 'signin', 'signup', 'register', 'oauth',
        'sso', 'saml', 'account', 'accounts', 'profile', 'me', 'user', 'users',
        'team', 'teams', 'org', 'orgs', 'billing', 'pay', 'payment',
        'payments', 'checkout', 'invoice', 'invoices', 'subscribe',
        'unsubscribe',

        // Operational endpoints. `horizon`, `telescope`, `reverb` and the
        // websocket labels match surfaces this app really exposes.
        'horizon', 'telescope', 'reverb', 'ws', 'wss', 'socket', 'sockets',
        'realtime', 'webhook', 'webhooks', 'health', 'up', 'metrics',
        'monitor', 'monitoring', 'grafana', 'prometheus', 'kibana', 'logs',
        'log', 'queue', 'worker', 'cron', 'jobs',

        // Data stores.
        'db', 'database', 'mysql', 'postgres', 'postgresql', 'redis', 'cache',
        'search', 'meili', 'elastic',

        // Environments. A tenant owning `staging` would make a phishing page
        // look like ours.
        'dev', 'develop', 'development', 'staging', 'stage', 'test', 'testing',
        'qa', 'demo', 'sandbox', 'preview', 'beta', 'alpha', 'canary',
        'internal', 'intranet', 'local', 'localhost',

        // Content and legal pages likely to move onto their own host.
        'blog', 'news', 'docs', 'doc', 'documentation', 'help', 'support',
        'kb', 'faq', 'about', 'contact', 'legal', 'terms', 'privacy',
        'security', 'careers', 'press', 'partners', 'pricing', 'store', 'shop',

        // Build and source infrastructure.
        'git', 'ci', 'cd', 'build', 'deploy', 'registry', 'npm', 'composer',

        // Generic system labels.
        'root', 'system', 'config', 'settings', 'setup', 'install', 'static1',
        'origin', 'edge', 'backup', 'backups',
    ],
];
