<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message a visitor sent through the public contact form, delivered to the operator.
 *
 * ONE RECIPIENT, AND IT IS THE OPERATOR
 *
 * There is deliberately no acknowledgement to the submitter, echoing the body or otherwise.
 * An unauthenticated endpoint that mails arbitrary text to an arbitrary address is a spam
 * relay, and it would be a relay carrying this domain's own SPF alignment and DKIM
 * signature, which is worse than an open relay somewhere else. The submitter gets a rendered
 * acknowledgement on the page instead, which costs nothing and cannot be abused.
 *
 * Header injection through the name or the address is closed by Symfony Mime, which encodes
 * every header value it writes, plus the `email` validation rule upstream. The subject is a
 * fixed string carrying no submitted input at all, so it cannot be used to forge one either.
 *
 * This mailable is always QUEUED, never sent inline (see `SendContactMessageController`): a
 * synchronous send to an unreachable SMTP host holds the request open until Octane's
 * `max_execution_time` kills the worker, and that is reachable from an unauthenticated
 * endpoint, so it is an availability lever rather than a slow page.
 */
class ContactMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $senderName  As typed by the visitor. Never trusted, always escaped.
     * @param  string  $senderEmail  Validated as an address, used as Reply-To so the
     *                               operator can answer straight from their client.
     * @param  string  $body  The message itself.
     */
    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $body,
    ) {}

    /**
     * The message envelope.
     *
     * The subject is a constant so the operator can filter on it and so no submitted text
     * reaches a header. `From` stays the application's configured address (the
     * deliverability gate already required it to be a real one): sending AS the visitor
     * would fail SPF at the receiving end and would be a forgery besides.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Uptizm contact form',
            replyTo: [
                new Address($this->senderEmail, $this->senderName),
            ],
        );
    }

    /**
     * The message content.
     *
     * A pre-rendered string rather than a Blade view: this is three labelled lines and the
     * escaping is the only logic in it, so a template file would contain nothing but three
     * echoes and one more place for the escaping to be forgotten.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody(),
        );
    }

    /**
     * The rendered body.
     *
     * Every submitted value goes through `e()` before it reaches the markup, and the line
     * breaks are turned into `<br>` only AFTER escaping, so a message containing markup
     * arrives as the text the visitor typed and never as markup in the operator's client.
     */
    protected function htmlBody(): string
    {
        return implode('', [
            '<p><strong>From:</strong> '.e($this->senderName).' &lt;'.e($this->senderEmail).'&gt;</p>',
            '<hr>',
            '<p>'.nl2br(e($this->body)).'</p>',
        ]);
    }
}
