<?php

namespace App\Mail;

use App\Jobs\AnnounceIncident;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The announcement of a newly opened incident, addressed to ONE confirmed
 * subscriber of a status page that publishes an affected component.
 *
 * One instance per recipient, queued by {@see AnnounceIncident}. The
 * subscriber is a constructor argument rather than a collection precisely so the
 * fan-out cannot address a batch: nothing here can name a second subscriber, so
 * no recipient ever learns who else follows the page.
 *
 * What a stranger reads is deliberately bounded: the incident's title, its
 * customer-facing impact, when it started, the affected component names as the
 * PAGE publishes them, and the unsubscribe link. Nothing that only the owning
 * team should see reaches the view: not the severity (an internal triage grade),
 * not the monitor targets, not the ids, not the timeline notes, which carry an
 * `is_public` flag this mail has no way to honour per-note.
 *
 * IMPACT, NOT SEVERITY, is the field shown. They are different judgements and
 * only one of them is addressed to a customer: severity is what the operator
 * graded the failure, impact is what the page tells the public.
 */
class IncidentAnnounced extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $componentNames  Affected components as the
     *                                              page publishes them, resolved
     *                                              once by the announcing job so
     *                                              the fan-out does not reload
     *                                              the pivot per recipient.
     */
    public function __construct(
        public StatusPage $page,
        public Incident $incident,
        public StatusPageSubscriber $subscriber,
        public array $componentNames = [],
    ) {}

    /**
     * The message envelope. The page name carries the identity: a subject naming
     * only the incident would be unrecognisable in a stranger's inbox, and it is
     * the same identification {@see ScheduledMaintenanceAnnounced} uses.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('status.emails.incident.subject', ['page' => $this->page->name]),
        );
    }

    /**
     * The message content: the incident as the public sees it, plus the one-click
     * unsubscribe link.
     */
    public function content(): Content
    {
        return new Content(
            view: 'status.emails.incident',
            with: [
                'pageName' => $this->page->name,
                'title' => $this->incident->title,
                'impactLabel' => __('status.emails.incident.impact.'.$this->incident->impact->value),
                'startedAt' => $this->formatBound($this->incident->started_at),
                'componentNames' => $this->componentNames,
                // Composed from configuration, not from the request, for the
                // reason StatusPageSubscribeConfirmation spells out: this link
                // outlives the request inside somebody's inbox.
                'unsubscribeUrl' => rtrim((string) config('app.url'), '/').route('status.unsubscribe', [
                    'token' => $this->subscriber->unsubscribe_token,
                ], absolute: false),
            ],
        );
    }

    /**
     * Render the start time for a reader whose timezone this system does not
     * know. Neither the page nor the subscriber carries one, so it is shown in
     * UTC and LABELLED as UTC rather than silently rendered in the server's
     * zone, which would read as local time to everyone.
     */
    protected function formatBound(CarbonInterface $at): string
    {
        return $at->setTimezone('UTC')->format('j M Y, H:i').' UTC';
    }
}
