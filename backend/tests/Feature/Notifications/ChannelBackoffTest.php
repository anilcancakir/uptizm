<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\PagerDutyChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Support\Monitoring\HostGuard;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the 429/Retry-After-aware backoff shared by all four team channels
 * (Slack, Webhook, PagerDuty, Teams). On a 429 each channel honors the
 * provider's `Retry-After` hint (delay-seconds or an HTTP-date, bounded by a
 * hard cap) with a single retry before succeeding, or reports an exhausted
 * budget honestly without throwing into the queue. The happy path is
 * unchanged: a first-try success neither retries nor sleeps.
 */
class ChannelBackoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Record sleeps for assertions instead of blocking the test thread.
        Sleep::fake();
    }

    /** Slack honors a numeric Retry-After, retries once, and succeeds. */
    public function test_slack_honors_retry_after_then_succeeds_on_retry(): void
    {
        Exceptions::fake();

        Http::fake([
            'slack.com/*' => Http::sequence()
                ->push(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '1'])
                ->push(['ok' => true], 200),
        ]);

        (new SlackChannel)->send($this->slackNotifiable(), $this->slackNotification());

        Http::assertSentCount(2);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 1, 1);
        Exceptions::assertNothingReported();
    }

    /** An exhausted retry budget on Slack is reported, never rethrown, without the token. */
    public function test_slack_reports_when_the_retry_budget_is_exhausted(): void
    {
        Exceptions::fake();

        Http::fake([
            'slack.com/*' => Http::sequence()
                ->push(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '1'])
                ->push(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '1']),
        ]);

        (new SlackChannel)->send($this->slackNotifiable(), $this->slackNotification());

        Http::assertSentCount(2);
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => ! str_contains($exception->getMessage(), 'xoxb-team-token'),
        );
    }

    /** A first-try success sends once, never sleeps, and is not reported. */
    public function test_slack_happy_path_sends_once_without_backoff(): void
    {
        Exceptions::fake();

        Http::fake([
            'slack.com/*' => Http::response(['ok' => true], 200),
        ]);

        (new SlackChannel)->send($this->slackNotifiable(), $this->slackNotification());

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Exceptions::assertNothingReported();
    }

    /** The webhook rebuilds its signed, IP-pinned request on the retry. */
    public function test_webhook_honors_retry_after_and_preserves_the_signature_on_retry(): void
    {
        Exceptions::fake();

        Http::fake([
            'example.com/*' => Http::sequence()
                ->push('', 429, ['Retry-After' => '1'])
                ->push('', 200),
        ]);

        $this->sendWebhook('https://example.com/hook', 'super-secret-signing-value');

        Http::assertSentCount(2);

        // Both attempts carry the HMAC signature header, proving the full
        // request (and its IP-pin + no-redirect options) is rebuilt on retry.
        $signed = Http::recorded(fn (Request $request): bool => $request->hasHeader('X-Uptizm-Signature'));
        $this->assertCount(2, $signed);

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 1, 1);
        Exceptions::assertNothingReported();
    }

    /** PagerDuty has no documented Retry-After, so a 429 uses the small default backoff. */
    public function test_pagerduty_uses_the_default_backoff_when_no_retry_after_then_succeeds(): void
    {
        Exceptions::fake();

        Http::fake([
            'events.pagerduty.com/*' => Http::sequence()
                ->push(['status' => 'throttled'], 429)
                ->push(['status' => 'success'], 202),
        ]);

        (new PagerDutyChannel)->send($this->pagerDutyNotifiable(), $this->pagerDutyNotification());

        Http::assertSentCount(2);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 1, 1);
        Exceptions::assertNothingReported();
    }

    /** Teams (no documented Retry-After) retries a 429 once and succeeds. */
    public function test_teams_retries_on_429_and_succeeds(): void
    {
        Exceptions::fake();

        Http::fake([
            'example.com/*' => Http::sequence()
                ->push('', 429)
                ->push('', 200),
        ]);

        $this->sendTeams('https://example.com/webhookb2/abc?sig=super-secret-sas');

        Http::assertSentCount(2);
        Exceptions::assertNothingReported();
    }

    /** The HTTP-date Retry-After form is parsed into a bounded backoff. */
    public function test_it_parses_an_http_date_retry_after(): void
    {
        Exceptions::fake();

        $retryAt = gmdate('D, d M Y H:i:s', time() + 3).' GMT';

        Http::fake([
            'slack.com/*' => Http::sequence()
                ->push(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => $retryAt])
                ->push(['ok' => true], 200),
        ]);

        (new SlackChannel)->send($this->slackNotifiable(), $this->slackNotification());

        Http::assertSentCount(2);
        Sleep::assertSlept(
            fn (CarbonInterval $duration): bool => $duration->totalSeconds >= 1 && $duration->totalSeconds <= 5,
            1,
        );
        Exceptions::assertNothingReported();
    }

    /** An excessive Retry-After is clamped to the hard cap so the thread never stalls. */
    public function test_it_caps_an_excessive_retry_after(): void
    {
        Exceptions::fake();

        Http::fake([
            'slack.com/*' => Http::sequence()
                ->push(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '3600'])
                ->push(['ok' => true], 200),
        ]);

        (new SlackChannel)->send($this->slackNotifiable(), $this->slackNotification());

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 5, 1);
        Exceptions::assertNothingReported();
    }

    /**
     * Build an on-demand notifiable exposing a per-team Slack route.
     */
    private function slackNotifiable(): object
    {
        return new class
        {
            /**
             * @return array<string, string>
             */
            public function routeNotificationForSlack(): array
            {
                return [
                    'token' => 'xoxb-team-token',
                    'channel' => '#alerts',
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

    /**
     * Build an on-demand notifiable exposing a per-team PagerDuty route.
     */
    private function pagerDutyNotifiable(): object
    {
        return new class
        {
            /**
             * @return array<string, string>
             */
            public function routeNotificationForPagerduty(): array
            {
                return [
                    'routing_key' => 'R0UT1NGK3Y0000000000000000000000',
                ];
            }
        };
    }

    /**
     * Build a notification exposing a PagerDuty trigger builder.
     */
    private function pagerDutyNotification(): Notification
    {
        return new class extends Notification
        {
            /**
             * @return array<string, mixed>
             */
            public function toPagerDuty(object $notifiable): array
            {
                return [
                    'event_action' => 'trigger',
                    'dedup_key' => 'uptizm-incident-42',
                    'payload' => [
                        'summary' => 'API Health is down',
                        'source' => 'API Health',
                        'severity' => 'critical',
                    ],
                ];
            }
        };
    }

    /**
     * Drive the webhook channel with a stub notifiable + notification.
     */
    private function sendWebhook(string $url, string $secret): void
    {
        $notifiable = new class($url, $secret)
        {
            public function __construct(
                public string $url,
                public string $secret,
            ) {}

            /**
             * @return array{url: string, secret: string}
             */
            public function routeNotificationForWebhook(): array
            {
                return [
                    'url' => $this->url,
                    'secret' => $this->secret,
                ];
            }
        };

        $notification = new class extends Notification
        {
            /**
             * @return array<string, mixed>
             */
            public function toWebhook(object $notifiable): array
            {
                return [
                    'event' => 'incident.opened',
                    'monitor' => 'API Health',
                ];
            }
        };

        (new WebhookChannel(new HostGuard))->send($notifiable, $notification);
    }

    /**
     * Drive the Teams channel with a stub notifiable + notification.
     */
    private function sendTeams(string $url): void
    {
        $notifiable = new class($url)
        {
            public function __construct(
                public string $url,
            ) {}

            /**
             * @return array{url: string}
             */
            public function routeNotificationForTeams(): array
            {
                return [
                    'url' => $this->url,
                ];
            }
        };

        $notification = new class extends Notification
        {
            /**
             * @return array<string, mixed>
             */
            public function toTeams(object $notifiable): array
            {
                return [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.2',
                    'body' => [
                        [
                            'type' => 'TextBlock',
                            'text' => 'API Health is down',
                        ],
                    ],
                    'actions' => [
                        [
                            'type' => 'Action.OpenUrl',
                            'title' => 'View incident',
                            'url' => 'https://uptizm.test/incidents/1',
                        ],
                    ],
                ];
            }
        };

        (new TeamsChannel(new HostGuard))->send($notifiable, $notification);
    }
}
