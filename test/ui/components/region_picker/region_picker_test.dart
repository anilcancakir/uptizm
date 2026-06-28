import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/region_picker/index.dart';

void main() {
  Widget wrap(Widget widget) => MaterialApp(
        home: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      );

  group('regionPickerRecipe', () {
    test('root is a responsive grid', () {
      final slots = regionPickerRecipe(variants: const {});
      expect(slots['root'], contains('grid'));
      expect(slots['root'], contains('sm:grid-cols-3'));
    });

    test('selected tile uses the soft brand tint (not solid accent)', () {
      final slots = regionPickerRecipe(variants: const {});
      expect(slots['optionSelected'], contains('bg-primary-container'));
      expect(slots['optionSelected'], contains('text-primary'));
    });
  });

  testWidgets('renders a tile per region with flag + label', (tester) async {
    await tester.pumpWidget(wrap(
      RegionPicker(
        regions: const [
          Region(label: 'US East', value: 'us-east', flag: '🇺🇸'),
          Region(label: 'EU West', value: 'eu-west', flag: '🇮🇪'),
        ],
        value: const ['us-east'],
        onChanged: (_) {},
      ),
    ));
    expect(find.text('US East'), findsOneWidget);
    expect(find.text('EU West'), findsOneWidget);
  });

  testWidgets('tapping a tile reports the next selection', (tester) async {
    List<String> next = const [];
    await tester.pumpWidget(wrap(
      RegionPicker(
        regions: const [Region(label: 'US East', value: 'us-east')],
        value: const [],
        onChanged: (v) => next = v,
      ),
    ));
    await tester.tap(find.text('US East'));
    await tester.pump();
    expect(next, ['us-east']);
  });

  // Note: RegionPickerPreview renders a 3-column grid that overflows the
  // default 800px test surface; it is visually verified at the real /preview
  // width. The per-tile smokes above cover rendering + toggle behaviour.
}
