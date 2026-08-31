<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\PagerDutyChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Support\Monitoring\HostGuard;
use App\Support\Sentry\SentryScrubber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Locks the one thing a transport failure may say about a channel target: its
 * HOST.
 *
 * Guzzle appends the full effective URI to every cURL error message and
 * `Psr7\Utils::redactUserInfo()` strips only the `user:pass@` component, so the
 * path and the query survive into the message Laravel wraps as
 * {@see ConnectionException}. That message then reaches `failed_jobs.exception`
 * and Sentry, and `SentryScrubber`'s key matching cannot reach a secret sitting
 * inside a URL. For two of these four channels the URL IS the credential: an
 * ntfy topic lives in the path, a Teams Workflows SAS in the `?sig=` query.
 *
 * This file covers the EXCEPTION route only, which is ONE of three. The url also
 * reaches Sentry through `sentry-laravel`'s HTTP-client breadcrumb and through
 * the `http.client` span on a sampled transaction, neither of which needs a
 * failure to fire. Both are closed in {@see SentryScrubber} and asserted by
 * `SentryScrubberTest::test_it_reduces_an_http_breadcrumb_url_to_its_origin` and
 * `::test_it_reduces_an_http_client_span_url_to_its_origin`. No one of the three
 * tests means the credential is contained; {@see WebhookChannel} enumerates the
 * routes.
 *
 * Each case therefore asserts on the WHOLE exception chain, not on the top
 * message alone: chaining the original transport error as `$previous` would
 * read as a careful rethrow while handing the renderer the same leaking string.
 *
 * The rethrow itself is asserted too. Reporting instead would turn a transport
 * failure into silence: propagation out of `send()` is the only thing that
 * reaches `NotificationFailed`, which is what records the failed delivery row,
 * and the only thing that leaves a `failed_jobs` entry. It does not buy a
 * retry on this deployment; {@see WebhookChannel} carries that measurement.
 */
class ChannelUrlLeakTest extends TestCase
{
    /**
     * A tenant target whose path segment and query value are both secrets.
     */
    private const string LEAKING_URL = 'https://ntfy.sh/secret-topic?sig=abc';

    /**
     * The shape Guzzle produces: a cURL diagnostic with the URI appended.
     */
    private const string TRANSPORT_MESSAGE = 'cURL error 7: Failed to connect to ntfy.sh port 443: Connection refused '
        .'(see https://curl.se/libcurl/c/libcurl-errors.html) for '.self::LEAKING_URL;

    /** The webhook channel names the host and drops the tenant URL. */
    public function test_the_webhook_channel_rethrows_a_transport_failure_without_the_url(): void
    {
        $this->failTheTransport();

        $thrown = $this->captureSendFailure(fn () => (new WebhookChannel($this->pinnedGuard()))->send(
            $this->webhookNotifiable(),
            $this->webhookNotification(),
        ));

        $this->assertRethrownWithHostOnly($thrown, 'ntfy.sh');
    }

    /** The Teams channel names the host and drops the SAS-bearing URL. */
    public function test_the_teams_channel_rethrows_a_transport_failure_without_the_url(): void
    {
        $this->failTheTransport();

        $thrown = $this->captureSendFailure(fn () => (new TeamsChannel($this->pinnedGuard()))->send(
            $this->teamsNotifiable(),
            $this->teamsNotification(),
        ));

        $this->assertRethrownWithHostOnly($thrown, 'ntfy.sh');
    }

    /**
     * The Slack channel names its own fixed host and drops the raw message.
     *
     * Slack's endpoint is a compile-time constant, so its URI carries no
     * secret. The case is here for the contract rather than for a leak: a
     * transport message is never passed through, whichever channel produced it.
     */
    public function test_the_slack_channel_rethrows_a_transport_failure_without_the_raw_message(): void
    {
        $this->failTheTransport();

        $thrown = $this->captureSendFailure(fn () => (new SlackChannel)->send(
            $this->slackNotifiable(),
            $this->slackNotification(),
        ));

        $this->assertRethrownWithHostOnly($thrown, 'slack.com');
    }

