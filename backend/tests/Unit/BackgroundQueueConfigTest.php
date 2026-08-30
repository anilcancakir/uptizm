<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Locks the split that keeps Horizon's idle worker floor off the background queues.
 *
 * Horizon's idle cost is driven by the QUEUE COUNT of a supervisor, not by its
 * `maxProcesses`, and nothing in the config says so. `Supervisor::createProcessPools()`
 * opens one process pool per queue whenever `balance` is `auto` or `simple`, and each
 * pool's floor is `minProcesses`, which Horizon refuses to let go below 1:
 * `ProvisioningPlan::convert()` throws "must be greater than 0" before the autoscaler
 * ever runs. So a supervisor listing eight queues holds eight resident workers with
 * every one of those queues empty, and `maxProcesses` never enters into it.
 *
 * Measured on the production box on 2026-08-30, with all eight queues at zero depth:
 * scheduling 104 MB, checks 108, processing 54, default 118, ai 104, feeds 110,
 * aggregates 42, ssl 34. That is 674 MB resident to drain nothing, on a 8 GB host that
 * was paging continuously.
 *
 * The split this test pins draws the line at latency rather than at volume:
 *
 * 1. The four customer-facing queues keep `balance: auto`, so each still gets a pool
 *    and a worker of its own. They are one pipeline (scheduling fans out to checks,
 *    checks dispatches to processing) plus `default`, and a queue behind another one
 *    here is late outage detection or a page that never went out.
 * 2. The four background queues move to a supervisor with `balance: off`, which builds
 *    a SINGLE pool for the whole list. Its floor is one worker total rather than four,
 *    and Laravel's worker drains the list left to right, so the priority order written
 *    there is finally load bearing instead of decorative.
 *
 * What this test CANNOT see, so it is worth stating plainly: `balance: off` gives up
 * per-queue isolation inside the background set. One worker on a 60-second feed ingest
 * is a worker not polling `ssl`, and the autoscaler only adds a second one once the
 * summed backlog across the four exceeds the running count. That is the trade the
 * split accepts, and it is only acceptable because none of these four is on a path a
 * customer is waiting on.
 */
class BackgroundQueueConfigTest extends TestCase
{
    /** The supervisor that collapses the tolerant queues into one pool. */
    private const SUPERVISOR = 'background';

    /** The supervisor that keeps a pool per queue. */
    private const SHARED_SUPERVISOR = 'supervisor-1';

    /** `off` is the whole point: it builds one pool instead of one per queue. */
    private const BALANCE = 'off';

    /** The shared supervisor must stay on per-queue pools. */
    private const SHARED_BALANCE = 'auto';

    /**
     * The tolerant queues, in the order a single worker drains them.
     *
     * `feeds` sits last for the reason config/horizon.php has always given: a third
     * party's status feed must never be picked up ahead of our own work. Under the old
     * `balance: auto` that ordering did nothing, because every queue had its own
     * dedicated worker and there was no single worker to order anything for.
     */
    private const BACKGROUND_QUEUES = ['ssl', 'aggregates', 'ai', 'feeds'];

    /**
     * The queues that must keep a worker each.
     *
     * `default` is on this list and it is the least obvious member, so: it carries
     * DispatchEscalationStep (the on-call paging path), AnnounceIncident, and the
     * IncidentOpened / IncidentResolved / IncidentEscalated notifications. None of
     * them calls `onQueue()`, so they land on `default` by omission, and every one of
     * them is the product telling a customer their service is down. Behind a 60-second
     * feed ingest, that is a page that arrives a minute late.
     */
    private const CUSTOMER_FACING_QUEUES = ['scheduling', 'checks', 'processing', 'default'];

    /** The connection both supervisors ride; the split must not have changed it. */
    private const CONNECTION = 'redis';

    /** The worker memory ceiling carried over from supervisor-1 unchanged. */
    private const MEMORY = 128;

    /** The worker timeout carried over from supervisor-1 unchanged. */
    private const TIMEOUT = 60;

    /**
     * Every queue that had a consumer before the split still has exactly one.
     *
     * The split moved four queue names between two arrays, and the failure mode of
     * getting that wrong is silent in both directions. A queue dropped from both lists
     * dispatches fine and is consumed by nobody, so the job sits in Redis forever with
     * nothing logged. A queue left in both lists is drained by two supervisors sized
     * for different things, so the same work runs under whichever worker grabs it.
     */
    public function test_every_queue_has_exactly_one_supervisor(): void
    {
        $expected = array_merge(self::CUSTOMER_FACING_QUEUES, self::BACKGROUND_QUEUES);

        foreach ($expected as $queue) {
            $draining = $this->supervisorsDraining($queue);

            $this->assertCount(
                1,
                $draining,
                "The [{$queue}] queue is drained by ".count($draining).' supervisors ('
                .(implode(', ', $draining) ?: 'none').'). Exactly one must list it: none means every '
                .'dispatch queues and never runs, two means the same work runs under a worker sized '
                .'for something else.'
            );
        }
    }

    /**
     * The customer-facing queues keep a pool, and therefore a worker, each.
     *
     * This is the assertion the whole split is worth doing carefully for. Moving any
     * of these four onto the background supervisor would save one more resident worker
     * and put outage detection or on-call paging behind a status-feed ingest. The
     * `balance` check is half of it: flipping supervisor-1 to `off` would collapse
     * these four into one pool too, which reads as a harmless one-word edit and
     * serializes the entire monitoring pipeline behind whichever queue is busiest.
     */
    public function test_the_customer_facing_queues_each_keep_a_pool_of_their_own(): void
    {
        $shared = config('horizon.defaults.'.self::SHARED_SUPERVISOR);

        $this->assertSame(
            self::SHARED_BALANCE,
            $shared['balance'] ?? null,
            'supervisor-1 must stay on per-queue pools. With `off` it serves its whole list from one '
            .'pool, so a slow check blocks the incident notification behind it.'
        );

        $this->assertSame(
            self::CUSTOMER_FACING_QUEUES,
            $shared['queue'] ?? null,
            'supervisor-1 must drain exactly the four queues a customer waits on. Anything else added '
            .'here buys another permanently resident worker; anything removed loses its own pool.'
        );
    }

