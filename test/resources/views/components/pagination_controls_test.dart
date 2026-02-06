import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/pagination_controls.dart';

void main() {
  setUpAll(() {
    Magic.init();
  });

  group('PaginationControls', () {
    testWidgets('renders correctly', (WidgetTester tester) async {
      // Set desktop size to avoid overflow in row
      tester.view.physicalSize = const Size(1440, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: PaginationControls(
                currentPage: 1,
                totalPages: 5,
                hasPrevious: false,
                hasNext: true,
                onPrevious: () {},
                onNext: () {},
              ),
            ),
          ),
        ),
      );

      expect(find.textContaining('pagination.previous'), findsOneWidget);
      expect(find.textContaining('pagination.next'), findsOneWidget);
      expect(find.text('1 / 5'), findsOneWidget); // Page info format
      expect(find.byType(WButton), findsNWidgets(2));
    });

    testWidgets('disables previous button on first page', (
      WidgetTester tester,
    ) async {
      bool previousPressed = false;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: PaginationControls(
                currentPage: 1,
                totalPages: 5,
                hasPrevious: false,
                hasNext: true,
                onPrevious: () => previousPressed = true,
                onNext: () {},
              ),
            ),
          ),
        ),
      );

      // Find previous button
      final prevBtn = find.ancestor(
        of: find.text('pagination.previous'),
        matching: find.byType(WButton),
      );

      await tester.tap(prevBtn);
      expect(previousPressed, isFalse);
    });

    testWidgets('triggers callbacks', (WidgetTester tester) async {
      bool nextPressed = false;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: PaginationControls(
                currentPage: 2,
                totalPages: 5,
                hasPrevious: true,
                hasNext: true,
                onPrevious: () {},
                onNext: () => nextPressed = true,
              ),
            ),
          ),
        ),
      );

      final nextBtn = find.ancestor(
        of: find.text('pagination.next'),
        matching: find.byType(WButton),
      );

      await tester.tap(nextBtn);
      expect(nextPressed, isTrue);
    });
  });
}
