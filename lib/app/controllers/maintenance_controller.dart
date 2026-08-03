import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/scheduled_maintenance.dart';

/// Controller behind scheduled maintenance windows: the write path and the
/// roster the Incidents screen's Maintenance tab renders.
///
/// [create] persists the window the incident-create form composes under its
/// maintenance kind (`POST /scheduled-maintenances`) and returns the backend's
/// per-field validation errors so the form can paint a 422 inline. It follows
/// `IncidentController.create`'s shape (ORM `save()`, bool-checked, field errors
/// handed back, generic toast for a non-field failure).
///
/// The read side ([windows], [load], [delete]) exists because without it a
/// window could be created and never seen again: the backend's index, show,
/// update and destroy endpoints all shipped with no caller, so the only surface
/// that ever showed a window was the PUBLIC status page. An operator planning
/// work in the product could not review it, let alone cancel it.
///
/// Session-scoped, which the write-only version deliberately was not. A roster
/// is tenant state, so a login or a team switch must clear it before the next
/// authenticated render or the incoming identity reads the previous team's
/// planned work. `SessionScopeSync` discovers this by TYPE, so implementing the
/// interface is the whole registration.
class MaintenanceController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor. Registers the controller; it does NOT fetch.
  ///
  /// The consumer triggers the first load (`if (!resolvedOnce) load()` in its
  /// `initState`), which is the same shape the incident-create view uses for
  /// `StatusPageController`. Two alternatives were tried and both are wrong
  /// here: `onInit` never fires for a controller that does not BACK a view, so
  /// the tab rendered "0 of 0" against a database holding a window with no
  /// request in the log; and self-firing from this getter starts a fetch before
  /// a test's `seedForTest` runs, so the late response clobbers the seed.
  static MaintenanceController get instance =>
      Magic.findOrPut(MaintenanceController.new);

  /// The team's maintenance windows, newest window first (the backend orders by
  /// `starts_at` descending).
  List<ScheduledMaintenance> get windows =>
      List<ScheduledMaintenance>.unmodifiable(_windows);

  List<ScheduledMaintenance> _windows = const [];

  /// Whether a roster fetch has ever resolved.
  ///
  /// The tab renders a skeleton until it has, so an empty list before the first
  /// fetch never reads as "no windows planned", which is a different claim.
  bool get resolvedOnce => _resolvedOnce;

  bool _resolvedOnce = false;

  /// Fetches the team's windows and republishes the roster.
  ///
  /// Degrades to the last known good list on a transport failure rather than
  /// blanking the tab, and logs the cause. [_resolvedOnce] is only set on a
  /// successful read, so a failed first fetch keeps the skeleton instead of
  /// claiming there is nothing planned.
  Future<void> load() async {
    try {
      final List<ScheduledMaintenance> fetched =
          await ScheduledMaintenance.all();

      _windows = fetched;
      _resolvedOnce = true;
    } catch (error) {
      Log.error('[MaintenanceController.load] failed: $error');
    }

    refreshUI();
  }

  /// Re-reads the roster. Same as [load]; named for the call sites that mean
  /// "refresh" rather than "first load".
  Future<void> reload() => load();

  @override
  Future<void> resetForSession() async {
    // Cleared BEFORE the refetch: [load] keeps the last-known-good list when a
    // fetch fails, so across an identity change a failed refetch would otherwise
    // leave the previous team's planned work on screen. Back to "not asked yet"
    // so the incoming identity gets a skeleton, not the outgoing team's answer.
    _windows = const [];
    _resolvedOnce = false;
    refreshUI();

    await load();
  }

  /// Seeds the roster directly, for widget tests that render the tab without a
  /// network.
  @visibleForTesting
  void seedForTest(List<ScheduledMaintenance> seed) {
    _windows = List<ScheduledMaintenance>.from(seed);
    _resolvedOnce = true;
    refreshUI();
  }

  /// Creates a maintenance window and opens the Maintenance tab.
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
  /// The roster is reloaded and the navigation lands on the Maintenance tab
  /// rather than the incidents list. It used to land on `/incidents`, where the
  /// default tab lists INCIDENTS: a window was created successfully and the
  /// operator was shown "No incidents yet".
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

    await load();

    // Read back off the model, not out of [fields]: a title the mass-assignment
    // filter dropped must read as missing here rather than be papered over by
    // the map it was dropped from.
    Magic.success(trans('uptizm.incidents.submit_schedule'), window.title);
    MagicRoute.to('/incidents', query: const {'tab': 'maintenance'});
    return const {};
  }

  /// Deletes the window [id] and refreshes the roster.
  ///
  /// Cancelling planned work is the other half of being able to see it: a window
  /// on the public status page that cannot be withdrawn from the product is a
  /// promise to customers with no way back.
  Future<void> delete(String id) async {
    final ScheduledMaintenance? window = _windows
        .where((ScheduledMaintenance candidate) => candidate.id == id)
        .firstOrNull;

    if (window == null) return;

    try {
      final bool ok = await window.delete();
      if (!ok) {
        Log.error('[MaintenanceController.delete] $id: save returned false');
        Magic.error(
          trans('common.error_occurred'),
          trans('common.error_occurred'),
        );

        return;
      }

      Magic.success(
        trans('uptizm.incidents.maintenance_deleted_title'),
        window.title,
      );
    } catch (error) {
      Log.error('[MaintenanceController.delete] $id failed: $error');
      Magic.error(
        trans('common.error_occurred'),
        trans('common.error_occurred'),
      );
    }

    await load();
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
