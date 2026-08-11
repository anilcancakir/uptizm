<?php

namespace App\Mail;

use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Double opt-in confirmation for a status-page subscription.
 *
 * The mail carries the single-use confirm link and is sent ONLY to the address
 * the visitor entered, so a subscribe can never be used to spray mail at a
 * third party. The confirm URL is derived from the subscriber's
 * `confirmed_token` and the page slug via `route()`, never from the incoming
 * request, so the link is stable regardless of the host that triggered it.
 */
class StatusPageSubscribeConfirmation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public StatusPage $page,
        public StatusPageSubscriber $subscriber,
    ) {}

    /**
     * The message envelope.
     *
     * The subject resolves from the catalogue like the body does, and it has to:
     * the send path sets the page's locale, so a PHP-composed literal here would
     * put an English subject over a Turkish body. `envelope()` runs inside
     * `Mailable::send()`, which wraps the render in `withLocale($this->locale)`, so
     * an ambient `__()` here is already the right language.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('status.emails.confirm.subject', ['page' => $this->page->name]),
        );
    }

    /**
     * The message content: the confirm link the recipient must click to
     * activate their subscription.
     */
    public function content(): Content
    {
        return new Content(
            view: 'status.emails.confirm',
            with: [
                'pageName' => $this->page->name,
                // Composed from configuration, not from the request. This mail is
                // rendered INLINE during the subscribe POST (`Mail::to()->send()`), so
                // `route()` would resolve it against whatever host that request arrived
                // on, and the link then outlives the request inside somebody's inbox. The
                // same idiom put the API host into the status-page address the editor
                // showed operators.
                //
                // The PATH form specifically, on the configured host: the confirm route
                // is registered only as `/s/{slug}/subscribe/confirm/{token}` and has no
                // subdomain form, so this must not follow the page's `domain_mode` the way
                // `StatusPage::publicUrl()` does.
                'confirmUrl' => rtrim((string) config('app.url'), '/').route('status.subscribe.confirm', [
                    'slug' => $this->page->slug,
                    'token' => $this->subscriber->confirmed_token,
                ], absolute: false),
            ],
        );
    }
}
