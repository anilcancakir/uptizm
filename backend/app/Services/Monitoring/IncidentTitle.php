<?php

namespace App\Services\Monitoring;

use App\Models\Incident;

/**
 * The one place an automatically opened incident's title is spelled, and the
 * seam that lets six surfaces render it in six different languages.
 *
 * A writer no longer composes a sentence. It calls {@see self::compose()} with
 * one of the six key constants plus the display-ready values that go into it,
 * and persists the triple that comes back: the English render for
 * `incidents.title`, the key for `incidents.title_key`, the parameters for
 * `incidents.title_params`. A reader that knows its reader's language calls
 * {@see self::render()} instead of reading the column.
 *
 * Three properties of that contract are load-bearing.
 *
 * The stored English comes out of the SAME catalogue entry the localized render
 * uses (`lang/en/incidents.php`, resolved with the locale pinned), so the two
 * forms of one sentence cannot drift. Nothing here composes a sentence by hand.
 *
 * The persisted key is always the BARE key. `ssl_expiring` carries a
 * `_one` / `_other` pair in the catalogue, and the suffix is chosen inside
 * {@see self::catalogueKey()} rather than at a call site, because the suffixed
 * form is a catalogue detail: it crosses no wire, and the Flutter enum that
 * decodes `title_key` has a member for the bare value only. A writer that
 * persisted `incidents.ssl_expiring_one` would hand the client a value it
 * cannot match, and the client's documented fallback is to render the stored
 * English, so the Turkish would silently never appear.
 *
 * A null `title_key` means the title was authored by a human (or the row
 * predates this seam), and {@see self::render()} hands back the stored text
 * untouched. That is what keeps the operator-written path and every historical
 * row correct without a backfill.
 */
final class IncidentTitle
{
    /** A monitor crossed its `consecutive_fails` threshold: a bare up/down outage. */
    public const string MONITOR_DOWN = 'incidents.monitor_down';

    /** A numeric metric sample landed in its warn band. */
    public const string METRIC_WARN_BOUND = 'incidents.metric_warn_bound';

    /**
     * A numeric metric sample landed in its critical band.
     *
     * The bound cases are two keys rather than one key with a `severity`
     * parameter, because a parameter would have to carry the English band name
     * into the Turkish sentence and leave "critical sınırını aştı" half
     * translated.
     */
    public const string METRIC_CRITICAL_BOUND = 'incidents.metric_critical_bound';

    /** A string metric reported a value configured as a breach. */
    public const string METRIC_STRING_VALUE = 'incidents.metric_string_value';

    /**
     * A monitor's TLS certificate is near expiry.
     *
     * Persisted bare. The catalogue holds `ssl_expiring_one` and
     * `ssl_expiring_other`; {@see self::catalogueKey()} picks between them.
     */
    public const string SSL_EXPIRING = 'incidents.ssl_expiring';

    /** The anomaly detector opened the incident rather than a configured bound. */
    public const string AI_ANOMALY = 'incidents.ai_anomaly';

    /**
     * The `incidents.title` column width. A title is composed here and inserted
     * one call later, so the cut has to happen on this side.
     */
    private const int TITLE_MAX_LENGTH = 200;

    /**
     * Characters of an extracted value a title may spend.
     *
     * Kept well under {@see self::TITLE_MAX_LENGTH} so the metric label stays
     * visible: a title that is nothing but a truncated blob names no metric.
     */
    private const int TITLE_VALUE_MAX_LENGTH = 80;

    /** Appended to a title value that was cut, matching MetricCandidate's digest mark. */
    private const string TITLE_TRUNCATION_MARK = '…';

