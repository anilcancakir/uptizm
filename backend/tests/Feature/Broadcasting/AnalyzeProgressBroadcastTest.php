<?php

namespace Tests\Feature\Broadcasting;

use App\Events\AnalyzeProgressBroadcast;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the contract of one analyze progress tick: the existing private
 * per-team channel, the wire name, the exact five-key payload, and the three
 * interface choices whose semantics are each the opposite of their obvious
 * reading.
 *
 * There is no RefreshDatabase and no factory here, and that is a property
 * rather than an oversight: the event takes plain scalars, so nothing about it
 * needs a row to exist. The day this test needs a model is the day something
 * that cannot cross a broadcast payload has been given a way in.
 */
class AnalyzeProgressBroadcastTest extends TestCase
{
    /**
     * Reverb refuses an inbound request whose raw buffer passes this
     * (vendor/laravel/reverb/config/reverb.php:39 `max_request_size`,
     * unoverridden here). Asserted against a literal rather than read from
     * config, because the ceiling is the reason the payload is shaped this way
     * and a config that drifted must fail this test, not silently pass it.
     */
    private const int REVERB_MAX_REQUEST_BYTES = 10_000;

    public function test_event_implements_the_three_broadcast_contracts(): void
    {
        $event = $this->tick();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldRescue::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);

        // THIS is the assertion that does the work, and the three above cannot
        // replace it: ShouldBroadcastNow EXTENDS ShouldBroadcast
        // (Illuminate/Contracts/Broadcasting/ShouldBroadcastNow.php:5), so
        // swapping the interface keeps every assertInstanceOf above green while
        // BroadcastManager::queue() (:190-200) starts running the Reverb HTTP
        // POST inline in the 150-second analyze worker, letting a Reverb outage
        // fail the very job this event reports on.
        $this->assertNotInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_no_broadcast_queue_is_pinned(): void
    {
        $event = $this->tick();

        // The exact three things BroadcastManager::queue() reads to choose a
        // queue (BroadcastManager.php:202-207). Declaring any of them is a
        // regression in both directions: a NEW name is drained by no supervisor
        // and no tick ever arrives, while the `analyze` queue from the job's own
        // supervisor serialises every tick behind the 150-second job on
        // maxProcesses 2 and delivers all of them at the end. Unpinned means
        // `default`, which supervisor-1 drains (config/horizon.php:221).
        $this->assertFalse(method_exists($event, 'broadcastQueue'));
        $this->assertFalse(property_exists($event, 'broadcastQueue'));
        $this->assertFalse(property_exists($event, 'queue'));
    }

    public function test_the_broadcast_is_enqueued_onto_the_shared_default_queue(): void
    {
        Queue::fake();

        // Through the real chooser rather than around it: this is
        // BroadcastManager::queue() itself deciding, so it also proves the event
        // takes the `:202-236` ENQUEUE branch. Under ShouldBroadcastNow the
        // manager would dispatchNow instead and nothing would be pushed at all.
        Broadcast::queue($this->tick());

        Queue::assertPushed(
            BroadcastEvent::class,
            // A null queue means "the connection's own default", which is
            // `default`, the queue supervisor-1 drains alongside the two events
            // already broadcasting there. Any string here is a pinned queue.
            fn (BroadcastEvent $job, ?string $queue): bool => $queue === null,
        );
    }

    public function test_broadcasts_on_the_private_team_channel(): void
    {
        $teamId = (string) Str::uuid();

        $channels = $this->tick(teamId: $teamId)->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);

