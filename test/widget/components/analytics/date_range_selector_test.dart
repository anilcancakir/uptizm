import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/resources/views/components/analytics/date_range_selector.dart';

void main() {
  Widget buildTestWidget({
    String? selectedPreset,
    DateTimeRange? customRange,
    ValueChanged<String>? onPresetSelected,
    ValueChanged<DateTimeRange>? onCustomRangeSelected,
  }) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(
        home: Scaffold(
          body: SizedBox(
            width: 1200, // Wide enough to fit all buttons
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DateRangeSelector(
                selectedPreset: selectedPreset,
                customRange: customRange,
                onPresetSelected: onPresetSelected ?? (_) {},
                onCustomRangeSelected: onCustomRangeSelected ?? (_) {},
              ),
            ),
          ),
        ),
      ),
    );
  }

  group('DateRangeSelector', () {
    group('Preset buttons', () {
      testWidgets('renders all preset buttons', (tester) async {
        await tester.pumpWidget(buildTestWidget(selectedPreset: '24h'));
        await tester.pumpAndSettle();

        expect(find.text('analytics.last_24h'), findsOneWidget);
        expect(find.text('analytics.last_7d'), findsOneWidget);
        expect(find.text('analytics.last_30d'), findsOneWidget);
      });

      testWidgets('highlights selected preset', (tester) async {
        await tester.pumpWidget(buildTestWidget(selectedPreset: '7d'));
        await tester.pumpAndSettle();

        final button7d = tester.widget<WButton>(
          find.ancestor(
            of: find.text('analytics.last_7d'),
            matching: find.byType(WButton),
          ),
        );

        expect(button7d, isNotNull);
      });

      testWidgets('tapping preset calls onPresetSelected', (tester) async {
        String? selected;
        await tester.pumpWidget(
          buildTestWidget(onPresetSelected: (val) => selected = val),
        );
        await tester.pumpAndSettle();

        await tester.tap(find.text('analytics.last_30d'));
        await tester.pumpAndSettle();

        expect(selected, '30d');
      });

      testWidgets('tapping 24h preset returns correct value', (tester) async {
        String? selected;
        await tester.pumpWidget(
          buildTestWidget(onPresetSelected: (val) => selected = val),
        );
        await tester.pumpAndSettle();

        await tester.tap(find.text('analytics.last_24h'));
        await tester.pumpAndSettle();

        expect(selected, '24h');
      });

      testWidgets('tapping 7d preset returns correct value', (tester) async {
        String? selected;
        await tester.pumpWidget(
          buildTestWidget(onPresetSelected: (val) => selected = val),
        );
        await tester.pumpAndSettle();

        await tester.tap(find.text('analytics.last_7d'));
        await tester.pumpAndSettle();

        expect(selected, '7d');
      });
    });

    group('Custom date picker button', () {
      testWidgets('renders custom range button with placeholder', (
        tester,
      ) async {
        await tester.pumpWidget(buildTestWidget());
        await tester.pumpAndSettle();

        expect(find.text('Custom Range'), findsOneWidget);
        expect(find.byIcon(Icons.calendar_today), findsOneWidget);
        expect(find.byIcon(Icons.keyboard_arrow_down), findsOneWidget);
      });

      testWidgets('shows formatted date when customRange is set', (
        tester,
      ) async {
        final range = DateTimeRange(
          start: DateTime(2026, 1, 15),
          end: DateTime(2026, 1, 20),
        );

        await tester.pumpWidget(buildTestWidget(customRange: range));
        await tester.pumpAndSettle();

        expect(find.text('Jan 15 - Jan 20'), findsOneWidget);
      });

      testWidgets('custom range button is tappable', (tester) async {
        await tester.pumpWidget(buildTestWidget());
        await tester.pumpAndSettle();

        // Verify button exists and contains expected widgets
        final customRangeButton = find.ancestor(
          of: find.text('Custom Range'),
          matching: find.byType(WButton),
        );
        expect(customRangeButton, findsOneWidget);
      });
    });

    group('Date formatting', () {
      testWidgets('formats January dates correctly', (tester) async {
        final range = DateTimeRange(
          start: DateTime(2026, 1, 1),
          end: DateTime(2026, 1, 31),
        );

        await tester.pumpWidget(buildTestWidget(customRange: range));
        await tester.pumpAndSettle();

        expect(find.text('Jan 1 - Jan 31'), findsOneWidget);
      });

      testWidgets('formats December dates correctly', (tester) async {
        final range = DateTimeRange(
          start: DateTime(2025, 12, 1),
          end: DateTime(2025, 12, 25),
        );

        await tester.pumpWidget(buildTestWidget(customRange: range));
        await tester.pumpAndSettle();

        expect(find.text('Dec 1 - Dec 25'), findsOneWidget);
      });

      testWidgets('formats cross-month range correctly', (tester) async {
        final range = DateTimeRange(
          start: DateTime(2026, 2, 1),
          end: DateTime(2026, 2, 4),
        );

        await tester.pumpWidget(buildTestWidget(customRange: range));
        await tester.pumpAndSettle();

        expect(find.text('Feb 1 - Feb 4'), findsOneWidget);
      });
    });
  });
}
