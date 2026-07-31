<?php

namespace Tests\Feature\Marketing;

use App\Http\Controllers\Marketing\SendContactMessageController;
use App\Http\Middleware\SetMarketingLocale;
use App\Mail\ContactMessage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The contact form: the only unauthenticated write path on the marketing site.
 *
 * WHY EVERY ASSERTION HERE READS THE RESPONSE BODY
 *
 * `assertRedirect` and `assertSessionHasErrors` are BANNED in this file, and the ban is
 * not stylistic. `TestResponse::session()` resolves `app('session.store')` and starts it
 * on demand (`vendor/.../Testing/TestResponse.php:1832`), and `RedirectResponse::withErrors()`
 * flashes into whatever store the Redirector holds, which exists whenever the binding
 * does. So a redirect-back design with NO `StartSession` on the route flashes into a store
 * nobody ever saves, the visitor sees an empty form, and BOTH of those assertions pass
 * anyway. A feature test phrased around the session cannot tell the working design from
 * the broken one. So every assertion below reads the rendered HTML instead, which is the
 * only thing a visitor actually receives.
 *
 * WHAT THE DELIVERABILITY GATE MEANS FOR THIS FILE
 *
 * `phpunit.xml` sets `MAIL_MAILER=array`, so the gate is CLOSED by default in every test
 * and the form does not exist unless a test opens it. That is deliberate: it makes the
 * withheld state the default one under test, and it means a test that forgets
 * `openTheGate()` fails loudly rather than silently exercising a form nobody can reach.
 */
class ContactFormTest extends TestCase
{
    /**
     * A message long enough to clear the `min` rule, so a test that means to fail on
     * something else does not accidentally fail on length.
     */
    protected const VALID_MESSAGE = 'A monitor reports down from one region only and I want to understand why.';

    /**
     * Middleware that reads or writes a session, ships a cookie, or gates on a CSRF token.
     * Restated here rather than borrowed from `CookieTest` (whose copy is `protected`, so it
     * is unreachable from another class) and matched as a SUBSTRING against the resolved
     * class name: on Laravel 13 the CSRF middleware is `PreventRequestForgery` and
     * `VerifyCsrfToken` is an empty compat subclass, so either name may legitimately appear
     * and both must be caught, and a substring also catches a project subclass.
     */
    protected const SESSION_COUPLED = [
        'StartSession',
        'EncryptCookies',
        'AddQueuedCookiesToResponse',
        'ShareErrorsFromSession',
        'PreventRequestForgery',
        'VerifyCsrfToken',
    ];

    // ---------------------------------------------------------------------
    // The deliverability gate
    // ---------------------------------------------------------------------

    public function test_the_form_is_withheld_whenever_the_transport_cannot_actually_send(): void
    {
        /*
         * The three transports the gate must refuse, and `failover` is the one a denylist
         * gets wrong: `config/mail.php` defines it as `['smtp', 'log']`, so a `!== 'log'`
         * check renders the form and then silently writes every message to the log file.
         * That is why the gate is an allowlist.
         */
        foreach (['log', 'array', 'failover'] as $mailer) {
            config([
                'mail.default' => $mailer,
                'mail.from.address' => 'noreply@uptizm.test',
            ]);

            $response = $this->get('/contact');
            $response->assertOk();

            $this->assertStringNotContainsString(
                '<form',
                $response->getContent(),
                "MAIL_MAILER={$mailer} cannot send, so the page must not offer a form that goes nowhere.",
            );

            $response->assertSee((string) config('legal.contact_email'));
        }
    }

