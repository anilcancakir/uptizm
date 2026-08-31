<?php

namespace Tests\Feature\Http;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers {@see NotificationChannelController::destroy()} against a channel
 * that already has delivery history. The FK on `notification_deliveries.channel_id`
 * is `nullOnDelete()` ({@see NotificationDelivery}), so a hard delete of the
 * channel must leave both rows in place with `channel_id` null.
 *
 * This test is real on BOTH engines, which is worth stating because the
 * opposite is easy to assume: `config/database.php:40` sets
 * `'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true)` and `phpunit.xml`
 * pins only `DB_CONNECTION` and `DB_DATABASE`, so the SQLite test connection
 * enforces FKs too. Measured: mutating the FK to `cascadeOnDelete()` reddens
 * this test on SQLite as well as on PostgreSQL. Do not discount a red here as
 * an engine artifact.
 *
 * Still run it against production's engine when the constraint is what is in
 * question: `DB_CONNECTION=pgsql DB_DATABASE=uptizm_test php artisan test`.
 * `.claude/rules/backend.md` lists the five divergences that have actually
 * produced defects here, and foreign keys are not among them.
 *
 * Its value over the model-level test is the path: this one deletes through the
 * HTTP endpoint an operator actually uses.
 */
class NotificationChannelDeleteWithDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_survives_the_channels_delivery_history_with_channel_id_nulled(): void
    {
        $team = $this->actingAsTeamMember();
        $channel = NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
        ]);

        $first = NotificationDelivery::create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => $channel->channel_type->value,
            'notification_type' => 'App\\Notifications\\IncidentOpened',
            'event' => 'opened',
            'outcome' => 'delivered',
            'status_code' => 200,
            'is_test' => false,
        ]);
        $second = NotificationDelivery::create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => $channel->channel_type->value,
            'notification_type' => 'App\\Notifications\\IncidentResolved',
            'event' => 'resolved',
            'outcome' => 'delivered',
            'status_code' => 200,
            'is_test' => false,
        ]);

        $response = $this->deleteJson("/api/v1/notification-channels/{$channel->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('notification_channels', ['id' => $channel->id]);

        foreach ([$first, $second] as $delivery) {
            $delivery->refresh();
            $this->assertNull($delivery->channel_id);
            $this->assertSame('webhook', $delivery->channel_type);
        }
    }

    /**
     * Authenticate as a user whose current team is a freshly created team.
     */
    protected function actingAsTeamMember(): Team
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->id])->save();

        Sanctum::actingAs($user);

        return $team;
    }
}
