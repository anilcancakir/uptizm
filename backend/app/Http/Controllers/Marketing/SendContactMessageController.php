<?php

namespace App\Http\Controllers\Marketing;

use App\Mail\ContactMessage;
use App\Rules\TurnstileRule;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The public contact form's write path: the only unauthenticated write on the marketing
 * site, and the one route on it that anybody would reflexively "fix" into a session.
 *
 * IT RETURNS A VIEW. IT NEVER REDIRECTS.
 *
 * This route carries no session, no cookie and no CSRF middleware, because the Privacy page
 * states without a qualifier that these pages store nothing on the visitor's device and that
 * claim covers this POST (`routes/marketing.php` carries the ePrivacy reasoning in full).
 * `redirect()->withErrors()` on a session-free route is not an error you can see: the
 * Redirector always holds `session.store` when the binding exists, so `withErrors()` flashes
 * into a live in-memory `Store` that nobody ever saves, because `save()` lives inside
 * `StartSession::handleStatefulRequest()`, which never ran. The visitor lands back on an
 * empty form with no message. Worse, `TestResponse::session()` starts a store on demand
 * (`vendor/.../Testing/TestResponse.php:1832`), so `assertRedirect` plus
 * `assertSessionHasErrors` PASS against that broken design and a feature test cannot tell
 * the two apart. So: validate here, and on failure re-render the page with the messages and
 * the submitted values as explicit view data and a 422 status.
 *
 * The accepted cost is that a browser refresh re-submits, since there is no
 * POST/redirect/GET. The two route buckets plus the aggregate cap below bound it, and it
 * matches the trade-off the public subscribe endpoint already accepted
 * (`StatusPage\SubscribeController`).
 *
 * THE FIVE ANTI-ABUSE LAYERS, AND WHY NONE OF THEM IS REDUNDANT
 *
 *   - The honeypot catches a form-filler bot that submits every field it finds. Free, and
 *     invisible to a real visitor.
 *   - The encrypted render timestamp catches a bot that posts without ever fetching the page,
 *     and (via the MAXIMUM age) one that fetched it once and replays the ciphertext forever.
 *   - The `Sec-Fetch-Site` check catches a third-party page driving a real visitor's browser
 *     into this endpoint. It is the session-free stand-in for the CSRF token this route cannot
 *     have, and the framework trusts the same signal (`PreventRequestForgery::hasValidOrigin`).
 *   - Turnstile is the only layer that resists an attacker who is actually paying attention.
 *     Dormant until a secret is configured.
 *   - The limiter is the only layer that bounds a distributed flood, and the only one that
 *     works when the others have been defeated.
 *
 * Removing one of them removes a whole class of protection, not a duplicate of another's.
 *
 * WHY THE AGGREGATE CAP IS ENFORCED HERE AND NOT AS ROUTE MIDDLEWARE
 *
 * `ThrottleRequests::handleRequest()` checks every bucket and then HITS every bucket, before
 * this controller runs and regardless of what it decides. A global bucket on the route was
 * therefore spent by a rejected submission exactly like by an accepted one, which turned the
 * one layer that survives a distributed flood into the lever that closes the site's only
 * contact channel: thirty garbage POSTs a minute, from a third-party page that needs no CSRF
 * token because there is none to steal, and every visitor met the framework's 429 page with
 * the fallback address nowhere on it. So the per-IP and per-email buckets stay on the route
 * (they are meant to bite on garbage) and the aggregate cap is taken HERE, after validation
 * passes, one slot per message actually queued.
 */
class SendContactMessageController
{
    /**
     * The named limiter registered in `bootstrap/app.php` and attached in
     * `routes/marketing.php`. Held here so the route, the registration and the test all
     * name the same string.
     */
    public const LIMITER = 'contact-form';

