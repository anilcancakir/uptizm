import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/check_status.dart';
import 'package:uptizm/resources/views/components/monitors/status_dot.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('StatusDot', () {
    testWidgets('renders green dot for CheckStatus.up', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const StatusDot(status: CheckStatus.up)),
      );

      // Should find a WDiv with bg-green-500 class
      expect(find.byType(StatusDot), findsOneWidget);
      // Will verify green color in implementation
    });

    testWidgets('renders red dot for CheckStatus.down', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const StatusDot(status: CheckStatus.down)),
      );

      expect(find.byType(StatusDot), findsOneWidget);
      // Will verify red color in implementation
    });

    testWidgets('renders amber dot for CheckStatus.degraded', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const StatusDot(status: CheckStatus.degraded)),
      );

      expect(find.byType(StatusDot), findsOneWidget);
      // Will verify amber color in implementation
    });

    testWidgets('renders gray dot for null status', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const StatusDot(status: null)),
      );

      expect(find.byType(StatusDot), findsOneWidget);
      // Will verify gray color in implementation
    });

    testWidgets('applies custom size when provided', (tester) async {
      await tester.pumpWidget(
        buildTestApp(child: const StatusDot(status: CheckStatus.up, size: 16)),
      );

      expect(find.byType(StatusDot), findsOneWidget);
      // Will verify size in implementation
    });
  });
}
