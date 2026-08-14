<?php

namespace Tests\Unit\Support\Sentry;

use App\Services\Monitoring\IncidentWriteService;
use App\Support\Sentry\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Tests\TestCase;

/**
 * Locks the last gate between this application's secrets and a third party.
 *
 * Sentry's own server-side scrubbing already redacts a field called `password`
 * or `token`, but it runs AFTER the event has crossed the network, and it knows
 * nothing about the names this codebase invented. `auth_config` is the one that
 * matters: it is a customer's own credential for their own origin, this
 * application decrypts it to probe with, and no default anywhere has heard of
 * it. So the scrub happens HERE, before the event is transmitted, and this test
 * is what says it still does.
 *
 * The matching is a case-insensitive SUBSTRING rather than an exact list, and
 * that is the load-bearing decision under test. An exact list is a list someone
 * has to remember to extend: this repo already carries `preview_token`,
 * `confirmed_token`, `unsubscribe_token`, `two_factor_secret` and
 * `remember_token`, five names that a single `token`/`secret` needle covers for
 * free, and the sixth one nobody has written yet is covered the same way. The
 * cost is a false positive on an innocent field whose name happens to contain
 * one of the needles, which costs a debugging session; the reverse mistake
 * costs a customer's credential.
 */
class SentryScrubberTest extends TestCase
{
    /**
     * The nested case, which is the realistic one.
     *
     * A model reaches an event through `extra` as a nested attribute array, not
     * as a top-level key, so a scrubber that only walked the first level would
     * pass every test written the lazy way and still ship the credential.
     */
    public function test_it_masks_a_credential_nested_inside_extra(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'monitor' => [
                'id' => 42,
                'name' => 'Checkout API',
                'auth_config' => [
                    'type' => 'bearer',
                    'token' => 'super-secret-value',
                ],
            ],
        ]);

        $scrubbed = SentryScrubber::beforeSend($event, null);

        $extra = $scrubbed->getExtra();

        $this->assertSame(SentryScrubber::MARKER, $extra['monitor']['auth_config']);
        $this->assertSame(42, $extra['monitor']['id'], 'An unrelated sibling key must survive.');
        $this->assertSame('Checkout API', $extra['monitor']['name']);
    }

    /**
     * The header case. `Authorization` carries the Sanctum bearer token of
     * whoever made the request that failed.
     */
    public function test_it_masks_sensitive_request_headers(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://app.uptizm.com/api/v1/monitors',
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer 1|abcdefghijklmnop',
                'Cookie' => 'session=value',
                'Accept' => 'application/json',
            ],
        ]);

        $scrubbed = SentryScrubber::beforeSend($event, null);

        $headers = $scrubbed->getRequest()['headers'];

        $this->assertSame(SentryScrubber::MARKER, $headers['Authorization']);
        $this->assertSame(SentryScrubber::MARKER, $headers['Cookie']);
        $this->assertSame('application/json', $headers['Accept'], 'A harmless header must survive.');
    }

    /**
     * The reason the matcher is a substring, stated as an assertion.
     *
     * None of these three names is written into the scrubber. They are covered
     * because they END in a needle, which is the property that makes the next
     * `*_token` column safe on the day it is added rather than on the day
     * someone remembers this file exists.
     */
    public function test_it_masks_field_names_it_was_never_told_about(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'preview_token' => 'a',
            'unsubscribe_token' => 'b',
            'two_factor_secret' => 'c',
        ]);

        $extra = SentryScrubber::beforeSend($event, null)->getExtra();

        $this->assertSame(SentryScrubber::MARKER, $extra['preview_token']);
        $this->assertSame(SentryScrubber::MARKER, $extra['unsubscribe_token']);
        $this->assertSame(SentryScrubber::MARKER, $extra['two_factor_secret']);
    }

    /**
     * The false positive the needle list is deliberately shaped to avoid.
     *
     * `author` contains `auth`. This repo passes an `$author` through every
     * incident lifecycle write ({@see IncidentWriteService}),
     * so a naive `auth` needle would blank the one field that says WHO resolved
     * an incident, on every event, forever, and it would look like the
     * scrubber working correctly. Hence `auth_` and `authorization` as separate
     * needles rather than a bare `auth`.
     */
    public function test_it_does_not_mask_a_field_that_merely_contains_auth(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'author' => 'anilcan@example.test',
            'auth_config' => 'bearer xyz',
        ]);

        $extra = SentryScrubber::beforeSend($event, null)->getExtra();

        $this->assertSame('anilcan@example.test', $extra['author']);
        $this->assertSame(SentryScrubber::MARKER, $extra['auth_config']);
    }

    /**
     * Contexts are a separate bag with a separate setter, so they are a
     * separate way to miss the same secret.
     */
    public function test_it_masks_sensitive_values_in_contexts(): void
    {
        $event = Event::createEvent();
        $event->setContext('probe', [
            'region' => 'eu-west',
            'credentials' => 'user:password',
        ]);

        $contexts = SentryScrubber::beforeSend($event, null)->getContexts();

        $this->assertSame(SentryScrubber::MARKER, $contexts['probe']['credentials']);
        $this->assertSame('eu-west', $contexts['probe']['region']);
    }

    /**
     * The path that was open until a review found it, and the one most likely
     * to carry a real credential in practice.
     *
     * Sentry's Laravel integration turns every `Log::warning($message, $context)`
     * into a breadcrumb with `$context` attached VERBATIM
     * (`EventHandler::logHandler()`), and 28 files in `app/` log with a context
     * array. Breadcrumbs travel with the next event, so a credential logged
     * anywhere reaches Sentry attached to an unrelated error, and the request,
     * extra, contexts and tags passes never see it.
     */
    public function test_it_masks_sensitive_values_in_breadcrumbs(): void
    {
        $event = Event::createEvent();
        $event->setBreadcrumb([
            new Breadcrumb(
                Breadcrumb::LEVEL_WARNING,
                Breadcrumb::TYPE_DEFAULT,
                'log.warning',
                'Probe failed',
                [
                    'monitor_id' => 42,
                    'auth_config' => 'Basic dXNlcjpwYXNz',
                ],
            ),
        ]);

        $breadcrumbs = SentryScrubber::beforeSend($event, null)->getBreadcrumbs();

        $this->assertCount(1, $breadcrumbs);
        $this->assertSame(SentryScrubber::MARKER, $breadcrumbs[0]->getMetadata()['auth_config']);
        $this->assertSame(42, $breadcrumbs[0]->getMetadata()['monitor_id']);
        $this->assertSame('Probe failed', $breadcrumbs[0]->getMessage(), 'The message must survive intact.');
    }

    /**
     * A credential nested inside a log context, which is the shape a real call
     * site produces.
     *
     * `Log::warning('...', ['monitor' => $monitor->toArray()])` puts the
     * credential one level down, where a top-level key check never looks. It is
     * also the case a naive fix gets wrong: by the time a log attribute exists
     * the SDK has already JSON-ENCODED the array into a string
     * (`Attribute::tryFromValue()`), so recursing over arrays finds nothing at
     * all. The scrub has to reach inside the encoded value.
     */
    public function test_it_masks_a_credential_nested_inside_a_log_attribute(): void
    {
        $log = new Log(microtime(true), str_repeat('a', 32), LogLevel::warn(), 'Probe failed');
        $log->setAttribute('monitor', [
            'id' => 42,
            'auth_config' => ['token' => 'LEAKED-IF-YOU-SEE-THIS'],
        ]);

        $scrubbed = SentryScrubber::beforeSendLog($log);

        $value = (string) $scrubbed->attributes()->get('monitor')?->getValue();

        $this->assertStringNotContainsString('LEAKED-IF-YOU-SEE-THIS', $value);
        $this->assertStringContainsString(SentryScrubber::MARKER, $value);
        $this->assertStringContainsString('42', $value, 'The unrelated sibling must survive.');
    }

    /**
     * The top-level case, which is the one a flat context produces.
     */
    public function test_it_masks_a_top_level_log_attribute(): void
    {
        $log = new Log(microtime(true), str_repeat('a', 32), LogLevel::warn(), 'Probe failed');
        $log->setAttribute('auth_config', 'Basic dXNlcjpwYXNz');
        $log->setAttribute('monitor_id', 42);

        $scrubbed = SentryScrubber::beforeSendLog($log);

        $this->assertSame(SentryScrubber::MARKER, $scrubbed->attributes()->get('auth_config')?->getValue());
        $this->assertSame(42, $scrubbed->attributes()->get('monitor_id')?->getValue());
    }

    /**
     * The scrubber filters, it never discards.
     *
     * `before_send` returning null drops the event entirely, and an
     * observability layer that silently swallows errors is worse than none at
     * all: nobody would know it was doing it.
     */
    public function test_it_always_returns_the_event(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'password' => 'x',
        ]);

        $this->assertNotNull(SentryScrubber::beforeSend($event, null));
    }

    /**
     * An event carrying nothing sensitive must come out byte-identical, so the
     * scrubber cannot be blamed for a field that went missing.
     */
    public function test_it_leaves_a_clean_event_untouched(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'monitor_id' => 7,
            'region' => 'us-east',
            'response_ms' => 143,
        ]);

        $extra = SentryScrubber::beforeSend($event, null)->getExtra();

        $this->assertSame([
            'monitor_id' => 7,
            'region' => 'us-east',
            'response_ms' => 143,
        ], $extra);
    }
}
