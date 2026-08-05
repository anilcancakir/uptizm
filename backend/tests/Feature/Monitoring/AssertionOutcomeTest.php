<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\CheckPersistenceService;
use App\Support\Monitoring\CheckResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The destination for an assertion report: `monitor_checks.assertions_passed`
 * (the verdict) and `assertion_results` (the per-rule outcomes).
 *
 * `assertions_passed` shipped as `boolean NOT NULL DEFAULT TRUE`, which made the
 * schema itself dishonest. A monitor that asserts nothing recorded "every
 * assertion passed" on every check it ever produced, and a status page or an SLO
 * reading that column would cite a result nobody measured. There is no boolean
 * value for "not evaluated", so the column has to be nullable and it has to lose
 * its default: a nullable column that still defaults to TRUE tells the same lie
 * to every insert that does not name it, which is every insert.
 *
 * That makes the pair a THREE-state record, and the tests below pin all three
 * plus the one a reader will mistake for a bug:
 *
 *   1. no rules configured   -> both columns NULL
 *   2. rules ran, all passed -> TRUE  beside a populated outcome list
 *   3. one rule failed       -> FALSE beside a populated outcome list
 *   4. every rule skipped    -> TRUE  beside recorded skip reasons, because a
 *      skip is OUR fault (a malformed rule, an operator this build does not
 *      implement) and a fault of ours must never become a verdict about the
 *      customer's target. Nothing was measured, so nothing failed.
 *
 * Read the stored outcomes BY FIELD and never by serialized form. The column is
 * `jsonb` on PostgreSQL, which preserves array order but not object key order, so
 * comparing an encoded string (or a hash of one, or `assertSame` over a decoded
 * nested map, since PHP's `===` on arrays is order-sensitive) passes on SQLite and
 * fails on PostgreSQL. A rule's POSITION in the list is its identity, which is
 * what makes the per-index assertions below legitimate.
 */
class AssertionOutcomeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The monitor every check in this class belongs to, created on first use so
     * several checks can share one owner.
     */
    protected ?Monitor $monitor = null;

    public function test_the_verdict_column_is_nullable_and_carries_no_default(): void
    {
        $column = collect(Schema::getColumns('monitor_checks'))
            ->firstWhere('name', 'assertions_passed');

        $this->assertNotNull($column, '`monitor_checks` has no `assertions_passed` column.');
        $this->assertTrue(
            $column['nullable'],
            'A check that evaluated no assertions has no boolean verdict to record, '
            .'so `assertions_passed` must accept NULL.',
        );
        // The default is the same defect one layer down: a nullable column that
        // still defaults to TRUE records "passed" for every insert that does not
        // name it, and no insert names it when there is nothing to say.
        $this->assertNull(
            $column['default'],
            'assertions_passed still carries a default ['.var_export($column['default'], true).'], '
            .'so an unevaluated check records a verdict anyway.',
        );
    }

    public function test_a_check_recorded_without_a_report_stores_null_rather_than_a_passed_verdict(): void
    {
        // Deliberately omits both columns, which is exactly what the persistence
        // path does for a monitor with no `assertion_rules`. Read back through
        // the query builder so the row is observed as the database holds it,
        // with no cast in the way.
        $check = $this->recordCheck();

        $row = DB::table('monitor_checks')
            ->where('id', $check->id)
            ->first([
                'assertions_passed',
                'assertion_results',
            ]);

        $this->assertNotNull($row);
        $this->assertNull($row->assertions_passed, 'An unevaluated check recorded a verdict.');
        $this->assertNull($row->assertion_results, 'An unevaluated check recorded outcomes.');
    }

    public function test_the_boolean_cast_leaves_an_unevaluated_verdict_null_rather_than_false(): void
    {
        // The whole point of the nullable column: `MonitorCheck` casts this
        // attribute to `boolean`, and a cast that turned NULL into `false` would
        // publish "assertions failed" for a monitor that asserts nothing. Every
        // reader of this column has to be able to tell the two apart, and PHP's
        // own `false == null` is why the distinction is asserted identically.
        $check = $this->recordCheck()->fresh();

        $this->assertNotNull($check);
        $this->assertNull($check->assertions_passed);
        $this->assertNotFalse($check->assertions_passed);
        $this->assertNull($check->assertion_results);
    }

    public function test_a_null_verdict_is_not_a_failed_verdict_in_a_query(): void
    {
        $unevaluated = $this->recordCheck();
        $failed = $this->recordCheck([
            'assertions_passed' => false,
            'assertion_results' => $this->failedReport(),
        ]);

        $matched = MonitorCheck::query()
            ->where('assertions_passed', false)
            ->pluck('id')
            ->all();

        // Non-vacuous in both directions: the failed row must be found, and the
        // unevaluated one must not be. A "fix" that backfilled NULL to `false`
        // would satisfy the first half and break the second.
        $this->assertSame([$failed->id], $matched);
        $this->assertNotContains($unevaluated->id, $matched);
    }

    public function test_a_report_whose_rules_all_passed_stores_the_verdict_beside_every_outcome(): void
    {
        $report = $this->passedReport();

        $check = $this->recordCheck([
            'assertions_passed' => true,
            'assertion_results' => $report,
        ])->fresh();

        $this->assertNotNull($check);
        $this->assertTrue($check->assertions_passed);
        $this->assertStoredOutcomes($report, $check->assertion_results);
    }

    public function test_a_failed_rule_stores_a_false_verdict_beside_the_outcome_that_failed(): void
    {
        $report = $this->failedReport();

        $check = $this->recordCheck([
            'status' => MonitorStatus::Down,
            'assertions_passed' => false,
            'assertion_results' => $report,
        ])->fresh();

        $this->assertNotNull($check);
        $this->assertFalse($check->assertions_passed);
        $this->assertStoredOutcomes($report, $check->assertion_results);

        // The verdict is not re-derivable from the outcomes at this layer and is
        // not re-derived: "all rules must pass" and "a skip is not a failure" are
        // implemented once, at the edge. What this layer owes is that the failing
        // rule stays identifiable, which is its position in the list.
        $this->assertSame('failed', $check->assertion_results[1]['verdict']);
    }

    public function test_a_report_whose_every_rule_was_skipped_stores_a_passing_verdict_with_its_reasons(): void
    {
        // This is the row that looks like a bug and is not. Every rule was
        // skipped, so `assertions_passed` is TRUE: a malformed rule and an
        // operator this build does not implement are faults in OUR configuration,
        // and the alternative pages the on-call for our own bad config while the
        // customer's service is up.
        $report = $this->skippedReport();

        $check = $this->recordCheck([
            'assertions_passed' => true,
            'assertion_results' => $report,
        ])->fresh();

        $this->assertNotNull($check);
        $this->assertTrue($check->assertions_passed);
        $this->assertStoredOutcomes($report, $check->assertion_results);

        $reasons = array_column($check->assertion_results, 'reason');
        $this->assertSame(
            [
                'regex_invalid',
                'rule_malformed',
            ],
            $reasons,
            'A skip without its reason recorded is a log line, not a record.',
        );
    }

    public function test_the_wire_contract_carries_a_report_from_the_worker_payload(): void
    {
        // The edge nests both halves under one `assertions` key, and this side keeps
        // the nesting for the reason the worker's own docblock gives: two nullable
        // fields make four states representable and two of them are contradictions.
        $result = CheckResult::fromWorkerPayload($this->workerPayload([
            'passed' => false,
            'results' => $this->failedReport(),
        ]));

        $this->assertIsArray($result->assertions);
        $this->assertFalse($result->assertions['passed']);
        $this->assertCount(2, $result->assertions['results']);

        // A payload replayed from before this field existed must still parse, the
        // same absent-tolerance `colo` and `exit_via` already have.
        $legacy = CheckResult::fromWorkerPayload($this->workerPayload(null));
        $this->assertNull($legacy->assertions);
    }

    public function test_a_malformed_report_on_the_wire_is_read_as_no_report_rather_than_half_stored(): void
    {
        // The row write happens inside the persist transaction, so a throw down
        // there loses the whole check row. Monitoring must never degrade to protect
        // a secondary field, which is why the shape is refused at the boundary
        // instead. Each of these is present but not a `{passed, results}` pair.
        foreach ([
            'a scalar instead of a report' => true,
            'a verdict with no outcomes' => ['passed' => true],
            'outcomes with no verdict' => ['results' => []],
            'outcomes that are not a list' => ['passed' => true, 'results' => 'nope'],
        ] as $label => $malformed) {
            $result = CheckResult::fromWorkerPayload($this->workerPayload($malformed));

            $this->assertNull($result->assertions, "A report shaped as {$label} must not be stored.");
        }
    }

    public function test_a_report_survives_the_queue_hop_and_reaches_both_columns(): void
    {
        // The end-to-end path, and the one nobody was covering: the worker payload
        // is parsed, serialized through `toArray()` onto the Redis `processing`
        // queue, rehydrated on the far side, and persisted. `content` is excluded
        // from that array on purpose, so a field that is not named there reaches the
        // job as null and the columns stay empty while every test on either side
        // passes.
        $monitor = $this->monitor();
        $report = $this->failedReport();

        $queued = CheckResult::fromWorkerPayload($this->workerPayload([
            'passed' => false,
            'results' => $report,
        ]))->toArray();

        $this->assertArrayHasKey('assertions', $queued, 'A field absent from toArray() never reaches the job.');

        app(CheckPersistenceService::class)->persist(
            $monitor,
            CheckResult::fromWorkerPayload($queued),
        );

        $check = MonitorCheck::query()->latest('checked_at')->first();

        $this->assertNotNull($check);
        $this->assertFalse($check->assertions_passed);
        $this->assertStoredOutcomes($report, $check->assertion_results);
    }

    public function test_a_monitor_that_asserts_nothing_still_persists_with_both_columns_null(): void
    {
        $monitor = $this->monitor();

        app(CheckPersistenceService::class)->persist(
            $monitor,
            CheckResult::fromWorkerPayload($this->workerPayload(null)),
        );

        $check = MonitorCheck::query()->latest('checked_at')->first();

        $this->assertNotNull($check);
        $this->assertNull($check->assertions_passed, 'No rules must not record a passing verdict.');
        $this->assertNull($check->assertion_results);
    }

    /**
     * A minimal worker payload, with whatever `assertions` value the case needs.
     *
     * `checked_at` moves per call so `latest('checked_at')` is deterministic when a
     * case persists more than one row.
     *
     * @return array<string, mixed>
     */
    protected function workerPayload(mixed $assertions): array
    {
        static $second = 0;

        $payload = [
            'monitor_id' => (string) $this->monitor()->id,
            'region' => 'eu-west',
            'checked_at' => '2026-08-05T10:00:'.str_pad((string) ($second++ % 60), 2, '0', STR_PAD_LEFT).'+00:00',
            'status' => 'down',
            'status_code' => 200,
            'response_ms' => 120,
            'probe_run_id' => (string) Str::orderedUuid(),
            'timing' => [
                'dns_ms' => 1,
                'connect_ms' => 2,
                'tls_ms' => 3,
                'ttfb_ms' => 4,
                'download_ms' => 5,
            ],
            'response_headers' => [
                'content-type' => 'application/json',
            ],
        ];

        // Absent rather than explicitly null, so the legacy-payload path is the one
        // being exercised when a case asks for no report.
        if ($assertions !== null) {
            $payload['assertions'] = $assertions;
        }

        return $payload;
    }

    /**
     * Assert the stored outcome list matches the report field by field, in order.
     *
     * @param  list<array<string, mixed>>  $expected
     */
    protected function assertStoredOutcomes(array $expected, mixed $stored): void
    {
        $this->assertIsArray($stored);
        $this->assertCount(count($expected), $stored);

        foreach ($expected as $index => $outcome) {
            $actual = $stored[$index] ?? null;
            $this->assertIsArray($actual, "Outcome [{$index}] did not survive the round trip.");

            // Scalars are compared strictly: a jsonb round trip must not turn a
            // number into a string or a null into an empty one.
            $this->assertSame($outcome['verdict'], $actual['verdict'] ?? null, "Outcome [{$index}] verdict.");
            $this->assertSame($outcome['observed'] ?? null, $actual['observed'] ?? null, "Outcome [{$index}] observed.");
            $this->assertSame($outcome['reason'] ?? null, $actual['reason'] ?? null, "Outcome [{$index}] reason.");

            // The echoed rule is a nested OBJECT, and PostgreSQL's jsonb does not
            // preserve object key order, so it is compared loosely on purpose:
            // `assertSame` over a decoded map is order-sensitive and would pass
            // on SQLite and fail on PostgreSQL. `null` for `rule_malformed`,
            // where there was no rule to echo.
            $this->assertEquals($outcome['rule'], $actual['rule'] ?? null, "Outcome [{$index}] rule.");
        }
    }

    /**
     * Two rules, both satisfied by the reading.
     *
     * @return list<array<string, mixed>>
     */
    protected function passedReport(): array
    {
        return [
            [
                'verdict' => 'passed',
                'rule' => [
                    'target' => 'status_code',
                    'operator' => 'equals',
                    'value' => 200,
                ],
                'observed' => 200,
            ],
            [
                'verdict' => 'passed',
                'rule' => [
                    'target' => 'body',
                    'operator' => 'contains',
                    'value' => 'ok',
                ],
                'observed' => '{"status":"ok"}',
            ],
        ];
    }

    /**
     * A passing rule followed by a failing one, so the failure is identified by
     * its position rather than by a stored index.
     *
     * @return list<array<string, mixed>>
     */
    protected function failedReport(): array
    {
        return [
            [
                'verdict' => 'passed',
                'rule' => [
                    'target' => 'status_code',
                    'operator' => 'equals',
                    'value' => 200,
                ],
                'observed' => 200,
            ],
            [
                'verdict' => 'failed',
                'rule' => [
                    'target' => 'header',
                    'operator' => 'equals',
                    'value' => 'application/json',
                    'name' => 'content-type',
                ],
                'observed' => 'text/html; charset=utf-8',
            ],
        ];
    }

    /**
     * Two rules neither of which could be evaluated, each carrying the closed-set
     * reason it was skipped for.
     *
     * @return list<array<string, mixed>>
     */
    protected function skippedReport(): array
    {
        return [
            [
                'verdict' => 'skipped',
                'rule' => [
                    'target' => 'body',
                    'operator' => 'matches_regex',
                    'value' => '([a-z]+',
                ],
                'observed' => null,
                'reason' => 'regex_invalid',
            ],
            [
                // Null because there was no rule to echo: the stored element was
                // not a rule object at all.
                'verdict' => 'skipped',
                'rule' => null,
                'observed' => null,
                'reason' => 'rule_malformed',
            ],
        ];
    }

    /**
     * Record one check row, naming only the columns the caller cares about.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function recordCheck(array $attributes = []): MonitorCheck
    {
        return MonitorCheck::query()->create(array_merge([
            'id' => (string) Str::orderedUuid(),
            'monitor_id' => $this->monitor()->id,
            'team_id' => $this->monitor()->team_id,
            'region' => 'us-east',
            'checked_at' => now(),
            'status' => MonitorStatus::Up,
            'status_code' => 200,
            'response_ms' => 120,
            'probe_run_id' => (string) Str::uuid(),
        ], $attributes));
    }

    /**
     * The single monitor every check in this class belongs to.
     */
    protected function monitor(): Monitor
    {
        return $this->monitor ??= $this->createMonitor();
    }

    protected function createMonitor(): Monitor
    {
        $user = User::factory()->create();
        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        return Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'Assertions',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 180,
        ]);
    }
}
