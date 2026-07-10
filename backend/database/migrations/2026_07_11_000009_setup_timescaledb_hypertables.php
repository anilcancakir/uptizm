<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote the check / metric value tables to TimescaleDB hypertables and
 * attach retention policies, if the `timescaledb` extension is present.
 *
 * Skipping is intentional: local / CI environments without the extension
 * keep the plain tables from the previous migrations and operate without
 * partitioning. Retention windows come from `config/timescale.php` so
 * ops can tune per environment without a new migration.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, time_column: string, retention_key: string}>
     */
    private const array HYPERTABLES = [
        [
            'table' => 'monitor_checks',
            'time_column' => 'checked_at',
            'retention_key' => 'raw_days',
        ],
        [
            'table' => 'monitor_metric_values',
            'time_column' => 'recorded_at',
            'retention_key' => 'raw_days',
        ],
    ];

    public function up(): void
    {
        if (! $this->timescaleAvailable()) {
            return;
        }

        foreach (self::HYPERTABLES as $target) {
            $this->promoteToHypertable($target['table'], $target['time_column']);
            $this->attachRetentionPolicy($target['table'], (int) config("timescale.retention.{$target['retention_key']}"));
        }
    }

    public function down(): void
    {
        if (! $this->timescaleAvailable()) {
            return;
        }

        // Retention policies dissolve automatically when the hypertable is dropped by the `create_*` migrations' down().
    }

    /**
     * Detect whether the running database exposes the `timescaledb` extension.
     */
    protected function timescaleAvailable(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return false;
        }

        $result = DB::selectOne("SELECT 1 AS available FROM pg_extension WHERE extname = 'timescaledb'");

        return $result !== null;
    }

    /**
     * Convert an existing table into a Timescale hypertable partitioned by
     * the given time column. `migrate_data => true` is required because
     * the migrations above may already hold test data on rerun.
     */
    protected function promoteToHypertable(string $table, string $timeColumn): void
    {
        DB::statement(
            'SELECT create_hypertable(?, ?, if_not_exists => TRUE, migrate_data => TRUE)',
            [
                $table,
                $timeColumn,
            ],
        );
    }

    /**
     * Attach a retention policy that drops chunks older than `$days`.
     */
    protected function attachRetentionPolicy(string $table, int $days): void
    {
        DB::statement(
            "SELECT add_retention_policy(?, INTERVAL '1 day' * ?, if_not_exists => TRUE)",
            [
                $table,
                $days,
            ],
        );
    }
};
