<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * One progress tick from an asynchronous monitor analyze run, pushed to the
 * owning team's private channel so the monitor form can advance its analyze
 * steps while the worker is still running.
 *
 * Dispatched once per step by the analyze job, which owns the monotonic
 * sequence counter for a run. The client listens for `analyze.progress` and
 * reconciles against `GET /api/v1/monitors/analyze/{run}`, which stays the
 * source of truth: a tick only lets the UI advance early. Nothing here is
 * load-bearing for correctness of the run itself.
 *
 * THE THREE INTERFACES ARE EACH THE OPPOSITE OF THEIR OBVIOUS READING, and all
 * three were read wrongly once while this was designed. The reasoning lives
 * here because the next reader's intuition will be wrong about all three.
 *
 * 1. `ShouldBroadcast`, NOT `ShouldBroadcastNow`. "Now" is the INLINE one:
 *    `BroadcastManager::queue()` answers a `ShouldBroadcastNow` event with
 *    `dispatchNow(new BroadcastEvent(...))`
 *    (vendor/laravel/framework/src/Illuminate/Broadcasting/BroadcastManager.php:190-200),
 *    running the Reverb HTTP POST inside the worker that dispatched it. That
 *    worker is the 150-second analyze itself, so a Reverb outage or a 413 over
 *    Reverb's inbound ceiling would fail the very job this event reports on.
 *    Plain `ShouldBroadcast` takes the `:202-236` branch, which pushes a
 *    separate `BroadcastEvent` job and leaves the analyze untouched.
 *
 * 2. `ShouldRescue` covers the ENQUEUE ONLY, never the broadcast. `:234-236`
 *    wraps `$push`, and `$push` is `->pushOn($queue, $broadcastEvent)`
 *    (`:225-232`), so what it swallows is a Redis push failure: a Redis blip
 *    cannot kill an analyze mid-run over a progress tick nothing depends on. It
 *    does NOT protect the Reverb call, which happens later inside
 *    `BroadcastEvent::handle()`, a class carrying no rescue and no
 *    `ShouldRescue` handling at all. That is the behaviour we want, as two
 *    independent protections: this interface keeps a push failure out of the
 *    analyze, and the separate job absorbs a Reverb outage or a 413 on its own
 *    queue under its own retry. Do not document or extend this as protecting
 *    the broadcast; it does not, and a lost tick is covered by the client's
 *    poll instead.
 *
 * 3. `ShouldDispatchAfterCommit` DEGRADES, it does not skip. `Dispatcher.php:299-307`
 *    defers to a commit callback only when a transaction manager resolves, and
 *    `DatabaseTransactionsManager::addCallback()` (`:205-212`) invokes the
 *    callback IMMEDIATELY when no transaction is open. So a tick broadcast from
 *    the analyze worker, which holds no transaction, still fires at once. It is
 *    here for parity with the two neighbouring events and so that a dispatch
 *    that ever does sit inside a transaction cannot announce a state a rollback
 *    undid. Reading it as "no transaction, no event" is what would make someone
 *    delete it and lose that.
 *
 * NO BROADCAST QUEUE IS PINNED HERE, and that is a decision rather than an
 * omission: `BroadcastManager` reads `broadcastQueue()`, `$broadcastQueue` and
 * `$queue` off the event at `:202-207`, and this class deliberately declares
 * none of the three. Nothing in this app pins one, so both existing events ride
 * `default`, which supervisor-1 drains (config/horizon.php:221). A NEW queue
 * name would be drained by nobody, no tick would ever arrive, and the client's
 * poll would advance the UI anyway and hide it. Pinning them onto the `analyze`
 * queue instead would serialise every tick behind the 150-second job on
 * `maxProcesses` 2 and deliver all of them at the end. The analyze JOB is
 * isolated because it runs for minutes; a `BroadcastEvent` is one
 * millisecond-scale HTTP POST and belongs on the shared queue beside the two
 * events already there.
 *
 * `SerializesModels` is deliberately ABSENT, unlike both neighbouring events:
 * this event carries plain scalars, there is no `AnalyzeRun` model and there
 * will not be one (a run lives in a cache-backed store), so the trait's
 * model-to-identifier round trip would have nothing to do and would only
 * suggest to a reader that a model is in here somewhere.
 * `InteractsWithSockets` stays: `BroadcastEvent` reads a `socket` property off
 * every event it serialises, and the trait is what declares it. The worker has
 * no request socket, so it stays null and no teammate is ever excluded, which
 * is what we want on a team-wide channel.
 */
class AnalyzeProgressBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use InteractsWithSockets;

    /**
     * The step is running now; the client renders a spinner on that row.
     */
    public const string STATE_RUNNING = 'running';

    /**
     * The step ran and finished.
     */
    public const string STATE_DONE = 'done';

    /**
     * The step genuinely did not run, and never will for this run. Load-bearing
     * rather than decorative: the research turn only happens when a credential
     * was supplied, and metric discovery degrades to an empty list, so at least
     * one step routinely does not happen. Collapsing this into `done` or leaving
     * such a step `running` hangs the form on work nothing was going to do.
     */
    public const string STATE_SKIPPED = 'skipped';

    /**
     * The step failed, which for this pipeline is also the end of the run.
     */
    public const string STATE_FAILED = 'failed';

    /**
     * Every per-step state a tick may carry, for a caller that wants to check
     * one rather than trust a string literal.
     *
     * @var list<string>
     */
    public const array STATES = [
        self::STATE_RUNNING,
        self::STATE_DONE,
        self::STATE_SKIPPED,
        self::STATE_FAILED,
    ];

    /**
     * Create a progress tick.
     *
     * Every argument is a plain scalar. Nothing from the request side (the url,
     * the operator credential, the probe body, the analysis result) has a
     * parameter here to travel in, which is the point: absence is readable in
     * the signature rather than asserted in a comment.
     *
     * @param  string  $teamId  The owning team. Addresses the channel; never enters the payload, where it would be redundant on a per-team channel.
     * @param  string  $runId  The run this tick belongs to. The channel is team-wide, so the client MUST ignore a tick for a run it did not start.
     * @param  int  $sequence  Monotonic within one run, owned by the dispatching job. See {@see self::broadcastWith()} for why it cannot be dropped.
     * @param  int  $step  The analyze step ordinal this tick reports on. A contract with the client's step list; the two must agree on the count.
     * @param  string  $state  That step's state, one of {@see self::STATES}.
     * @param  string  $status  The run's own status, as a RAW string: `queued`, `analyzing`, `completed` or `failed`. Deliberately not the `AnalyzeRunStatus` enum type, because that enum is authored in a sibling step of the same wave and this class must not depend on it. Callers pass `$status->value` once it exists; the enum stays the single owner of the vocabulary, and repeating it as constants here would create a second one.
     */
    public function __construct(
        public readonly string $teamId,
        public readonly string $runId,
        public readonly int $sequence,
        public readonly int $step,
        public readonly string $state,
        public readonly string $status,
    ) {}

    /**
     * The channels the event should broadcast on.
     *
     * The existing per-team private channel, authorised by team membership at
     * routes/channels.php:22. There is no per-run channel: what a teammate
     * learns from a tick is that somebody on the team is running an analyze,
     * which is a team-visible fact, and a per-run channel would be machinery
     * with an authorisation surface of its own for no gain.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('teams.'.$this->teamId),
        ];
    }

    /**
     * The wire name the client subscribes to for this event.
     */
    public function broadcastAs(): string
    {
        return 'analyze.progress';
    }

    /**
     * The wire payload: five bounded scalars, and nothing else.
     *
     * Reverb refuses an inbound request whose raw buffer passes 10,000 bytes
     * (vendor/laravel/reverb/config/reverb.php:39 `max_request_size`,
     * unoverridden here, enforced in
     * vendor/laravel/reverb/src/Servers/Reverb/Http/Request.php:25-27). An
     * analysis result is far larger than that, so THE RESULT CANNOT TRAVEL ON
     * THIS CHANNEL; it is read over the authorised
     * `GET /api/v1/monitors/analyze/{run}` instead. The url stays out for a
     * second, independent reason: the channel is team-wide, so every teammate
     * receives every tick.
     *
     * `sequence` is not decoration, and both reasons are measured. Production
     * Horizon runs `maxProcesses` 10 on the supervisor that drains the queue
     * these broadcasts ride (`default` in supervisor-1's list at
     * config/horizon.php:221, its production process count at `:349`), and
     * Laravel guarantees
     * delivery order only for SQS FIFO, so a client trusting arrival order
     * would render step 2 after step 3. It also keeps consecutive payloads
     * distinct: the Dart driver drops a byte-identical frame through a
     * 100-entry dedup ring (magic
     * lib/src/broadcasting/drivers/reverb_broadcast_driver.dart:556-561,593-601),
     * so two otherwise-identical ticks would silently arrive as one.
     *
     * @return array{run_id: string, sequence: int, step: int, state: string, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'sequence' => $this->sequence,
            'step' => $this->step,
            'state' => $this->state,
            'status' => $this->status,
        ];
    }
}
