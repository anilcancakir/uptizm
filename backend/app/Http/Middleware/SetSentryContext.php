<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells Sentry WHO an error happened to.
 *
 * Without this every issue is anonymous, and anonymous is where triage stops:
 * the first three questions asked about any error here are whether it is one
 * tenant or all of them, whether it is the team that just changed plan, and
 * whether the data can be reproduced. All three start from a user id and a team
 * id, and nothing fills either by default, because `send_default_pii` is off
 * and this application never wanted the blanket version of that setting.
 *
 * WHAT IT SENDS, AND WHAT IT DELIBERATELY DOES NOT. An id, an email and a team
 * id. The id and the team are already in this application's own logs, so they
 * are not new exposure. The email is, and it is here on purpose: an operator
 * reporting "the dashboard is broken" is found by address, not by primary key.
 * A name, a phone number, a plan or a locale would be a second copy of a
 * customer record living in a third party for no triage benefit, so they stay
 * out.
 *
 * IT RUNS ON THE `api` GROUP ONLY, appended after the auth middleware that
 * resolves the user it reads. The marketing and status-page groups are
 * anonymous by design (a public status page sets no cookie at all), so there is
 * no actor to report there and adding one would mean inventing a session for
 * pages that deliberately have none.
 *
 * Inert without a DSN, like the rest of the SDK: `configureScope` on a disabled
 * client writes to a scope nobody reads.
 */
class SetSentryContext
{
    /**
     * Attach the caller's identity for the rest of the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = self::contextFor($request->user());

        if ($context['user'] !== [] || $context['tags'] !== []) {
            Integration::configureScope(static function (Scope $scope) use ($context): void {
                if ($context['user'] !== []) {
                    $scope->setUser($context['user']);
                }

                foreach ($context['tags'] as $key => $value) {
                    $scope->setTag($key, $value);
                }
            });
        }

        return $next($request);
    }

    /**
     * The identity to report for [$user], as a plain array.
     *
     * Separated from `handle()` so the decisions above are testable without a
     * Sentry client: what is included, and what is omitted rather than sent as
     * a null.
     *
     * OMITTED, NOT NULLED, and both cases are real rather than defensive. Guest
     * authentication mints users with no email at all (the mobile guest path in
     * magic-starter-laravel), and a user between teams has no
     * `current_team_id`. Sending `null` for either would put a literal "null"
     * in Sentry's user card and in a tag's value list, where it reads as a
     * corrupt record rather than an absent one, and tags are indexed so it
     * would also become a searchable value nobody wants.
     *
     * @param  Authenticatable|null  $user  The resolved caller, or null for a guest.
     * @return array{user: array<string, string>, tags: array<string, string>}
     */
    public static function contextFor(?Authenticatable $user): array
    {
        if ($user === null) {
            return [
                'user' => [],
                'tags' => [],
            ];
        }

        $identity = [
            'id' => (string) $user->getAuthIdentifier(),
        ];

        $email = $user->email ?? null;

        if (is_string($email) && $email !== '') {
            $identity['email'] = $email;
        }

        $tags = [];
        $teamId = $user->current_team_id ?? null;

        if ($teamId !== null) {
            $tags['team_id'] = (string) $teamId;
        }

        return [
            'user' => $identity,
            'tags' => $tags,
        ];
    }
}