    /**
     * The aggregate cap's key, and the reason it is a CONSTANT rather than a request value:
     * this form mails the OPERATOR, so an attacker varies the submitted address for free and
     * the per-email bucket protects nothing at all here (it protects the subscribe endpoint,
     * which mails the SUBMITTED address). A fixed key is what caps a distributed flood in
     * aggregate, across every address and every source.
     *
     * Spent through the `RateLimiter` facade in `__invoke()`, so this is the RAW cache key.
     * The route middleware would have hashed it (`md5($limiterName.$limit->key)`), which is
     * why a test counting attempts on this constant cannot see a route bucket being spent and
     * the flood is asserted end to end instead.
     */
    public const GLOBAL_LIMITER_KEY = 'contact-form:global';

    /**
     * The aggregate cap and its window: thirty accepted messages a minute, across every
     * source and every submitted address.
     *
     * Deliberately generous next to the per-IP bucket's five. It exists to stop a thousand
     * hosts, not to make one busy afternoon look like an attack, and it is now spent ONLY by
     * a message that passed every other layer, so the number no longer doubles as a budget
     * that garbage can drain.
     */
    public const GLOBAL_LIMIT_MAX_ATTEMPTS = 30;

    public const GLOBAL_LIMIT_DECAY_SECONDS = 60;

    /**
     * The Fetch Metadata header that stands in for the CSRF token this route cannot have.
     *
     * Its wire name, not ours. A `Sec-` prefixed header is a forbidden header name, so no
     * script can set or overwrite it: only the browser does, which is what makes it usable
     * with no cookie, no session and no token round trip.
     */
    public const FETCH_SITE_HEADER = 'Sec-Fetch-Site';

    /**
     * The `Sec-Fetch-Site` values a real submission of this form can carry.
     *
     * `same-origin` is the form on this page posting to its own relative action.
     * `none` is a user-initiated navigation (a typed address, a bookmark, a restored tab).
     *
     * `same-site` is ABSENT on purpose, matching `PreventRequestForgery`'s own default
     * (`$allowSameSite = false`): a status page on a `{slug}.uptizm.com` subdomain is
     * same-site and has no business posting to this endpoint, and this form's action is
     * always a relative path on the page the visitor is reading.
     *
     * An ABSENT header is not in this list and must still be allowed; see
     * `isCrossSiteSubmission()` for why that is deliberate rather than an oversight.
     *
     * @var list<string>
     */
    public const SAME_ORIGIN_FETCH_SITES = [
        'same-origin',
        'none',
    ];

    /**
     * The `MessageBag` key for a rejection that belongs to no input the visitor typed.
     *
     * The view renders `$fieldErrors->all()` in one `role="alert"` summary precisely so a
     * form-level failure has somewhere to appear; a per-field layout would render it nowhere.
     */
    public const FORM_ERROR_KEY = 'form';

    /**
     * The honeypot field.
     *
     * Named so browser autofill has nothing to match: `name`, `email`, `url`, `website`,
     * `phone`, `company` and `address` are all autofill targets, and a honeypot with one of
     * those names fires on real visitors whose browser helpfully filled it in.
     */
    public const HONEYPOT_FIELD = 'delivery_note';

    /**
     * The encrypted render timestamp the form ships and this controller ages.
     */
    public const TIMESTAMP_FIELD = 'form_rendered_at';

    /**
     * Cloudflare's own field name for the Turnstile token. Its wire name, not ours: the
     * widget script writes it, so it cannot be renamed. It is kept out of every message a
     * visitor reads (see `messages()`).
     */
    public const TURNSTILE_FIELD = 'cf-turnstile-response';

    /**
     * The minimum seconds between rendering the form and submitting it. A human reads a
     * label, thinks, and types; three seconds is under any real fill and over any script's.
     */
    public const MIN_FILL_SECONDS = 3;

    /**
     * The maximum age of a render timestamp, and the half that is easy to leave out.
     *
     * `Encrypter::encrypt()` appends an HMAC that `decrypt()` verifies, so a bot cannot MINT
     * a token. It can fetch the page once and replay the ciphertext it was handed, though,
     * and a minimum-age-only check gets HAPPIER as that ciphertext ages. Two hours is long
     * enough for a real visitor who left the tab open over lunch, and the view-returning
     * design turns the expiry into a re-rendered page with a fresh token rather than the 419
     * a session-backed form would have produced.
     */
    public const MAX_FORM_AGE_SECONDS = 7200;

