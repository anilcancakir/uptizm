<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SetSentryContext;
use App\Models\User;
use Tests\TestCase;

/**
 * Locks what an issue says about WHO hit it.
 *
 * An error with no actor is a bug report with the most useful line torn off:
 * every triage question ("is this one tenant or everyone", "is it the team that
 * just upgraded", "can I reproduce it on their data") starts from the user and
 * the team. Sentry has a first-class place for both, and nothing fills it by
 * default here because `send_default_pii` is off.
 *
 * The shape is deliberately narrow. An id and a team are identifiers this
 * application already puts in its own logs; a name, a phone number or a plan
 * would be a second copy of a customer's record living in a third party for no
 * triage benefit.
 */
class SetSentryContextTest extends TestCase
{
    /**
     * A guest request must not invent an actor.
     */
    public function test_it_reports_no_user_for_a_guest(): void
    {
        $context = SetSentryContext::contextFor(null);

        $this->assertSame([], $context['user']);
        $this->assertSame([], $context['tags']);
    }

    /**
     * The ordinary case: an authenticated member of a team.
     */
    public function test_it_carries_the_user_and_the_team(): void
    {
        $user = new User;
        $user->id = 7;
        $user->email = 'operator@example.test';
        $user->current_team_id = 3;

        $context = SetSentryContext::contextFor($user);

        $this->assertSame('7', $context['user']['id']);
        $this->assertSame('operator@example.test', $context['user']['email']);
        $this->assertSame('3', $context['tags']['team_id']);
    }

    /**
     * Guest authentication is a real feature here, and it mints users with a
     * NULL email (magic-starter-laravel's `ConditionallyUsesUuids` guest path).
     *
     * A middleware that assumed a string would either send `null` as an email
     * or throw inside a reporting layer, which is the worst place to throw: the
     * request fails for a reason that has nothing to do with the request.
     */
    public function test_it_omits_an_email_the_user_does_not_have(): void
    {
        $user = new User;
        $user->id = 9;
        $user->email = null;
        $user->current_team_id = 4;

        $context = SetSentryContext::contextFor($user);

        $this->assertSame('9', $context['user']['id']);
        $this->assertArrayNotHasKey('email', $context['user']);
    }

    /**
     * A user between teams (just deleted their last one, or mid-invitation) is
     * still worth reporting; the tag is simply absent rather than 'null'.
     */
    public function test_it_omits_the_team_tag_when_there_is_no_current_team(): void
    {
        $user = new User;
        $user->id = 11;
        $user->email = 'lonely@example.test';
        $user->current_team_id = null;

        $context = SetSentryContext::contextFor($user);

        $this->assertSame('11', $context['user']['id']);
        $this->assertArrayNotHasKey('team_id', $context['tags']);
    }
}
