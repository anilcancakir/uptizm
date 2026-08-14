<?php

namespace Tests\Unit\Support\Sentry;

use App\Support\Sentry\SentryEventThrottle;
use Illuminate\Support\Facades\Cache;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Tests\TestCase;

/**
 * Locks the only defence this deployment has against an error flood.
 *
 * Sentry's own per-key rate limit is a Business-plan feature and this org is on
 * Team, so the platform offers nothing: the API accepts a `rateLimit` field and
 * silently ignores it. That leaves the arithmetic unguarded, and the arithmetic
 * is bad. A relay outage fails every check job, `PerformMonitorCheck` retries
 * three times, and at ~1000 jobs a minute that is ~3000 events a minute against
 * a 50,000 per MONTH allowance with no overage budget. Twenty minutes of one
 * outage would spend the rest of the month's visibility, during exactly the
 * incident class this product exists to detect.
 *
 * The events it drops carry no information that the first one did not. Sentry
 * groups them into a single issue anyway; it just bills for each.
 */
class SentryEventThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * The first occurrence always gets through. Anything else would mean an
     * error that happens once is an error nobody hears about.
     */
    public function test_the_first_occurrence_is_allowed(): void
    {
        $this->assertTrue(SentryEventThrottle::allows($this->exceptionEvent('RuntimeException', 'relay unreachable')));
    }

    /**
     * The repeat is what the flood is made of.
     */
    public function test_an_identical_repeat_is_dropped(): void
    {
        $first = $this->exceptionEvent('RuntimeException', 'relay unreachable');
        $second = $this->exceptionEvent('RuntimeException', 'relay unreachable');

        $this->assertTrue(SentryEventThrottle::allows($first));
        $this->assertFalse(SentryEventThrottle::allows($second));
    }

    /**
     * A different fault is a different signal, and must not be suppressed by a
     * noisy neighbour.
     */
    public function test_a_different_exception_is_allowed_through(): void
    {
        SentryEventThrottle::allows($this->exceptionEvent('RuntimeException', 'relay unreachable'));

        $this->assertTrue(
            SentryEventThrottle::allows($this->exceptionEvent('LogicException', 'something else entirely')),
        );
    }

    /**
     * Same class, different message: still a different fault.
     */
    public function test_the_message_is_part_of_the_identity(): void
    {
        SentryEventThrottle::allows($this->exceptionEvent('RuntimeException', 'region eu-west refused'));

        $this->assertTrue(
            SentryEventThrottle::allows($this->exceptionEvent('RuntimeException', 'region us-east refused')),
        );
    }

    /**
     * FAIL-OPEN, and this is the important one.
     *
     * The throttle exists to protect a quota, not to police correctness. If the
     * cache is unreachable the honest failure is to report too much, because
     * the alternative is an observability layer that goes silent exactly when
     * infrastructure is already breaking, which is when it is needed most.
     */
    public function test_it_allows_the_event_when_the_cache_is_unavailable(): void
    {
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('redis is gone'));

        $this->assertTrue(SentryEventThrottle::allows($this->exceptionEvent('RuntimeException', 'anything')));
    }

    /**
     * An event with no exception (a `captureMessage`) still needs an identity,
     * or every message would collide into one bucket and only the first would
     * ever be reported.
     */
    public function test_a_message_event_is_identified_by_its_message(): void
    {
        $first = Event::createEvent();
        $first->setMessage('scheduled digest skipped');

        $second = Event::createEvent();
        $second->setMessage('a completely different message');

        $this->assertTrue(SentryEventThrottle::allows($first));
        $this->assertTrue(SentryEventThrottle::allows($second));
        $this->assertFalse(SentryEventThrottle::allows($first));
    }

    /**
     * Build an event carrying one exception, the way the SDK does.
     */
    private function exceptionEvent(string $type, string $value): Event
    {
        $event = Event::createEvent();
        $event->setExceptions([
            new ExceptionDataBag(
                new \RuntimeException($value),
                null,
                new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, true),
            ),
        ]);
        $event->setMessage($type.': '.$value);

        return $event;
    }
}
