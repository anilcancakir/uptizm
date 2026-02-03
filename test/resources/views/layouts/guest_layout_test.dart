import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/resources/views/layouts/guest_layout.dart';

void main() {
  group('GuestLayout', () {
    testWidgets('renders child widget centered', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: GuestLayout(child: const Text('Test child widget')),
          ),
        ),
      );

      // Should find the child content
      expect(find.text('Test child widget'), findsOneWidget);

      // Should be wrapped in Center and SingleChildScrollView
      expect(find.byType(Center), findsOneWidget);
      expect(find.byType(SingleChildScrollView), findsOneWidget);
    });

    testWidgets('applies slate background color', (tester) async {
      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp(
            home: GuestLayout(child: const Text('Test content')),
          ),
        ),
      );

      final scaffold = tester.widget<Scaffold>(find.byType(Scaffold));
      expect(scaffold.backgroundColor, isNotNull);
    });
  });
}