    /** The PagerDuty channel names its own fixed host and drops the raw message. */
    public function test_the_pagerduty_channel_rethrows_a_transport_failure_without_the_raw_message(): void
    {
        $this->failTheTransport();

        $thrown = $this->captureSendFailure(fn () => (new PagerDutyChannel)->send(
            $this->pagerDutyNotifiable(),
            $this->pagerDutyNotification(),
        ));

        $this->assertRethrownWithHostOnly($thrown, 'events.pagerduty.com');
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
     * Run a send and hand back what it threw, failing the test when it threw
     * nothing: a swallowed transport error is the other half of this contract.
     */
    private function captureSendFailure(callable $send): Throwable
    {
        try {
            $send();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        $this->fail('The channel swallowed the transport failure instead of rethrowing it.');
    }

    /**
     * Assert the rethrow carries the host and nothing else from the target.
     */
    private function assertRethrownWithHostOnly(Throwable $thrown, string $expectedHost): void
    {
        $chain = $this->renderChain($thrown);

        // The path segment and the query value are asserted separately: a
        // redaction that strips one and keeps the other is still a leak.
        $this->assertStringNotContainsString('secret-topic', $chain, 'The URL path leaked out of send().');
        $this->assertStringNotContainsString('sig=abc', $chain, 'The URL query leaked out of send().');

        $this->assertInstanceOf(
            RuntimeException::class,
            $thrown,
            'The transport error must be replaced, not propagated as it arrived.',
        );

        $this->assertStringContainsString(
            $expectedHost,
            $thrown->getMessage(),
            'The rethrown failure must still name the host it failed to reach.',
        );
    }

    /**
     * Flatten an exception and every `$previous` into one string.
     *
     * The renderer behind `failed_jobs.exception` and Sentry walks the chain,
     * so a leak hidden one link down is a leak.
     */
    private function renderChain(Throwable $thrown): string
    {
        $messages = [];

        for ($exception = $thrown; $exception !== null; $exception = $exception->getPrevious()) {
            $messages[] = $exception::class.': '.$exception->getMessage();
        }

        return implode("\n", $messages);
    }

    /**
     * A guard with its DNS pinned to a public address.
     *
     * `resolveHostIps()` is HostGuard's only network call; overriding it keeps
     * the rest of the policy (https, no port, no credentials, no internal
     * address) running for real while removing a third party's DNS records
     * from a test about what an exception message carries.
     */
    private function pinnedGuard(): HostGuard
    {
        return new class extends HostGuard
        {
            /**
             * @return list<string>
             */
            protected function resolveHostIps(string $host): array
            {
                return ['203.0.113.10'];
            }
        };
    }

    /**
     * A notifiable exposing the leaking webhook target.
     */
    private function webhookNotifiable(): object
    {
        return new class
        {
            /**
             * @return array{url: string, secret: string}
             */
            public function routeNotificationForWebhook(): array
            {
                return [
                    'url' => ChannelUrlLeakTest::leakingUrl(),
                    'secret' => 'webhook-signing-secret',
                ];
            }
        };
    }

    /**
     * A notifiable exposing the leaking Teams target.
     */
    private function teamsNotifiable(): object
    {
        return new class
        {
            /**
             * @return array{url: string}
             */
            public function routeNotificationForTeams(): array
            {
                return [
                    'url' => ChannelUrlLeakTest::leakingUrl(),
                ];
            }
        };
    }

    /**
     * A notifiable exposing a Slack bot token.
     */
    private function slackNotifiable(): object
    {
        return new class
        {
            /**
             * @return array{token: string, channel: string}
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
     * A notifiable exposing a PagerDuty routing key.
     */
    private function pagerDutyNotifiable(): object
    {
        return new class
        {
            /**
             * @return array{routing_key: string}
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
     * The webhook payload builder the channel looks for.
     */
    private function webhookNotification(): Notification
    {
        return new class extends Notification
        {
            /**
             * @return array<string, mixed>
             */
            public function toWebhook(object $notifiable): array
            {
                return [
                    'event' => 'incident.opened',
                ];
            }
        };
    }

    /**
     * The Adaptive Card builder the Teams channel looks for.
     */
    private function teamsNotification(): Notification
    {
        return new class extends Notification
        {
            /**
             * @return array<string, mixed>
             */
            public function toTeams(object $notifiable): array
            {
                return [
                    'type' => 'AdaptiveCard',
                    'version' => '1.2',
                    'body' => [],
                    'actions' => [],
                ];
            }
        };
    }

    /**
     * The Slack payload builder the channel looks for.
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
     * The PagerDuty event builder the channel looks for.
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
                    'dedup_key' => 'uptizm-incident-1',
                    'payload' => [
                        'summary' => 'API Health is down',
                        'source' => 'uptizm',
                        'severity' => 'critical',
                    ],
                ];
            }
        };
    }

    /**
     * The leaking target, reachable from the anonymous notifiable stubs.
     */
    public static function leakingUrl(): string
    {
        return self::LEAKING_URL;
    }
}
