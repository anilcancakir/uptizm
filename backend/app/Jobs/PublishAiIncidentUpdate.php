<?php

namespace App\Jobs;

use App\Enums\IncidentDraftKind;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Team;
use App\Services\Ai\IncidentAnalysisService;
use App\Services\Ai\IncidentDraftService;
use App\Services\Billing\PlanGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Writes and publishes the status update for an incident on a monitor whose
 * operator has allowed it (`ai_auto_updates`).
 *
 * This is the one path in the product where model output reaches a customer
 * with no human in between, so every rule below is about the size of that.
 *
 * The order is the point and is not an implementation detail: the ANALYSIS runs
 * first and is stored, then the update is drafted FROM it. Drafting first would
 * mean announcing a cause before anything established one, and running the two
 * independently would let the incident page and the status page disagree about
 * the same outage on the same afternoon.
 *
 * It runs at exactly two moments, which is a product decision and not a
 * limitation: when the incident opens, and when it resolves. Those are the two
 * a customer is actually waiting on. Everything between them stays the
 * operator's own words, because a stage change is usually something a person
 * decided and is already writing about.
 *
 * There is NO template fallback here, deliberately, and this is the one place
 * that differs from the app's own Draft button. The client falls back to a
 * localized template because a person is about to read and edit it; here nobody
 * is. A degrade (over budget, provider down, output untrusted) posts NOTHING
 * and logs why. Silence on a status page is a smaller failure than a sentence
 * the operator never approved and no model actually wrote.
 */
class PublishAiIncidentUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The name an autonomous update is signed with.
     *
     * A constant rather than a translation key, matching
     * `IncidentWriteService::SYSTEM_AUTHOR`: a product name is the same word in
     * every language, and `lang/*\/incidents.php` is scanned by the title
     * catalogue parity test, which reads every key there as a title needing a
     * client mirror. It is not a title.
     */
    protected const string AUTHOR = 'Uptizm AI';

    /**
     * One attempt. A retry would re-spend two AI budget units to post a second
     * customer-facing message about a moment that has already passed.
     */
    public int $tries = 1;

    /**
     * Room for two model calls end to end, each of which runs 13 to 21 seconds
     * against the real provider, plus the evidence assembly around them.
     */
    public int $timeout = 180;

    /**
     * @param  string  $incidentId  The incident to write about.
     * @param  string  $stage  The lifecycle this update is posted as.
     */
    public function __construct(
        public string $incidentId,
        public string $stage,
    ) {}

    public function handle(
        IncidentAnalysisService $analysisService,
        IncidentDraftService $draftService,
    ): void {
        $incident = Incident::query()
            ->with('primaryMonitor')
            ->find($this->incidentId);

        // 1. Everything that makes this unsafe or pointless, before any spend.
        if ($incident === null || ! $this->isAutonomous($incident)) {
            return;
        }

        if ($this->alreadyPosted($incident)) {
            return;
        }

        // 2. The analysis first, and its result is deliberately discarded here:
        //    what matters is that it is STORED, because the draft service reads
        //    the stored row and hands it to the model as the settled cause.
        //    Asking for it here also means the operator opening the incident a
        //    minute later finds it already waiting rather than paying for it.
        $analysisService->storedAnalysisFor($incident);

        // 3. Draft the customer-facing sentence for the stage this update is
        //    posted as, in the team's own language.
        $result = $draftService->draftFor(
            $incident,
            IncidentDraftKind::Update,
            $this->locale(),
            $this->stage,
        );

        if ($result->draft === null) {
            // Nothing is posted and that is the correct outcome, so this is a
            // warning rather than an error: the system declined to speak for the
            // operator because it had nothing trustworthy to say.
            Log::warning('Autonomous status update not posted: no draft was produced.', [
                'incident_id' => $this->incidentId,
                'stage' => $this->stage,
                'degrade_reason' => $result->degradeReason?->value,
            ]);

            return;
        }

        // 4. Post it publicly, marked as what it is.
        $incident->updates()->create([
            'actor' => 'ai',
            'author' => self::AUTHOR,
            'status' => $this->stage,
            'message' => $result->draft,
            'is_public' => true,
            'autonomous' => true,
            'display_at' => now(),
        ]);
    }

    /**
     * Whether this incident's monitor is allowed to speak for its operator.
     *
     * `ai_auto_updates` and NOT `ai_mode`, which is the correction that split
     * this out. Riding on `ai_mode = auto` forced an operator who only wanted
     * their outages narrated to also accept autonomous incident creation, and it
     * withheld narration from the most common incident there is: the one a
     * threshold opened, on a monitor with no anomaly detection at all. The two
     * are different consents and cross freely now.
     *
     * Re-read at FIRE time rather than trusted from dispatch time, because the
     * two are minutes apart and this is consent: switching it off has to stop
     * the next post, not the one after it.
     */
    protected function isAutonomous(Incident $incident): bool
    {
        $monitor = $incident->primaryMonitor;

        if ($monitor === null || ! $monitor->ai_auto_updates) {
            return false;
        }

        $team = Team::find($incident->team_id);

        // The same tier that gates auto-opening gates auto-speaking. A team that
        // downgraded keeps its incidents and loses the autonomy.
        return $team !== null && (new PlanGate)->aiLevelAllows($team, 'auto');
    }

    /**
     * Whether an autonomous update for this stage is already on the timeline.
     *
     * The guard is per STAGE rather than per incident, because the same incident
     * legitimately gets one at open and one at resolve. It exists because a
     * requeue, a reopen, or a second recovery in the same minute would otherwise
     * put two machine-written messages about one moment in front of customers.
     */
    protected function alreadyPosted(Incident $incident): bool
    {
        return IncidentUpdate::query()
            ->where('incident_id', $incident->getKey())
            ->where('autonomous', true)
            ->where('actor', 'ai')
            ->where('status', $this->stage)
            ->exists();
    }

    /**
     * The language the update is written in.
     *
     * The team's own locale, not the request's: nobody is making this request.
     * A status page reader gets the page's language through the translation
     * fan-out that every other public update already goes through.
     */
    protected function locale(): string
    {
        return (string) config('app.default_locale', config('app.locale'));
    }
}
