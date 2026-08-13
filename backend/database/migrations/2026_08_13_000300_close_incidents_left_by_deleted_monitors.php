<?php

use App\Enums\IncidentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Closes the incidents that were already orphaned before a monitor's `deleted`
 * hook started doing it.
 *
 * Measured on production before this ran: five monitors deleted, five incidents
 * left with no live monitor at all, THREE of them still open, out of eight the
 * dashboard was calling open. None of the three could ever have closed. Auto-
 * resolve rides the next check and no check arrives for a deleted monitor; every
 * manual write path went through `IncidentWriteService::monitorFor()`, which
 * threw on a missing monitor, so the Resolve button answered 500; and the
 * escalation ladder gates on lifecycle and maintenance without asking whether
 * the monitor still exists, so a queued step would still have paged the on-call
 * about a monitor nobody could look at.
 *
 * Deliberately raw rather than routed through the service. The service closes
 * ONE monitor's orphans and emits a timeline note authored by that event; this
 * is a backfill of rows whose deleting event is long past, and the note it
 * leaves says so in its own words. Running the service per deleted monitor
 * would also re-enter the model layer during a migration, which is the shape
 * that breaks when a later refactor changes the hook.
 *
 * Not reversible. `down()` would have to reopen incidents that were closed for a
 * true reason, and an incident reopened by a rollback pages people.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deletedMonitorIds = DB::table('monitors')->whereNotNull('deleted_at')->pluck('id');

        if ($deletedMonitorIds->isEmpty()) {
            return;
        }

        $candidateIds = DB::table('incident_monitors')
            ->whereIn('monitor_id', $deletedMonitorIds)
            ->pluck('incident_id')
            ->unique();

        foreach ($candidateIds as $incidentId) {
            $incident = DB::table('incidents')
                ->where('id', $incidentId)
                ->whereNull('deleted_at')
                ->whereNull('resolved_at')
                ->first();

            if ($incident === null) {
                continue;
            }

            // Still live if ANY attached monitor survives. An incident spanning
            // two components is not over because one of them was deleted.
            $liveMonitors = DB::table('incident_monitors')
                ->join('monitors', 'monitors.id', '=', 'incident_monitors.monitor_id')
                ->where('incident_monitors.incident_id', $incidentId)
                ->whereNull('monitors.deleted_at')
                ->count();

            if ($liveMonitors > 0) {
                continue;
            }

            $now = now();

            DB::table('incidents')->where('id', $incidentId)->update([
                'lifecycle' => IncidentStatus::Resolved->value,
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('incident_updates')->insert([
                'id' => (string) Str::uuid(),
                'incident_id' => $incidentId,
                'actor' => 'system',
                'author' => 'Uptizm',
                'status' => IncidentStatus::Resolved->value,
                'message' => 'Closed during a backfill: the monitor this incident belonged to had '
                    .'been deleted, so no further check could report on it.',
                'is_public' => false,
                'autonomous' => true,
                'display_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: see the class docblock.
    }
};
