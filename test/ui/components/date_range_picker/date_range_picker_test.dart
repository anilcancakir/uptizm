import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/date_range_picker/index.dart';
import 'package:uptizm/ui/components/date_range_picker/date_range_picker.preview.dart';

void main() {
  Widget wrap(Widget widget) => MaterialApp(
    home: WindTheme(
      data: WindThemeData(),
      child: Scaffold(body: widget),
    ),
  );

  group('dateRangePickerRecipe / presets', () {
    test('trigger looks like a secondary button', () {
      final slots = dateRangePickerRecipe(variants: const {});
      expect(slots['trigger'], contains('border-color-border'));
      expect(slots['trigger'], contains('rounded-md'));
      expect(slots['icon'], contains('text-fg-muted'));
    });

    test('exposes the four canonical presets', () {
      expect(
        kDateRangePresets.map((p) => p.value),
        containsAll(['24h', '7d', '30d', '90d']),
      );
    });
  });

  testWidgets('trigger shows the active preset label', (tester) async {
    await tester.pumpWidget(
      wrap(DateRangePicker(value: '7d', onChanged: (_) {})),
    );
    expect(find.text('Last 7 days'), findsOneWidget);
  });

  testWidgets('unknown value falls back to Custom range', (tester) async {
    await tester.pumpWidget(
      wrap(DateRangePicker(value: 'custom', onChanged: (_) {})),
    );
    expect(find.text('Custom range'), findsOneWidget);
  });

  testWidgets('preview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const DateRangePickerPreview()));
    expect(find.byType(DateRangePicker), findsOneWidget);
  });
}
