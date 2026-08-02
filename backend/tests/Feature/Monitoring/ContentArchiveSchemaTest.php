<?php

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pins the content-archive schema, and specifically the two unique indexes the
 * archive's atomic claim depends on.
 *
 * Both unique keys must carry `normalizer_version`. `insertOrIgnore` compiles to
 * `on conflict do nothing` with NO conflict target, so a collision on the
 * address key is indistinguishable from one on the normalized key. Leave
 * `normalizer_version` out of the address key and, after a version bump, every
 * already-archived body still carries its old raw hash: the claim is silently
 * ignored on the address index, the follow-up lookup by normalized hash finds
 * nothing, and archiving stops without an error anywhere.
 *
 * The index-name length assertion is not cosmetic either. PostgreSQL truncates
 * an identifier at 63 bytes while SQLite keeps the whole string, so an
 * auto-generated name (85 characters for the normalized key) would give
 * production a different index name from the one this suite sees.
 */
class ContentArchiveSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PostgreSQL silently truncates any identifier past this many bytes.
     */
    protected const int MAX_IDENTIFIER_BYTES = 63;

    public function test_monitor_checks_carries_both_content_hash_columns_as_nullable(): void
    {
        // Nullable because a TCP monitor has no body to hash and every row
        // written before this feature has neither hash.
        foreach (['content_hash', 'content_hash_normalized'] as $name) {
            $this->assertTrue(
                Schema::hasColumn('monitor_checks', $name),
                "`monitor_checks` has no `{$name}` column."
            );

            $column = collect(Schema::getColumns('monitor_checks'))
                ->firstWhere('name', $name);

            $this->assertNotNull($column);
            $this->assertTrue(
                $column['nullable'],
                "`monitor_checks.{$name}` must be nullable."
            );
        }
    }

    public function test_the_versions_table_carries_exactly_the_documented_column_set(): void
    {
        $expected = [
            'byte_size',
            'content_hash',
            'content_hash_normalized',
            'content_type',
            'created_at',
            'first_seen_at',
            'id',
            'last_seen_at',
            'monitor_id',
            'normalizer_version',
            'team_id',
            'truncated',
            'updated_at',
        ];

        $actual = collect(Schema::getColumns('monitor_content_versions'))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual);
    }

    public function test_the_versions_table_has_no_storage_path_column(): void
    {
        // A blob path is always derived from `(team_id, content_hash)`. Storing
        // one would make a written-and-never-read string the input to a delete
        // on a remote that holds the only PostgreSQL backups.
        $this->assertFalse(
            Schema::hasColumn('monitor_content_versions', 'storage_path'),
            'A `storage_path` column crept back in; the path is derived, never stored.'
        );
    }

    public function test_both_unique_indexes_carry_the_normalizer_version(): void
    {
        $unique = $this->uniqueIndexColumnSets('monitor_content_versions');

        $this->assertContains(
            [
                'monitor_id',
                'content_hash',
                'normalizer_version',
            ],
            $unique,
            'The address key `(monitor_id, content_hash, normalizer_version)` is missing.'
        );

        $this->assertContains(
            [
                'monitor_id',
                'content_hash_normalized',
                'normalizer_version',
            ],
            $unique,
            'The change-signal key `(monitor_id, content_hash_normalized, normalizer_version)` is missing.'
        );
    }

    public function test_the_prune_scan_column_is_indexed(): void
    {
        $columnSets = array_map(
            static fn (array $index): array => $index['columns'] ?? [],
            Schema::getIndexes('monitor_content_versions'),
        );

        $this->assertContains(
            ['last_seen_at'],
            $columnSets,
            'The nightly prune scans `last_seen_at`; it needs its own index.'
        );
    }

    public function test_every_index_name_fits_the_postgresql_identifier_limit(): void
    {
        $indexes = Schema::getIndexes('monitor_content_versions');
        $offenders = [];

        // Guard the guard: an empty catalogue would make the length assertion
        // below pass without measuring anything.
        $this->assertNotEmpty($indexes, 'No indexes to measure on `monitor_content_versions`.');

        foreach ($indexes as $index) {
            $name = (string) ($index['name'] ?? '');

            if (strlen($name) > self::MAX_IDENTIFIER_BYTES) {
                $offenders[] = $name.' ('.strlen($name).' bytes)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'PostgreSQL would truncate these index names while SQLite keeps them whole: '
            .implode(' | ', $offenders),
        );
    }

    public function test_both_migrations_roll_back_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('monitor_content_versions'));
        $this->assertTrue(Schema::hasColumn('monitor_checks', 'content_hash'));

        // Target the two migrations under test BY PATH rather than by a step
        // count. The step-count form was a deliberate tripwire: its comment
        // below records that a third migration landing after these two would
        // take this test red rather than let it quietly roll back somebody
        // else's work. That tripwire fired the first time an unrelated feature
        // added a migration, which is the outcome it was designed to force.
        // Naming the two files keeps the original guarantee (exactly these two
        // roll back, nothing beyond them) without coupling it to what else the
        // project has migrated since.
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_08_01_000000_add_content_hashes_to_monitor_checks_table.php',
            'database/migrations/2026_08_01_000001_create_monitor_content_versions_table.php',
        ]])->assertExitCode(0);

        $this->assertFalse(
            Schema::hasTable('monitor_content_versions'),
            '`monitor_content_versions` survived its own `down()`.'
        );
        $this->assertFalse(
            Schema::hasColumn('monitor_checks', 'content_hash'),
            '`monitor_checks.content_hash` survived its own `down()`.'
        );
        $this->assertFalse(
            Schema::hasColumn('monitor_checks', 'content_hash_normalized'),
            '`monitor_checks.content_hash_normalized` survived its own `down()`.'
        );

        // Exactly two migrations went, not a whole batch: the migration before
        // these two is still applied. `--step=2` walks the log backwards, so a
        // third migration landing after these would take this assertion red
        // rather than quietly rolling back someone else's work.
        $this->assertTrue(
            Schema::hasColumn('monitors', 'last_probe_error'),
            'The rollback reached past the two migrations under test.'
        );
    }

    /**
     * Column lists of every unique index on the given table, primary keys included.
     *
     * @return array<int, array<int, string>>
     */
    protected function uniqueIndexColumnSets(string $table): array
    {
        $sets = [];

        foreach (Schema::getIndexes($table) as $index) {
            if (! ($index['unique'] ?? false) && ! ($index['primary'] ?? false)) {
                continue;
            }

            $sets[] = array_map(
                static fn (string $column): string => mb_strtolower($column),
                $index['columns'] ?? [],
            );
        }

        return $sets;
    }
}
