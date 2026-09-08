<?php

namespace App\Support\Notifications;

/**
 * The origin every customer-facing link in a notification is built on.
 *
 * One place rather than a copy per notification class, because the guard below
 * is the kind that drifts: two identical private helpers are two chances for
 * one of them to be corrected and the other not.
 */
final class FrontendBase
{
    /**
     * The Flutter client's origin, with no trailing slash.
     *
     * `app.frontend_url` rather than `app.url`: the client is served from its
     * own host (production `app.uptizm.com`) and this API's host serves no
     * `/incidents/{id}` at all, so a link built on `app.url` is a 404. It is
     * also the host a Universal Link has to name, since the OS opens the app
     * only for a host in its entitlement.
     *
     * Deliberately NOT `FlutterSdk\MagicStarter\Support\FrontendUrl`, which
     * solves the same problem but reads `magic-starter.frontend_url` first.
     * That key exists for the starter's own auth mails and a deployment may
     * point it elsewhere; letting it win here would silently move where an
     * incident link goes.
     *
     * Two fallbacks, and the second is the one that is easy to get wrong.
     * `config('app.frontend_url')` cannot fall back on its own, because
     * `env()` substitutes its default only for an ABSENT key and a blank
     * `.env` line leaves the key PRESENT and EMPTY. And emptiness is tested
     * AFTER the trailing slashes come off, not only before: a slash-only
     * value trims to `''` only at that point, and testing first would accept
     * `/` as a base and rebuild the exact relative URL this class exists to
     * prevent. `deploy/README.md` records that failure happening to three
     * emails on the sibling key.
     */
    public static function url(): string
    {
        foreach (['app.frontend_url', 'app.url'] as $key) {
            $base = rtrim(trim((string) config($key)), '/');

            if ($base !== '') {
                return $base;
            }
        }

        return '';
    }
}
