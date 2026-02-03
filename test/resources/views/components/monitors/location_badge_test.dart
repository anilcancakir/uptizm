import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/app/enums/monitor_location.dart';
import 'package:uptizm/resources/views/components/monitors/location_badge.dart';

Widget buildTestApp({required Widget child}) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('LocationBadge', () {
    testWidgets('renders location label', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const LocationBadge(location: MonitorLocation.usEast),
        ),
      );

      expect(find.text('US East'), findsOneWidget);
    });

    testWidgets('renders globe icon', (tester) async {
      await tester.pumpWidget(
        buildTestApp(
          child: const LocationBadge(location: MonitorLocation.euWest),
        ),
      );

      expect(find.byIcon(Icons.public), findsOneWidget);
      expect(find.text('EU West'), findsOneWidget);
    });

    testWidgets('renders for all location types', (tester) async {
      for (final location in MonitorLocation.values) {
        await tester.pumpWidget(
          buildTestApp(child: LocationBadge(location: location)),
        );

        expect(find.text(location.label), findsOneWidget);
        expect(find.byIcon(Icons.public), findsOneWidget);
      }
    });
  });
}
