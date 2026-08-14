<?php

namespace App\Support\Sentry;

use App\Models\Monitor;
use App\Services\Monitoring\IncidentWriteService;
use App\Support\Monitoring\CredentialRedactor;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;

/**
 * The last gate between this application's secrets and Sentry.
 *
 * Wired as `before_send` in `config/sentry.php`, so it runs on every event
 * BEFORE transmission. That placement is the point: Sentry's own server-side
 * scrubbing is real and useful, but it runs after the event has crossed the
 * network, it is configured in a web UI nobody diffs, and it has never heard of
 * the field names this codebase invented.
 *
 * `auth_config` is the one that forced this class. It is a customer's own
 * credential for their own origin, stored `encrypted:array` and decrypted in
 * memory to probe with ({@see Monitor}), and it travels through
 * jobs, services and the analyze endpoint. An exception anywhere along that
 * path can put the decrypted value into an event's `extra` as part of a model
 * dump, and no default anywhere would stop it.
 *
 * WHY SUBSTRING MATCHING, AND WHY THESE NEEDLES
 *
 * The match is a case-insensitive substring rather than an exact key list,
 * because an exact list is a list someone has to remember to extend. This repo
 * already carries `preview_token`, `confirmed_token`, `unsubscribe_token`,
 * `remember_token`, `two_factor_secret` and `two_factor_recovery_codes`: six
 * names covered for free by two needles, and the seventh one nobody has written
 * yet is covered on the day it is added rather than on the day someone
 * remembers this file exists.
 *
 * The needles are narrower than they look, though, and `auth_` is the reason.
 * A bare `auth` also matches `author`, which this repo passes through every
 * incident lifecycle write ({@see IncidentWriteService}),
 * so it would blank the field naming WHO resolved an incident on every event
 * forever, while looking exactly like the scrubber working. `auth_` and
 * `authorization` are listed separately for that reason. A test pins it.
 *
 * WHAT THIS CANNOT DO, stated rather than implied:
 *
 * - It matches KEYS, never values. A credential pasted into an exception
 *   message, a URL with a token in its query string, or a stack trace argument
 *   is not reached. {@see CredentialRedactor} is the
 *   value-based counterpart, and it covers probe-controlled text only.
 * - It cannot see inside an object. Anything the SDK has already serialised to
 *   a scalar before this runs is past the gate.
 * - It is not a reason to relax `send_default_pii`, which stays false.
 */
class SentryScrubber
{
    /**
     * What a matched value becomes.
     *
     * A visible marker rather than removal of the key, for the same reason
     * {@see CredentialRedactor::MARKER} is: a missing
     * key reads as a field that was never set, this reads as evidence
     * deliberately withheld, and the difference matters when someone is
     * debugging the event rather than the secret.
     */
    public const string MARKER = '[filtered]';

    /**
     * Key fragments whose value never leaves this server.
     *
     * Lower-cased, matched as substrings. See the class docblock for why
     * `auth_` and `authorization` are separate entries and `auth` is not one.
     *
     * @var list<string>
     */
    private const array SENSITIVE_NEEDLES = [
        'auth_',
        'authorization',
        'password',
        'secret',
        'token',
        'credential',
        'cookie',
        'api_key',
        'apikey',
        'private_key',
        'recovery_codes',
    ];

    /**
     * Scrub an outgoing event.
     *
     * The two-parameter signature is the one `Sentry\Client` actually calls
     * with; the docs' scrubbing page still shows a one-parameter example.
     *
     * @param  Event  $event  The event about to be transmitted.
     * @param  EventHint|null  $hint  The SDK's hint, unused here but part of the contract.
     * @return Event|null The scrubbed event, or null when {@see SentryEventThrottle}
     *                    has already reported this same fault within the last
     *                    minute. Nothing else here ever discards an event.
     */
    public static function beforeSend(Event $event, ?EventHint $hint): ?Event
    {
        // 0. Drop a fault already reported this minute, BEFORE doing the work of
        //    scrubbing it. This is the only quota control available to this
        //    deployment: Sentry's own per-key rate limit is a Business-plan
        //    feature, and its absence is silent (the API accepts the field and
        //    keeps null). See SentryEventThrottle for the arithmetic; it fails
        //    open, so a cache outage costs quota rather than visibility.
        if (! SentryEventThrottle::allows($event)) {
            return null;
        }

        // 1. The request bag: headers (Authorization, Cookie), body, query.
        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest(self::scrub($request));
        }

