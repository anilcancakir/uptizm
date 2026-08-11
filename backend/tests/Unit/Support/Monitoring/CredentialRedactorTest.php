<?php

namespace Tests\Unit\Support\Monitoring;

use App\Support\Monitoring\CredentialRedactor;
use ReflectionClass;
use Tests\TestCase;

/**
 * Exercises the redactor that keeps the operator's own credential out of
 * everything derived from a probe's response.
 *
 * The cases below are written against the WIRE forms the relay worker emits
 * (`authHeaders()`, `regional-probe.ts:1105-1135`), because that is what a
 * target echoing its request headers actually prints. A redactor built from the
 * SUBMITTED username and password passes almost every test here and fails
 * {@see test_redacts_the_basic_wire_form_when_the_plaintext_pair_is_absent()},
 * which is why that one exists and why its red phase was measured by deleting
 * the base64 entry rather than reasoned about.
 */
class CredentialRedactorTest extends TestCase
{
    /**
     * A basic pair whose base64 carries `/`, `+` and `=` padding all at once.
     *
     * Deliberately awkward: those three characters are exactly the ones that
     * would change meaning if the replacement were a pattern instead of a
     * plain string replace, so the fixture is chosen to produce them rather
     * than to look pretty.
     */
    private const string BASIC_USERNAME = 'ops';

    private const string BASIC_PASSWORD = 'hunter2?~>>!';

    /** No credential means no needles: the text comes back byte-identical. */
    public function test_a_null_config_is_a_no_op(): void
    {
        $redactor = CredentialRedactor::for(null);

        $this->assertSame('nothing to hide here', $redactor->redact('nothing to hide here'));
        $this->assertNull($redactor->redact(null));
        $this->assertSame(['server' => 'nginx'], $redactor->redactMap(['server' => 'nginx']));
    }

    /**
     * `type: none` is also a no-op, so a caller needs no conditional.
     *
     * The form submits the whole map with the type switched to `none`, leaving
     * whatever the operator had typed into the other fields in place. Those
     * values never reach the wire, so redacting them would shred the digest for
     * a credential that was never sent.
     */
    public function test_type_none_is_a_no_op_even_with_leftover_fields(): void
    {
        $redactor = CredentialRedactor::for([
            'type' => 'none',
            'username' => 'ops',
            'password' => 'hunter2',
        ]);

        $this->assertSame('login as ops with hunter2', $redactor->redact('login as ops with hunter2'));
    }

    /** A type this backend does not know sends no header, so it matches nothing. */
    public function test_an_unknown_type_is_a_no_op(): void
    {
        $redactor = CredentialRedactor::for([
            'type' => 'mtls',
            'password' => 'hunter2',
        ]);

        $this->assertSame('hunter2', $redactor->redact('hunter2'));
    }

    /**
     * THE DISCRIMINATING CASE. A body carrying ONLY the base64 wire form, with
     * neither the username nor the password in plaintext anywhere, is redacted.
     *
     * This is what a Laravel debug page or a request-echo endpoint prints when
     * the probe authenticated: the header value as sent. A redactor built from
     * the submitted values finds `ops` and `hunter2?~>>!` in this string zero
     * times and returns it untouched, straight into two prompts.
     */
    public function test_redacts_the_basic_wire_form_when_the_plaintext_pair_is_absent(): void
    {
        $wireForm = base64_encode(self::BASIC_USERNAME.':'.self::BASIC_PASSWORD);

        // The fixture is only meaningful if it really is the awkward shape:
        // a pattern-based replacement would break on these three characters.
        $this->assertStringContainsString('/', $wireForm);
        $this->assertStringContainsString('+', $wireForm);
        $this->assertStringContainsString('=', $wireForm);

        $body = '<tr><td>Authorization</td><td>Basic '.$wireForm.'</td></tr>';

        $redacted = CredentialRedactor::for([
            'type' => 'basic',
            'username' => self::BASIC_USERNAME,
            'password' => self::BASIC_PASSWORD,
        ])->redact($body);

        $this->assertStringNotContainsString($wireForm, $redacted);
        $this->assertStringContainsString(CredentialRedactor::MARKER, $redacted);

        // The scheme name is not a secret and stays, so the evidence still says
        // the target echoed an Authorization header.
        $this->assertStringContainsString('Basic ', $redacted);
    }

    /** The percent-encoded copy of that same wire form is redacted too. */
    public function test_redacts_the_percent_encoded_basic_wire_form(): void
    {
        $wireForm = base64_encode(self::BASIC_USERNAME.':'.self::BASIC_PASSWORD);
        $encoded = rawurlencode($wireForm);

        $this->assertNotSame($wireForm, $encoded, 'the fixture must actually differ once encoded');

        $redacted = CredentialRedactor::for([
            'type' => 'basic',
            'username' => self::BASIC_USERNAME,
            'password' => self::BASIC_PASSWORD,
        ])->redact('retry: /debug?h=Basic%20'.$encoded);

        $this->assertStringNotContainsString($encoded, $redacted);
    }

    /** A head-truncated bearer echo, the shape a page prints with an ellipsis. */
    public function test_redacts_a_head_truncated_bearer_echo(): void
    {
        $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIn0.7Hk3Qb2Zc';
        $truncated = mb_substr('Bearer '.$token, 0, CredentialRedactor::HEAD_PREFIX_LENGTH);

        $redacted = CredentialRedactor::for([
            'type' => 'bearer',
            'token' => $token,
        ])->redact('rejected token '.$truncated.'... at 12:04');

        $this->assertStringNotContainsString($truncated, $redacted);
        $this->assertStringContainsString(CredentialRedactor::MARKER, $redacted);
    }

