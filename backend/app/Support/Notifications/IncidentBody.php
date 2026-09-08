<?php

namespace App\Support\Notifications;

use App\Enums\IncidentSeverity;
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
            self::subjectLabel($incident),
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
            // The host, unconditionally: this family's title is
            // `:monitor got worse`, so it names the monitor whatever the
            // incident's own title says.
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
            // The host, unconditionally: this family's title is
            // `:monitor is resolved`, so it names the monitor whatever the
            // incident's own title says. Measured live on 2026-09-08, reading
            // `title_params` here instead put the monitor name in both lines of
            // an authored incident: "API sorunu giderildi" over "30 dakika 33
            // saniye sürdü · API".
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
        // A match rather than a key built from the stored token. Laravel's
        // translator answers a miss with the key itself, so a fourth enum case
        // would have shipped "notifications.severity_major · example.com" to a
        // lock screen with nothing failing anywhere; this way it does not
        // compile. Same shape as `IncidentOpened::pagerDutySeverity()`.
        $key = match ($incident->severity) {
            IncidentSeverity::Critical => 'notifications.severity_critical',
            IncidentSeverity::Warn => 'notifications.severity_warn',
            IncidentSeverity::Info => 'notifications.severity_info',
        };

        return __($key, [], $locale);
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
     *
     * TWO parts, not one. At one part a 119-minute outage renders "1 hour", which
     * rounds away most of what the operator is reading the line for; at two it is
     * "1 hour 59 minutes", and the shorter windows are unchanged (47 minutes is
     * still "47 minutes", a day still "1 day").
     */
    private static function durationLabel(Incident $incident, ?string $locale): ?string
    {
        $started = $incident->started_at;
        $resolved = $incident->resolved_at;

        if ($started === null || $resolved === null) {
            return null;
        }

        // An open-and-close inside the same second renders "0 seconds", which is
        // a sentence about nothing. The part goes rather than the number being
        // rounded up to a second that did not pass.
        if ($started->diffInSeconds($resolved, true) < 1) {
            return null;
        }

        return $started
            ->locale($locale ?? app()->getLocale())
            ->diffForHumans($resolved, [
                'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 2,
            ]);
    }

    /**
     * What the incident is about, in the words the title did not already spend.
     *
     * Only the OPENED family needs this, and only because its title is the
     * incident's own sentence (`incident_opened_title` is `:title`), which names
     * the monitor for some incidents and not for others. A third of the
     * catalogue is metric-derived and names the METRIC alone
     * (`incidents.metric_critical_bound` is ":metric breached critical bound"),
     * and an operator-authored title names whatever a human typed. On those the
     * host would leave the row with no monitor name anywhere the client renders,
     * since it shows the title over the body and reads `monitor_name` only for
     * routing.
     *
     * The escalated and resolved families do NOT go through here: their titles
     * are `:monitor got worse` and `:monitor is resolved`, so the monitor is
     * always already named and the body owes the host instead.
     */
    private static function subjectLabel(Incident $incident): ?string
    {
        $params = $incident->title_params;
        $titleNamesMonitor = is_array($params)
            && is_string($params['monitor'] ?? null)
            && trim($params['monitor']) !== '';

        return $titleNamesMonitor
            ? self::targetLabel($incident->primaryMonitor)
            : self::monitorLabel($incident->primaryMonitor);
    }

    /**
     * The monitor's own name, or null when there is no monitor left to name.
     */
    private static function monitorLabel(?Monitor $monitor): ?string
    {
        $name = trim((string) ($monitor?->name ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * The host the monitor watches, as an operator would name it.
     *
     * The full URL is not it: `https://example.com/health?token=...` is long
     * enough to be truncated by every push surface and carries a query string
     * that may hold a credential. Host plus port is one component tighter than
     * `SentryScrubber::originOnly()`, which the repo already settled on for this
     * same value class, and it drops userinfo too.
     *
     * Null when there is no monitor to name, which is a real state: deleting a
     * monitor leaves its incidents behind (`primary_monitor_id` is `nullOnDelete`,
     * and a soft delete takes it out of the relation's scope).
     */
    private static function targetLabel(?Monitor $monitor): ?string
    {
        $url = trim((string) ($monitor?->url ?? ''));

        if ($url === '') {
            return null;
        }

        // `parse_url` reads a scheme-less `host:port` as host plus port already
        // (measured on PHP 8.5.2), so the authority retry is for the shapes it
        // answers with a bare path instead: a plain `example.com`.
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
