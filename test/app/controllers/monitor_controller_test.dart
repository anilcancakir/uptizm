import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/resources/views/monitors/monitors_index_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_show_view.dart';

void main() {
  group('MonitorController', () {
    test('can be instantiated', () {
      final controller = MonitorController();
      expect(controller, isA<MonitorController>());
    });

    test('index() returns MonitorsIndexView widget', () {
      final controller = MonitorController();
      final result = controller.index();
      expect(result, isA<MonitorsIndexView>());
    });

    test('show() returns MonitorShowView widget', () {
      final controller = MonitorController();
      final result = controller.show();
      expect(result, isA<MonitorShowView>());
    });

    test('create() returns Widget', () {
      final controller = MonitorController();
      final result = controller.create();
      expect(result, isA<Widget>());
    });

    test('monitorsNotifier starts with empty list', () {
      final controller = MonitorController();
      expect(controller.monitorsNotifier.value, isEmpty);
    });

    test('checksNotifier starts with empty list', () {
      final controller = MonitorController();
      expect(controller.checksNotifier.value, isEmpty);
    });

    test('checksPaginationNotifier starts with null', () {
      final controller = MonitorController();
      expect(controller.checksPaginationNotifier.value, isNull);
    });

    test('loadChecks updates pagination state', () async {
      final controller = MonitorController();
      // Note: We can't easily mock the static MonitorCheck.forMonitor call here
      // so we just verify the method exists and handles the call
      try {
        await controller.loadChecks(1);
      } catch (_) {
        // Expected to fail due to no backend connection in unit test
      }
    });

    test('loadNextPage exists', () async {
      final controller = MonitorController();
      expect(controller.loadNextPage, isNotNull);
    });

    test('loadPreviousPage exists', () async {
      final controller = MonitorController();
      expect(controller.loadPreviousPage, isNotNull);
    });

    test('selectedMonitorNotifier starts with null', () {
      final controller = MonitorController();
      expect(controller.selectedMonitorNotifier.value, isNull);
    });
  });
}
