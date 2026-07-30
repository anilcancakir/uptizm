<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to resolve a status page whose slug is a reserved hostname label.
 *
 * Applied ONLY to the subdomain route (see routes/status.php). On the path
 * route a reserved word is harmless, and 404ing there would break a customer's
 * already-working `<host>/s/<word>` page the moment a word is added to the
 * list. On the subdomain route the same word would shadow infrastructure we
 * serve ourselves, so it must not resolve even if the row predates the list.
 *
 * The 404 is the same fail-closed answer the controller gives an unknown slug,
 * so a reserved label is indistinguishable from an unclaimed one.
 */
class RejectReservedStatusPageSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        if (! is_string($slug)) {
            abort(404);
        }

        /** @var array<int, string> $reserved */
        $reserved = config('status_pages.reserved_slugs', []);

        if (in_array(mb_strtolower($slug), $reserved, true)) {
            abort(404);
        }

        return $next($request);
    }
}
