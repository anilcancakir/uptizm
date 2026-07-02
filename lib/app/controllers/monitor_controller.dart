import 'package:magic/magic.dart';

import '../mocks/monitors.dart';
import '../mocks/monitors.dart' as monitors_fixture;

/// Controller backing the four routed monitor screens ([MonitorsListView],
/// [MonitorDetailView], [MonitorCreateView], [MonitorEditView]).
///
/// Holds the monitor fixture access ([monitors], [monitorById]) plus the mock
/// business actions the views used to run inline ([pause], [resume], [delete],
/// [create], [save]). Those actions are toast + navigation only: the fixtures
/// are `const` and this is a design-lab mock, so no monitor state is mutated
/// and no [refreshUI] is needed (parity with the pre-controller behavior).
///
/// The custom-metrics CRUD stays local to the standalone [MonitorMetricsTab]
/// (an embedded, non-routed component), so it is intentionally absent here.
class MonitorController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static MonitorController get instance =>
      Magic.findOrPut(MonitorController.new);

  /// The monitor inventory fixture.
  ///
  /// Delegates to the top-level `monitors` fixture (reached through a prefixed
  /// import to avoid shadowing this getter).
  List<MonitorSummary> get monitors => monitors_fixture.monitors;

  /// Resolves a monitor by [id], or `null` when none matches.
  MonitorSummary? monitorById(String? id) => findMonitor(id);

  // ---------------------------------------------------------------------------
  // Business actions (mock: toast + navigation, no persistence).
  // ---------------------------------------------------------------------------

  /// Surfaces the paused toast for the monitor [id] (the view runs the confirm
  /// dialog before calling). No-op when [id] resolves to no fixture.
  void pause(String id) {
    final MonitorSummary? monitor = monitorById(id);
    if (monitor == null) return;

    Magic.success(
      trans('uptizm.monitors.toast_paused_title'),
      trans('uptizm.monitors.toast_paused_description', {'name': monitor.name}),
    );
  }

  /// Surfaces the resumed toast for the monitor [id]. No-op when [id] resolves
  /// to no fixture.
  void resume(String id) {
    final MonitorSummary? monitor = monitorById(id);
    if (monitor == null) return;

    Magic.success(
      trans('uptizm.monitors.toast_resumed_title'),
      trans('uptizm.monitors.toast_resumed_description', {'name': monitor.name}),
    );
  }

  /// Surfaces the deleted toast for the monitor [id] and returns to the
  /// monitors list (the view runs the confirm dialog before calling). No-op
  /// when [id] resolves to no fixture.
  void delete(String id) {
    final MonitorSummary? monitor = monitorById(id);
    if (monitor == null) return;

    Magic.success(
      trans('uptizm.monitors.toast_deleted_title'),
      trans('uptizm.monitors.toast_deleted_description', {'name': monitor.name}),
    );
    MagicRoute.to('/monitors');
  }

  /// Completes the create flow by returning to the monitors list (mock:
  /// nothing persists, so both submit and cancel land here).
  void create() => MagicRoute.to('/monitors');

  /// Completes the edit flow for the monitor [id] by returning to its detail
  /// route (mock: nothing persists, so both submit and cancel land here).
  void save(String id) => MagicRoute.to('/monitors/$id');
}
