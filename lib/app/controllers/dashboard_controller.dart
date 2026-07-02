import 'package:magic/magic.dart';

import '../mocks/incidents.dart';
import '../mocks/incidents.dart' as incidents_fixture;
import '../mocks/monitors.dart';
import '../mocks/status.dart';

/// Controller backing [DashboardView].
///
/// Data-only: the dashboard has no mutable state and no mock actions, so this
/// controller only exposes derived reads over the fixture data (monitors,
/// incidents). The active-incident / AI-suggestion filters delegate to the
/// shared getters in `lib/app/mocks/incidents.dart` so `IncidentController`
/// reuses the same source instead of re-deriving them.
class DashboardController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static DashboardController get instance =>
      Magic.findOrPut(DashboardController.new);

  /// Active incidents: everything not yet resolved.
  ///
  /// Delegates to the shared `activeIncidents` getter in `incidents.dart`.
  List<IncidentSummary> get activeIncidents =>
      incidents_fixture.activeIncidents;

  /// AI inbox entries: active incidents that carry an AI analysis payload.
  ///
  /// Delegates to the shared `aiSuggestions` getter in `incidents.dart`.
  List<IncidentSummary> get aiSuggestions => incidents_fixture.aiSuggestions;

  /// Count of monitors currently up.
  int get upCount => monitors.where((m) => m.status == StatusKey.up).length;

  /// Count of monitors currently down.
  int get downCount => monitors.where((m) => m.status == StatusKey.down).length;

  /// Total number of monitors.
  int get monitorCount => monitors.length;

  /// Count of active incidents currently owned by the AI.
  int get aiActiveCount => activeIncidents.where((i) => i.aiOwned).length;

  /// Average response time (ms) across monitors that report timing.
  int get avgResponseMs {
    final List<MonitorSummary> responders = monitors
        .where((m) => m.responseMs != null)
        .toList();

    if (responders.isEmpty) return 0;

    return (responders.fold<int>(0, (sum, m) => sum + m.responseMs!) /
            responders.length)
        .round();
  }
}
