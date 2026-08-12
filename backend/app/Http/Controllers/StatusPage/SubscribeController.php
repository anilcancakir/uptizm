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
 *
 * Every subscriber-facing render answers in `StatusPageSubscriber::$locale`
 * rather than the page's current language, captured once at {@see self::store()}.
 * The subscribe route carries no locale segment (it is a write, not an
 * indexable document), so the only honest source is a value the subscribe form
 * itself submits: the hidden `locale` field at
 * `resources/views/status/partials/subscribe-box.blade.php:32`, carrying the
 * language the visitor was reading when they subscribed. That field IS the
 * channel, so deleting it silently sends every future subscriber the page's
 * canonical language instead of their own; {@see self::resolveSubscriberLocale()}
 * falls back to it for a request that arrives without the field, which is the
 * only shape a hand-built POST can take.
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

        // The language to capture for this subscriber, resolved once so every
        // branch below (dedupe, cap, fresh create) answers with the same value.
        $locale = $this->resolveSubscriberLocale($request, $page);

        // 3. Dedupe without an oracle: if this address already has a row for the
        //    page (pending OR confirmed), return the uniform response and send no
        //    second mail. Guarding on ANY existing row (not just pending) also
        //    dodges the `(status_page_id, email)` unique-constraint 500 that a
        //    resubscribe of a confirmed address would otherwise leak as a signal.
        //    The existing row keeps ITS OWN captured locale rather than the one
        //    just resolved: a repeat submission from a different language must
        //    not silently move a confirmed subscriber's mail language.
        $existing = $page->subscribers()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->checkInboxView($page, $existing->locale ?? $locale);
        }

        // At the plan's per-page subscriber cap, decline silently: the uniform
        // check-inbox view with no new row, consistent with the page's other
        // privacy-preserving responses (the cap is never leaked to the visitor;
        // the owner sees it in the billing usage report and can upgrade).
        $team = Team::find($page->team_id);
        $limit = $team !== null ? (new PlanGate)->subscriberLimit($team) : null;
        if ($limit !== null && $page->subscribers()->count() >= $limit) {
            return $this->checkInboxView($page, $locale);
        }

        // 4. Create an unconfirmed subscriber and mail the confirm link ONLY to
        //    the entered address (double opt-in).
        $subscriber = $page->subscribers()->create([
            'email' => $email,
            'confirmed_token' => Str::random(48),
            'unsubscribe_token' => Str::random(48),
            'subscribed_at' => now(),
            'locale' => $locale,
        ]);

        // The subscriber's captured language, not the page's current one: this
        // mail is the continuation of a page a visitor just read, and the two can
        // already diverge (the page's language changes; the subscriber's does
        // not). `->locale()` scopes the render through Laravel's own
        // `withLocale`, which covers the SUBJECT as well as the body, since
        // `envelope()` runs inside `Mailable::send()`.
        Mail::to($email)
            ->locale($subscriber->locale ?? (string) config('app.default_locale'))
            ->send(new StatusPageSubscribeConfirmation($page, $subscriber));

        return $this->checkInboxView($page, $subscriber->locale);
    }

    /**
     * Resolve the language to capture for a new (or re-submitted) subscriber.
     *
     * The subscribe route carries no locale segment, so the only honest source
     * of "the language the visitor was reading" is a value their page's own
     * form submits. An unsupported submitted value is stored as null rather
     * than rejected, per this endpoint's Must NOT: a subscriber must never fail
     * to subscribe over a language code. With no submitted value at all, the
     * capture falls back to the page's own canonical language, read here ONCE
     * at write time; every subsequent RENDER reads the subscriber's stored
     * value instead, never the page's.
     */
    protected function resolveSubscriberLocale(Request $request, StatusPage $page): string
    {
        $submitted = $request->input('locale');
        $supported = array_values((array) config('magic-starter.supported_locales', []));

        if (is_string($submitted) && in_array($submitted, $supported, true)) {
            return $submitted;
        }

        // An unsupported submitted value takes the SAME fallback as no value at
        // all. Answering null there instead sent the subscriber
        // `app.default_locale` (the read sites coalesce a null column that way)
        // while an absent field sent them the page's own language, so one
        // hand-built POST field decided between two different languages for
        // reasons no reader could see. Both are supported languages, so this is a
        // consistency fix rather than a hole.
        return $page->locale ?? (string) config('app.default_locale');
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

        // This is the ONLY place `opt_in_confirmed_at` is ever written, from
        // either the public or the operator surface. It records that a click on a
        // confirm link reached this system for this address, which is what the
        // outbound announcement path selects recipients on: `confirmed_at` alone
        // cannot distinguish a proven opt-in from a row an operator typed in
        // before the add path required a click.
        $subscriber->update([
            'confirmed_at' => now(),
            'opt_in_confirmed_at' => now(),
            'confirmed_token' => null,
        ]);

        // The subscriber's own captured language, applied before the view renders.
        // Every string in `status.confirmed` resolves from the catalogue, so
        // without this a Turkish-reading subscriber confirms in English: the copy
        // exists and is simply never reached. Read off the subscriber rather than
        // the page, since the page's language can change after this row was
        // written. Set unconditionally, including for the default, for the reason
        // `ShowStatusPageController` documents.
        app()->setLocale($subscriber->locale ?? (string) config('app.default_locale'));

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

        // The subscriber's own captured language: an unsubscribe link is followed
        // days later, out of any page context, and it should still speak the
        // language THIS subscriber was reading, not the page's current one (which
        // may have changed since). Read BEFORE the delete, since the row is gone
        // afterwards.
        $locale = $subscriber->locale ?? (string) config('app.default_locale');

        $subscriber->delete();

        app()->setLocale($locale);

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
     *
     * @param  string|null  $locale  The subscriber's captured language (the
     *                               existing row's for a dedupe, the just-resolved
     *                               one otherwise), never re-derived from the
     *                               page here.
     */
    protected function checkInboxView(StatusPage $page, ?string $locale): View
    {
        app()->setLocale($locale ?? (string) config('app.default_locale'));

        return view('status.subscribe-check-inbox', [
            'page' => $page,
        ]);
    }
}
