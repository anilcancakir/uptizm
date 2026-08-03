<?php

namespace App\Services\Services;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;

/**
 * Reads an Atlassian Statuspage v2 `summary.json` body (the shape every
 * Statuspage-hosted provider serves, documented at https://metastatuspage.com/api).
 *
 * ## Two of the three vocabularies need no mapping table at all
 *
 * `components[].status` uses exactly `operational`, `degraded_performance`,
 * `partial_outage`, `major_outage`, `under_maintenance`, and the first four are
 * BYTE-IDENTICAL to {@see ComponentStatus}'s backing values. `incidents[].impact`
 * uses exactly `none`, `minor`, `major`, `critical`, byte-identical to
 * {@see IncidentImpact}. So both are read with `tryFrom()` and a lookup table
 * would be dead weight that could drift.
 * `tests/Unit/Services/StatuspageV2AdapterTest.php` pins the byte-identity
 * itself, not just the parse, so a future Statuspage rename reddens there
 * instead of silently turning every component unknown.
 *
 * `under_maintenance` is the fifth value and is deliberately NOT in
 * {@see ComponentStatus} (see that enum's own docblock: it mirrors Statuspage's
 * taxonomy "minus the scheduled-maintenance state"). It therefore parses to
 * null = UNKNOWN, which is the correct answer: this catalog has no maintenance
 * vocabulary of its own to render it with, and claiming `operational` for a
 * component the provider took offline on purpose would be a false claim.
 *
 * ## The one field that does need a map, and the choice recorded in it
 *
 * `incidents[].status` is Statuspage's `investigating`, `identified`,
 * `monitoring`, `resolved`, `postmortem`. Four of those are byte-identical to
 * {@see IncidentStatus}; `postmortem` has no counterpart, because this repo's
 * enum carries `detected` and `mitigated` instead. THE CHOICE MADE HERE:
 * `postmortem` is treated as RESOLVED. A postmortem is published after the
 * incident is over, so treating it as open would keep a months-old outage on
 * the public page forever. The inverse choice (an UNRECOGNISED status) is
 * treated as OPEN, because hiding a possible live incident is the more
 * expensive direction of that error.
 *
 * Group rows (`group: true`) are skipped: a Statuspage group's status is a
 * rollup of its own children, which this feed already lists individually, so
 * carrying both would show one outage twice.
 */
class StatuspageV2Adapter implements ServiceStatusAdapter
{
    /**
     * Statuspage incident statuses this catalog reads as terminal even though
     * {@see IncidentStatus} has no case for them.
     *
     * One entry, and it is the whole "mapping table" the byte-identity of the
     * other two vocabularies made unnecessary. Keeping it as a list (rather
     * than folding `postmortem` into a `match`) is what makes the choice
     * greppable from the outside.
     *
     * @var list<string>
     */
    protected const array TERMINAL_STATUSES_WITHOUT_A_LOCAL_CASE = [
        'postmortem',
    ];

    /**
     * Read a `summary.json` payload.
     *
     * Nothing here trusts a key's presence or its type: a Statuspage response
     * arrives from the public internet and this catalog's honest answer to a
     * shape it cannot read is an EMPTY reading, never a healthy one.
     *
     * @param  array<mixed>  $payload  The decoded `summary.json` body.
     * @param  string  $sourceUrl  Unused here: Statuspage publishes absolute incident links in `shortlink`.
     */
    public function read(array $payload, string $sourceUrl): FeedReading
    {
        return new FeedReading(
            indicator: $this->readIndicator($payload),
            components: $this->readComponents($payload),
            incidents: $this->readOpenIncidents($payload),
        );
    }

    /**
     * The provider's own overall word from `status.indicator`, verbatim.
     *
     * Not translated into any local enum (see {@see FeedReading}), and dropped
     * entirely when it is missing, empty, not a string, or longer than the
     * column can hold.
     *
     * @param  array<mixed>  $payload
     */
    protected function readIndicator(array $payload): ?string
    {
        $status = $payload['status'] ?? null;

        if (! is_array($status)) {
            return null;
        }

        $indicator = $status['indicator'] ?? null;

        if (! is_string($indicator) || $indicator === '') {
            return null;
        }

        return mb_strlen($indicator) > FeedReading::MAX_INDICATOR_LENGTH ? null : $indicator;
    }

