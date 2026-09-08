<?php

namespace App\Support\Notifications;

use App\Models\Incident;
use App\Models\Monitor;
use Carbon\CarbonInterface;

/**
 * Composes the one-line body a notification carries under its title.
 *
 * The title says WHAT happened ("API Health is down"); the body says what an
 * operator needs next and the title cannot hold: how serious it is, what it is
 * about, and for a closed incident how long it ran. Three surfaces share this
 * composer (the in-app row, the socket frame built from it, and the push
 * content), so none of them can drift from the others.
 *
 * Every part is a fact that does not go stale, and that constraint is the whole
 * design. The in-app row is written into `notifications` once and read for as
 * long as it lives, so a relative phrase ("started 3 minutes ago") would freeze
 * at send time and be wrong an hour later; the row already carries its own
 * timestamp for that question. A resolved incident's LENGTH is safe by contrast,
 * because it stops changing the moment the incident closes.
 */
final class IncidentBody
{
    /**
     * What separates two body parts.
     *
     * A middot rather than a dash: the incident titles this sits under are
     * sentences, and a dash inside one reads as punctuation of that sentence.
     */
    private const SEPARATOR = ' · ';

    /**
     * The body for a newly opened incident: how serious, and about what.
     *
     * No elapsed time here on purpose. The dispatch runs inside the same call
     * that opened the incident, so the answer is always "just now" and the part
     * would cost a line to say nothing.
     *
     * @param  string|null  $locale  Explicit locale, needed by the push payload,
     *                               which renders every language in one message.
     *                               Null resolves the ambient one, which the
     *                               database channel sets per recipient.
     */
    public static function forOpened(Incident $incident, ?string $locale = null): string
    {
        return self::join([
            self::severityName($incident, $locale),
            self::targetLabel($incident->primaryMonitor),
        ]);
    }

    /**
     * The body for an incident that got worse: the tier it reached, and about what.
     *
     * It names the destination rather than the transition, because the severity
     * it came FROM is not on the row: `incidents` keeps one `severity` column and
     * the escalation overwrites it. Naming a previous tier here would mean
     * threading it through the dispatcher and the notification constructor, which
     * is a wider change than the sentence is worth.
     *
     * @param  string|null  $locale  See {@see self::forOpened()}.
     */
    public static function forEscalated(Incident $incident, ?string $locale = null): string
    {
        return self::join([
            __('notifications.body_raised_to', [
                'severity' => self::severityName($incident, $locale),
            ], $locale),
            self::targetLabel($incident->primaryMonitor),
        ]);
    }

    /**
     * The body for a closed incident: how long it ran, and about what.
     *
     * @param  string|null  $locale  See {@see self::forOpened()}.
     */
    public static function forResolved(Incident $incident, ?string $locale = null): string
    {
        $duration = self::durationLabel($incident, $locale);

        return self::join([
            $duration === null
                ? null
                : __('notifications.body_lasted', ['duration' => $duration], $locale),
            self::targetLabel($incident->primaryMonitor),
        ]);
    }

    /**
     * The localized name of the incident's severity tier.
     *
     * Public because the mail and the Slack payload need the same word: both used
     * to interpolate the stored token straight into a translated sentence, which
     * read "Önem derecesi: critical." to a Turkish recipient.
     *
     * @param  string|null  $locale  See {@see self::forOpened()}.
     */
    public static function severityName(Incident $incident, ?string $locale = null): string
    {
        return __('notifications.severity_'.$incident->severity->value, [], $locale);
    }

    /**
     * How long the incident ran, as a localized magnitude ("47 minutes").
     *
     * Null when either end of the window is missing, which is the open incident
     * and the malformed row alike; the caller drops the part rather than
     * rendering a sentence about an unknown length.
     *
     * The locale is set on the instance rather than left to Carbon's global,
     * because `App::setLocale()` does not touch it and a push payload renders two
     * languages inside one call anyway.
     */
    private static function durationLabel(Incident $incident, ?string $locale): ?string
    {
        $started = $incident->started_at;
        $resolved = $incident->resolved_at;

        if ($started === null || $resolved === null) {
            return null;
        }

        return $started
            ->locale($locale ?? app()->getLocale())
            ->diffForHumans($resolved, [
                'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 1,
            ]);
    }

    /**
     * The host the monitor watches, as an operator would name it.
     *
     * The full URL is not it: `https://example.com/health?token=...` is long
     * enough to be truncated by every push surface and carries a query string
     * that may hold a credential. The host, plus a port when the monitor names a
     * non-default one, is what identifies the target on a lock screen.
     *
     * Null when there is no monitor to name, which `nullOnDelete` on
     * `primary_monitor_id` makes a real state rather than a defensive one.
     */
    private static function targetLabel(?Monitor $monitor): ?string
    {
        $url = trim((string) ($monitor?->url ?? ''));

        if ($url === '') {
            return null;
        }

        // A scheme-less `host:port` (how a TCP monitor stores its target) parses
        // as a path with the port read as a scheme, so retry it as an authority.
        $parts = parse_url($url) ?: [];
        $host = $parts['host'] ?? (parse_url('//'.ltrim($url, '/')) ?: [])['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = $parts['port'] ?? null;

        return $port === null ? $host : $host.':'.$port;
    }

    /**
     * Join the parts that have something to say, dropping the separator with any
     * part that does not.
     *
     * @param  array<int, string|null>  $parts
     */
    private static function join(array $parts): string
    {
        $present = array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        );

        return implode(self::SEPARATOR, $present);
    }
}
