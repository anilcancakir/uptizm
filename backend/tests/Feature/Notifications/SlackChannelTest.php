<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\SlackChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the hand-rolled {@see SlackChannel}: it posts to Slack's
 * chat.postMessage with the per-team bot token, refuses to send (and never
 * falls back to the shared config token) when the team token is empty, and
 * reports a Slack `{ok:false}` answer as a failure without poisoning the queue.
 */
class SlackChannelTest extends TestCase
{
    public function test_it_posts_to_chat_post_message_with_the_team_token(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);

        $notifiable = $this->notifiableWithSlackRoute('xoxb-team-token', '#alerts');

        (new SlackChannel)->send($notifiable, $this->slackNotification());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request->hasHeader('Authorization', 'Bearer xoxb-team-token')
                && $request['channel'] === '#alerts'
                && $request['text'] === 'API Health is down';
        });
    }

    public function test_it_sends_nothing_when_the_team_token_is_empty(): void
    {
        Http::fake();

        $notifiable = $this->notifiableWithSlackRoute('', '#alerts');

        (new SlackChannel)->send($notifiable, $this->slackNotification());

        Http::assertNothingSent();
    }

    public function test_it_never_falls_back_to_the_shared_config_bot_token(): void
    {
        config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-shared-config-token']);
        config(['services.slack.notifications.channel' => '#shared']);

        Http::fake();

        $notifiable = $this->notifiableWithSlackRoute(null, null);

        (new SlackChannel)->send($notifiable, $this->slackNotification());

        Http::assertNothingSent();
    }

    public function test_a_slack_ok_false_response_is_reported_without_throwing_and_without_the_token(): void
    {
        Exceptions::fake();

        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found']),
        ]);

        $notifiable = $this->notifiableWithSlackRoute('xoxb-team-token', '#missing');

        // A logical Slack failure is reported, not rethrown into the queue.
        (new SlackChannel)->send($notifiable, $this->slackNotification());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://slack.com/api/chat.postMessage');

        Exceptions::assertReported(function (RuntimeException $exception): bool {
            return str_contains($exception->getMessage(), 'channel_not_found')
                && ! str_contains($exception->getMessage(), 'xoxb-team-token');
        });
    }

    /**
     * Build an on-demand notifiable exposing a per-team Slack route.
     */
    private function notifiableWithSlackRoute(?string $token, ?string $channel): object
    {
        return new class($token, $channel)
        {
            public function __construct(
                private ?string $token,
                private ?string $channel,
            ) {}

            /**
             * @return array<string, string|null>
             */
            public function routeNotificationForSlack(): array
            {
                return [
                    'token' => $this->token,
                    'channel' => $this->channel,
                ];
            }
        };
    }

    /**
     * Build a notification exposing a Slack payload builder.
     */
    private function slackNotification(): Notification
    {
        return new class extends Notification
        {
            /**
             * @return array<string, mixed>
             */
            public function toSlack(object $notifiable): array
            {
                return [
                    'text' => 'API Health is down',
                ];
            }
        };
    }
}
