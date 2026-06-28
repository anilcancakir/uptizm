import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/slo_budget_card/index.dart';

void main() {
  Widget wrap(Widget widget) => MaterialApp(
        home: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      );

  group('computeErrorBudget', () {
    test('full uptime -> healthy (up), 100% left', () {
      final b = computeErrorBudget(99.9, 100);
      expect(b.tone, SloBudgetTone.up);
      expect(b.remainingPct.round(), 100);
    });

    test('uptime above target but budget nearly spent -> degraded', () {
      final b = computeErrorBudget(99.9, 99.91);
      expect(b.tone, SloBudgetTone.degraded);
      expect(b.remainingPct, lessThan(25));
    });

    test('uptime below target -> down (breached)', () {
      final b = computeErrorBudget(99.95, 99.94);
      expect(b.tone, SloBudgetTone.down);
      expect(b.used, greaterThan(b.allowed));
    });
  });

  group('sloBudgetCardRecipe', () {
    test('tone maps bar/dot/status to the status family', () {
      for (final tone in ['up', 'degraded', 'down']) {
        final slots = sloBudgetCardRecipe(variants: {kSloBudgetToneAxis: tone});
        expect(slots['bar'], contains('bg-$tone'));
        expect(slots['dot'], contains('bg-$tone'));
        expect(slots['status'], contains('text-$tone-soft-foreground'));
      }
    });

    test('root + track carry layout + neutral tokens', () {
      final slots = sloBudgetCardRecipe(variants: const {});
      expect(slots['root'], contains('rounded-xl'));
      expect(slots['root'], contains('bg-surface'));
      expect(slots['track'], contains('bg-surface-container-high'));
    });
  });

  testWidgets('renders header, status label and footer', (tester) async {
    await tester.pumpWidget(wrap(
      const SloBudgetCard(target: 99.9, uptimePct: 100),
    ));
    expect(find.text('Error budget'), findsOneWidget);
    expect(find.text('Healthy'), findsOneWidget);
    expect(find.textContaining('budget left'), findsOneWidget);
  });

  testWidgets('breached card shows the over-budget note', (tester) async {
    await tester.pumpWidget(wrap(
      const SloBudgetCard(target: 99.95, uptimePct: 99.94),
    ));
    expect(find.text('Budget breached'), findsOneWidget);
    expect(find.textContaining('Over budget by'), findsOneWidget);
  });

  // Note: SloBudgetCardPreview renders a 3-column grid that overflows the
  // default 800px test surface (each card ~266px). It is visually verified at
  // the real /preview width; the per-card smokes above cover rendering.
}