    /**
     * The three columns a writer persists for one composed title.
     *
     * `title` is the English render, so search, the LLM prompts and any reader
     * with no locale keep seeing a real sentence; `title_key` and
     * `title_params` are what a localized surface renders from instead.
     *
     * The returned `title_params` is not the array that came in: the extracted
     * value is cut to {@see self::TITLE_VALUE_MAX_LENGTH} here, BEFORE it
     * becomes a parameter, so the already-cut text is what gets persisted and no
     * other surface has to re-derive the rule. That cut is a bound on
     * attacker-influenced text on its way to a `varchar(200)` column and to a
     * public status page, not a formatting preference.
     *
     * @param  string  $key  one of this class's six key constants
     * @param  array<string, string|int>  $params  display-ready values only: a monitor
     *                                             name, a metric label, the extracted
     *                                             value, a day count. Never a model and
     *                                             never anything a reader would have to
     *                                             load a relation to resolve.
     * @return array{title: string, title_key: string, title_params: array<string, string|int>}
     */
    public static function compose(string $key, array $params): array
    {
        // 1. Cut the extracted value first, so the parameters that get persisted
        //    are the ones every surface renders from.
        $params = self::truncateValue($params);

        // 2. Render the English out of the same catalogue a localized read uses,
        //    with the locale pinned so an operator's app language cannot decide
        //    what lands in the column.
        $title = self::resolve($key, $params, 'en');

        // 3. Fit the column. A long metric label plus a cut value can still
        //    overrun 200 characters, and PostgreSQL throws rather than trimming.
        return [
            'title' => mb_substr($title, 0, self::TITLE_MAX_LENGTH),
            'title_key' => $key,
            'title_params' => $params,
        ];
    }

    /**
     * The incident's title in the given locale, or the stored text when there is
     * no key to render from.
     *
     * A null `$locale` means the locale that is active right now, and that
     * default is what makes this correct on the notification path: Laravel wraps
     * each recipient's channel build in `withLocale(preferredLocale(...))`, so a
     * call made inside a channel method resolves per recipient. A caller that
     * needs a specific language regardless of the ambient one (the OneSignal
     * language map, which carries both) passes it explicitly.
     */
    public static function render(Incident $incident, ?string $locale = null): string
    {
        if ($incident->title_key === null) {
            return (string) $incident->title;
        }

        // `is_array` rather than a cast: `(array) '{"monitor":"x"}'` would wrap a JSON
        // STRING into a one-element list, `__()` would find no replacement to make,
        // and the surface would publish `:monitor is down` with the placeholder
        // showing. That is the shape any read path which bypasses the model's `array`
        // cast produces, and it is worth answering with the stored English instead of
        // a broken sentence.
        $params = $incident->title_params;

        if (! is_array($params)) {
            return (string) $incident->title;
        }

        return self::resolve($incident->title_key, $params, $locale);
    }

    /**
     * Resolve a key plus its parameters against the catalogue.
     *
     * The single choke point both public methods go through, which is what makes
     * "the stored English and the localized render come from one entry" true
     * rather than promised.
     *
     * @param  array<string, string|int>  $params
     */
    private static function resolve(string $key, array $params, ?string $locale): string
    {
        return (string) __(self::catalogueKey($key, $params), $params, $locale);
    }

    /**
     * The catalogue entry a key resolves to, which is the key itself for five of
     * the six and a `_one` / `_other` sibling for the SSL one.
     *
     * The suffix is derived here and nowhere else: the persisted `title_key`
     * stays bare so the Flutter enum can match it, and both catalogues carry the
     * pair so neither locale can fall through to a raw dotted key. The choice is
     * a plain `=== 1` rather than `trans_choice()` because magic's client-side
     * `trans()` has no plural API to mirror a Laravel pluralization rule with,
     * and the two halves have to agree on the entry they read.
     *
     * @param  array<string, string|int>  $params
     */
    private static function catalogueKey(string $key, array $params): string
    {
        if ($key !== self::SSL_EXPIRING) {
            return $key;
        }

        return ((int) ($params['days'] ?? 0)) === 1
            ? $key.'_one'
            : $key.'_other';
    }

    /**
     * Cut the extracted value down to what a title may spend on it.
     *
     * Only the `value` parameter is bounded, because it is the only one that is
     * neither authored nor derived: it came out of a monitored response, where
     * `monitor_metric_values.string_value` is `text` and a `json_path` pointed at
     * an object yields a whole JSON blob. A monitor name and a metric label are
     * operator-written and already column-bounded.
     *
     * @param  array<string, string|int>  $params
     * @return array<string, string|int>
     */
    private static function truncateValue(array $params): array
    {
        $value = $params['value'] ?? null;

        if (! is_string($value) || mb_strlen($value) <= self::TITLE_VALUE_MAX_LENGTH) {
            return $params;
        }

        $params['value'] = mb_substr($value, 0, self::TITLE_VALUE_MAX_LENGTH)
            .self::TITLE_TRUNCATION_MARK;

        return $params;
    }
}
