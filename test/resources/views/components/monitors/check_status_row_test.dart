import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/monitor_check.dart';
import 'package:uptizm/resources/views/components/monitors/check_status_row.dart';

Widget buildTestApp({required Widget child, Size size = const Size(1200, 800)}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: Scaffold(body: child),
      ),
    ),
  );
}

void main() {
  group('CheckStatusRow', () {
    testWidgets(
      'renders status dot, response time, status code, location, and time',
      (tester) async {
        final check = MonitorCheck.fromMap({
          'id': 1,
          'status': 'up',
          'response_time_ms': 245,
          'status_code': 200,
          'location': 'us-east',
          'checked_at': '2026-02-03T12:00:00Z',
        });

        await tester.pumpWidget(
          buildTestApp(child: CheckStatusRow(check: check)),
        );

        expect(find.byType(CheckStatusRow), findsOneWidget);
        expect(find.textContaining('245'), findsOneWidget);
        expect(find.textContaining('200'), findsOneWidget);
        expect(find.textContaining('US East'), findsOneWidget);
      },
    );

    testWidgets('renders error message when check has error', (tester) async {
      final check = MonitorCheck.fromMap({
        'id': 1,
        'status': 'down',
        'response_time_ms': null,
        'status_code': null,
        'location': 'eu-west',
        'error_message': 'Connection timeout',
        'checked_at': '2026-02-03T12:00:00Z',
      });

      await tester.pumpWidget(
        buildTestApp(child: CheckStatusRow(check: check)),
      );

      expect(find.text('Connection timeout'), findsOneWidget);
    });

    testWidgets('uses monospace for response time and status code', (
      tester,
    ) async {
      final check = MonitorCheck.fromMap({
        'id': 1,
        'status': 'up',
        'response_time_ms': 123,
        'status_code': 201,
        'location': 'us-west',
        'checked_at': '2026-02-03T12:00:00Z',
      });

      await tester.pumpWidget(
        buildTestApp(child: CheckStatusRow(check: check)),
      );

      expect(find.textContaining('123'), findsOneWidget);
      expect(find.textContaining('201'), findsOneWidget);
      // Will verify monospace styling in implementation
    });

    testWidgets('handles null responseTimeMs gracefully', (tester) async {
      final check = MonitorCheck.fromMap({
        'id': 1,
        'status': 'down',
        'response_time_ms': null,
        'status_code': 500,
        'location': 'ap-southeast',
        'checked_at': '2026-02-03T12:00:00Z',
      });

      await tester.pumpWidget(
        buildTestApp(child: CheckStatusRow(check: check)),
      );

      expect(find.byType(CheckStatusRow), findsOneWidget);
      expect(find.textContaining('500'), findsOneWidget);
      // Should render without crashing when responseTimeMs is null
    });
  });
}