    /**
     * The transports that genuinely hand a message to something that delivers it.
     *
     * AN ALLOWLIST, AND NEVER A DENYLIST. `config/mail.php` defines `failover` as
     * `['smtp', 'log']`, so a `!== 'log'` check passes `MAIL_MAILER=failover`, renders the
     * form, and silently writes every message a visitor sends into `laravel.log` the moment
     * SMTP is down. A denylist here lies by construction; only an explicit set of sending
     * transports does not.
     *
     * `roundrobin` is absent on purpose even though this repo happens to configure it with
     * two sending members: it is a COMPOSITE whose member list is itself config, so admitting
     * it would reopen the same hole `failover` opens as soon as somebody adds `log` to that
     * list, and verifying it honestly would mean recursing into the members. Nothing here
     * uses it, so the answer is "no" rather than "maybe".
     *
     * @var list<string>
     */
    public const DELIVERABLE_TRANSPORTS = [
        // A real SMTP conversation with a real MTA.
        'smtp',
        // Hands the message to a local MTA binary, which then delivers it.
        'sendmail',
        // Amazon SES, both API generations.
        'ses',
        'ses-v2',
        // Transactional API providers, each with a credential slot in config/services.php.
        'postmark',
        'resend',
    ];

    /**
     * The framework's placeholder From address. `smtp` plus this is "configured" and refused
     * by every receiving MTA, so the transport alone is not a deliverability claim.
     */
    private const PLACEHOLDER_FROM_ADDRESS = 'hello@example.com';

    /**
     * The GET controller is a collaborator, not a duplicate.
     *
     * Every response this class returns is the contact page in a different state (rejected,
     * or sent), and rendering one needs the whole chrome contract, the rendered document and
     * a freshly minted form token. A second copy of that assembly here would drift, and the
     * way it would drift is a Turkish submission answered with an English chrome, or a
     * re-render whose form carries no token.
     */
    public function __construct(
        protected ShowContactController $page,
        protected ValidationFactory $validation,
    ) {}

