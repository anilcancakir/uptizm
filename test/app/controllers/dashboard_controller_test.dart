import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/dashboard_controller.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('DashboardController.instance registers and returns a singleton', () {
    final DashboardController first = DashboardController.instance;
    final DashboardController second = DashboardController.instance;

    expect(identical(first, second), isTrue);
  });

  test('activeIncidents delegates to the shared not-resolved fixture filter', () {
    final DashboardController controller = DashboardController.instance;

    final List<IncidentSummary> expected = incidents
        .where((i) => i.lifecycle != IncidentLifecycle.resolved)
        .toList();

    expect(controller.activeIncidents, equals(expected));
  });

  test('aiSuggestions delegates to the shared ai-payload fixture filter', () {
    final DashboardController controller = DashboardController.instance;

    final List<IncidentSummary> expected = activeIncidents
        .where((i) => i.ai != null)
        .toList();

    expect(controller.aiSuggestions, equals(expected));
  });

  test('upCount and downCount count monitors by status', () {
    final DashboardController controller = DashboardController.instance;

    final int expectedUp = monitors.where((m) => m.status == StatusKey.up).length;
    final int expectedDown =
        monitors.where((m) => m.status == StatusKey.down).length;

    expect(controller.upCount, equals(expectedUp));
    expect(controller.downCount, equals(expectedDown));
  });

  test('monitorCount matches the fixture monitor list length', () {
    final DashboardController controller = DashboardController.instance;

    expect(controller.monitorCount, equals(monitors.length));
  });

  test('aiActiveCount counts ai-owned active incidents', () {
    final DashboardController controller = DashboardController.instance;

    final int expected = controller.activeIncidents
        .where((i) => i.aiOwned)
        .length;

    expect(controller.aiActiveCount, equals(expected));
  });

  test('avgResponseMs averages the response times of monitors that report one', () {
    final DashboardController controller = DashboardController.instance;

    final List<MonitorSummary> responders =
        monitors.where((m) => m.responseMs != null).toList();
    final int expected = responders.isEmpty
        ? 0
        : (responders.fold<int>(0, (sum, m) => sum + m.responseMs!) /
                responders.length)
            .round();

    expect(controller.avgResponseMs, equals(expected));
  });
}
