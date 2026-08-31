<?php

namespace App\Support\Sentry;

use App\Models\Monitor;
use App\Services\Monitoring\IncidentWriteService;
use App\Support\Monitoring\CredentialRedactor;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Logs\Log;
use Sentry\Tracing\Span;

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
 * - It matches KEYS, never values, with ONE exception: the URL-bearing fields on
 *   an HTTP breadcrumb or an `http.client` span, where the key names are innocent
 *   and the values are the credential. {@see self::HTTP_URL_KEYS} carries that
 *   case and the reason it had to be special. Everywhere else a credential
 *   pasted into an exception
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
     * The URL-bearing metadata keys on an HTTP breadcrumb or span, as a set.
     *
     * These are the one place key matching cannot work, because the key names
     * are innocent and the VALUES are the credential. `sentry-laravel`'s
     * `HttpClientIntegration` attaches all three to every outbound request,
     * on success as well as on failure
     * (`HttpClientIntegration::handleResponseReceivedHandlerForBreadcrumb` and
     * `handleConnectionFailedHandlerForBreadcrumb`), and its `getPartialUri()`
     * drops only the authority and the query: the PATH survives in `url` and
     * the raw query survives verbatim in `http.query`.
     *
     * That is a leak on this product specifically, because this application
     * makes outbound HTTP to TENANT-CONTROLLED urls as a core function and two
     * of those carry their credential in the url itself: an ntfy webhook's
     * topic lives in the path, and a Teams Workflows url carries a SAS in
     * `?sig=`. A monitor's probe target is tenant-controlled too.
     *
     * The same three fields ride the `http.client` SPAN on a sampled transaction
     * (`handleRequestSendingHandlerForTracing`), which puts the uri in the span
     * description as well, so both places are reduced there.
     *
     * So an HTTP breadcrumb or span keeps its ORIGIN and loses everything after
     * it. The
     * origin is what makes the breadcrumb worth having (which host did we fail
     * to reach) and the path is what makes it dangerous. Sentry's own author
     * named the method "partial" and drew the line one component later than a
     * monitoring product can afford.
     */
    private const array HTTP_URL_KEYS = [
        'url' => true,
        'http.query' => true,
        'http.fragment' => true,
    ];

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

        return self::scrubEvent($event);
    }

    /**
     * The same gate for TRANSACTION events, which travel a third pipeline.
     *
     * Wired as `before_send_transaction` in `config/sentry.php`, and it has to
     * be wired separately: `Sentry\Client::applyBeforeSendCallback` switches on
     * the event TYPE and routes a transaction to
     * `getBeforeSendTransactionCallback()`, never to `before_send`. Leaving it
     * unset means the SDK's default passthrough runs and nothing below happens,
     * including the breadcrumb reduction, because the whole event bypasses the
     * error hook rather than only the span part of it.
     *
     * The reason this matters here rather than being defensive tidiness:
     * `tracing.http_client_requests` is on by default, so
     * `HttpClientIntegration::handleRequestSendingHandlerForTracing` opens an
     * `http.client` span per outbound request carrying `url` (scheme, host, port
     * and PATH), a RAW `http.query`, and a description of
     * `"{method} {partial uri}"`. On this product two of those urls ARE the
     * credential (an ntfy topic in the path, a Teams SAS in `?sig=`), and no
     * exception is needed: a SUCCESSFUL test-send on a sampled request ships it.
     *
     * The throttle is deliberately not applied here. It exists to bound repeated
     * FAULT reports; dropping transactions would silently break the performance
     * data the sampler is tuned for.
     *
     * @param  Event  $event  The transaction about to be transmitted.
     * @param  EventHint|null  $hint  The SDK's hint, unused here but part of the contract.
     * @return Event|null Always the event; nothing here discards a transaction.
     */
    public static function beforeSendTransaction(Event $event, ?EventHint $hint): ?Event
    {
        return self::scrubEvent($event, scrubSpans: true);
    }

    /**
     * Every bag an event can carry a secret in, in one place.
     *
     * Shared by the error and transaction hooks because a transaction carries
     * the same request bag, extra, contexts, tags and breadcrumbs an error does,
     * and a fix applied to one hook and not the other is the shape of defect
     * this class exists to prevent.
     *
     * @param  Event  $event  The event to scrub in place.
     * @param  bool  $scrubSpans  Whether to reduce span urls, which only a
     *                            transaction carries.
     */
    private static function scrubEvent(Event $event, bool $scrubSpans = false): Event
    {
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

        // 6. Spans, on a transaction only. An `http.client` span puts the url in
        //    TWO places (`data['url']` and the description), so both are reduced.
        if ($scrubSpans) {
            foreach ($event->getSpans() as $span) {
                self::scrubSpan($span);
            }
        }

        return $event;
    }

    /**
     * Reduce one span's url-bearing fields, in place.
     *
     * `Span` is mutable and `setData()` MERGES, so only the masked keys are
     * written back. The description is rewritten rather than blanked: the method
     * verb is worth keeping and only the uri half is dangerous.
     */
    private static function scrubSpan(Span $span): void
    {
        $data = $span->getData();
        $masked = [];

        foreach (array_keys(self::HTTP_URL_KEYS) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $masked[$key] = $key === 'url' ? self::originOnly($data[$key]) : self::MARKER;
        }

        if ($masked !== []) {
            $span->setData($masked);
        }

        // The description is `"{method} {partial uri}"`, built by
        // `HttpClientIntegration::handleRequestSendingHandlerForTracing`, so the
        // path rides here as well as in `data['url']`. Rebuild it from the verb
        // plus the origin.
        $description = $span->getDescription();

        if ($description === null || $description === '') {
            return;
        }

        $parts = explode(' ', $description, 2);

        if (count($parts) === 2 && str_contains($parts[1], '://')) {
            $span->setDescription($parts[0].' '.self::originOnly($parts[1]));
        }
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
        $isHttp = $breadcrumb->getType() === Breadcrumb::TYPE_HTTP;

        foreach ($breadcrumb->getMetadata() as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($isHttp && isset(self::HTTP_URL_KEYS[$key])) {
                $breadcrumb = $breadcrumb->withMetadata(
                    $key,
                    $key === 'url' ? self::originOnly($value) : self::MARKER,
                );

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
     * Reduce a URL to its origin, dropping the path.
     *
     * A non-string or unparseable value becomes the marker rather than being
     * passed through: this runs on a credential path, so the failure mode has
     * to be losing information rather than leaking it.
     */
    private static function originOnly(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return self::MARKER;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return self::MARKER;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.$parts['host'].$port;
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
