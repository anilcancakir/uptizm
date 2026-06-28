import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/usage_meter/index.dart';
import 'package:uptizm/ui/components/usage_meter/usage_meter.preview.dart';

void main() {
  Widget wrap(Widget widget) => MaterialApp(
        home: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      );

  group('usageMeterRecipe', () {
    test('tone maps the bar to the status family', () {
      for (final tone in ['up', 'degraded', 'down']) {
        final slots = usageMeterRecipe(variants: {kUsageMeterToneAxis: tone});
        expect(slots['bar'], contains('bg-$tone'));
      }
    });

    test('track is a neutral rounded rail', () {
      final slots = usageMeterRecipe(variants: const {});
      expect(slots['track'], contains('rounded-full'));
      expect(slots['track'], contains('bg-surface-container-high'));
      expect(slots['track'], contains('overflow-hidden'));
    });
  });

  testWidgets('renders label + used/limit readout', (tester) async {
    await tester.pumpWidget(wrap(
      const UsageMeter(label: 'Monitors', used: 4, limit: 50),
    ));
    expect(find.text('Monitors'), findsOneWidget);
    expect(find.text('4 / 50'), findsOneWidget);
  });

  testWidgets('null limit renders the infinity glyph', (tester) async {
    await tester.pumpWidget(wrap(
      const UsageMeter(label: 'Monitors', used: 420, limit: null),
    ));
    expect(find.text('420 / ∞'), findsOneWidget);
  });

  testWidgets('preview renders every row', (tester) async {
    await tester.pumpWidget(wrap(const UsageMeterPreview()));
    expect(find.text('Alerts'), findsOneWidget);
    expect(find.text('Responders'), findsOneWidget);
  });
}