    /**
     * ORDERING. The full token and its own truncated head on ONE page.
     *
     * Shortest-first replacement would consume the head of the full token in
     * place and leave its tail rendered, which is worse than doing nothing:
     * the marker makes the line look handled. Longest-first eats the whole
     * value, and the standalone truncation afterwards.
     */
    public function test_a_full_token_beside_its_own_truncation_leaves_no_tail(): void
    {
        $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIn0.7Hk3Qb2Zc';
        $tail = mb_substr($token, CredentialRedactor::HEAD_PREFIX_LENGTH);

        $body = 'header: Bearer '.$token."\n"
            .'log: rejected Bearer '.mb_substr($token, 0, CredentialRedactor::HEAD_PREFIX_LENGTH).'...';

        $redacted = CredentialRedactor::for([
            'type' => 'bearer',
            'token' => $token,
        ])->redact($body);

        $this->assertStringNotContainsString($token, $redacted);
        $this->assertStringNotContainsString($tail, $redacted, 'a half-redacted token still leaks its tail');
        $this->assertSame(2, substr_count($redacted, CredentialRedactor::MARKER));
    }

    /**
     * A three-character password never becomes a needle, and the wire form
     * still does.
     *
     * Matching a three-character value would replace it throughout ordinary
     * prose and destroy the digest the analysis reads, so the floor drops it.
     * What survives is the form that would actually be echoed: the base64 pair,
     * which is long enough to be a signal.
     */
    public function test_a_three_character_password_is_not_a_needle_but_its_wire_form_is(): void
    {
        $config = [
            'type' => 'basic',
            'username' => 'operations',
            'password' => 'abc',
        ];

        $wireForm = base64_encode('operations:abc');
        $redacted = CredentialRedactor::for($config)->redact('the abc test ran; header was Basic '.$wireForm);

        $this->assertStringContainsString('the abc test ran', $redacted, 'a 3-character needle would shred ordinary text');
        $this->assertStringNotContainsString($wireForm, $redacted);
    }

    /** The api_key value is redacted; the header NAME it travels under is not. */
    public function test_redacts_the_api_key_value_and_never_the_header_name(): void
    {
        $redacted = CredentialRedactor::for([
            'type' => 'api_key',
            'key' => 'sk-live-9f2c41ab77',
            'header' => 'X-Api-Key',
        ])->redact('X-Api-Key: sk-live-9f2c41ab77');

        $this->assertStringNotContainsString('sk-live-9f2c41ab77', $redacted);
        $this->assertStringContainsString('X-Api-Key', $redacted);
    }

    /**
     * An incomplete credential contributes nothing, mirroring the worker.
     *
     * `authHeaders()` sends no header at all for a basic config without a
     * password, so nothing was on the wire and nothing can be echoed back.
     */
    public function test_a_basic_config_without_a_password_matches_nothing(): void
    {
        $redactor = CredentialRedactor::for([
            'type' => 'basic',
            'username' => 'operations',
        ]);

        $this->assertSame('operations', $redactor->redact('operations'));
    }

    /**
     * An EMPTY password is still a credential: `Basic base64("user:")` is what
     * the worker sends for it, so that wire form is a needle.
     */
    public function test_an_empty_password_still_produces_the_pair_wire_form(): void
    {
        $wireForm = base64_encode('operations:');

        $redacted = CredentialRedactor::for([
            'type' => 'basic',
            'username' => 'operations',
            'password' => '',
        ])->redact('Basic '.$wireForm);

        $this->assertStringNotContainsString($wireForm, $redacted);
    }

    /** Map values are redacted, keys are left alone, nested values are reached. */
    public function test_redact_map_touches_values_and_never_keys(): void
    {
        $redactor = CredentialRedactor::for([
            'type' => 'api_key',
            'key' => 'sk-live-9f2c41ab77',
            'header' => 'X-Api-Key',
        ]);

        $redacted = $redactor->redactMap([
            'x-api-key' => 'sk-live-9f2c41ab77',
            'link' => ['<https://example.com/?k=sk-live-9f2c41ab77>', '<https://example.com/>'],
            'age' => 42,
            'server' => 'nginx',
        ]);

        $this->assertSame(CredentialRedactor::MARKER, $redacted['x-api-key']);
        $this->assertArrayHasKey('x-api-key', $redacted, 'the header name is not a secret');
        $this->assertStringNotContainsString('sk-live-9f2c41ab77', $redacted['link'][0]);
        $this->assertSame('<https://example.com/>', $redacted['link'][1]);
        $this->assertSame(42, $redacted['age'], 'a non-string leaf is returned untouched, never cast');
        $this->assertSame('nginx', $redacted['server']);
    }

    /**
     * The needle set is deduplicated, floored and sorted, asserted directly.
     *
     * White-box on purpose: replacing the same needle twice is idempotent, so
     * a duplicated entry has no output signal at all and can only be seen from
     * the inside. The short-token case is where the duplicates come from: a
     * five-character alphanumeric token equals its own `rawurlencode` form AND
     * its own 16-character head, so a set built without deduplication would
     * carry the same string three times, and `Bearer abcde` twice (its head is
     * itself, at twelve characters). Only the encoded scheme-prefixed form
     * genuinely differs, because a space becomes `%20`, and it sorts first for
     * being two characters longer than the form it encodes.
     */
    public function test_the_needle_set_is_deduplicated_floored_and_sorted_longest_first(): void
    {
        $redactor = CredentialRedactor::for([
            'type' => 'bearer',
            'token' => 'abcde',
        ]);

        $needles = (new ReflectionClass($redactor))->getProperty('needles')->getValue($redactor);

        $this->assertSame(['Bearer%20abcde', 'Bearer abcde', 'abcde'], $needles);
    }
}
