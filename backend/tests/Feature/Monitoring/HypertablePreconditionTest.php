<?php

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the schema precondition TimescaleDB imposes on the two time-series
 * tables: EVERY unique index (primary key included) must contain the
 * partitioning column, or `create_hypertable()` refuses outright.
 *
 * This is a regression test for a defect that survived every earlier run because
 * the hypertable migration is conditional: without the extension installed it
 * returns early, so its precondition was never exercised until the extension was
 * enabled on a real cluster. `monitor_checks` carried a three-column unique
 * (`monitor_id, region, probe_run_id`) that omitted `checked_at`, and the very
 * first real promotion failed with TS103.
 *
 * The assertions run on the test driver's own catalogue rather than needing
 * TimescaleDB, so they hold in CI where the extension is absent, which is
 * exactly where the gap was.
 */
class HypertablePreconditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hypertables(): array
    {
        return [
            'monitor_checks' => ['monitor_checks', 'checked_at'],
            'monitor_metric_values' => ['monitor_metric_values', 'recorded_at'],
        ];
    }

    #[DataProvider('hypertables')]
    public function test_every_unique_index_contains_the_partitioning_column(
        string $table,
        string $timeColumn,
    ): void {
        $offenders = [];

        foreach (Schema::getIndexes($table) as $index) {
            $isUnique = ($index['unique'] ?? false) || ($index['primary'] ?? false);

            if (! $isUnique) {
                continue;
            }

            $columns = array_map(
                static fn (string $column): string => mb_strtolower($column),
                $index['columns'] ?? [],
            );

            if (! in_array($timeColumn, $columns, true)) {
                $offenders[] = ($index['name'] ?? '(unnamed)').' ('.implode(', ', $columns).')';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "TimescaleDB cannot promote `{$table}`: these unique indexes omit `{$timeColumn}`: "
            .implode(' | ', $offenders),
        );
    }

    #[DataProvider('hypertables')]
    public function test_the_partitioning_column_exists_and_is_not_nullable(
        string $table,
        string $timeColumn,
    ): void {
        $this->assertTrue(
            Schema::hasColumn($table, $timeColumn),
            "`{$table}` has no `{$timeColumn}` column to partition on."
        );

        $column = collect(Schema::getColumns($table))
            ->firstWhere('name', $timeColumn);

        $this->assertNotNull($column);
        $this->assertFalse(
            $column['nullable'],
            "`{$table}.{$timeColumn}` is nullable; a hypertable's time column cannot be null."
        );
    }
}