        // The existing channel authorised by team membership at
        // routes/channels.php:22. A per-run channel is explicitly out of scope.
        $this->assertSame("private-teams.{$teamId}", $channels[0]->name);
    }

    public function test_broadcasts_as_analyze_progress(): void
    {
        $this->assertSame('analyze.progress', $this->tick()->broadcastAs());
    }

    public function test_payload_carries_exactly_the_five_progress_keys(): void
    {
        $runId = (string) Str::uuid();

        $payload = $this->tick(
            runId: $runId,
            sequence: 3,
            step: 2,
            state: AnalyzeProgressBroadcast::STATE_RUNNING,
            status: 'analyzing',
        )->broadcastWith();

        // The key set is asserted whole, not key by key: "and NOTHING ELSE" is
        // half the contract, and a per-key assertion would pass while a sixth
        // key carried the result onto a team-wide channel.
        $this->assertSame(
            ['run_id', 'sequence', 'step', 'state', 'status'],
            array_keys($payload),
        );

        $this->assertSame($runId, $payload['run_id']);
        $this->assertSame(3, $payload['sequence']);
        $this->assertSame(2, $payload['step']);
        $this->assertSame('running', $payload['state']);
        $this->assertSame('analyzing', $payload['status']);
    }

    public function test_run_status_travels_as_a_raw_string(): void
    {
        // The four run statuses, spelled out rather than taken from
        // AnalyzeRunStatus: that enum is authored in a sibling step of the same
        // wave, and this contract must hold without it. Once it exists the
        // caller passes $status->value, which is what these literals are.
        foreach (['queued', 'analyzing', 'completed', 'failed'] as $status) {
            $this->assertSame($status, $this->tick(status: $status)->broadcastWith()['status']);
        }
    }

    public function test_the_run_status_vocabulary_matches_the_enum_that_owns_it(): void
    {
        // Resolved by string and guarded, not imported: the enum is authored by
        // a sibling step of this same wave, and the event deliberately does not
        // depend on it. The skip is the repo's own precedent for a cross-step
        // assertion that un-skips itself the moment the other class lands
        // (PreviewQueueConfigTest::test_the_pinned_job_timeout_matches_the_render_job).
        $enum = 'App\Enums\AnalyzeRunStatus';

        if (! enum_exists($enum)) {
            $this->markTestSkipped("{$enum} does not exist yet; it owns this vocabulary and lands in its own step.");
        }

        // Compared as a set rather than a list: declaration order is nobody's
        // contract, the four wire values are. A fifth case, or a renamed one,
        // would otherwise be invisible until a live run, because nothing decodes
        // this payload against the enum.
        $this->assertEqualsCanonicalizing(
            ['queued', 'analyzing', 'completed', 'failed'],
            array_column($enum::cases(), 'value'),
        );
    }

    public function test_sequence_increases_across_two_ticks(): void
    {
        $runId = (string) Str::uuid();

        $first = $this->tick(runId: $runId, sequence: 1, step: 1)->broadcastWith();
        $second = $this->tick(runId: $runId, sequence: 2, step: 1)->broadcastWith();

        // Production Horizon runs maxProcesses 10 on the supervisor draining the
        // queue these ride (config/horizon.php:221 and :349), and Laravel
        // guarantees delivery order only for SQS
        // FIFO, so the client orders by this rather than by arrival. Presence is
        // asserted before the comparison so dropping the field fails here with
        // its own name rather than erroring on an undefined key.
        $this->assertArrayHasKey('sequence', $first);
        $this->assertArrayHasKey('sequence', $second);
        $this->assertGreaterThan($first['sequence'], $second['sequence']);

        // And the same field is what keeps two ticks for one step distinct on
        // the wire: the Dart driver silently drops a byte-identical frame
        // through a 100-entry dedup ring
        // (reverb_broadcast_driver.dart:556-561,593-601), so two ticks that
        // encoded identically would arrive as one.
        $this->assertNotSame(json_encode($first), json_encode($second));
    }

    public function test_a_step_that_never_runs_is_representable_as_skipped(): void
    {
        // Not decoration: the research turn only runs when a credential was
        // supplied and metric discovery degrades to an empty list, so at least
        // one step routinely does not happen. Without a terminal state for it,
        // that step stays a spinner and hangs the form on work nothing was ever
        // going to do.
        $this->assertSame('skipped', AnalyzeProgressBroadcast::STATE_SKIPPED);
        $this->assertContains('skipped', AnalyzeProgressBroadcast::STATES);

        $payload = $this->tick(state: AnalyzeProgressBroadcast::STATE_SKIPPED)->broadcastWith();

        $this->assertSame('skipped', $payload['state']);
    }

    public function test_every_per_step_state_survives_into_the_payload(): void
    {
        foreach (AnalyzeProgressBroadcast::STATES as $state) {
            $this->assertSame($state, $this->tick(state: $state)->broadcastWith()['state']);
        }

        $this->assertSame(
            ['running', 'done', 'skipped', 'failed'],
            AnalyzeProgressBroadcast::STATES,
        );
    }

    public function test_encoded_payload_carries_no_url_and_no_result(): void
    {
        $encoded = json_encode($this->tick()->broadcastWith());

        // The result cannot travel here at all: it is far past Reverb's inbound
        // ceiling, so it is read over the authorised
        // GET /api/v1/monitors/analyze/{run} instead. The url is out for a
        // second reason, that the channel is team-wide.
        $decoded = json_decode($encoded, true);

        $this->assertArrayNotHasKey('url', $decoded);
        $this->assertArrayNotHasKey('result', $decoded);
        $this->assertArrayNotHasKey('probe', $decoded);
        $this->assertArrayNotHasKey('suggested_metrics', $decoded);
        $this->assertArrayNotHasKey('content', $decoded);
        $this->assertArrayNotHasKey('auth_config', $decoded);

        // A key rename would slip past the assertions above, so also assert on
        // the bytes: nothing that looks like a target, a body, or a suggestion
        // may appear anywhere in the frame.
        $this->assertStringNotContainsString('http', $encoded);
        $this->assertStringNotContainsString('suggested', $encoded);
        $this->assertStringNotContainsString('rationale', $encoded);
    }

    public function test_payload_stays_far_under_the_reverb_inbound_ceiling(): void
    {
        $encoded = json_encode($this->tick()->broadcastWith());

        $this->assertLessThan(self::REVERB_MAX_REQUEST_BYTES, strlen($encoded));

        // And far under, not merely under: a tick that grew to within an order
        // of magnitude of the ceiling would mean something variable-length got
        // in, which is exactly what a 413 on a Reverb POST looks like later.
        $this->assertLessThan(512, strlen($encoded));
    }

    /**
     * Build one progress tick. Defaults are a mid-run tick; every test overrides
     * only the field it is about.
     */
    protected function tick(
        ?string $teamId = null,
        ?string $runId = null,
        int $sequence = 1,
        int $step = 1,
        string $state = AnalyzeProgressBroadcast::STATE_RUNNING,
        string $status = 'analyzing',
    ): AnalyzeProgressBroadcast {
        return new AnalyzeProgressBroadcast(
            teamId: $teamId ?? (string) Str::uuid(),
            runId: $runId ?? (string) Str::uuid(),
            sequence: $sequence,
            step: $step,
            state: $state,
            status: $status,
        );
    }
}
