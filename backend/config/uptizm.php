<?php

/*
|--------------------------------------------------------------------------
| Uptizm product configuration
|--------------------------------------------------------------------------
|
| The keys uptizm's own subsystems read that belong to no vendor package:
| the staff back-office host, the staff allowlist, the identity of the
| internal team that owns the service-catalog monitors, and the User-Agent
| the feed ingester introduces itself with.
|
| This file is written in ONE place on purpose. Four separate subsystems read
| it (the panel gate, the system-team resolver, the feed ingester and the
| public service pages), so a key added next to its consumer instead of here
| leaves the other three reading null and failing at runtime rather than at
| boot. If you need a new product-level key, add it here.
|
*/

// APP_URL is a URL and every key below wants a bare hostname, so parse it once.
// The `?:` guards a malformed or schemeless APP_URL, where parse_url() returns
// null and a naive concatenation would produce the host `admin.`.
$appHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

/*
 * The staff allowlist, normalised at load rather than at every comparison.
 *
 * An env-driven comma-separated list collects stray whitespace and mixed case
 * from whoever edits it, so both are stripped here. The gate that reads this
 * must STILL normalise its candidate address, because a test may inject the
 * array through `config()` and bypass this normalisation entirely.
 *
 * An empty list yields an empty array, which the gate must read as "deny
 * everyone". That is the fail-closed direction and it matches how the Horizon
 * gate (`HorizonServiceProvider::gate()`) behaves with its empty array today.
 */
$staffEmails = array_values(array_filter(
    array_map(
        static fn (string $email): string => mb_strtolower(trim($email)),
        explode(',', (string) env('UPTIZM_STAFF_EMAILS', '')),
    ),
    static fn (string $email): bool => $email !== '',
));

return [
    /*
    |--------------------------------------------------------------------------
    | Staff back-office host
    |--------------------------------------------------------------------------
    |
    | The single hostname the Filament staff panel answers on. The panel owns
    | the ROOT of this host (`AdminPanelProvider` pins `->path('')`), so this
    | must never be the apex: the apex root is the marketing landing page, and
    | the panel carries StartSession + EncryptCookies, which would falsify the
    | cookie-free claim published at `resources/legal/privacy.en.md:208-212`.
    | Its own host is how the panel gets a session without touching that claim.
    |
    | An EMPTY value is treated as absent, not as a valid host. `->domain('')`
    | drops the constraint altogether (`Panel::domain()` calls `filled()`),
    | which would mount the whole panel on the root of EVERY host this app
    | serves, landing page included. So the fallback derives `admin.<app host>`
    | from APP_URL and can never itself be empty.
    |
    | Production: `admin.uptizm.com`. `deploy/vhost-uptizm.com.conf:86` already
    | serves `*.uptizm.com`, so no vhost change is needed to reach it.
    |
    | `tests/Feature/Admin/PanelIsolationTest.php` pins that the panel, and not
    | the wildcard status-page route, owns this host's root.
    |
    */

    'admin_host' => env('ADMIN_HOST') ?: 'admin.'.$appHost,

    /*
    |--------------------------------------------------------------------------
    | Staff allowlist
    |--------------------------------------------------------------------------
    |
    | The email addresses allowed through the panel's access gate, lower-cased
    | and trimmed above. Comma-separated in the environment
    | (`UPTIZM_STAFF_EMAILS`), because the panel's login page is publicly
    | reachable on its own subdomain and the allowlist is the control in front
    | of a console with cross-team CRUD over every user and monitor.
    |
    | Empty by default, and empty MUST mean nobody. Never a wildcard, never
    | "the first user", never a team role.
    |
    */

    'staff_emails' => $staffEmails,

    /*
    |--------------------------------------------------------------------------
    | System team
    |--------------------------------------------------------------------------
    |
    | The internal team that owns the monitors behind the public service
    | catalog, and the address of the user row that owns that team.
    |
    | `teams.user_id` is a NOT NULL cascading FK, so the team needs an owning
    | user row to exist at all. That user never logs in: it holds no
    | `team_user` pivot row (which is what keeps every paging path a no-op for
    | service outages) and its address is deliberately absent from
    | `staff_emails` above.
    |
    | The default address sits under the reserved `.invalid` TLD (RFC 2606) so
    | it can never be delivered to, whatever sweep or mailer reaches it later.
    | Override it only if you actually want mail to arrive.
    |
    */

    'system_team_name' => env('UPTIZM_SYSTEM_TEAM_NAME', 'Uptizm Services'),

    'system_team_email' => env('UPTIZM_SYSTEM_TEAM_EMAIL', 'system@uptizm.invalid'),

    /*
    |--------------------------------------------------------------------------
    | Outbound bot identity
    |--------------------------------------------------------------------------
    |
    | The User-Agent the service-feed ingester sends. It names the product and
    | carries a contact URL, which is what every provider's robots policy asks
    | of an automated client and what lets an operator on the other side reach
    | us instead of blocking us. Do not send a browser User-Agent here.
    |
    */

    'bot_user_agent' => env('UPTIZM_BOT_USER_AGENT', 'UptizmBot/1.0 (+https://uptizm.com/bot)'),

    /*
    |--------------------------------------------------------------------------
    | Catalog probe cadence
    |--------------------------------------------------------------------------
    |
    | How often, in seconds, each catalog monitor checks its provider endpoint.
    | `ServiceCatalogSeeder` builds its monitors from this, and the public `/bot`
    | page states it as the availability check's cadence.
    |
    | It lives here rather than as a constant on the seeder for one reason: the
    | `/bot` page has to describe this traffic to the operator receiving it, and a
    | marketing controller importing a seeder is the wrong direction. It cannot be
    | read from the monitor rows either, tempting as that is, because these content
    | pages are served without a database (`LegalPagesTest` runs them with no
    | connection at all) and a query here would 500 them.
    |
    | One minute: these monitors back a public page that says when uptizm last
    | measured the endpoint, and a coarser cadence would date the claim. The system
    | team is exempt from the plan interval floor, so this is a product decision
    | rather than a plan-tier one.
    |
    */

    'catalog_probe_interval_sec' => (int) env('UPTIZM_CATALOG_PROBE_INTERVAL_SEC', 60),
];
