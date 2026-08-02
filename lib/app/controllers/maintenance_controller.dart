import 'package:magic/magic.dart';

import '../models/scheduled_maintenance.dart';

/// Controller behind the scheduled-maintenance write path.
///
/// One action, [create]: it persists the window the incident-create form
/// composes under its maintenance kind (`POST /scheduled-maintenances`) and
/// returns the backend's per-field validation errors so the form can paint a
/// 422 inline. It follows `IncidentController.create`'s shape (ORM `save()`,
/// bool-checked, field errors handed back, generic toast for a non-field
/// failure) and deliberately has no read side: there is no maintenance list or
/// detail screen yet, so caching a roster here would be state nothing reads.
///
/// It is therefore not session-scoped either: it holds no tenant-scoped state
/// to clear when the authenticated identity changes.
class MaintenanceController extends MagicController {
  /// Singleton accessor. Registers the controller on first access; there is no
  /// initial load to trigger (see the class docblock).
  static MaintenanceController get instance =>
      Magic.findOrPut(MaintenanceController.new);

  /// Creates a maintenance window and returns to the incidents list.
  ///
  /// [fields] is the create form's wire-field map, matching
  /// `StoreScheduledMaintenanceRequest`: `status_page_id`, `title`, an optional
  /// `description`, the UTC ISO-8601 `starts_at` / `ends_at` bounds, and the
  /// `monitor_ids` pivot list. It mass-assigns them into a fresh
  /// [ScheduledMaintenance] and persists through the ORM.
  ///
  /// Returns the backend per-field validation errors (single message per field,
  /// keyed by the wire field name) so the form can render a server 422 inline;
  /// an empty map means success. A failed save carrying field errors
  /// ([ScheduledMaintenance.validationErrors]) stays on the form with no toast
  /// so the operator corrects the flagged fields; a failed save with NO field
  /// errors (a transport error, a 500) surfaces the generic error toast and
  /// returns an empty map. [ScheduledMaintenance.save] absorbs transport
  /// failures internally and returns `false` rather than throwing.
  ///
  /// The subscriber announcement is NOT this client's concern: the backend
  /// claims it atomically on create, which is what makes it announce once.
  Future<Map<String, String>> create(Map<String, dynamic> fields) async {
    final ScheduledMaintenance window = ScheduledMaintenance()..fill(fields);

    final bool ok = await window.save();
    if (!ok) {
      final Map<String, String>? fieldErrors = _fieldErrorsOrToast(window);
      if (fieldErrors != null) return fieldErrors;
      return const {};
    }

    // Read back off the model, not out of [fields]: a title the mass-assignment
    // filter dropped must read as missing here rather than be papered over by
    // the map it was dropped from.
    Magic.success(trans('uptizm.incidents.submit_schedule'), window.title);
    MagicRoute.to('/incidents');
    return const {};
  }

  /// Resolves a failed [window] save into either its per-field validation
  /// errors or a generic toast.
  ///
  /// Returns the field errors (single message per field, keyed by the wire field
  /// name) when the failed save carried the Laravel 422 shape, so the caller
  /// hands them to the form and stays put. Returns `null` for a non-field
  /// failure after surfacing the generic error toast and logging the cause, so
  /// the caller falls back to its empty-map contract. Mirrors
  /// `IncidentController._fieldErrorsOrToast`.
  Map<String, String>? _fieldErrorsOrToast(ScheduledMaintenance window) {
    final Map<String, List<String>> errors = window.validationErrors;
    if (errors.isNotEmpty) {
      return {
        for (final MapEntry<String, List<String>> entry in errors.entries)
          entry.key: entry.value.first,
      };
    }

    Log.error(
      '[MaintenanceController.create] save returned false with no errors',
    );
    Magic.error(
      trans('common.error_occurred'),
      trans('common.error_occurred'),
    );
    return null;
  }
}