    /**
     * Accept a contact message, or re-render the page saying why not.
     *
     * @throws NotFoundHttpException When this deployment cannot deliver mail.
     */
    public function __invoke(Request $request): Response
    {
        // 1. No form was rendered, so no write path exists. 404 and not 403 or a friendly
        //    explanation: this codebase masks what a visitor must not learn exists, and a
        //    deployment that cannot deliver must not accept a message it would drop.
        if (! self::mailDeliverable()) {
            abort(404);
        }

        // 2. A cross-site POST is never a visitor using the form on this page, so it is
        //    refused before it can cost anything: no validation, and above all no slot out
        //    of the aggregate budget. 403 mirrors the code the framework gives its own
        //    origin failure (`Handler` maps `OriginMismatchException` to 403), rendered as
        //    THIS PAGE rather than an error page, because a cross-site form POST navigates
        //    the visitor's browser here and they should land on a working form.
        if (self::isCrossSiteSubmission($request)) {
            return $this->page->response([
                'fieldErrors' => new MessageBag([
                    self::FORM_ERROR_KEY => [
                        __('This form only accepts messages sent from this page. Please write your message here.'),
                    ],
                ]),
                // NOT repopulated, unlike every other rejection: a third-party page chose
                // these values, and echoing them would print attacker-authored prose onto
                // our own branded page (escaped, so not an injection, but still their words
                // under our chrome).
            ], 403);
        }

        $validator = $this->validation->make($request->all(), $this->rules(), $this->messages());

        // 3. The timing trap runs as an after-callback rather than a rule, because it reads
        //    two fields' worth of state (the ciphertext and the clock) and fails the FORM
        //    rather than a value the visitor typed.
        $this->guardFormAge($request, $validator);

        // 4. A view and a 422. Never a redirect: see the class docblock for why that is a
        //    silent data loss on this route and why the test suite cannot see it.
        if ($validator->fails()) {
            return $this->page->response([
                'fieldErrors' => $validator->errors(),
                'submitted' => $this->submitted($request),
            ], 422);
        }

        $validated = $validator->validated();

        // 5. The aggregate cap, taken HERE and only now: a message that has passed every
        //    other layer is about to be queued, so this is the one point where spending a
        //    slot means what the slot is for. `tooManyAttempts()` before `hit()` rather than
        //    `attempt()`, because the refusal branch returns a Response and `attempt()`'s
        //    false-or-callback-result contract cannot express that without ambiguity.
        //
        //    The refusal is THE CONTACT PAGE with a 429: the fallback address on it, and the
        //    visitor's own text still in the fields so a retry in a minute is not a retype.
        //    The framework's 429 error page names no address, which would hide the only
        //    remaining channel at exactly the moment the form is unavailable.
        if (RateLimiter::tooManyAttempts(self::GLOBAL_LIMITER_KEY, self::GLOBAL_LIMIT_MAX_ATTEMPTS)) {
            return $this->page->response([
                'throttled' => true,
                'submitted' => $this->submitted($request),
            ], 429);
        }

        RateLimiter::hit(self::GLOBAL_LIMITER_KEY, self::GLOBAL_LIMIT_DECAY_SECONDS);

        // 6. QUEUED, never sent inline. Horizon already runs; an inline send to a dead SMTP
        //    host would hold this unauthenticated request until Octane kills the worker. It
        //    is also why the acknowledgement claims the message was ACCEPTED and never that
        //    it arrived: everything after this line happens in a job nobody is watching.
        //
        //    Addressed through `destinationAddress()` and never `config()` directly, so the
        //    string the gate validated is the string that is sent. Reading the config twice
        //    would let a trailing space pass `FILTER_VALIDATE_EMAIL` in `mailDeliverable()`
        //    (which trims) and then reach `Address` untrimmed inside the job (which does not),
        //    which is the very "fails after the page said accepted" case this step closes.
        Mail::to(self::destinationAddress())->queue(new ContactMessage(
            senderName: (string) $validated['name'],
            senderEmail: (string) $validated['email'],
            body: (string) $validated['message'],
        ));

        return $this->page->response(['sent' => true]);
    }

    /**
     * Whether this submission came from somewhere other than this site.
     *
     * THE SESSION-FREE STAND-IN FOR A CSRF TOKEN, and there can be no token here: this route
     * carries no session (see the class docblock and `routes/marketing.php`), and
     * `PreventRequestForgery` cannot go on a session-free route at all because on the way out
     * it always mints `XSRF-TOKEN` from `$request->session()->token()`. `Sec-Fetch-Site` needs
     * none of that: the browser sets it, a script cannot (a `Sec-` prefixed header is a
     * forbidden header name), and reading a request header stores nothing on the visitor's
     * device. The framework already trusts this exact signal, short-circuiting its own token
     * check on `same-origin` (`PreventRequestForgery::hasValidOrigin()`).
     *
     * AN ABSENT HEADER IS ALLOWED, and that is the load-bearing half. Absent means a
     * non-browser client: curl, a monitoring probe, a browser old enough to predate Fetch
     * Metadata. Refusing those would break every legitimate non-browser caller while stopping
     * no attacker at all, because the attack this check exists for IS a browser and a browser
     * always sends the header.
     *
     * Lower-cased before comparison. The values are fixed lowercase tokens in the spec, so
     * this only ever admits a non-conforming browser or a proxy that rewrote the case; it can
     * never admit a cross-site request, whose value lowercases to `cross-site` either way.
     */
    public static function isCrossSiteSubmission(Request $request): bool
    {
        $fetchSite = $request->header(self::FETCH_SITE_HEADER);

        if (! is_string($fetchSite) || $fetchSite === '') {
            return false;
        }

        return ! in_array(Str::lower(trim($fetchSite)), self::SAME_ORIGIN_FETCH_SITES, true);
    }

