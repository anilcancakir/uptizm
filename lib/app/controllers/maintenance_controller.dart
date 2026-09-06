import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/scheduled_maintenance.dart';
import '../support/field_errors.dart';
import '../support/roster_page.dart';

/// Controller behind scheduled maintenance windows: the write path and the
/// roster the Incidents screen's Maintenance tab renders.
///
/// [create] persists the window the incident-create form composes under its
/// maintenance kind (`POST /scheduled-maintenances`), validating client-side
/// against [_createRules] first. It follows `MonitorController.create`'s
/// contract: `Future<bool>`, with the per-field detail published on
/// [validationErrors] (via [ValidatesRequests]) for the create view's
/// `hasError`/`getError` reads rather than in the return value. Because the
/// create view is `MagicStatefulView<IncidentController>`, the framework's
/// per-mount error clear never reaches this controller; the view calls
/// [clearErrors] explicitly whenever it enters maintenance mode.
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
    with ValidatesRequests
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

  /// The token for the next page, or null when the roster has been walked.
  String? _nextCursor;

  /// Whether a [loadMore] is in flight.
  bool _loadingMore = false;

  /// Whether the last read failed to reach an answer.
  bool _loadFailed = false;

  /// Fetches the team's windows and republishes the roster.
  ///
  /// Degrades to the last known good list on a transport failure rather than
  /// blanking the tab, and logs the cause. [_resolvedOnce] is only set on a
  /// successful read, so a failed first fetch keeps the skeleton instead of
  /// claiming there is nothing planned.
  Future<void> load() => _load(reset: true);

  /// Appends the next page of windows. A no-op at the end of the roster.
  Future<void> loadMore() async {
    if (_nextCursor == null || _loadingMore) return;

    _loadingMore = true;
    refreshUI();
    await _load(reset: false);
    _loadingMore = false;
    refreshUI();
  }

  /// Whether a page after the current one exists.
  bool get hasMore => _nextCursor != null;

  /// Whether the next page is being fetched right now.
  bool get isLoadingMore => _loadingMore;

  /// Whether there is nothing to show AND the reason is a failed read.
  bool get loadFailed => _loadFailed && _windows.isEmpty;

  Future<void> _load({required bool reset}) async {
    final RosterPage<ScheduledMaintenance> page =
        await readRosterPage<ScheduledMaintenance>(
          resource: 'scheduled-maintenances',
          fromMap: ScheduledMaintenance.fromMap,
          logTag: 'MaintenanceController.load',
          cursor: reset ? null : _nextCursor,
        );

    _resolvedOnce = true;

    if (page.failed) {
      // The catch this replaces logged and moved on, leaving the previous
      // windows on screen with nothing to say the read had failed.
      _loadFailed = true;
      refreshUI();

      return;
    }

    _loadFailed = false;
    _nextCursor = page.nextCursor;
    _windows = reset
        ? page.rows!
        : <ScheduledMaintenance>[..._windows, ...page.rows!];
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
    // A cursor names a row in the OUTGOING team's ordering, and the reset's own
    // refetch only overwrites it when that refetch succeeds.
    _nextCursor = null;
    _loadingMore = false;
    clearErrors();
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

  // ---------------------------------------------------------------------------
  // The write path's client-side rules.
  // ---------------------------------------------------------------------------

  /// The client-side mirror of `StoreScheduledMaintenanceRequest::rules()`
  /// (`POST /scheduled-maintenances`).
  ///
  /// Only the rules magic ships EXACTLY are here. Deliberately absent:
  ///
  ///  - `status_page_id`'s `bail` + `IdFormat::rules()` (`string`/`uuid` or
  ///    `integer`) + `Rule::exists`: same reasoning as
  ///    `IncidentController._createRules`'s `monitor_id` — magic has no type
  ///    rule for a bare id shape, and every [AsyncRule] (including [Unique])
  ///    is skipped SILENTLY by the synchronous [validate].
  ///  - `title`'s `string` and `description`'s `nullable|string`: magic has
  ///    no type rules; [Max] measures whatever type it is handed, and both
  ///    fields always arrive here as a String.
  ///  - `suppress_alerts`'s `sometimes|boolean`: magic has no boolean rule,
  ///    and no field in the create form ever sets it.
  ///  - `starts_at`/`ends_at`'s `date`: magic has no date rule. Both are
  ///    seeded to a real [DateTime] the moment the view mounts, so
  ///    [Required] is a redundant-but-honest mirror of a shape the picker
  ///    already guarantees, kept for the rare direct call this class's own
  ///    tests make with a raw map.
  ///  - `ends_at`'s `after:starts_at`: the one window rule this client does
  ///    NOT approximate. A naive local comparison could reject a window the
  ///    server's own UTC one accepts.
  ///  - `monitor_ids` (`sometimes|array`) and `monitor_ids.*` (`Rule::exists`
  ///    per element): no magic type rule for an array, and the same silently
  ///    skipped [AsyncRule] concern as `status_page_id`. The create view's
  ///    own "select at least one monitor" policy is STRICTER than this
  ///    endpoint (which accepts zero), so it stays a view-local check rather
  ///    than living here: it is not a rule this endpoint enforces at all.
  Map<String, List<Rule>> get _createRules => <String, List<Rule>>{
    'status_page_id': [Required()],
    'title': [Required(), Max(200)],
    'description': [Max(2000)],
    'starts_at': [Required()],
    'ends_at': [Required()],
  };

  /// Creates a maintenance window and opens the Maintenance tab.
  ///
  /// [fields] is the create form's wire-field map, matching
  /// `StoreScheduledMaintenanceRequest`: `status_page_id`, `title`, an optional
  /// `description`, the UTC ISO-8601 `starts_at` / `ends_at` bounds, and the
  /// `monitor_ids` pivot list. It is checked against [_createRules] BEFORE
  /// anything is sent, then mass-assigned into a fresh [ScheduledMaintenance]
  /// and persisted through the ORM.
  ///
  /// Answers whether the window was written, following
  /// `MonitorController.create`'s contract: the per-field detail does NOT
  /// travel in the return value. It is published in [validationErrors] and
  /// the create form reads it back through [hasError] / [getError]. So
  /// `false` with a populated [validationErrors] means "stay on the form and
  /// correct the flagged fields", and `false` with an EMPTY one means the
  /// generic save-failed toast has already fired.
  ///
  /// [ScheduledMaintenance.save] absorbs transport failures internally and
  /// returns `false` rather than throwing; a `false` that carries the Laravel
  /// 422 shape on [ScheduledMaintenance.validationErrors] is republished here.
  ///
  /// On success, the roster is reloaded and the navigation lands on the
  /// Maintenance tab rather than the incidents list. It used to land on
  /// `/incidents`, where the default tab lists INCIDENTS: a window was
  /// created successfully and the operator was shown "No incidents yet".
  ///
  /// The subscriber announcement is NOT this client's concern: the backend
  /// claims it atomically on create, which is what makes it announce once.
  Future<bool> create(Map<String, dynamic> fields) async {
    try {
      validate(fields, _createRules);
    } on ValidationException {
      return false;
    }

    final ScheduledMaintenance window = ScheduledMaintenance()
      ..fill(fields, strict: true);

    final bool ok = await window.save();
    if (!ok) return _publishFieldErrors(window);

    await load();

    // Read back off the model, not out of [fields]: a title the mass-assignment
    // filter dropped must read as missing here rather than be papered over by
    // the map it was dropped from.
    Magic.success(trans('uptizm.incidents.submit_schedule'), window.title);
    MagicRoute.to('/incidents', query: const {'tab': 'maintenance'});
    return true;
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

  /// Publishes a failed [window] save as either per-field validation errors
  /// or a generic toast, and answers `false` either way.
  ///
  /// Mirrors `IncidentController._publishFieldErrors` / `MonitorController.
  /// _publishFieldErrors`: a failure carrying field errors lands on
  /// [validationErrors] for the form's `hasError`/`getError` to read; a
  /// failure carrying NONE (a transport error, a 500) surfaces the generic
  /// toast instead and logs the cause.
  bool _publishFieldErrors(ScheduledMaintenance window) {
    final Map<String, String> fieldErrors = fieldErrorsFromModel(window);
    if (fieldErrors.isNotEmpty) {
      validationErrors = fieldErrors;
      refreshUI();

      return false;
    }

    Log.error(
      '[MaintenanceController.create] save returned false with no errors',
    );
    Magic.error(
      trans('common.error_occurred'),
      trans('common.error_occurred'),
    );

    return false;
  }
}
