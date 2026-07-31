{{--
    The Contact page: the contact document, and a form when this deployment can send mail.

    WHY THIS IS ITS OWN VIEW AND NOT `marketing.content-page`

    Every other long-form page renders through `content-page`, which is a document and
    nothing else: it has no slot a form could go in, and this step may not add one to a
    template three other pages already render through. So the reading column, the
    effective-date line, the table of contents and the prose styling below are the same
    shape as `content-page`'s on purpose, and a change to either belongs in both. If a
    second page ever needs a form, that is the moment `content-page` earns a `@yield`
    beneath its body rather than a third copy of this column.

    WHAT THIS PAGE MUST SUPPLY

    `App\Support\Marketing\ChromeData` spread into the view data (the shell dereferences
    its variables unguarded), plus, all from `ShowContactController::viewData()`:

      $title             the document's name, for the <h1> and the browser tab
      $document          `LegalDocument::render()`'s ['html' => ..., 'toc' => [...]]
      $contactEmail      the operator's address, from config/legal.php
      $formEnabled       whether mail is deliverable, so whether a form is offered at all
      $formAction        this page's own path, per language
      $formToken         the encrypted render timestamp, or null when no form is shown
      $timestampField    the name that hidden input must carry
      $honeypotField     the name the honeypot input must carry
      $turnstileSiteKey  the Turnstile site key, or null when Turnstile is unconfigured
      $fieldErrors       a MessageBag, empty on a plain GET
      $submitted         the values to repopulate after a rejection
      $sent              render the acknowledgement instead of the form
      $throttled         the aggregate cap is exhausted, so this render carries a 429

    WHY THERE IS NO @csrf, NO old() AND NO @error HERE

    This route carries no session, by design and as a published claim: see
    routes/marketing.php for the ePrivacy reasoning. So `PreventRequestForgery` cannot be
    on it (on the way out it always mints `XSRF-TOKEN` from the session), `old()` has no
    store to read, and `@error` wants a `ViewErrorBag` that `ShareErrorsFromSession` would
    have put there. The errors and the old values therefore arrive as ORDINARY VIEW DATA,
    named `$fieldErrors` and `$submitted` rather than `$errors`, so nobody reads this page
    as if a session were involved.

    The submitted values are the only visitor input that reaches this page's markup. Every
    one of them is echoed with `{{ }}`. Nothing on this path may ever use `{!! !!}`.
--}}
@extends('marketing.layout')

@section('title', $title.' | '.config('app.name'))

