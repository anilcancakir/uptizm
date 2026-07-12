<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Locks the UUID correctness of the published Cashier schema: the billable
 * foreign key on `subscriptions` is a UUID `team_id` whose type matches the
 * `teams.id` primary key (Cashier ships it as a bigint `user_id`), and the
 * published customer-columns migration is removed because S13 owns every
 * `teams` billing column.
 */
class CashierMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_subscriptions_billable_fk_matches_the_teams_uuid_primary_key(): void
    {
        $this->assertTrue(
            Schema::hasColumn('subscriptions', 'team_id'),
            'The subscriptions table must reference the billable via a `team_id` column.',
        );

        $this->assertFalse(
            Schema::hasColumn('subscriptions', 'user_id'),
            'The stock bigint `user_id` billable column must not survive the hand-edit.',
        );

        $this->assertSame(
            Schema::getColumnType('teams', 'id'),
            Schema::getColumnType('subscriptions', 'team_id'),
            'The subscriptions billable FK type must match the teams UUID primary key.',
        );
    }

    public function test_no_cashier_customer_columns_migration_remains(): void
    {
        $customerColumnMigrations = glob(
            database_path('migrations/*_create_customer_columns.php'),
        );

        $this->assertSame(
            [],
            $customerColumnMigrations,
            'S13 owns every teams billing column; the published customer-columns migration must be deleted.',
        );
    }
}
