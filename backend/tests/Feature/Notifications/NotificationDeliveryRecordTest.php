<?php

namespace Tests\Feature\Notifications;

use App\Listeners\RecordNotificationDelivery;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\ChannelDeliveryResult;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\IncidentOpened;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Locks {@see RecordNotificationDelivery}: one `notification_deliveries` row
 * per attempted send through a team-scoped {@see NotificationChannel}, written
 * from BOTH notification seams, carrying no credential.
 *
 * The two seams are asserted separately because either alone under-records.
 * {@see NotificationSent} fires only once `send()` has returned, so the
 * transport failure the channels rethrow (so the queue can retry it) reaches
 * only {@see NotificationFailed}; a suite that drove one path would report a
 * table that silently loses every connect failure.
 *
 * Nothing here uses `Notification::fake()`: faking replaces the sender, so
 * neither event is ever dispatched and every assertion below would pass
 * vacuously. The sends are real, and only the HTTP transport is faked.
 */
class NotificationDeliveryRecordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The tenant target. Both halves that a leak would expose are distinctive
     * strings: the path segment (an ntfy topic lives exactly there and IS the
     * credential) and the query value (a Teams Workflows SAS lives there).
     */
    private const string CHANNEL_URL = 'https://example.com/webhook/tenant-path-secret?sig=channel-sig';

    /**
     * The shape Guzzle produces: a cURL diagnostic with the effective URI
     * appended. It carries a DIFFERENT secret-bearing URL from the channel's
     * own, so a row echoing either one is caught.
     */
    private const string TRANSPORT_MESSAGE = 'cURL error 6: Could not resolve host (see '
        .'https://curl.se/libcurl/c/libcurl-errors.html) for https://ntfy.sh/secret-topic?sig=abc';

    /** A send the provider accepted records one delivered row. */
    public function test_a_successful_send_records_one_delivered_row(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $team = $this->makeTeam();
        $channel = $this->webhookChannel($team);

        $channel->notifyNow(new IncidentOpened($this->makeIncident($team)));

        $row = $this->soleDelivery();

        $this->assertSame(ChannelDeliveryResult::OUTCOME_DELIVERED, $row->outcome);
        $this->assertSame(200, $row->status_code);
        $this->assertNull($row->error_code);
        $this->assertNull($row->exception_class);
        $this->assertSame($team->id, $row->team_id);
        $this->assertSame($channel->id, $row->channel_id);
        $this->assertSame('webhook', $row->channel_type);
    }

    /**
     * A transport failure records one failed row naming the exception CLASS,
     * with no status code.
     *
     * This is the case `NotificationSent` cannot see: the channel rethrows, so
     * only `NotificationFailed` is dispatched. `status_code` is null by
     * construction rather than by omission, because a throw means no response
     * ever existed to read a status from.
     */
    public function test_a_transport_failure_records_one_failed_row_with_no_status(): void
    {
        $this->failTheTransport();

        $team = $this->makeTeam();
        $channel = $this->webhookChannel($team);

        $this->sendAndSwallow($channel, $team);

        $row = $this->soleDelivery();

        $this->assertSame(ChannelDeliveryResult::OUTCOME_FAILED, $row->outcome);
        $this->assertSame(RuntimeException::class, $row->exception_class);
        $this->assertNull($row->status_code, 'A throw means no response existed to read a status from.');
        $this->assertNull($row->error_code);
    }

    /**
     * A channel that returns no result is recorded as delivered.
     *
     * All four channels return a `ChannelDeliveryResult`, so nothing in the
     * application reaches this branch and no other fixture covers it. It is
     * pinned anyway because it encodes an assumption rather than a reading: a
     * `NotificationSent` proves only that `send()` returned without throwing,
     * so a channel added later that forgets the return type is recorded as a
     * success it never claimed. If that is ever the wrong default, this test is
     * what fails and says so.
     */
    public function test_a_channel_returning_no_result_is_recorded_as_delivered(): void
    {
        $team = $this->makeTeam();
        $channel = $this->webhookChannel($team);

        event(new NotificationSent(
            $channel,
            new IncidentOpened($this->makeIncident($team)),
            WebhookChannel::class,
            null,
        ));

        $row = $this->soleDelivery();

        $this->assertSame(ChannelDeliveryResult::OUTCOME_DELIVERED, $row->outcome);
        $this->assertNull($row->status_code);
        $this->assertNull($row->error_code);
        $this->assertNull($row->exception_class);
    }

    /**
     * A notification to a PERSON writes no row, however it was delivered.
     *
     * Every notification in the application passes through the same two events,
     * so without the `instanceof NotificationChannel` filter the mail, database
     * and broadcast lanes would each write a channel-delivery row. The database
     * assertion first proves the send actually ran, so an absent delivery row
     * is the filter working rather than nothing having happened.
     */
    public function test_a_notification_to_a_person_records_no_row(): void
    {
        Mail::fake();

        $team = $this->makeTeam();
        $user = User::factory()->create();

        Notification::send($user, new IncidentOpened($this->makeIncident($team)));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    /**
     * No column of a recorded row carries any part of a channel target.
     *
     * The failure path is the one worth asserting on: it is where a URL has a
     * route into the record, through the exception the channel rethrows and the
     * vendor code the result carries. The assertion runs over the RAW database
     * row rather than the model, so a column the model happens not to expose is
     * covered too, and it names the path segment and the query value
     * separately: a redaction that drops one and keeps the other is still a
     * leak.
     */
    public function test_no_column_of_a_recorded_row_carries_the_channel_url(): void
    {
        $this->failTheTransport();

        $team = $this->makeTeam();
        $channel = $this->webhookChannel($team);

        $this->sendAndSwallow($channel, $team);

        $stored = json_encode(DB::table('notification_deliveries')->get(), JSON_THROW_ON_ERROR);

        foreach ([
            self::CHANNEL_URL,
            'tenant-path-secret',
            'sig=channel-sig',
            'example.com',
            'https://ntfy.sh/secret-topic?sig=abc',
            'secret-topic',
            'sig=abc',
        ] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $stored,
                "The delivery row leaked '{$secret}'.",
            );
        }
    }

    /** A named incident notification records its lifecycle word, not a test. */
    public function test_an_incident_opened_send_records_the_opened_lifecycle_event(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $team = $this->makeTeam();

        $this->webhookChannel($team)->notifyNow(new IncidentOpened($this->makeIncident($team)));

        $row = $this->soleDelivery();

        $this->assertSame('opened', $row->event);
        $this->assertFalse($row->is_test);
        $this->assertSame(IncidentOpened::class, $row->notification_type);
    }

    /**
     * The test-send endpoint records a row marked as a test.
     *
     * `is_test` is the discriminator that keeps a diagnostic ping out of any
     * future SLA read, and the endpoint sends synchronously, so it writes a row
     * like any other send.
     */
    public function test_a_test_send_through_the_endpoint_records_a_test_row(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $team = $this->makeTeam();
        $user = User::factory()->create();
        $user->forceFill(['current_team_id' => $team->id])->save();
        Sanctum::actingAs($user);

        $channel = $this->webhookChannel($team);

        $this->postJson("/api/v1/notification-channels/{$channel->id}/test")
            ->assertStatus(200)
            ->assertJsonPath('data.delivered', true);

        $row = $this->soleDelivery();

        $this->assertSame('test', $row->event);
        $this->assertTrue($row->is_test);
        $this->assertSame(ChannelDeliveryResult::OUTCOME_DELIVERED, $row->outcome);

        // The endpoint's notification is an ANONYMOUS class, which PHP names
        // `Parent@anonymous` + a NUL byte + the declaring file and line. The
        // NUL is not storable on PostgreSQL, so the listener keeps the stable
        // half; a raw write would pass on SQLite and fail on the box.
        $this->assertStringNotContainsString("\0", $row->notification_type);
        $this->assertStringNotContainsString('NotificationChannelController', $row->notification_type);
    }

    /**
     * The sole delivery row, asserting there is exactly one of them: a listener
     * registered on both events could otherwise double-write a single send.
     */
    private function soleDelivery(): NotificationDelivery
    {
        $this->assertDatabaseCount('notification_deliveries', 1);

        return NotificationDelivery::query()->sole();
    }

    /**
     * Make every outbound call fail the way a real connect failure does.
     */
    private function failTheTransport(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException(self::TRANSPORT_MESSAGE);
        });
    }

    /**
     * Drive a send that is expected to throw, and swallow only that throw.
     *
     * The channel rethrows a transport failure on purpose (propagation is what
     * buys the queued notification its retry), and `NotificationSender`
     * dispatches `NotificationFailed` before rethrowing it in turn. The test is
     * about the row that dispatch writes, so the throw is caught here and its
     * absence is a failure: a swallowed transport error would mean the row came
     * from the wrong seam.
     */
    private function sendAndSwallow(NotificationChannel $channel, Team $team): void
    {
        try {
            $channel->notifyNow(new IncidentOpened($this->makeIncident($team)));
        } catch (Throwable) {
            return;
        }

        $this->fail('The transport failure did not propagate, so NotificationFailed never fired.');
    }

    /**
     * A team owned by a fresh user.
     */
    private function makeTeam(): Team
    {
        return Team::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);
    }

    /**
     * An enabled webhook channel pointing at the secret-bearing tenant target.
     */
    private function webhookChannel(Team $team): NotificationChannel
    {
        return NotificationChannel::factory()->webhook()->create([
            'team_id' => $team->id,
            'credentials' => [
                'url' => self::CHANNEL_URL,
                'secret' => 'webhook-signing-secret',
            ],
        ]);
    }

    /**
     * A persisted incident with a primary monitor on the given team.
     */
    private function makeIncident(Team $team): Incident
    {
        $monitor = Monitor::create([
            'team_id' => $team->id,
            'name' => 'API Health',
            'type' => 'http',
            'url' => 'https://uptizm.test/health',
            'method' => 'get',
            'check_interval_sec' => 60,
            'timeout_sec' => 30,
            'regions' => ['us-east'],
            'expected_status_code' => 200,
            'status' => 'active',
            'next_check_at' => now(),
        ]);

        return Incident::create([
            'team_id' => $team->id,
            'primary_monitor_id' => $monitor->id,
            'title' => 'API Health is down',
            'impact' => 'critical',
            'severity' => 'critical',
            'signal_source' => 'user_threshold',
            'lifecycle' => 'detected',
            'started_at' => now(),
        ]);
    }
}