    /**
     * The background supervisor serves its whole list from one pool.
     *
     * `off` is load bearing and is the only value that removes the floor: `auto` and
     * `simple` both route through `createProcessPoolPerQueue()`, so either one silently
     * reinstates the four idle workers this split exists to remove. Setting
     * `minProcesses` to 0 is not the alternative; Horizon throws on it at boot.
     */
    public function test_the_background_supervisor_shares_a_single_pool(): void
    {
        $this->assertSame(
            self::BALANCE,
            config('horizon.defaults.'.self::SUPERVISOR.'.balance'),
            'The background supervisor must use `off`. `auto` or `simple` open a pool per queue, and '
            .'each pool carries a resident worker Horizon will not let fall below one.'
        );
    }

    /**
     * The background queues are listed in the order a single worker drains them.
     *
     * With one pool the list order is the priority order: Laravel's worker walks
     * `--queue=` left to right and returns on the first job it finds. `feeds` last is
     * the rule config/horizon.php already wrote down, and it only started meaning
     * something when the pool became single.
     */
    public function test_the_background_queues_are_drained_in_priority_order(): void
    {
        $this->assertSame(
            self::BACKGROUND_QUEUES,
            config('horizon.defaults.'.self::SUPERVISOR.'.queue'),
            'The background queue order changed. It is the drain order now, not a list: a third '
            .'party\'s status feed must stay behind our own housekeeping.'
        );
    }

    /**
     * Every environment block names the background supervisor and funds a process.
     *
     * Horizon sizes workers per environment, so a supervisor missing from a block is
     * sized by accident, and one provisioning zero processes is skipped outright by
     * ProvisioningPlan: four queues consumed by nobody, with nothing logged anywhere.
     */
    public function test_the_background_supervisor_is_declared_in_every_horizon_environment(): void
    {
        foreach ($this->horizonEnvironments() as $environment => $supervisors) {
            $this->assertArrayHasKey(
                self::SUPERVISOR,
                $supervisors,
                "The [{$environment}] Horizon environment does not declare the [background] supervisor."
            );

            $maxProcesses = $this->effectiveSupervisors($supervisors)[self::SUPERVISOR]['maxProcesses'] ?? null;

            $this->assertIsInt(
                $maxProcesses,
                "The [{$environment}] environment provisions no [background] maxProcesses."
            );

            $this->assertGreaterThanOrEqual(
                1,
                $maxProcesses,
                "The [{$environment}] background supervisor provisions no process, so Horizon skips it "
                .'and the four queues it owns are consumed by nobody.'
            );
        }
    }

    /**
     * The split moved queues and nothing else.
     *
     * These three values came over from supervisor-1 untouched, and each one is a
     * different way the split could have quietly widened something. A raised `memory`
     * is a bigger resident worker, which is the cost the split was undoing. A raised
     * `timeout` outlives the shared connection's retry_after (90) and hands a running
     * job to a second worker. A different `connection` puts these queues in a
     * namespace the dev listener does not drain.
     */
    public function test_the_split_carried_the_shared_ceilings_over_unchanged(): void
    {
        $background = config('horizon.defaults.'.self::SUPERVISOR);

        $this->assertSame(
            self::CONNECTION,
            $background['connection'] ?? null,
            'The background supervisor must stay on the shared redis connection.'
        );

        $this->assertSame(
            self::MEMORY,
            $background['memory'] ?? null,
            'The background memory ceiling moved. The split exists to hold fewer resident megabytes, '
            .'so a bigger ceiling here gives some of that straight back.'
        );

        $this->assertSame(
            self::TIMEOUT,
            $background['timeout'] ?? null,
            'The background worker timeout moved. It must stay under the shared connection\'s '
            .'retry_after (90) or a still-running job is released to a second worker.'
        );
    }

    /**
     * The supervisors that list a given queue.
     *
     * @return array<int, string>
     */
    private function supervisorsDraining(string $queue): array
    {
        $supervisors = (array) config('horizon.defaults');

        $this->assertNotEmpty($supervisors, 'Horizon declares no supervisors.');

        return array_keys(array_filter(
            $supervisors,
            fn (array $supervisor): bool => in_array($queue, (array) ($supervisor['queue'] ?? []), true),
        ));
    }

    /**
     * The declared Horizon environments, guarded so an emptied block cannot turn the
     * loop above into a vacuous pass.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function horizonEnvironments(): array
    {
        $environments = config('horizon.environments');

        $this->assertNotEmpty($environments, 'Horizon declares no environments.');

        return $environments;
    }

    /**
     * The supervisor options Horizon actually provisions for an environment.
     *
     * Mirrors ProvisioningPlan::applyDefaultOptions(), which merges the environment
     * block over `horizon.defaults` with array_replace_recursive.
     *
     * @param  array<string, array<string, mixed>>  $supervisors
     * @return array<string, array<string, mixed>>
     */
    private function effectiveSupervisors(array $supervisors): array
    {
        return array_replace_recursive(config('horizon.defaults'), $supervisors);
    }
}