    /**
     * The provider's component rows, in the order they published them.
     *
     * @param  array<mixed>  $payload
     * @return list<array{label: string, status: ComponentStatus|null}>
     */
    protected function readComponents(array $payload): array
    {
        $components = $payload['components'] ?? null;

        if (! is_array($components)) {
            return [];
        }

        $reading = [];

        foreach ($components as $component) {
            // 1. A null, a scalar, or a group rollup is not a component row.
            if (! is_array($component) || ($component['group'] ?? false) === true) {
                continue;
            }

            // 2. A row with no readable name cannot be labelled on the page,
            //    and an unlabelled status is not a fact anybody can use.
            $label = $component['name'] ?? null;
            if (! is_string($label) || trim($label) === '') {
                continue;
            }

            // 3. tryFrom, never a default: an unrecognised or missing status is
            //    UNKNOWN. `under_maintenance` lands here on purpose.
            $status = $component['status'] ?? null;

            $reading[] = [
                'label' => trim($label),
                'status' => is_string($status) ? ComponentStatus::tryFrom($status) : null,
            ];
        }

        return $reading;
    }

    /**
     * The provider's OPEN incidents only.
     *
     * @param  array<mixed>  $payload
     * @return list<array{title: string, impact: IncidentImpact|null, started_at: string|null, url: string|null}>
     */
    protected function readOpenIncidents(array $payload): array
    {
        $incidents = $payload['incidents'] ?? null;

        if (! is_array($incidents)) {
            return [];
        }

        $reading = [];

        foreach ($incidents as $incident) {
            if (! is_array($incident)) {
                continue;
            }

            // 1. Closed incidents are history; this page publishes present
            //    state. `postmortem` counts as closed (see the class docblock).
            if (! $this->isOpen($incident['status'] ?? null)) {
                continue;
            }

            // 2. An incident with no readable title cannot be rendered as a
            //    claim, and a blank row on a public page is worse than none.
            $title = $incident['name'] ?? null;
            if (! is_string($title) || trim($title) === '') {
                continue;
            }

            $impact = $incident['impact'] ?? null;
            $startedAt = $incident['started_at'] ?? $incident['created_at'] ?? null;

            $reading[] = [
                'title' => trim($title),
                // tryFrom on a byte-identical vocabulary: no table, and an
                // unrecognised impact stays null rather than becoming `none`.
                'impact' => is_string($impact) ? IncidentImpact::tryFrom($impact) : null,
                'started_at' => is_string($startedAt) && $startedAt !== '' ? $startedAt : null,
                'url' => $this->readIncidentUrl($incident['shortlink'] ?? null),
            ];
        }

        return $reading;
    }

    /**
     * The incident's own `shortlink`, kept only when it is an http(s) URL.
     *
     * Statuspage publishes an ABSOLUTE link on Atlassian's own shortener
     * (`https://stspg.io/<id>`), so unlike Google's relative `uri` there is
     * nothing to compose. The scheme check is not cosmetic: this value is
     * rendered into an `href` on a public page, and a payload carrying
     * `javascript:` or `data:` there survives Blade's attribute escaping.
     */
    protected function readIncidentUrl(mixed $shortlink): ?string
    {
        if (! is_string($shortlink) || trim($shortlink) === '') {
            return null;
        }

        $shortlink = trim($shortlink);
        $scheme = parse_url($shortlink, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $shortlink : null;
    }

    /**
     * Whether a Statuspage incident status means the incident is still live.
     *
     * Resolved and postmortem are closed. Everything else, INCLUDING a status
     * this catalog has never seen and a missing status, is treated as open: a
     * stale incident on the page is a visible error an operator can correct,
     * while a suppressed live one is invisible.
     */
    protected function isOpen(mixed $status): bool
    {
        if (! is_string($status)) {
            return true;
        }

        if (in_array($status, self::TERMINAL_STATUSES_WITHOUT_A_LOCAL_CASE, true)) {
            return false;
        }

        return IncidentStatus::tryFrom($status)?->isTerminal() !== true;
    }
}
