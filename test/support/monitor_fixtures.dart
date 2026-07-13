import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/mocks/monitors.dart';

/// The design-lab [monitors] fixtures, exposed under the name widget and
/// controller tests seed [MonitorController] with.
///
/// The fixtures are already [Monitor] ORM models: the predecessor
/// `MonitorSummary` DTO was deleted once every controller migrated to the
/// model, so the mocks layer now hydrates the four representative monitors
/// through [Monitor.fromMap] directly. This getter keeps the historical
/// `monitorFixtures` name the seeding tests import.
List<Monitor> get monitorFixtures => monitors;
