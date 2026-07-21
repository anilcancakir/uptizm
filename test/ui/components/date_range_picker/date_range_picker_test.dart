import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/date_range_picker/index.dart';
import 'package:uptizm/ui/components/date_range_picker/date_range_picker.preview.dart';

/// Feeds the range preset labels so [trans] returns the real English prose the
/// picker renders instead of the raw key tokens.
class _RangesLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.ranges.custom': 'Custom range',
    'uptizm.ranges.last_24h': 'Last 24 hours',
    'uptizm.ranges.last_7d': 'Last 7 days',
    'uptizm.ranges.last_30d': 'Last 30 days',
    'uptizm.ranges.last_90d': 'Last 90 days',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_RangesLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

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
