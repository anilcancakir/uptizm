<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\PagerDutyChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the hand-rolled {@see PagerDutyChannel}: it POSTs an Events API v2
 * event to https://events.pagerduty.com/v2/enqueue with the per-team routing
 * key carried in the body, refuses to send when the routing key is empty, and
 * reports a non-202 answer (including a 429 rate-limit) as a failure without
 * poisoning the queue. The routing key is never logged or reported.
 */
class PagerDutyChannelTest extends TestCase
{
    public function test_it_posts_a_trigger_event_with_the_routing_key_in_the_body(): void
    {
        Http::fake([
            'events.pagerduty.com/v2/enqueue' => Http::response(['status' => 'success'], 202),
        ]);

        $notifiable = $this->notifiableWithRoutingKey('R0UT1NGK3Y0000000000000000000000');

        (new PagerDutyChannel)->send($notifiable, $this->triggerNotification());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://events.pagerduty.com/v2/enqueue'
                && $request['routing_key'] === 'R0UT1NGK3Y0000000000000000000000'
                && $request['event_action'] === 'trigger'
                && $request['dedup_key'] === 'uptizm-incident-42'
                && $request['payload']['severity'] === 'critical';
        });
    }

    public function test_it_sends_nothing_when_the_routing_key_is_empty(): void
    {
        Exceptions::fake();
        Http::fake();

        $notifiable = $this->notifiableWithRoutingKey('');

        (new PagerDutyChannel)->send($notifiable, $this->triggerNotification());

        Http::assertNothingSent();

        Exceptions::assertReported(fn (RuntimeException $exception): bool => true);
    }

    public function test_a_non_202_response_is_reported_without_throwing_and_without_the_key(): void
    {
        Exceptions::fake();

        Http::fake([
            'events.pagerduty.com/v2/enqueue' => Http::response(['status' => 'invalid event'], 400),
        ]);

        $notifiable = $this->notifiableWithRoutingKey('R0UT1NGK3Y0000000000000000000000');

        // A rejected event is reported, not rethrown into the queue, and the
        // report never carries the routing key.
        (new PagerDutyChannel)->send($notifiable, $this->triggerNotification());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://events.pagerduty.com/v2/enqueue');

        Exceptions::assertReported(function (RuntimeException $exception): bool {
            return str_contains($exception->getMessage(), '400')
                && ! str_contains($exception->getMessage(), 'R0UT1NGK3Y0000000000000000000000');
        });
    }

    public function test_a_429_rate_limit_is_reported_without_throwing(): void
    {
        Exceptions::fake();

        Http::fake([
            'events.pagerduty.com/v2/enqueue' => Http::response(['status' => 'throttled'], 429),
        ]);

        $notifiable = $this->notifiableWithRoutingKey('R0UT1NGK3Y0000000000000000000000');

        (new PagerDutyChannel)->send($notifiable, $this->triggerNotification());

        Exceptions::assertReported(fn (RuntimeException $exception): bool => true);
    }

    /**
     * Build an on-demand notifiable exposing a per-team PagerDuty route.
     */
    private function notifiableWithRoutingKey(?string $routingKey): object
    {
        return new class($routingKey)
        {
            public function __construct(
                private ?string $routingKey,
            ) {}

            /**
             * @return array<string, string|null>
             */
            public function routeNotificationForPagerduty(): array
            {
                return [
                    'routing_key' => $this->routingKey,
                ];
            }
        };
    }

    /**
     * Build a notification exposing a PagerDuty trigger builder.
     */
    private function triggerNotification(): Notification
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
}
