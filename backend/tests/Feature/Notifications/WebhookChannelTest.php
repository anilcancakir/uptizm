<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\WebhookChannel;
use App\Support\Monitoring\HostGuard;
use App\Support\Monitoring\RelaySigner;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the hand-rolled webhook channel: an internal/loopback/metadata target
 * or a non-https URL is skipped with no POST, while an allowed https target
 * receives a single POST carrying a verifiable HMAC-SHA256 signature over
 * "{timestamp}.{body}".
 */
class WebhookChannelTest extends TestCase
{
    /** A loopback target is rejected at send time with no POST issued. */
    public function test_it_skips_a_loopback_target_without_posting(): void
    {
        Http::fake();

        $this->sendTo('https://127.0.0.1/hook');

        Http::assertNothingSent();
    }

    /** The cloud-metadata address is rejected with no POST issued. */
    public function test_it_skips_a_metadata_target_without_posting(): void
    {
        Http::fake();

        $this->sendTo('https://169.254.169.254/hook');

        Http::assertNothingSent();
    }

    /** An RFC1918 private-range target is rejected with no POST issued. */
    public function test_it_skips_a_private_range_target_without_posting(): void
    {
        Http::fake();

        $this->sendTo('https://10.0.0.5/hook');

        Http::assertNothingSent();
    }

    /** A non-https scheme is rejected with no POST issued. */
    public function test_it_rejects_a_non_https_scheme(): void
    {
        Http::fake();

        $this->sendTo('http://example.com/hook');

        Http::assertNothingSent();
    }

    /** An allowed https target receives one signed POST. */
    public function test_it_posts_a_signed_payload_to_an_allowed_https_target(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $secret = 'super-secret-signing-value';
        $this->sendTo('https://example.com/webhook', $secret);

        Http::assertSent(function (Request $request) use ($secret): bool {
            $signature = $request->header('X-Uptizm-Signature')[0] ?? '';
            $timestamp = (int) ($request->header('X-Uptizm-Timestamp')[0] ?? 0);

            return $request->url() === 'https://example.com/webhook'
                && $signature !== ''
                && (new RelaySigner($secret))->verify($timestamp, $request->body(), $signature);
        });
    }

    /**
     * A 3xx redirect answer is a non-delivery: it is reported (with the host
     * and status, never the secret), so the test-send path reads it as failure.
     */
    public function test_it_reports_a_3xx_response_as_a_delivery_failure(): void
    {
        Exceptions::fake();

        Http::fake([
            'example.com/*' => Http::response('', 301),
        ]);

        $this->sendTo('https://example.com/webhook', 'super-secret-signing-value');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/webhook');

        Exceptions::assertReported(function (RuntimeException $exception): bool {
            return str_contains($exception->getMessage(), '301')
                && ! str_contains($exception->getMessage(), 'super-secret-signing-value');
        });
    }

    /**
     * An SSRF-blocked target is reported as a delivery failure (without the
     * secret) and never POSTed, so the test-send path reads it as failure.
     */
    public function test_it_reports_an_ssrf_blocked_target_without_posting(): void
    {
        Exceptions::fake();
        Http::fake();

        $this->sendTo('https://169.254.169.254/hook', 'super-secret-signing-value');

        Http::assertNothingSent();

        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => ! str_contains(
                $exception->getMessage(),
                'super-secret-signing-value',
            ),
        );
    }

    /**
     * A route missing its url/secret is a non-delivery: it is reported and no
     * POST is issued, so an empty-credential channel fails the test-send.
     */
    public function test_it_reports_an_empty_credential_route_without_posting(): void
    {
        Exceptions::fake();
        Http::fake();

        // An empty secret makes the route unresolvable (a deliberate no-send).
        $this->sendTo('https://example.com/hook', '');

        Http::assertNothingSent();

        Exceptions::assertReported(fn (RuntimeException $exception): bool => true);
    }

    /**
     * Drive the channel with a stub notifiable + notification.
     *
     * The stub notifiable exposes `routeNotificationForWebhook()` (url+secret)
     * and the stub notification a `toWebhook()` payload, so the channel is
     * exercised without depending on the Step 6 model wiring.
     */
    private function sendTo(string $url, string $secret = 'secret'): void
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
}
