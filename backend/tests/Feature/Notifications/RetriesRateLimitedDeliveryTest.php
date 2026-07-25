<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\Concerns\RetriesRateLimitedDelivery;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Support\Monitoring\HostGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the SSRF half of the shared {@see RetriesRateLimitedDelivery} concern
 * for the two channels whose target is tenant-controlled (webhook, Teams).
 *
 * The host is resolved and validated exactly ONCE, before the first attempt,
 * and the resulting IP set is captured by the send closure. A 429 retry
 * re-invokes that closure, so the same pre-validated addresses are re-pinned
 * with redirects still disabled. Resolving again inside the retry would reopen
 * the DNS-rebinding window the pin exists to close, so the resolution count is
 * asserted, not just the pinned value.
 *
 * {@see ChannelBackoffTest} covers the retry budget, the Retry-After parsing,
 * and the clamp for all four channels.
 */
class RetriesRateLimitedDeliveryTest extends TestCase
{
    /**
     * The cURL resolve pin and redirect policy recorded per outbound attempt.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $attempts = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Record sleeps for assertions instead of blocking the test thread.
        Sleep::fake();
    }

    /** The webhook retry re-pins the first attempt's IPs without resolving again. */
    public function test_the_webhook_retry_reuses_the_pinned_ips_without_resolving_again(): void
    {
        Exceptions::fake();

        $guard = $this->countingGuard();
        $this->recordAttempts([429, 200]);

        (new WebhookChannel($guard))->send($this->webhookNotifiable(), $this->webhookNotification());

        Http::assertSentCount(2);
        $this->assertSame(1, $guard->resolutions, 'The host must be resolved once, never on the retry path.');
        $this->assertPinnedIdentically('example.com:443:93.184.216.34');
        Exceptions::assertNothingReported();
    }

    /** The Teams retry re-pins the first attempt's IPs without resolving again. */
    public function test_the_teams_retry_reuses_the_pinned_ips_without_resolving_again(): void
    {
        Exceptions::fake();

        $guard = $this->countingGuard();
        $this->recordAttempts([429, 200]);

        (new TeamsChannel($guard))->send($this->teamsNotifiable(), $this->teamsNotification());

        Http::assertSentCount(2);
        $this->assertSame(1, $guard->resolutions, 'The host must be resolved once, never on the retry path.');
        $this->assertPinnedIdentically('example.com:443:93.184.216.34');
        Exceptions::assertNothingReported();
    }

    /** An exhausted budget still resolves once, and reports instead of throwing. */
    public function test_an_exhausted_budget_neither_resolves_again_nor_throws(): void
    {
        Exceptions::fake();

        $guard = $this->countingGuard();
        $this->recordAttempts([429, 429]);

        (new WebhookChannel($guard))->send($this->webhookNotifiable(), $this->webhookNotification());

        Http::assertSentCount(2);
        $this->assertSame(1, $guard->resolutions, 'The host must be resolved once, never on the retry path.');
        $this->assertPinnedIdentically('example.com:443:93.184.216.34');

        // The surviving 429 is reported for the caller, never thrown: the queued
        // job must not re-send the already-delivered sibling channels.
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'status 429'),
        );
    }

    /**
     * Assert every recorded attempt pinned the same single IP set and refused
     * redirects, which is what proves the retry reused the validated target.
     */
    private function assertPinnedIdentically(string $expectedPin): void
    {
        $this->assertCount(2, $this->attempts);

        foreach ($this->attempts as $attempt) {
            $this->assertSame([$expectedPin], $attempt['resolve']);
            $this->assertFalse($attempt['allow_redirects']);
        }
    }

    /**
     * Fake the outbound endpoint with the given per-attempt statuses, recording
     * the cURL resolve pin and redirect policy each attempt was built with.
     *
     * @param  list<int>  $statuses  One status per expected attempt, in order.
     */
    private function recordAttempts(array $statuses): void
    {
        Http::fake(function (Request $request, array $options) use ($statuses) {
            $this->attempts[] = [
                'resolve' => $options['curl'][CURLOPT_RESOLVE] ?? null,
                'allow_redirects' => $options['allow_redirects'] ?? null,
            ];

            return Http::response('', $statuses[count($this->attempts) - 1]);
        });
    }

    /**
     * Build an SSRF guard that counts resolutions and pins a fixed public IP,
     * so the assertion is about how often the channel resolves, not about DNS.
     */
    private function countingGuard(): HostGuard
    {
        return new class extends HostGuard
        {
            /**
             * Number of send-time resolutions the channel asked for.
             */
            public int $resolutions = 0;

            /**
             * @return list<string>
             */
            public function resolveAndAssertAllowed(string $url): array
            {
                $this->resolutions++;

                return [
                    '93.184.216.34',
                ];
            }
        };
    }

    /**
     * Build a notifiable exposing a webhook route (url + signing secret).
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
                    'url' => 'https://example.com/hook',
                    'secret' => 'super-secret-signing-value',
                ];
            }
        };
    }

    /**
     * Build a notification exposing a webhook payload builder.
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
                    'monitor' => 'API Health',
                ];
            }
        };
    }

    /**
     * Build a notifiable exposing a Teams Workflows route (url only).
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
                    'url' => 'https://example.com/webhookb2/abc?sig=super-secret-sas',
                ];
            }
        };
    }

    /**
     * Build a notification exposing an Adaptive Card builder.
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
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.2',
                    'body' => [
                        [
                            'type' => 'TextBlock',
                            'text' => 'API Health is down',
                        ],
                    ],
                ];
            }
        };
    }
}
