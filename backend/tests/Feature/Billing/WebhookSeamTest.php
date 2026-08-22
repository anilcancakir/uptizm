<?php

namespace Tests\Feature\Billing;

use App\Models\ProcessedWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\LoopbackHttpServer;
use Tests\Support\RawWebhookRequest;
use Tests\TestCase;

/**
 * The webhook seam, measured on the real wire rather than described by a mock.
 *
 * This file is an INSTRUMENT: the RevenueCat handler, the queued re-read and the
 * client that decides entitlement are all verified through the two helpers it
 * exercises, so a capability that is faked here silently disarms every later
 * assertion built on it. Each test below therefore proves one capability really
 * works, and where a capability can be proven only by its own absence, the
 * negative control is a test of its own.
 *
 * Three capabilities, in the order a delivery meets them:
 *
 *  1. INBOUND BYTES. {@see RawWebhookRequest} delivers a body byte for byte, so
 *     an HMAC over `"{t}.{raw}"` can be verified the way the handler will.
 *     {@see self::test_post_json_re_encodes_the_body_which_is_why_the_raw_helper_exists()}
 *     is the negative control: it shows what `postJson()` delivers instead, and
 *     it is what fails if anyone ever "simplifies" the helper into `postJson()`.
 *  2. IDEMPOTENCY. A re-delivered event id is a no-op, asserted through the
 *     `recordIfNew` return value and the side effect that value gates. Never
 *     through a row count: a row count is a property of the unique index, not of
 *     the guard the handler actually branches on, and it stays 1 whether the
 *     handler skipped the side effect or ran it twice.
 *  3. OUTBOUND WIRE. {@see LoopbackHttpServer} is a real listener the HTTP client
 *     genuinely connects to, so a wrong header, a wrong path, a body that did not
 *     survive and a deadline that is not honoured are all visible. `Http::fake`
 *     reaches none of those: it short-circuits above the transport, so a faked
 *     `GET /subscribers` would pass with the request malformed or never sent.
 *
 * ## What the SQLite run proves, and what only PostgreSQL can
 *
 * The suite defaults to SQLite `:memory:` while production is PostgreSQL, and
 * the two engines disagree exactly where this file measures. On PostgreSQL a
 * failed statement aborts the WHOLE transaction, so a caught unique violation
 * poisons every later query with 25P02 and the eventual COMMIT silently becomes
 * a ROLLBACK; SQLite carries on as if nothing happened. That is why
 * `ProcessedWebhookEvent::recordIfNew()` wraps its insert in a SAVEPOINT, and it
 * is why the two dedup tests here mean strictly less on the default engine: on
 * SQLite they pass with the savepoint deleted.
 *
 * Run them on the engine that raises before trusting them:
 *
 *     DB_CONNECTION=pgsql DB_DATABASE=<a scratch database> php artisan test --filter=WebhookSeamTest
 *
 * Both engines were run for this file. The three wire tests are engine-agnostic:
 * they touch no database at all.
 */
class WebhookSeamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The signing secret both halves of every signature test share.
     */
    protected const string SECRET = 'whsec_seam_secret';

    /**
     * The throwaway route the raw-body tests deliver to.
     *
     * Registered per test rather than in the application: this file measures the
     * transport, and the handler that will eventually receive these deliveries
     * does not exist yet.
     */
    protected const string ECHO_ROUTE = '__seam/echo';

    public function test_the_raw_body_reaches_the_application_byte_for_byte(): void
    {
        $this->registerEchoRoute();

        $raw = $this->awkwardPayload();

        $response = RawWebhookRequest::withBody($raw)
            ->signedWith(static::SECRET)
            ->deliverTo($this, static::ECHO_ROUTE);

        $response->assertOk();

        // The bytes, echoed back and compared to the input. Not a length, not a
        // decoded structure: the signature is computed over bytes, so bytes are
        // what has to survive.
        $this->assertSame(
            $raw,
            $response->getContent(),
            'The body the application received is not the body that was signed.',
        );

        // And the HMAC the handler will compute over what it received matches the
        // one the sender computed over what it sent, which is the only form of
        // this assertion that a real handler cares about.
        $this->assertSame(
            'yes',
            $response->headers->get('X-Signature-Matches'),
            'The signature over the received bytes did not match the signature over the sent bytes.',
        );
    }

    public function test_a_body_altered_after_signing_fails_the_hmac_over_the_raw_bytes(): void
    {
        // The other half of the test above, and what stops it being vacuous: the
        // same helper, the same secret, one field different, and the verdict
        // flips. Without this, a verifier that returned 'yes' unconditionally
        // would pass the byte-fidelity test.
        $this->registerEchoRoute();

        $raw = $this->awkwardPayload();
        $tampered = str_replace('"RENEWAL"', '"EXPIRATION"', $raw);
        $signedAt = time();

        $this->assertNotSame($raw, $tampered, 'The fixture was not actually tampered with.');

        $response = RawWebhookRequest::withBody($tampered)
            ->withHeader(
                RawWebhookRequest::SIGNATURE_HEADER,
                "t={$signedAt},v1=".RawWebhookRequest::signatureFor($raw, static::SECRET, $signedAt),
            )
            ->deliverTo($this, static::ECHO_ROUTE);

        $this->assertSame($tampered, $response->getContent());
        $this->assertSame(
            'no',
            $response->headers->get('X-Signature-Matches'),
            'A body altered after signing verified anyway.',
        );
    }

    public function test_post_json_re_encodes_the_body_which_is_why_the_raw_helper_exists(): void
    {
        // THE NEGATIVE CONTROL. `postJson()` takes an array and encodes it
        // itself, so what arrives is the framework's encoding: escaped slashes,
        // `\u` escapes, `9.90` collapsed to `9.9`, every newline and indentation
        // gone. A signature computed over the sender's bytes cannot survive that,
        // and this test is what fails if the raw helper is ever replaced by a
        // convenient `postJson()` call.
        $this->registerEchoRoute();

        $raw = $this->awkwardPayload();
        $signedAt = time();

        $response = $this->postJson(
            static::ECHO_ROUTE,
            (array) json_decode($raw, true),
            [
                RawWebhookRequest::SIGNATURE_HEADER => "t={$signedAt},v1="
                    .RawWebhookRequest::signatureFor($raw, static::SECRET, $signedAt),
            ],
        );

        // Measured, not assumed: `9.90` arrives as `9.9`, the Turkish letters as
        // `\u` escapes, every `/` as `\/`, and the whole body on one line.
        $this->assertNotSame(
            $raw,
            $response->getContent(),
            'postJson preserved the raw body, so this negative control no longer measures anything.',
        );
        $this->assertSame(
            'no',
            $response->headers->get('X-Signature-Matches'),
            'A re-encoded body verified against a signature over the original bytes.',
        );
    }

    public function test_a_second_delivery_of_one_event_id_is_a_no_op(): void
    {
        $eventId = 'rc:9d1e1a5c-4f2a-4c1b-9f0e-2b7d3c5a8e11';

        // What a handler actually does with the return value: the side effect
        // runs only for the delivery that claimed the event.
        $processed = [];
        $claims = [];

        foreach (['first', 'second'] as $delivery) {
            $claims[$delivery] = ProcessedWebhookEvent::recordIfNew($eventId, 'RENEWAL');

            if ($claims[$delivery]) {
                $processed[] = $delivery;
            }
        }

        $this->assertTrue($claims['first'], 'The first delivery did not claim the event.');
        $this->assertFalse(
            $claims['second'],
            'A re-delivered event claimed the event again, so its side effects would run twice.',
        );

        // The side effect the return value gates, which is the thing that would
        // double-charge. A row count cannot tell these two worlds apart.
        $this->assertSame(['first'], $processed);
    }

    public function test_a_replayed_event_leaves_the_surrounding_transaction_usable(): void
    {
        // The handler claims the event INSIDE its own transaction, so a
        // re-delivery raises a unique violation with an outer transaction open.
        // This is the PostgreSQL-only failure: without the SAVEPOINT inside
        // `recordIfNew`, the aborted statement poisons the whole transaction and
        // the write below dies with 25P02, on a re-delivery the handler was
        // supposed to ignore quietly. On SQLite it passes either way.
        $claims = [];

        DB::transaction(function () use (&$claims): void {
            $claims['first'] = ProcessedWebhookEvent::recordIfNew('rc:evt_savepoint', 'RENEWAL');
            $claims['second'] = ProcessedWebhookEvent::recordIfNew('rc:evt_savepoint', 'RENEWAL');

            ProcessedWebhookEvent::query()->create([
                'event_id' => 'rc:evt_after_the_replay',
                'type' => 'RENEWAL',
                'processed_at' => now(),
            ]);
        });

        $this->assertTrue($claims['first']);
        $this->assertFalse($claims['second']);

        // Deliberately NOT a dedup assertion: this row is the surrounding
        // transaction proving it still works after the swallowed violation.
        $this->assertTrue(
            ProcessedWebhookEvent::query()->where('event_id', 'rc:evt_after_the_replay')->exists(),
            'The write that followed the swallowed unique violation never landed.',
        );
    }

    public function test_the_loopback_listener_records_the_request_it_received(): void
    {
        // The seam where entitlement gets decided: an authoritative
        // `GET /subscribers/{app_user_id}` with a bearer key. A faked client
        // cannot see a wrong path or a missing header; this listener can.
        $server = LoopbackHttpServer::serving(
            body: (string) json_encode(['subscriber' => ['original_app_user_id' => 'team_01HZ']]),
        );

        $response = Http::withToken('sk_seam_key')
            ->timeout(5)
            ->get($server->url('/v1/subscribers/team_01HZ'));

        $observed = $server->report();

        $this->assertSame('GET', $observed['method']);
        $this->assertSame('/v1/subscribers/team_01HZ', $observed['path']);
        $this->assertSame('Bearer sk_seam_key', $observed['headers']['authorization'] ?? null);
        $this->assertSame('127.0.0.1:'.$server->port(), $observed['headers']['host'] ?? null);

        // And the answer travelled back, so the exchange really completed rather
        // than the listener recording a request nobody read the response to.
        $this->assertTrue($response->ok());
        $this->assertSame('team_01HZ', $response->json('subscriber.original_app_user_id'));
    }

    public function test_the_loopback_listener_captures_the_request_body_byte_for_byte(): void
    {
        // The outbound half of the byte-fidelity claim: a signed request body has
        // the same fragility as a signed webhook, so the listener records what it
        // received rather than reporting that something arrived.
        $server = LoopbackHttpServer::serving(body: '{"ok":true}');

        $raw = $this->awkwardPayload();

        Http::withBody($raw, 'application/json')
            ->timeout(5)
            ->post($server->url('/v1/receipts'));

        $observed = $server->report();

        $this->assertSame('POST', $observed['method']);
        $this->assertSame('/v1/receipts', $observed['path']);
        $this->assertSame($raw, $observed['body'], 'The listener did not receive the bytes that were sent.');
        $this->assertSame((string) strlen($raw), $observed['headers']['content-length'] ?? null);
    }

    public function test_a_listener_told_to_stall_past_the_deadline_produces_a_honoured_timeout(): void
    {
        // The stall arm. RevenueCat's own handler runs under a 60-second wall and
        // the re-read is queued behind it, so "the deadline is honoured" is a
        // claim the later steps have to be able to test. It is only testable
        // against something that genuinely does not answer.
        $server = LoopbackHttpServer::serving(body: '{"ok":true}', delayMs: 3000);

        $startedAt = microtime(true);

        $this->assertThrows(
            fn () => Http::timeout(1)->get($server->url('/v1/subscribers/team_01HZ')),
            ConnectionException::class,
        );

        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(
            2.0,
            $elapsed,
            "The caller waited {$elapsed}s for a listener stalling 3s, so its deadline was not honoured.",
        );

        // The stall was a stall, not a connection that never happened: the
        // listener has the request that timed out.
        $observed = $server->report();
        $this->assertSame('GET', $observed['method']);
        $this->assertSame('/v1/subscribers/team_01HZ', $observed['path']);
    }

    /**
     * Register a route that echoes the raw request body back and reports, in a
     * header, whether the HMAC over the bytes it RECEIVED matches the signature
     * the sender attached.
     *
     * The body travels back as the response content rather than inside a JSON
     * envelope, because an envelope is one more encoding between the bytes and
     * the assertion, which is the exact failure being measured.
     *
     * Registered here rather than in `routes/`: the real handler is a later
     * step's, and this file must be able to prove the transport before that
     * handler exists.
     */
    protected function registerEchoRoute(): void
    {
        Route::post(static::ECHO_ROUTE, function (Request $request) {
            $received = $request->getContent();

            [$signedAt, $signature] = $this->parseSignature(
                (string) $request->header(RawWebhookRequest::SIGNATURE_HEADER, ''),
            );

            $matches = hash_equals(
                RawWebhookRequest::signatureFor($received, static::SECRET, $signedAt),
                $signature,
            );

            return response($received)->withHeaders([
                'X-Signature-Matches' => $matches ? 'yes' : 'no',
                'X-Received-Bytes' => (string) strlen($received),
            ]);
        });
    }

    /**
     * Split a `t=<unix>,v1=<hex>` signature header into its two parts.
     *
     * @return array{0: int, 1: string}
     */
    protected function parseSignature(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            if (! str_contains($piece, '=')) {
                continue;
            }

            [$field, $value] = explode('=', $piece, 2);
            $parts[$field] = $value;
        }

        return [(int) ($parts['t'] ?? 0), (string) ($parts['v1'] ?? '')];
    }

    /**
     * A webhook body written the way a sender writes one, not the way
     * `json_encode` would.
     *
     * Every property here is one a decode-and-re-encode round trip changes, and
     * each on its own is enough to break a signature over the raw bytes: the
     * indentation and line breaks, the unescaped `/` in the URL, the literal
     * Turkish characters rather than `\u` escapes, and `9.90` rather than `9.9`.
     * A fixture built by `json_encode` could not detect any of them, because it
     * would already be in the framework's own encoding.
     */
    protected function awkwardPayload(): string
    {
        return <<<'JSON'
            {
              "api_version": "1.0",
              "event": {
                "id": "9d1e1a5c-4f2a-4c1b-9f0e-2b7d3c5a8e11",
                "type": "RENEWAL",
                "app_user_id": "team_01HZ",
                "store": "APP_STORE",
                "environment": "PRODUCTION",
                "price": 9.90,
                "currency": "USD",
                "note": "Ödeme alındı, yenilendi",
                "management_url": "https://apps.apple.com/account/subscriptions"
              }
            }
            JSON;
    }
}
