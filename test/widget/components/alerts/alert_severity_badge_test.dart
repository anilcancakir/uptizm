import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/alert_severity.dart';
import 'package:uptizm/resources/views/components/alerts/alert_severity_badge.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('AlertSeverityBadge', () {
    testWidgets('renders critical badge with correct text and color',
        (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const AlertSeverityBadge(severity: AlertSeverity.critical),
        ),
      );

      expect(find.text('Critical'), findsOneWidget);
    });

    testWidgets('renders warning badge with correct text and color',
        (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const AlertSeverityBadge(severity: AlertSeverity.warning),
        ),
      );

      expect(find.text('Warning'), findsOneWidget);
    });

    testWidgets('renders info badge with correct text and color',
        (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const AlertSeverityBadge(severity: AlertSeverity.info),
        ),
      );

      expect(find.text('Info'), findsOneWidget);
    });
  });
}
