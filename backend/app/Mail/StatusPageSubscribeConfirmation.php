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
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Confirm your subscription to {$this->page->name}",
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
                'confirmUrl' => route('status.subscribe.confirm', [
                    'slug' => $this->page->slug,
                    'token' => $this->subscriber->confirmed_token,
                ]),
            ],
        );
    }
}
