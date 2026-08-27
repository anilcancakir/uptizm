<?php

namespace App\Mail;

use App\Jobs\AnnounceMaintenanceCancelled;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The cancellation of a planned maintenance window, addressed to ONE confirmed
 * subscriber of the page it belonged to.
 *
 * One instance per recipient, queued by {@see AnnounceMaintenanceCancelled}.
 * The subscriber is a constructor argument rather than a collection precisely
 * so the fan-out cannot address a batch: nothing here can name a second
 * subscriber, so no recipient ever learns who else follows the page.
 *
 * The window is GONE by the time this renders, so the title and the component
 * names arrive as values rather than through a model. That is not a
 * convenience: there is no row left to read them from.
 *
 * Deliberately does NOT repeat the window's bounds. The mail these readers
 * already hold carries them, and restating a schedule in the message that
 * cancels it invites the reader to diary the wrong thing twice.
 */
class ScheduledMaintenanceCancelled extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $componentNames  Affected components as the
     *                                              page published them.
     */
    public function __construct(
        public StatusPage $page,
        public string $title,
        public StatusPageSubscriber $subscriber,
        public array $componentNames = [],
    ) {}

    /**
     * The message envelope. The page name carries the identity: a subject
     * naming only the window would be unrecognisable in a stranger's inbox.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('status.emails.maintenance_cancelled.subject', ['page' => $this->page->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'status.emails.maintenance_cancelled',
            with: [
                'pageName' => $this->page->name,
                'title' => $this->title,
                'componentNames' => $this->componentNames,
                // Composed from configuration, not from the request: this link
                // outlives the request inside somebody's inbox.
                'unsubscribeUrl' => rtrim((string) config('app.url'), '/').route('status.unsubscribe', [
                    'token' => $this->subscriber->unsubscribe_token,
                ], absolute: false),
            ],
        );
    }
}
