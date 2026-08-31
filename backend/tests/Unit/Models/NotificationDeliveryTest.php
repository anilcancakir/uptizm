<?php

namespace Tests\Unit\Models;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the {@see NotificationDelivery} shape: the fillable set, the casts,
 * and the two FK modes (`team_id` cascades, `channel_id` nulls out) that
 * decide whether the row survives its channel's deletion.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_set_persists_every_documented_column(): void
    {
        $team = $this->makeTeam();
        $channel = $this->makeChannel($team);

        $delivery = NotificationDelivery::query()->create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => 'webhook',
            'notification_type' => 'App\\Notifications\\IncidentOpened',
            'event' => 'opened',
            'outcome' => 'delivered',
            'status_code' => 200,
            'error_code' => null,
            'exception_class' => null,
            'is_test' => false,
        ]);

        $delivery->refresh();

        $this->assertSame($team->id, $delivery->team_id);
        $this->assertSame($channel->id, $delivery->channel_id);
        $this->assertSame('webhook', $delivery->channel_type);
        $this->assertSame('App\\Notifications\\IncidentOpened', $delivery->notification_type);
        $this->assertSame('opened', $delivery->event);
        $this->assertSame('delivered', $delivery->outcome);
        $this->assertSame(200, $delivery->status_code);
        $this->assertNull($delivery->error_code);
        $this->assertNull($delivery->exception_class);
        $this->assertFalse($delivery->is_test);
    }

    public function test_is_test_and_status_code_cast_to_their_native_types(): void
    {
        $team = $this->makeTeam();
        $channel = $this->makeChannel($team);

        $delivery = NotificationDelivery::query()->create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => 'webhook',
            'notification_type' => 'App\\Notifications\\IncidentOpened',
            'event' => 'test',
            'outcome' => 'delivered',
            'status_code' => '204',
            'is_test' => 1,
        ]);

        $this->assertIsBool($delivery->is_test);
        $this->assertTrue($delivery->is_test);
        $this->assertIsInt($delivery->status_code);
        $this->assertSame(204, $delivery->status_code);
    }

    public function test_deleting_the_team_cascades_to_its_deliveries(): void
    {
        $team = $this->makeTeam();
        $channel = $this->makeChannel($team);

        $delivery = NotificationDelivery::query()->create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => 'webhook',
            'notification_type' => 'App\\Notifications\\IncidentOpened',
            'event' => 'opened',
            'outcome' => 'delivered',
        ]);

        $team->delete();

        $this->assertNull(NotificationDelivery::query()->find($delivery->id));
    }

    public function test_deleting_the_channel_nulls_channel_id_and_keeps_the_row(): void
    {
        $team = $this->makeTeam();
        $channel = $this->makeChannel($team);

        $delivery = NotificationDelivery::query()->create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => 'webhook',
            'notification_type' => 'App\\Notifications\\IncidentOpened',
            'event' => 'opened',
            'outcome' => 'delivered',
        ]);

        $channel->delete();
        $delivery->refresh();

        $this->assertNull($delivery->channel_id);
        $this->assertSame('webhook', $delivery->channel_type);
    }

    public function test_belongs_to_its_team_and_its_channel(): void
    {
        $team = $this->makeTeam();
        $channel = $this->makeChannel($team);

        $delivery = NotificationDelivery::query()->create([
            'team_id' => $team->id,
            'channel_id' => $channel->id,
            'channel_type' => 'webhook',
            'notification_type' => 'App\\Notifications\\IncidentOpened',
            'event' => 'opened',
            'outcome' => 'delivered',
        ]);

        $this->assertTrue($delivery->team->is($team));
        $this->assertTrue($delivery->channel->is($channel));
    }

    /**
     * Creates a persisted team owned by a freshly created user.
     */
    protected function makeTeam(): Team
    {
        $user = User::query()->create([
            'name' => 'Notification Delivery Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        return Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Notification Delivery Team',
        ]);
    }

    /**
     * Creates a persisted webhook channel on the given team.
     */
    protected function makeChannel(Team $team): NotificationChannel
    {
        return NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
        ]);
    }
}