        // 2. `extra`, where a model dump or a job payload lands.
        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra(self::scrub($extra));
        }

        // 3. Contexts are a separate bag with a per-name setter, so they are a
        //    separate way to miss the same secret.
        foreach ($event->getContexts() as $name => $context) {
            if (is_array($context)) {
                $event->setContext($name, self::scrub($context));
            }
        }

        // 4. Tags are indexed and searchable in Sentry's UI, which makes a
        //    secret in one worse than a secret anywhere else here.
        $tags = $event->getTags();

        if ($tags !== []) {
            $event->setTags(self::scrubTags($tags));
        }

        // 5. Breadcrumbs, which is where a credential is MOST likely to be
        //    found. Sentry's Laravel integration turns every
        //    `Log::warning($message, $context)` into a breadcrumb carrying that
        //    context verbatim, 28 files here log with one, and breadcrumbs ride
        //    along with whatever event happens next. A credential logged in one
        //    place therefore arrives attached to an unrelated error somewhere
        //    else, which none of the passes above would ever see.
        $breadcrumbs = $event->getBreadcrumbs();

        if ($breadcrumbs !== []) {
            $event->setBreadcrumb(array_map(self::scrubBreadcrumb(...), $breadcrumbs));
        }

        return $event;
    }

    /**
     * The same gate for structured logs, which travel on their own pipeline.
     *
     * Wired as `before_send_log` in `config/sentry.php`. It is a SEPARATE hook
     * for a separate transport: `enable_logs` ships every log line's context as
     * log attributes (`LogsHandler` merges `$context` and `$record['extra']`
     * straight in), and none of it passes through `beforeSend()` above. Turning
     * logs on without this would open a second, wider road for exactly the
     * values that road exists to keep in.
     *
     * @param  Log  $log  The log record about to be transmitted.
     * @return Log|null Always the record; see `beforeSend()` for why this never discards.
     */
    public static function beforeSendLog(Log $log): ?Log
    {
        foreach ($log->attributes()->all() as $key => $attribute) {
            if (self::isSensitive($key)) {
                $log->setAttribute($key, self::MARKER);

                continue;
            }

            $rewritten = self::scrubEncodedAttribute($attribute->getValue());

            if ($rewritten !== null) {
                $log->setAttribute($key, $rewritten);
            }
        }

        return $log;
    }

    /**
     * Mask inside an attribute the SDK has already JSON-encoded.
     *
     * This exists because a log attribute is a SCALAR by the time it reaches
     * `before_send_log`. `Log::warning('...', ['monitor' => $monitor->toArray()])`
     * is the realistic call shape, and `Attribute::tryFromValue()` turns that
     * nested array into a JSON string, so the credential sits one level down
     * inside a value rather than under a key anything can match. Recursing over
     * arrays here, the obvious fix, would find nothing at all: there are no
     * arrays left to recurse into.
     *
     * Decoding is guarded rather than attempted on everything: only a value
     * that starts as an object or array is parsed, so an ordinary message
     * string is never round-tripped. When nothing changed the original is kept
     * untouched, so a re-encode can never reformat a value it had no reason to
     * touch.
     *
     * @param  mixed  $value  The attribute's current value.
     * @return string|null The rewritten JSON, or null when this attribute needs no change.
     */
    private static function scrubEncodedAttribute($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if ($value[0] !== '{' && $value[0] !== '[') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return null;
        }

        $scrubbed = self::scrub($decoded);

        if ($scrubbed === $decoded) {
            return null;
        }

        $encoded = json_encode($scrubbed);

        return $encoded === false ? null : $encoded;
    }

    /**
     * Walk an arbitrarily nested array, masking every sensitive key.
     *
     * Recursion is not optional: a model reaches an event as a nested attribute
     * array, so a single-level pass would satisfy a naive test and still ship
     * the credential.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::MARKER;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    /**
     * Mask the sensitive metadata on one breadcrumb.
     *
     * A `Breadcrumb` is immutable and exposes no bulk setter, so this rebuilds
     * it through `withMetadata()` one key at a time. Only masked keys are
     * rewritten; an untouched breadcrumb is returned as-is, which keeps the
     * common case free.
     *
     * The message itself is left alone. It is a developer-authored sentence,
     * and this class masks KEYS rather than reading values (see the class
     * docblock for what that does not cover).
     */
    private static function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        foreach ($breadcrumb->getMetadata() as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (self::isSensitive($key)) {
                $breadcrumb = $breadcrumb->withMetadata($key, self::MARKER);

                continue;
            }

            if (is_array($value)) {
                $breadcrumb = $breadcrumb->withMetadata($key, self::scrub($value));
            }
        }

        return $breadcrumb;
    }

    /**
     * The tag bag is flat and string-valued, so it gets its own pass rather
     * than sharing the recursive one.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function scrubTags(array $tags): array
    {
        foreach (array_keys($tags) as $key) {
            if (self::isSensitive($key)) {
                $tags[$key] = self::MARKER;
            }
        }

        return $tags;
    }

    /**
     * Whether a key name carries something that must not be transmitted.
     *
     * @param  string  $key  The key as it appears in the event.
     */
    private static function isSensitive(string $key): bool
    {
        $haystack = strtolower($key);

        foreach (self::SENSITIVE_NEEDLES as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
