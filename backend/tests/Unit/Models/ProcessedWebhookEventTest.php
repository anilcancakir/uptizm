<?php

namespace Tests\Unit\Models;

use App\Models\ProcessedWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the idempotency guard the Stripe webhook (S16) will rely on: a unique
 * `event_id` at the DB level, and the `recordIfNew` insert-then-handle helper
 * that turns a re-delivered event into a no-op instead of a duplicate row.
 */
class ProcessedWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_event_id_violates_the_unique_constraint(): void
    {
        ProcessedWebhookEvent::query()->create([
            'event_id' => 'evt_123',
            'type' => 'customer.subscription.created',
            'processed_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        ProcessedWebhookEvent::query()->create([
            'event_id' => 'evt_123',
            'type' => 'invoice.payment_succeeded',
            'processed_at' => now(),
        ]);
    }

    public function test_record_if_new_returns_true_on_first_call_and_false_on_replay(): void
    {
        $first = ProcessedWebhookEvent::recordIfNew('evt_456', 'customer.subscription.updated');
        $second = ProcessedWebhookEvent::recordIfNew('evt_456', 'customer.subscription.updated');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, ProcessedWebhookEvent::query()->where('event_id', 'evt_456')->count());
    }
}
