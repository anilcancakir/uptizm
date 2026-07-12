import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';

/// Projects a design-lab [MonitorSummary] fixture into a [Monitor] ORM model,
/// reconstructing the wire status the model decodes from its computed
/// [StatusKey].
///
/// [MonitorController] migrated its inventory from the [MonitorSummary] DTO to
/// the [Monitor] ORM model, so `seedForTest` now accepts `List<Monitor>`. This
/// bridges the still-present design-lab fixtures onto the model shape without
/// duplicating the four representative monitors across every widget test that
/// seeds the controller: an administratively paused monitor round-trips through
/// the `status` column, every other health through `last_status` (the wire
/// value equals the [StatusKey] name that [statusKeyFromWire] decodes).
Monitor asMonitor(MonitorSummary summary) {
  return Monitor.fromMap(<String, dynamic>{
    'id': summary.id,
    'name': summary.name,
    'url': summary.url,
    if (summary.status == StatusKey.paused)
      'status': 'paused'
    else
      'last_status': summary.status.name,
    if (summary.responseMs != null) 'last_response_ms': summary.responseMs,
    'regions': summary.regions,
    if (summary.sloTarget != null) 'slo_target': summary.sloTarget,
    if (summary.intervalLabel.endsWith('s'))
      'check_interval_sec': int.tryParse(
        summary.intervalLabel.substring(0, summary.intervalLabel.length - 1),
      ),
  });
}

/// The design-lab [monitors] fixture projected into [Monitor] models, for
/// seeding [MonitorController] in widget tests.
List<Monitor> get monitorFixtures => monitors.map(asMonitor).toList();