@section('content')
    {{-- The same reading column `content-page` uses: a paragraph set to the full grid
         width runs past the line length anybody reads comfortably. --}}
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:py-16">
        <h1 class="text-section text-fg">{{ $title }}</h1>

        {{-- The effective date, or the honest absence of one, exactly as `content-page`
             renders it: `legal.effective_date` is null on this deployment and
             config/legal.php says in terms that the catalog does not invent one. --}}
        <p class="mt-3 text-label-sm text-fg-muted">
            @if (filled(config('legal.effective_date')))
                {{ __('Effective from :date', ['date' => config('legal.effective_date')]) }}
            @else
                {{ __('No effective date has been published for this document yet.') }}
            @endif
        </p>

        @if ($document['toc'] !== [])
            <nav
                class="mt-8 rounded-lg border border-border bg-surface-container p-4 sm:p-6"
                aria-labelledby="toc-heading"
            >
                <h2 id="toc-heading" class="text-label-sm uppercase tracking-[0.12em] text-fg-muted">
                    {{ __('On this page') }}
                </h2>

                <ul class="mt-3 space-y-2">
                    @foreach ($document['toc'] as $heading)
                        @if ($heading['level'] >= 2 && $heading['level'] <= 3)
                            <li class="{{ $heading['level'] === 3 ? 'ps-4' : '' }}">
                                <a
                                    href="#{{ $heading['slug'] }}"
                                    class="text-body-md text-fg-muted transition-colors hover:text-fg"
                                >{{ $heading['text'] }}</a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </nav>
        @endif

        {{-- The rendered Markdown. `LegalDocument` runs CommonMark with unsafe links
             disabled over admin-curated files in version control, which is what makes the
             unescaped echo safe here; nothing on this path is visitor input. --}}
        <div
            class="mt-10
                [&_p]:mt-4 [&_p]:text-body-lg [&_p]:text-fg-muted
                [&_h2]:mt-10 [&_h2]:text-title-lg [&_h2]:text-fg
                [&_h3]:mt-6 [&_h3]:text-label-md [&_h3]:text-fg
                [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:ps-5 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:ps-5
                [&_li]:mt-2 [&_li]:text-body-lg [&_li]:text-fg-muted
                [&_a]:text-primary [&_a]:underline [&_a]:underline-offset-2
                [&_strong]:font-semibold [&_strong]:text-fg
                [&_code]:rounded-sm [&_code]:bg-surface-container-high [&_code]:px-1 [&_code]:font-mono [&_code]:text-body-md
                [&_blockquote]:mt-4 [&_blockquote]:border-s-2 [&_blockquote]:border-border [&_blockquote]:ps-4
                [&_hr]:my-10 [&_hr]:border-border-subtle
                [&_table]:mt-4 [&_table]:w-full [&_table]:text-start
                [&_th]:border-b [&_th]:border-border [&_th]:py-2 [&_th]:text-start [&_th]:text-label-md [&_th]:text-fg
                [&_td]:border-t [&_td]:border-border-subtle [&_td]:py-2 [&_td]:pe-4 [&_td]:align-top [&_td]:text-body-md [&_td]:text-fg-muted
                [&_details]:mt-3 [&_details]:rounded-md [&_details]:border [&_details]:border-border [&_details]:bg-surface-container [&_details]:p-4
                [&_summary]:cursor-pointer [&_summary]:text-label-md [&_summary]:text-fg"
        >{!! $document['html'] !!}</div>

        @if ($sent)
            {{-- The acknowledgement, and the whole of the submitter's receipt: no mail goes
                 back to them, because an unauthenticated endpoint that mails arbitrary text
                 to an arbitrary address is a spam relay carrying this domain's own DKIM
                 signature. The form is gone from the page, so a second press of the button
                 cannot resend the same message.

                 ACCEPTED, AND NEVER "REACHED US". This wording is load-bearing, not modesty.
                 `SendContactMessageController` QUEUES the send, so at the moment this markup
                 is written the message has reached a queue and nothing else: a stopped
                 Horizon, a recipient Symfony's `Address` refuses, or a dead SMTP host each
                 fail inside a job nobody is watching, minutes after the visitor read this
                 page. `mailDeliverable()` rules out the configuration that guarantees
                 failure, which is a claim about config and never about delivery. So this may
                 only claim what is true when it is written, and it names the operator's
                 address again (the document above names it too) so a visitor who is not
                 satisfied with "accepted" has a channel that needs no queue at all. --}}
            <section
                class="mt-10 rounded-lg border border-up bg-up-soft p-4 sm:p-6"
                role="status"
                aria-live="polite"
            >
                <h2 class="text-title-lg text-up-soft-foreground">{{ __('Your message has been accepted.') }}</h2>

                <p class="mt-2 text-body-md text-up-soft-foreground">
                    {{ __('It is queued for the inbox the operator reads, and the sending happens later.') }}
                </p>

                <p class="mt-2 text-body-md text-up-soft-foreground">
                    {{ __('So nothing here can promise it arrived, and no response time is promised either.') }}
                </p>

                <p class="mt-2 text-body-md text-up-soft-foreground">
                    {{ __('If you hear nothing back, write to :email directly.', ['email' => $contactEmail]) }}
                </p>
            </section>
        @elseif ($formEnabled)
            <section class="mt-10 rounded-lg border border-border bg-surface-container p-4 sm:p-6">
                <h2 class="text-title-lg text-fg">{{ __('Send a message') }}</h2>

                @if ($throttled)
                    {{-- The aggregate cap, refused as THIS PAGE with a 429 rather than as the
                         framework's error page.

                         The whole reason the cap moved out of the route middleware and into
                         `SendContactMessageController` is that the framework's 429 page names
                         no address at all, so the fallback channel disappeared at exactly the
                         moment the form was unavailable. Here the operator's address is on the
                         page, the form is still rendered below, and `$submitted` still holds
                         what the visitor typed, so a retry in a minute is not a retype.

                         `degraded` tokens and not `down`: nothing is broken and no message was
                         lost, the channel is busy for a minute. `role="alert"` because this
                         arrives on a page the visitor did not ask to re-read, exactly like the
                         validation summary below, and it is a separate block from that summary
                         because this failure belongs to no field the visitor filled in. --}}
                    <div
                        class="mt-4 rounded-md border border-degraded bg-degraded-soft p-4"
                        role="alert"
                    >
                        <p class="text-label-md text-degraded-soft-foreground">
                            {{ __('The form is taking a short break.') }}
                        </p>

                        <p class="mt-2 text-body-md text-degraded-soft-foreground">
                            {{ __('More messages arrived this minute than this form accepts, so yours was not sent.') }}
                        </p>

                        <p class="mt-2 text-body-md text-degraded-soft-foreground">
                            {{ __('Your text is still below. Send it again in a minute, or write to us:') }}
                            {{ $contactEmail }}
                        </p>
                    </div>
                @endif

                @if ($fieldErrors->isNotEmpty())
                    {{-- One summary block rather than a message under each field. `role=alert`
                         announces the whole list as soon as the re-rendered page loads, and a
                         single list is also the only place a FORM-level failure (an expired
                         token, a refused challenge) can appear at all: those belong to no
                         input, so a per-field layout would render them nowhere and the
                         visitor would see a rejected form with no stated reason. --}}
                    <div
                        class="mt-4 rounded-md border border-down bg-down-soft p-4"
                        role="alert"
                    >
                        <p class="text-label-md text-down-soft-foreground">{{ __('Your message was not sent.') }}</p>

                        <ul class="mt-2 list-disc space-y-1 ps-5">
                            @foreach ($fieldErrors->all() as $message)
                                <li class="text-body-md text-down-soft-foreground">{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ $formAction }}" class="mt-6 space-y-4">
                    {{-- The encrypted render timestamp. It is what makes "submitted in under
                         three seconds" and "replayed from a page fetched hours ago" both
                         answerable, and it is encrypted rather than plain so a bot cannot
                         mint one; see SendContactMessageController for both age bounds. --}}
                    <input type="hidden" name="{{ $timestampField }}" value="{{ $formToken }}">

                    <div>
                        <label for="contact-name" class="block text-label-md text-fg">{{ __('Your name') }}</label>

                        <input
                            id="contact-name"
                            type="text"
                            name="name"
                            value="{{ $submitted['name'] ?? '' }}"
                            maxlength="100"
                            autocomplete="name"
                            required
                            @if ($fieldErrors->has('name')) aria-invalid="true" @endif
                            class="mt-2 w-full rounded-base border border-border bg-surface-container-high px-3 py-2 text-body-md text-fg outline-none focus-visible:border-primary"
                        >
                    </div>

                    <div>
                        <label for="contact-email" class="block text-label-md text-fg">
                            {{ __('Your email address') }}
                        </label>

                        <input
                            id="contact-email"
                            type="email"
                            name="email"
                            value="{{ $submitted['email'] ?? '' }}"
                            maxlength="254"
                            autocomplete="email"
                            required
                            @if ($fieldErrors->has('email')) aria-invalid="true" @endif
                            class="mt-2 w-full rounded-base border border-border bg-surface-container-high px-3 py-2 text-body-md text-fg outline-none focus-visible:border-primary"
                        >
                    </div>

                    <div>
                        <label for="contact-message" class="block text-label-md text-fg">
                            {{ __('Your message') }}
                        </label>

                        <textarea
                            id="contact-message"
                            name="message"
                            rows="6"
                            maxlength="2000"
                            required
                            @if ($fieldErrors->has('message')) aria-invalid="true" @endif
                            class="mt-2 w-full rounded-base border border-border bg-surface-container-high px-3 py-2 text-body-md text-fg outline-none focus-visible:border-primary"
                        >{{ $submitted['message'] ?? '' }}</textarea>
                    </div>

                    {{-- The honeypot.

                         VISIBLE AND LABELLED, not `display: none`. A hidden trap is hidden
                         from a text browser and a CSS-blocked client too, so a real person
                         fills it in and is refused with no idea why. This one asks in plain
                         language to be left empty, sits outside the tab order
                         (`tabindex="-1"`) and is hidden from assistive technology
                         (`aria-hidden="true"`), so a keyboard or screen-reader visitor never
                         lands on it, while a form-filling bot that submits every input it
                         finds trips over it.

                         The field name is a decoy that browser autofill has nothing to match:
                         `name`, `email`, `url`, `website`, `phone`, `company` and `address`
                         are all autofill targets and would fire on real visitors. --}}
                    <div aria-hidden="true">
                        <label
                            for="contact-{{ $honeypotField }}"
                            class="block text-label-sm text-fg-muted"
                        >{{ __('Leave this field empty') }}</label>

                        <input
                            id="contact-{{ $honeypotField }}"
                            type="text"
                            name="{{ $honeypotField }}"
                            value=""
                            tabindex="-1"
                            autocomplete="off"
                            class="mt-2 w-full rounded-base border border-border-subtle bg-surface-container-high px-3 py-2 text-body-md text-fg-muted outline-none"
                        >
                    </div>

                    @if ($turnstileSiteKey !== null)
                        {{-- Turnstile, and ONLY when a site key is configured: an unconfigured
                             deployment loads no third-party script from this page at all,
                             which is what keeps the zero-cookie claim on this surface simple
                             to state. Turning it on means revisiting the Privacy page's
                             cookie section, because the widget is a Cloudflare script.

                             Cloudflare and not reCAPTCHA: Cloudflare is already a
                             subprocessor here (the regional probe relay), so it adds no new
                             recipient of personal data, and Turnstile is the only anti-abuse
                             layer on this form that resists an attacker who is paying
                             attention. --}}
                        <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>

                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    @endif

                    <button
                        type="submit"
                        class="rounded-md bg-primary px-4 py-2 text-label-md text-on-primary transition-opacity hover:opacity-90"
                    >{{ __('Send') }}</button>
                </form>
            </section>
        @endif
    </div>
@endsection
