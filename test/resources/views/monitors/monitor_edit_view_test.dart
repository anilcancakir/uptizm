import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/enums/monitor_type.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/resources/views/monitors/monitor_edit_view.dart';
import 'package:uptizm/resources/views/components/monitors/monitor_basic_info_section.dart';
import 'package:uptizm/resources/views/components/monitors/monitor_settings_section.dart';

void main() {
  group('MonitorEditView', () {
    setUp(() {
      // Reset controller state between tests
      MonitorController.instance.selectedMonitorNotifier.value = null;
    });

    Widget buildSubject() {
      return WindTheme(
        data: WindThemeData(),
        child: MaterialApp(home: Scaffold(body: const MonitorEditView())),
      );
    }

    Future<void> pumpWithSize(WidgetTester tester, Widget widget) async {
      tester.view.physicalSize = const Size(1920, 1080);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });
      await tester.pumpWidget(widget);
    }

    Monitor createMonitor(Map<String, dynamic> attrs) {
      return Monitor()
        ..setRawAttributes(attrs, sync: true)
        ..exists = true;
    }

    testWidgets('renders not-found when monitor is null', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pump();

      expect(find.byType(MonitorEditView), findsOneWidget);
      // No form sections when monitor is null
      expect(find.byType(MonitorBasicInfoSection), findsNothing);
    });

    testWidgets('renders form sections when monitor is loaded', (tester) async {
      MonitorController.instance.selectedMonitorNotifier.value = createMonitor({
        'id': 1,
        'name': 'Test Monitor',
        'type': 'http',
        'url': 'https://example.com',
        'method': 'GET',
        'expected_status_code': 200,
        'check_interval': 60,
        'timeout': 30,
        'monitoring_locations': ['us-east'],
        'status': 'active',
      });

      await pumpWithSize(tester, buildSubject());
      await tester.pumpAndSettle();

      expect(find.byType(MonitorBasicInfoSection), findsOneWidget);
      expect(find.byType(MonitorSettingsSection), findsOneWidget);

      for (final type in MonitorType.values) {
        expect(find.text(type.label), findsOneWidget);
      }
    });

    testWidgets('pre-fills form with monitor data', (tester) async {
      MonitorController.instance.selectedMonitorNotifier.value = createMonitor({
        'id': 1,
        'name': 'My API Monitor',
        'type': 'http',
        'url': 'https://api.example.com/health',
        'method': 'POST',
        'expected_status_code': 201,
        'check_interval': 120,
        'timeout': 15,
        'monitoring_locations': ['us-east', 'eu-west'],
        'tags': ['api', 'health'],
        'status': 'active',
      });

      await pumpWithSize(tester, buildSubject());
      await tester.pumpAndSettle();

      // Name should be pre-filled
      expect(find.text('My API Monitor'), findsWidgets);
      // URL should be pre-filled
      expect(find.text('https://api.example.com/health'), findsWidgets);
    });

    testWidgets('type selector is not editable', (tester) async {
      MonitorController.instance.selectedMonitorNotifier.value = createMonitor({
        'id': 1,
        'name': 'Test',
        'type': 'http',
        'url': 'https://example.com',
        'method': 'GET',
        'expected_status_code': 200,
        'check_interval': 60,
        'timeout': 30,
        'monitoring_locations': ['us-east'],
        'status': 'active',
      });

      await pumpWithSize(tester, buildSubject());
      await tester.pumpAndSettle();

      // Find the basic info section and verify typeEditable is false
      final section = tester.widget<MonitorBasicInfoSection>(
        find.byType(MonitorBasicInfoSection),
      );
      expect(section.typeEditable, isFalse);
    });
  });
}
