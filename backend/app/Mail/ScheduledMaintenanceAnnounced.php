<?php

namespace App\Mail;

use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The announcement of a planned maintenance window, addressed to ONE confirmed
 * subscriber of the status page the window belongs to.
 *
 * One instance per recipient, queued by {@see AnnounceScheduledMaintenance}. The
 * subscriber is a constructor argument rather than a collection precisely so the
 * fan-out cannot address a batch: nothing here can name a second subscriber, so
 * no recipient ever learns who else follows the page.
 *
 * What a stranger reads is deliberately bounded: the window's public title, its
 * description, its bounds, the affected component names, and the unsubscribe
 * link. Nothing that only the owning team should see (`suppress_alerts`,
 * `announced_at`, ids, monitor targets) reaches the view.
 */
class ScheduledMaintenanceAnnounced extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $componentNames  Affected components, resolved
     *                                              once by the announcing job so
     *                                              the fan-out does not reload
     *                                              the pivot per recipient.
     */
    public function __construct(
        public StatusPage $page,
        public ScheduledMaintenance $maintenance,
        public StatusPageSubscriber $subscriber,
        public array $componentNames = [],
    ) {}

    /**
     * The message envelope. The page name carries the identity: a subject naming
     * only the window would be unrecognisable in a stranger's inbox, and it is
     * the same identification {@see StatusPageSubscribeConfirmation} uses.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('status.emails.maintenance.subject', ['page' => $this->page->name]),
        );
    }

    /**
     * The message content: the window as the public sees it, plus the one-click
     * unsubscribe link.
     */
    public function content(): Content
    {
        return new Content(
            view: 'status.emails.maintenance',
            with: [
                'pageName' => $this->page->name,
                'title' => $this->maintenance->title,
                'description' => $this->maintenance->description,
                'startsAt' => $this->formatBound($this->maintenance->starts_at),
                'endsAt' => $this->formatBound($this->maintenance->ends_at),
                'componentNames' => $this->componentNames,
                // Composed from configuration, not from the request, for the
                // reason StatusPageSubscribeConfirmation spells out: this link
                // outlives the request inside somebody's inbox, so `route()`
                // would pin it to whatever host the operator's create call
                // happened to arrive on. The PATH form on the configured host:
                // the unsubscribe route is token-addressed and carries no slug,
                // so it has no per-page or subdomain form to follow.
                'unsubscribeUrl' => rtrim((string) config('app.url'), '/').route('status.unsubscribe', [
                    'token' => $this->subscriber->unsubscribe_token,
                ], absolute: false),
            ],
        );
    }

    /**
     * Render a window bound for a reader whose timezone this system does not
     * know. Neither the page nor the subscriber carries one, so the bound is
     * shown in UTC and LABELLED as UTC rather than silently rendered in the
     * server's zone, which would read as local time to everyone.
     */
    protected function formatBound(CarbonInterface $at): string
    {
        return $at->setTimezone('UTC')->format('j M Y, H:i').' UTC';
    }
}