    public function test_the_form_is_withheld_while_the_from_address_is_the_framework_default(): void
    {
        // `smtp` plus `hello@example.com` is "configured" and rejected by every MTA, so the
        // transport alone is not enough to claim the deployment can send.
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'hello@example.com',
        ]);

        $response = $this->get('/contact');

        $response->assertOk();
        $this->assertStringNotContainsString('<form', $response->getContent());
        $response->assertSee((string) config('legal.contact_email'));
    }

    public function test_the_form_renders_once_the_transport_and_the_from_address_are_both_real(): void
    {
        $this->openTheGate();

        $response = $this->get('/contact');

        $response->assertOk();
        $this->assertSame(
            1,
            substr_count($response->getContent(), '<form'),
            'The contact page must render exactly one form.',
        );
    }

    public function test_the_write_path_does_not_exist_while_mail_is_not_deliverable(): void
    {
        // No form is rendered, so no POST target should answer either: a 404 masks the
        // endpoint entirely rather than accepting a message this deployment cannot deliver.
        Mail::fake();

        $this->post('/contact', $this->payload())->assertNotFound();

        Mail::assertNothingQueued();
    }

    // ---------------------------------------------------------------------
    // The view-returning contract
    // ---------------------------------------------------------------------

    public function test_a_validation_failure_re_renders_the_page_with_the_messages_and_the_submitted_values(): void
    {
        $this->openTheGate();
        Mail::fake();

        $response = $this->post('/contact', $this->payload([
            'email' => 'not-an-address',
            'message' => 'too short',
        ]));

        // A 422 and a rendered page, never a redirect: the errors have to arrive in the
        // response body because there is no session to carry them.
        $response->assertStatus(422);
        $response->assertSee('That does not look like an email address.');
        $response->assertSee('Please write a little more so the operator can help.');

        // The submitted values come back with the page. Without this a visitor retypes
        // everything, and with no session there is no `old()` to read them from.
        $response->assertSee('value="not-an-address"', escape: false);
        $response->assertSee('too short');

        // The form is still there to correct and resend.
        $this->assertSame(1, substr_count($response->getContent(), '<form'));

        Mail::assertNothingQueued();
    }

    public function test_an_empty_submission_answers_422_with_every_missing_field_named(): void
    {
        $this->openTheGate();
        Mail::fake();

        $response = $this->post('/contact', []);

        $response->assertStatus(422);
        $response->assertSee('Please tell us your name.');
        $response->assertSee('Please give us an email address we can reply to.');
        $response->assertSee('Please write your message.');

        Mail::assertNothingQueued();
    }

    public function test_a_valid_submission_queues_exactly_one_mail_to_the_operator(): void
    {
        $this->openTheGate();
        Mail::fake();

        $response = $this->post('/contact', $this->payload());

        $response->assertOk();
        $response->assertSee('Your message reached us.');

        // The form is replaced by the acknowledgement, so a visitor cannot resend the same
        // message by mashing the button.
        $this->assertStringNotContainsString('<form', $response->getContent());

        Mail::assertQueued(ContactMessage::class, 1);
    }

    public function test_the_mail_goes_to_the_operator_and_never_back_to_the_submitter(): void
    {
        /*
         * An acknowledgement echoing the submitted body would turn this endpoint into a
         * spam relay from an SPF-aligned domain: an attacker puts the payload in the
         * message, the victim's address in `email`, and our own DKIM signature delivers it.
         * So exactly one recipient, and it is the operator.
         */
        $this->openTheGate();
        Mail::fake();

        $this->post('/contact', $this->payload(['email' => 'victim@example.test']))->assertOk();

        Mail::assertQueued(ContactMessage::class, function (ContactMessage $mail): bool {
            return $mail->hasTo((string) config('legal.contact_email'))
                && ! $mail->hasTo('victim@example.test');
        });
    }

    // ---------------------------------------------------------------------
    // Anti-abuse: the four layers
    // ---------------------------------------------------------------------

    public function test_a_submission_faster_than_a_human_can_type_is_rejected(): void
    {
        $this->openTheGate();
        Mail::fake();

        $response = $this->post('/contact', $this->payload([
            SendContactMessageController::TIMESTAMP_FIELD => $this->formToken(0),
        ]));

        $response->assertStatus(422);
        $response->assertSee('That went too fast. Please send it again.');

        Mail::assertNothingQueued();
    }

    public function test_a_harvested_form_token_cannot_be_replayed_forever(): void
    {
        /*
         * `Encrypter` appends an HMAC, so a bot cannot MINT a token. It can fetch the page
         * once and replay the ciphertext it was given, though, and a minimum-age-only check
         * passes more happily the older that ciphertext gets. The maximum age is what
         * closes it.
         */
        $this->openTheGate();
        Mail::fake();

        $response = $this->post('/contact', $this->payload([
            SendContactMessageController::TIMESTAMP_FIELD => $this->formToken(
                SendContactMessageController::MAX_FORM_AGE_SECONDS + 60,
            ),
        ]));

        $response->assertStatus(422);
        $response->assertSee('This form has been open too long. Please send it again.');

        Mail::assertNothingQueued();
    }

    public function test_a_tampered_form_token_is_a_validation_error_and_never_a_500(): void
    {
        $this->openTheGate();
        Mail::fake();

        $response = $this->post('/contact', $this->payload([
            SendContactMessageController::TIMESTAMP_FIELD => 'not-a-ciphertext',
        ]));

        $response->assertStatus(422);
        Mail::assertNothingQueued();
    }

    public function test_a_filled_honeypot_is_rejected(): void
    {
        $this->openTheGate();
        Mail::fake();

        $response = $this->post('/contact', $this->payload([
            SendContactMessageController::HONEYPOT_FIELD => 'https://example.test/cheap-pills',
        ]));

        $response->assertStatus(422);
        Mail::assertNothingQueued();
    }

    public function test_the_honeypot_is_reachable_and_labelled_rather_than_display_none(): void
    {
        /*
         * A `display: none` honeypot is invisible to a CSS-blocked client too, so somebody
         * on a text browser or a screen reader fills it in and is silently refused. The
         * field is therefore visible, labelled "leave this empty", out of the tab order and
         * hidden from assistive technology.
         */
        $this->openTheGate();

        $html = $this->get('/contact')->getContent();

        $this->assertStringContainsString('name="'.SendContactMessageController::HONEYPOT_FIELD.'"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringNotContainsString('display: none', $html);
        $this->assertStringNotContainsString('display:none', $html);
    }

    public function test_the_honeypot_is_not_named_after_anything_browser_autofill_targets(): void
    {
        // Autofill happily writes into `name`, `email`, `url`, `website`, `phone` and
        // `company`, which would make the trap fire on a real visitor.
        $this->assertNotContains(
            SendContactMessageController::HONEYPOT_FIELD,
            ['name', 'email', 'url', 'website', 'phone', 'company', 'address', 'organization'],
        );
    }

    public function test_turnstile_is_dormant_until_a_secret_is_configured(): void
    {
        // The rule must not exist without a secret, or every deployment that has not set
        // one up rejects every message.
        $this->openTheGate();
        Mail::fake();
        Http::preventStrayRequests();

        $this->post('/contact', $this->payload())->assertOk();

        Mail::assertQueued(ContactMessage::class, 1);
    }

    public function test_turnstile_rejects_a_submission_with_no_token_once_configured(): void
    {
        $this->openTheGate();
        config(['services.turnstile.secret_key' => 'a-secret']);
        Mail::fake();
        Http::preventStrayRequests();

        $response = $this->post('/contact', $this->payload());

        $response->assertStatus(422);
        $response->assertSee('Please complete the verification challenge.');

        // The Cloudflare field name is an implementation detail and must not leak into a
        // message a visitor reads.
        $response->assertDontSee('cf-turnstile-response');

        Mail::assertNothingQueued();
    }

    public function test_a_turnstile_network_failure_is_a_validation_error_and_never_a_500(): void
    {
        $this->openTheGate();
        config(['services.turnstile.secret_key' => 'a-secret']);
        Mail::fake();
        Http::fake(function (): void {
            throw new ConnectionException('siteverify is unreachable');
        });

        $response = $this->post('/contact', $this->payload([
            'cf-turnstile-response' => 'a-token',
        ]));

        $response->assertStatus(422);
        $response->assertSee('Verification is temporarily unavailable. Please try again.');

        Mail::assertNothingQueued();
    }

    public function test_a_turnstile_refusal_rejects_the_submission(): void
    {
        $this->openTheGate();
        config(['services.turnstile.secret_key' => 'a-secret']);
        Mail::fake();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false]),
        ]);

        $response = $this->post('/contact', $this->payload([
            'cf-turnstile-response' => 'a-token',
        ]));

        $response->assertStatus(422);
        $response->assertSee('Verification failed. Please try again.');

        Mail::assertNothingQueued();
    }

    public function test_a_confirmed_turnstile_token_lets_the_message_through(): void
    {
        $this->openTheGate();
        config(['services.turnstile.secret_key' => 'a-secret']);
        Mail::fake();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->post('/contact', $this->payload([
            'cf-turnstile-response' => 'a-token',
        ]))->assertOk();

        Mail::assertQueued(ContactMessage::class, 1);
    }

    // ---------------------------------------------------------------------
    // The three-bucket limiter
    // ---------------------------------------------------------------------

    public function test_the_write_path_is_throttled_by_a_named_limiter(): void
    {
        $this->assertContains(
            'throttle:'.SendContactMessageController::LIMITER,
            $this->routeFor('/contact', 'POST')->gatherMiddleware(),
            'The contact POST is unauthenticated, so the named limiter is the only thing bounding a flood.',
        );
    }

    public function test_three_buckets_are_registered_and_the_global_one_is_keyed_by_a_constant(): void
    {
        $limiter = RateLimiter::limiter(SendContactMessageController::LIMITER);

        $this->assertNotNull($limiter, 'The `'.SendContactMessageController::LIMITER.'` limiter is not registered.');

        $first = $limiter($this->submissionFrom('203.0.113.9', 'one@example.test'));
        $second = $limiter($this->submissionFrom('198.51.100.4', 'two@example.test'));

        $this->assertCount(3, $first, 'Three buckets: per IP, per submitted email, and a fixed global key.');

        foreach ($first as $limit) {
            $this->assertInstanceOf(Limit::class, $limit);
        }

        $firstKeys = array_map(fn (Limit $limit): string => (string) $limit->key, $first);
        $secondKeys = array_map(fn (Limit $limit): string => (string) $limit->key, $second);

        $this->assertStringContainsString(
            '203.0.113.9',
            implode(' ', $firstKeys),
            'No bucket is keyed by the source address, so one host can flood the operator inbox.',
        );

        $this->assertStringContainsString(
            'one@example.test',
            implode(' ', $firstKeys),
            'No bucket is keyed by the submitted email address.',
        );

        /*
         * The load-bearing bucket. A contact form mails the OPERATOR, so an attacker varies
         * the submitted email for free and the per-email bucket protects nothing at all. The
         * fixed key is what caps a distributed flood in aggregate, and it is only fixed if
         * it is IDENTICAL for two requests that share neither address nor email: that is
         * exactly what the intersection below measures.
         */
        $this->assertSame(
            [SendContactMessageController::GLOBAL_LIMITER_KEY],
            array_values(array_intersect($firstKeys, $secondKeys)),
            'Exactly one bucket must be shared by two unrelated submissions, and it is the global one.',
        );
    }

    // ---------------------------------------------------------------------
    // The cookie-free contract
    // ---------------------------------------------------------------------

    public function test_the_write_path_carries_no_session_cookie_or_csrf_middleware(): void
    {
        /*
         * Asserted the same way `CookieTest` does, and it matters most here: the POST is the
         * one route on this surface anybody would reflexively "fix" by adding CSRF, and
         * `PreventRequestForgery` cannot go on a session-free route at all, because on the
         * way out it always calls `$request->session()->token()` to mint `XSRF-TOKEN`
         * (`PreventRequestForgery.php:243`, verified against this repo's vendor).
         */
        foreach ($this->middlewareFor('/contact', 'POST') as $entry) {
            foreach (self::SESSION_COUPLED as $coupled) {
                $this->assertStringNotContainsString(
                    $coupled,
                    $entry,
                    "POST /contact resolves {$entry}, which puts a cookie on the one page that publishes a "
                    .'claim that it sets none.',
                );
            }
        }
    }

    public function test_the_write_path_is_not_in_the_web_group(): void
    {
        $this->assertNotContains(
            'web',
            $this->routeFor('/contact', 'POST')->gatherMiddleware(),
            'POST /contact is in the `web` group, so it inherits StartSession no matter what else is done to it.',
        );
    }

    public function test_the_write_path_still_binds_parameters_and_sets_the_locale(): void
    {
        // The positive control: the two assertions above pass just as happily against a
        // route that lost its middleware entirely, or one that stopped existing.
        $middleware = $this->middlewareFor('/contact', 'POST');

        $this->assertContains(SubstituteBindings::class, $middleware);
        $this->assertContains(SetMarketingLocale::class, $middleware);
    }

    public function test_every_language_can_submit_the_form_on_its_own_url(): void
    {
        /*
         * Without a per-language POST route a Turkish visitor's form posts to the English
         * path, `SetMarketingLocale` sets `en`, and every error message comes back in
         * English on a Turkish page. The wording is not asserted here (the translation keys
         * are added outside this step); what is asserted is that the URL exists and answers.
         */
        $this->openTheGate();
        Mail::fake();

        foreach (array_diff($this->supported(), [(string) config('app.default_locale')]) as $locale) {
            $this->post('/'.$locale.'/contact', $this->payload())->assertOk();
        }

        Mail::assertQueued(ContactMessage::class, count($this->supported()) - 1);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Put the deployment into the one state that can actually deliver mail: a genuinely
     * sending transport AND a from-address that is not the framework's placeholder.
     */
    protected function openTheGate(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'noreply@uptizm.test',
            'services.turnstile.secret_key' => null,
        ]);
    }

    /**
     * A submission that passes every layer, so an override is the only thing under test.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'message' => self::VALID_MESSAGE,
            SendContactMessageController::HONEYPOT_FIELD => '',
            SendContactMessageController::TIMESTAMP_FIELD => $this->formToken(10),
            ...$overrides,
        ];
    }

    /**
     * The encrypted render timestamp the form ships, aged by the given number of seconds.
     */
    protected function formToken(int $ageInSeconds): string
    {
        return Crypt::encryptString((string) now()->subSeconds($ageInSeconds)->timestamp);
    }

    /**
     * A POST request as the limiter callback receives it.
     */
    protected function submissionFrom(string $ip, string $email): Request
    {
        return Request::create('/contact', 'POST', ['email' => $email], [], [], ['REMOTE_ADDR' => $ip]);
    }

    /**
     * The languages the whole product speaks, from the config the routes are built from.
     *
     * @return list<string>
     */
    protected function supported(): array
    {
        return array_values((array) config('magic-starter.supported_locales', []));
    }

    /**
     * The resolved middleware class list for the route that answers a path and method.
     *
     * @return list<string>
     */
    protected function middlewareFor(string $path, string $method): array
    {
        $middleware = app('router')->gatherRouteMiddleware($this->routeFor($path, $method));

        foreach ($middleware as $entry) {
            // A closure middleware would sail through every substring assertion above
            // without being readable at all, so fail on it here instead.
            $this->assertIsString($entry, "{$method} {$path} carries a middleware that is not a class name.");
        }

        return array_values($middleware);
    }

    /**
     * The route that actually answers a request, matched rather than looked up by name, so
     * a route shadowed by another cannot pass.
     */
    protected function routeFor(string $path, string $method): Route
    {
        return app('router')->getRoutes()->match(Request::create($path, $method));
    }
}
