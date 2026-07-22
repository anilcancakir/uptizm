<?php

namespace Tests\Feature\Notifications;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Support\Monitoring\RelaySigner;
use FlutterSdk\MagicStarter\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers the notifiable-type branching added to {@see IncidentOpened} and
 * {@see IncidentResolved}: a team-scoped {@see NotificationChannel} routes
 * through the matching custom channel class (or nothing, when its credential is
 * empty), while a {@see User} keeps its person-channel set unchanged.
 */
class ChannelDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_slack_channel_notifiable_resolves_the_slack_channel(): void
    {
        $incident = $this->makeIncident();
        $channel = $this->slackChannel($incident, 'xoxb-team-token');

        $this->assertSame(
            [SlackChannel::class],
            (new IncidentOpened($incident))->via($channel),
        );
    }

    public function test_a_webhook_channel_notifiable_resolves_the_webhook_channel(): void
    {
        $incident = $this->makeIncident();
        $channel = $this->webhookChannel($incident, 'https://example.com/hook');

        $this->assertSame(
            [WebhookChannel::class],
            (new IncidentOpened($incident))->via($channel),
        );
    }

    public function test_incident_resolved_also_branches_to_the_channel_class(): void
    {
        $incident = $this->makeIncident();
        $channel = $this->slackChannel($incident, 'xoxb-team-token');

        $this->assertSame(
            [SlackChannel::class],
            (new IncidentResolved($incident))->via($channel),
        );
    }

    public function test_a_user_notifiable_still_resolves_person_channels(): void
    {
        config(['magic-starter.onesignal.app_id' => 'test-app-id']);

        $incident = $this->makeIncident();
        $user = User::factory()->create();

        // No regression: the person path stays mail/database/onesignal.
        $this->assertSame(
            ['mail', 'database', 'onesignal'],
            (new IncidentOpened($incident))->via($user),
        );
    }

    public function test_an_empty_credential_channel_yields_an_empty_via_and_sends_nothing(): void
    {
        Http::fake();

        $incident = $this->makeIncident();
        $channel = $this->slackChannel($incident, '');

        $this->assertSame([], (new IncidentOpened($incident))->via($channel));

        Notification::send($channel, new IncidentOpened($incident));

        Http::assertNothingSent();
    }

    public function test_a_slack_channel_notifiable_actually_posts_to_slack(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        $incident = $this->makeIncident();
        $channel = $this->slackChannel($incident, 'xoxb-team-token', '#alerts');

        Notification::send($channel, new IncidentOpened($incident));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request->hasHeader('Authorization', 'Bearer xoxb-team-token')
                && $request['channel'] === '#alerts';
        });
    }

    public function test_a_webhook_channel_notifiable_actually_posts_a_signed_payload(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $incident = $this->makeIncident();
        $secret = 'webhook-signing-secret';
        $channel = $this->webhookChannel($incident, 'https://example.com/hook', $secret);

        Notification::send($channel, new IncidentOpened($incident));

        Http::assertSent(function (Request $request) use ($secret): bool {
            $signature = $request->header('X-Uptizm-Signature')[0] ?? '';
            $timestamp = (int) ($request->header('X-Uptizm-Timestamp')[0] ?? 0);

            return str_starts_with($request->url(), 'https://example.com/hook')
                && $signature !== ''
                && (new RelaySigner($secret))->verify($timestamp, $request->body(), $signature);
        });
    }

    /**
     * Persist a Slack channel on the incident's team with an explicit token.
     */
    private function slackChannel(Incident $incident, ?string $token, ?string $channel = '#alerts'): NotificationChannel
    {
        return NotificationChannel::factory()->slack()->create([
            'team_id' => $incident->team_id,
            'credentials' => [
                'token' => $token,
                'channel' => $channel,
            ],
        ]);
    }

    /**
     * Persist a webhook channel on the incident's team with an explicit target.
     */
    private function webhookChannel(Incident $incident, string $url, string $secret = 'secret'): NotificationChannel
    {
        return NotificationChannel::factory()->webhook()->create([
            'team_id' => $incident->team_id,
            'credentials' => [
                'url' => $url,
                'secret' => $secret,
            ],
        ]);
    }

    /**
     * Build a persisted incident with a primary monitor for a fresh team.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeIncident(array $overrides = []): Incident
    {
        $owner = User::factory()->create();

        $team = Team::create([
            'user_id' => $owner->id,
            'name' => 'Acme Ops',
            'personal_team' => true,
        ]);

        $monitor = Monitor::create([
            'team_id' => $team->id,
            'name' => 'API Health',
            'type' => 'http',
            'url' => 'https://example.com/health',
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
            ...$overrides,
        ]);
    }
}
