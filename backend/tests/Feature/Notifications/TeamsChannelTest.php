<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\TeamsChannel;
use App\Support\Monitoring\HostGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the hand-rolled Microsoft Teams channel: it POSTs the Adaptive Card
 * message envelope to a tenant-configured Workflows webhook, re-validates that
 * URL through the SSRF guard at send time (an internal/loopback/metadata or
 * non-https target is skipped with no POST, closing the DNS-rebinding window
 * the store-time gate alone leaves open), and reports a non-2xx answer as a
 * delivery failure without poisoning the queue. The `?sig=` SAS the URL
 * carries is never logged or reported.
 */
class TeamsChannelTest extends TestCase
{
    /** An allowed https target receives one Adaptive Card message envelope. */
    public function test_it_posts_the_adaptive_card_envelope_to_an_allowed_target(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $this->sendTo('https://example.com/webhookb2/abc?sig=super-secret-sas');

        Http::assertSent(function (Request $request): bool {
            $attachment = $request['attachments'][0] ?? [];

            return str_starts_with($request->url(), 'https://example.com/webhookb2/abc')
                && $request['type'] === 'message'
                && $attachment['contentType'] === 'application/vnd.microsoft.card.adaptive'
                && $attachment['content']['type'] === 'AdaptiveCard'
                && $attachment['content']['version'] === '1.2'
                && is_array($attachment['content']['body'])
                && is_array($attachment['content']['actions']);
        });
    }

    /** A loopback target is rejected at send time with no POST issued. */
    public function test_it_skips_a_loopback_target_without_posting(): void
    {
        Http::fake();

        $this->sendTo('https://127.0.0.1/webhook');

        Http::assertNothingSent();
    }

    /** The cloud-metadata address is rejected with no POST issued. */
    public function test_it_skips_a_metadata_target_without_posting(): void
    {
        Http::fake();

        $this->sendTo('https://169.254.169.254/webhook');

        Http::assertNothingSent();
    }

    /** A non-https scheme is rejected with no POST issued. */
    public function test_it_rejects_a_non_https_scheme(): void
    {
        Http::fake();

        $this->sendTo('http://example.com/webhook');

        Http::assertNothingSent();
    }

    /**
     * A 3xx redirect answer is a non-delivery: it is reported (host + status
     * only, never the `?sig=` secret), so the test-send path reads it as
     * failure and the pinned connection refuses to follow it.
     */
    public function test_it_reports_a_3xx_response_as_a_delivery_failure(): void
    {
        Exceptions::fake();

        Http::fake([
            'example.com/*' => Http::response('', 301),
        ]);

        $this->sendTo('https://example.com/webhookb2/abc?sig=super-secret-sas');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://example.com/webhookb2/abc'));

        Exceptions::assertReported(function (RuntimeException $exception): bool {
            return str_contains($exception->getMessage(), '301')
                && ! str_contains($exception->getMessage(), 'super-secret-sas');
        });
    }

    /**
     * A 5xx answer is reported as a delivery failure without rethrowing into
     * the queue, and the report never carries the SAS secret.
     */
    public function test_it_reports_a_non_2xx_response_without_throwing(): void
    {
        Exceptions::fake();

        Http::fake([
            'example.com/*' => Http::response('', 500),
        ]);

        $this->sendTo('https://example.com/webhookb2/abc?sig=super-secret-sas');

        Exceptions::assertReported(function (RuntimeException $exception): bool {
            return str_contains($exception->getMessage(), '500')
                && ! str_contains($exception->getMessage(), 'super-secret-sas');
        });
    }

    /**
     * An SSRF-blocked target is reported as a delivery failure (without the
     * SAS) and never POSTed, so the test-send path reads it as failure.
     */
    public function test_it_reports_an_ssrf_blocked_target_without_posting(): void
    {
        Exceptions::fake();
        Http::fake();

        $this->sendTo('https://169.254.169.254/webhook?sig=super-secret-sas');

        Http::assertNothingSent();

        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => ! str_contains(
                $exception->getMessage(),
                'super-secret-sas',
            ),
        );
    }

    /**
     * A route missing its url is a non-delivery: it is reported and no POST is
     * issued, so an empty-credential channel fails the test-send.
     */
    public function test_it_reports_an_empty_url_without_posting(): void
    {
        Exceptions::fake();
        Http::fake();

        $this->sendTo('');

        Http::assertNothingSent();

        Exceptions::assertReported(fn (RuntimeException $exception): bool => true);
    }

    /**
     * Drive the channel with a stub notifiable + notification.
     *
     * The stub notifiable exposes `routeNotificationForTeams()` (url) and the
     * stub notification a `toTeams()` Adaptive Card, so the channel is
     * exercised without depending on the incident-notification wiring.
     */
    private function sendTo(string $url): void
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
