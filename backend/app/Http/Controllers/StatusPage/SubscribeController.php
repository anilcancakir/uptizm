<?php

namespace App\Http\Controllers\StatusPage;

use App\Mail\StatusPageSubscribeConfirmation;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public, unauthenticated write surface for status-page subscriptions.
 *
 * Every action inherits the read surface's fail-closed privacy gate: an unknown
 * slug and a non-public page both answer 404 (never 403, never a distinguishable
 * body), so the write surface leaks no more than the read one. Subscribe
 * deliberately has NO preview-token path, so a private page never accepts a
 * subscribe and the unknown-vs-private parity holds without a token branch.
 *
 * Two abuse mitigations live here and are load-bearing:
 *   - Dedupe: a repeat subscribe for an address that already has a row returns
 *     the SAME uniform response and sends no second mail, so a visitor cannot
 *     probe whether an address is already subscribed (no already-subscribed
 *     oracle) and cannot use the endpoint to spray mail.
 *   - Single-use confirm: confirming burns the token, so a link-prefetch or a
 *     scanner replay of the confirm URL is a no-op 404, not a re-confirmation.
 */
class SubscribeController
{
    /**
     * Register an unconfirmed subscriber and mail the confirm link.
     *
     * @throws NotFoundHttpException
     */
    public function store(Request $request, string $slug): View
    {
        // 1. Fail-closed privacy gate: a subscribe only exists for a genuinely
        //    public page (unknown OR private slug -> 404 parity with show).
        $page = $this->resolvePublicPageOrFail($slug);

        // 2. A page whose owner disabled subscriptions is indistinguishable from
        //    one that never existed.
        if (! $page->subscriptions_enabled) {
            abort(404);
        }

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:254',
            ],
        ]);
        $email = $validated['email'];

        // 3. Dedupe without an oracle: if this address already has a row for the
        //    page (pending OR confirmed), return the uniform response and send no
        //    second mail. Guarding on ANY existing row (not just pending) also
        //    dodges the `(status_page_id, email)` unique-constraint 500 that a
        //    resubscribe of a confirmed address would otherwise leak as a signal.
        $existing = $page->subscribers()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->checkInboxView($page);
        }

        // At the plan's per-page subscriber cap, decline silently: the uniform
        // check-inbox view with no new row, consistent with the page's other
        // privacy-preserving responses (the cap is never leaked to the visitor;
        // the owner sees it in the billing usage report and can upgrade).
        $team = Team::find($page->team_id);
        $limit = $team !== null ? (new PlanGate)->subscriberLimit($team) : null;
        if ($limit !== null && $page->subscribers()->count() >= $limit) {
            return $this->checkInboxView($page);
        }

        // 4. Create an unconfirmed subscriber and mail the confirm link ONLY to
        //    the entered address (double opt-in).
        $subscriber = $page->subscribers()->create([
            'email' => $email,
            'confirmed_token' => Str::random(48),
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now(),
        ]);

        Mail::to($email)->send(new StatusPageSubscribeConfirmation($page, $subscriber));

        return $this->checkInboxView($page);
    }

    /**
     * Activate a subscription from its confirm link. Single-use: the token is
     * burned on success so a replay of the same URL is a no-op 404.
     *
     * @throws NotFoundHttpException
     */
    public function confirm(Request $request, string $slug, string $token): View
    {
        $page = $this->resolvePublicPageOrFail($slug);

        // Look the row up BY the indexed token (48-char random), then constant-
        // time compare the stored token to avoid a timing-sensitive equals. A
        // burned (nulled) token matches no row, so a replay 404s.
        $subscriber = $page->subscribers()
            ->where('confirmed_token', $token)
            ->first();

        if ($subscriber === null || ! hash_equals((string) $subscriber->confirmed_token, $token)) {
            abort(404);
        }

        $subscriber->update([
            'confirmed_at' => now(),
            'confirmed_token' => null,
        ]);

        return view('status.confirmed', [
            'page' => $page,
        ]);
    }

    /**
     * Remove a subscription from its one-click unsubscribe link. This route
     * carries no slug: it is addressed by the token alone.
     *
     * @throws NotFoundHttpException
     */
    public function unsubscribe(Request $request, string $token): View
    {
        // Same token discipline as confirm: indexed lookup + constant-time
        // compare, then remove the row so the address stops receiving mail.
        $subscriber = StatusPageSubscriber::query()
            ->where('unsubscribe_token', $token)
            ->first();

        if ($subscriber === null || ! hash_equals((string) $subscriber->unsubscribe_token, $token)) {
            abort(404);
        }

        $subscriber->delete();

        return view('status.unsubscribed');
    }

    /**
     * Resolve a public page by slug behind the same fail-closed gate the read
     * surface enforces: an unknown slug and a non-public page both 404.
     *
     * @throws NotFoundHttpException
     */
    protected function resolvePublicPageOrFail(string $slug): StatusPage
    {
        $page = StatusPage::query()->where('slug', $slug)->first();

        if ($page === null || ! $page->is_public) {
            abort(404);
        }

        return $page;
    }

    /**
     * The uniform "check your inbox" response. Identical for a fresh subscribe
     * and a deduped repeat so neither reveals subscription state.
     */
    protected function checkInboxView(StatusPage $page): View
    {
        return view('status.subscribe-check-inbox', [
            'page' => $page,
        ]);
    }
}