    /**
     * Whether this deployment can actually send mail.
     *
     * THIS IS A CLAIM ABOUT CONFIGURATION AND NEVER ABOUT DELIVERY. `smtp` pointed at a dead
     * host passes every check below, and so does a real host that greylists us. What it rules
     * out is the case the gate exists for: a form that accepts a visitor's message and writes
     * it to a log file, or hands it to an MTA under a From address nothing will accept, or
     * sends it to nowhere at all. When this returns false the form is not rendered and the
     * page shows `config('legal.contact_email')` instead, which is a channel that works.
     *
     * All THREE addresses matter, and the destination is the one that was missing. The send is
     * queued, so a blank or malformed `legal.contact_email` fails inside the job (Symfony's
     * `Address` refuses it) AFTER the page has already told the visitor their message was
     * accepted. A gate that verifies where mail comes FROM but not where it goes TO offers a
     * form with nowhere to send.
     *
     * Static because the GET page asks the write path whether it can send, rather than each
     * of them owning a copy of the answer. Mirrors the capability-gate shape at
     * `ShowLandingController::aiEnabled()`: withhold the offer when the deployment cannot
     * back it.
     */
    public static function mailDeliverable(): bool
    {
        $mailer = config('mail.default');

        if (! is_string($mailer) || $mailer === '') {
            return false;
        }

        // The mailer NAME and its transport are allowed to differ (`'sendgrid' =>
        // ['transport' => 'smtp']` is ordinary), so the allowlist is applied to the
        // TRANSPORT. An undefined mailer falls back to its own name, which then fails the
        // allowlist: unknown means closed, never open.
        $transport = config("mail.mailers.{$mailer}.transport", $mailer);

        if (! is_string($transport) || ! in_array($transport, self::DELIVERABLE_TRANSPORTS, true)) {
            return false;
        }

        $from = config('mail.from.address');

        if (! is_string($from) || $from === '' || $from === self::PLACEHOLDER_FROM_ADDRESS) {
            return false;
        }

        // The destination. `FILTER_VALIDATE_EMAIL` and not merely `!== ''`, because a
        // half-edited env value (`info@`, or two addresses in one string) is non-empty and
        // still unsendable, and `Mail::to()` only finds that out inside the queued job.
        return filter_var(self::destinationAddress(), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * The address a contact message is sent to.
     *
     * One reader for one value, so the gate above cannot validate a different string from the
     * one `Mail::to()` is handed. Trimmed, because a trailing space in an env value is
     * invisible in a diff and would otherwise be validated in one place and sent in another.
     * `is_string` rather than a cast: `config()` can hand back an array or null, and neither
     * may become the word "Array" or a warning on a public endpoint.
     */
    protected static function destinationAddress(): string
    {
        $destination = config('legal.contact_email');

        return is_string($destination) ? trim($destination) : '';
    }

    /**
     * The validation rules.
     *
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:254'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            // The honeypot. `prohibited` passes for a missing OR empty value and fails for
            // anything else, which is exactly the trap's contract.
            self::HONEYPOT_FIELD => ['prohibited'],
        ];

        // Dormancy gate: verify Turnstile only when a secret is configured. Without one the
        // rule is absent and the form behaves as if Turnstile did not exist, rather than
        // rejecting every message on a deployment that never signed up for it.
        if (filled(config('services.turnstile.secret_key'))) {
            $rules[self::TURNSTILE_FIELD] = ['required', new TurnstileRule];
        }

        return $rules;
    }

    /**
     * Every message a visitor can be shown, written out in full rather than assembled from
     * `:attribute`.
     *
     * Two reasons this is not left to the framework's defaults. First, there is no
     * `lang/tr/validation.php` in this repo, so `validation.required` on a Turkish page falls
     * back to English in silence: an authored `__()` string is translatable through the same
     * `lang/tr.json` every other string on this surface uses. Second, the default text leaks
     * field names, and `cf-turnstile-response` is a Cloudflare implementation detail that a
     * visitor should never read.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => __('Please tell us your name.'),
            'name.max' => __('That name is longer than we can store.'),
            'email.required' => __('Please give us an email address we can reply to.'),
            'email.email' => __('That does not look like an email address.'),
            'email.max' => __('That email address is longer than we can store.'),
            'message.required' => __('Please write your message.'),
            'message.min' => __('Please write a little more so the operator can help.'),
            'message.max' => __('That message is longer than we can accept. Please shorten it.'),
            // The honeypot is a visible, labelled field (see the view for why it is not
            // `display: none`), so somebody who ignores the label deserves a real sentence.
            self::HONEYPOT_FIELD.'.prohibited' => __('Please leave the last field empty.'),
            self::TURNSTILE_FIELD.'.required' => __('Please complete the verification challenge.'),
        ];
    }

    /**
     * The timing trap: too fast is a bot, too old is a replayed ciphertext.
     */
    protected function guardFormAge(Request $request, Validator $validator): void
    {
        $validator->after(function (Validator $validator) use ($request): void {
            $renderedAt = $this->decryptRenderTimestamp($request);

            // Missing, tampered or encrypted under a different key. Fail closed as a
            // validation error, never as a 500, and never tell the submitter which it was.
            if ($renderedAt === null) {
                $validator->errors()->add(
                    self::TIMESTAMP_FIELD,
                    __('This form is no longer valid. Please send it again.'),
                );

                return;
            }

            $age = now()->timestamp - $renderedAt;

            // A negative age (a token from the future, so a clock change or a forgery under a
            // leaked key) is caught by the same comparison as "too fast".
            if ($age < self::MIN_FILL_SECONDS) {
                $validator->errors()->add(
                    self::TIMESTAMP_FIELD,
                    __('That went too fast. Please send it again.'),
                );

                return;
            }

            if ($age > self::MAX_FORM_AGE_SECONDS) {
                $validator->errors()->add(
                    self::TIMESTAMP_FIELD,
                    __('This form has been open too long. Please send it again.'),
                );
            }
        });
    }

    /**
     * The render timestamp, or null for anything that is not one.
     *
     * The value is attacker-controlled, so a `DecryptException` must not propagate: it would
     * 500 an unauthenticated endpoint on any malformed input at all. It collapses to null and
     * the caller turns that into a validation message.
     */
    protected function decryptRenderTimestamp(Request $request): ?int
    {
        $token = $request->input(self::TIMESTAMP_FIELD);

        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            return (int) Crypt::decryptString($token);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The values to echo back into the re-rendered form.
     *
     * With no session there is no `old()` to read, so a visitor whose email had a typo would
     * retype the whole message unless the page is handed these explicitly. Only the three
     * fields a human filled in: the honeypot is never echoed (a bot does not need help
     * repopulating it) and neither is the form token (the page mints a fresh one).
     *
     * Trimmed and length-capped here rather than at the template, because these strings are
     * the only visitor input that reaches this page's HTML at all; the view escapes them with
     * `{{ }}` and nothing on this path may ever use `{!! !!}`.
     *
     * @return array<string, string>
     */
    protected function submitted(Request $request): array
    {
        return [
            'name' => $this->stringInput($request, 'name', 100),
            'email' => $this->stringInput($request, 'email', 254),
            'message' => $this->stringInput($request, 'message', 2000),
        ];
    }

    /**
     * One submitted field as a bounded string.
     *
     * `is_string` and not a cast, because `name[]=x` arrives as an ARRAY: casting it would
     * raise "Array to string conversion" and echo the word "Array" back at the visitor. The
     * `string` rule already refuses it, so the field is simply not repopulated.
     */
    protected function stringInput(Request $request, string $key, int $limit): string
    {
        $value = $request->input($key);

        return is_string($value) ? Str::limit($value, $limit, '') : '';
    }
}
