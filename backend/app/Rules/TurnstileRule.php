<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a Cloudflare Turnstile token against the siteverify endpoint.
 *
 * Ported from fluttersdk.com's `App\Rules\TurnstileRule` (READ-ONLY reference in the
 * sibling repo, not a dependency), because a CAPTCHA rule is thirty lines and a shared
 * package between two unrelated products would be a worse coupling than the copy.
 *
 * FAIL CLOSED, BUT NEVER WITH A 500
 *
 * Every outcome other than a confirmed `success` fails the field: a blank token, a non-2xx
 * siteverify response, a thrown transport exception, and an explicit `success: false`. The
 * transport exception is the one that matters. Cloudflare being unreachable is an ordinary
 * event, and an uncaught `ConnectionException` here would surface as a 500 on an
 * unauthenticated public endpoint, which is both a bad visitor experience and a free
 * availability signal for anybody probing. It becomes a validation error instead, and the
 * contact form's view-returning design re-renders the page with the message so the visitor
 * can simply try again.
 *
 * This rule is only ever attached when `services.turnstile.secret_key` is filled; see
 * `SendContactMessageController::rules()` for the dormancy gate.
 *
 * THE VERDICT IS CODE-AGNOSTIC, THE LOG IS NOT
 *
 * A refusal is a refusal: nothing below branches on which code Cloudflare returned, and
 * nothing should, because the visitor-facing message must not vary with a bot's mistake and
 * because the code table is Cloudflare's to change. But the visitor's message is also the
 * only trace a refusal used to leave, and it reads identically whether a bot was turned away
 * or the deployment is holding the wrong secret and refusing everybody. So every non-success
 * writes its codes to the log, and the three codes that mean the fault is OURS get a second,
 * louder line.
 */
class TurnstileRule implements ValidationRule
{
    /**
     * The Cloudflare Turnstile siteverify endpoint.
     */
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * How long to wait on siteverify before giving up. Short on purpose: the visitor is
     * holding an open request, and a slow CAPTCHA is indistinguishable from a broken one.
     */
    private const TIMEOUT_SECONDS = 10;

    /**
     * The siteverify codes that mean the DEPLOYMENT is broken rather than the submission.
     *
     * `missing-input-secret` and `invalid-input-secret` are a blank or mis-copied
     * `TURNSTILE_SECRET_KEY`, and `bad-request` is a malformed call to siteverify: none of
     * the three can be produced by anything a visitor does, and each of them refuses every
     * legitimate visitor for as long as it lasts. Every other code (a stale, replayed or
     * absent token, an internal error at Cloudflare) is ordinary traffic and is only
     * recorded.
     *
     * @var list<string>
     */
    private const OPERATOR_FAULT_CODES = [
        'missing-input-secret',
        'invalid-input-secret',
        'bad-request',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            $fail(__('Please complete the verification challenge.'));

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::SITEVERIFY_URL, [
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $value,
                    // Cloudflare scores the token against the address that solved the
                    // challenge, so a mismatch here weakens the check rather than breaking
                    // it. `TrustProxies` is configured for the loopback only, so this is the
                    // address nginx rewrote from `CF-Connecting-IP` and not a client-supplied
                    // `X-Forwarded-For` entry.
                    'remoteip' => request()->ip(),
                ]);
        } catch (ConnectionException) {
            $fail(__('Verification is temporarily unavailable. Please try again.'));

            return;
        }

        if ($response->failed()) {
            $fail(__('Verification is temporarily unavailable. Please try again.'));

            return;
        }

        if ($response->json('success') !== true) {
            $this->recordRefusal($response->json('error-codes'));

            $fail(__('Verification failed. Please try again.'));
        }
    }

    /**
     * Write down why siteverify refused, since the visitor's message deliberately cannot.
     *
     * @param  mixed  $codes  Whatever sat under `error-codes`; siteverify documents a list of
     *                        strings, and an absent or malformed value is normalized away
     *                        rather than trusted, because this runs on a public endpoint.
     */
    private function recordRefusal(mixed $codes): void
    {
        // 1. Normalize to a list of strings. `Arr::wrap(null)` is `[]`, so an answer carrying
        //    no `error-codes` at all still logs, with an empty list saying exactly that.
        $codes = array_values(array_filter(
            Arr::wrap($codes),
            static fn (mixed $code): bool => is_string($code),
        ));

        // 2. Every refusal, whoever's fault it is.
        Log::warning('Turnstile refused a contact form submission.', [
            'error_codes' => $codes,
        ]);

        $ourFault = array_values(array_intersect($codes, self::OPERATOR_FAULT_CODES));

        if ($ourFault === []) {
            return;
        }

        // 3. And the distinct line for the failure that is ours, at a level nobody filters
        //    out: while one of these codes is coming back, the contact form is rejecting
        //    every human who fills it in, and the page they see says nothing about it.
        Log::error(
            'Turnstile is rejecting every submission because of this deployment, not the visitor. '
            .'Check TURNSTILE_SECRET_KEY against the widget it was issued for.',
            ['error_codes' => $ourFault],
        );
    }
}
