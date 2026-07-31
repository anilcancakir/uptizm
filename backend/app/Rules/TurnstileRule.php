<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
            $fail(__('Verification failed. Please try again.'));
        }
    }
}
