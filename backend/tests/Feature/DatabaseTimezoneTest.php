<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pins the PostgreSQL session time zone to UTC.
 *
 * Laravel binds a datetime as a NAIVE string ("2026-07-27 00:28:29") in the
 * app time zone, and PostgreSQL resolves a naive literal written to a
 * `timestamptz` column using the SESSION time zone. Sixteen columns in this
 * schema are `timestamptz`, including `monitor_checks.checked_at`,
 * `monitors.next_check_at`, `incidents.started_at` / `resolved_at`,
 * `incident_updates.display_at` and the on-call override window.
 *
 * With the session zone inherited from the server's OS (a developer machine on
 * Europe/Istanbul), every one of those writes was shifted by the offset: an
 * instant stamped 00:28:29Z read back as "00:28:29+03", three hours earlier
 * than it happened. Live, that surfaced as an incident resolved seconds ago
 * reading "resolved 3h ago", and it silently moves uptime windows, incident
 * durations and which responder is on call.
 *
 * The suite runs on SQLite, so this asserts the configuration rather than the
 * driver behaviour: the connector only issues `SET TIME ZONE` when the key is
 * present, so losing the key is exactly the regression to catch.
 */
class DatabaseTimezoneTest extends TestCase
{
    public function test_the_postgres_connection_pins_its_session_timezone_to_utc(): void
    {
        $this->assertSame('UTC', config('database.connections.pgsql.timezone'));
    }

    public function test_the_application_timezone_matches_the_connection_timezone(): void
    {
        // The naive string Laravel binds is in the app zone, so the two must
        // agree or the same shift reappears in the other direction.
        $this->assertSame(
            config('app.timezone'),
            config('database.connections.pgsql.timezone'),
        );
    }
}
