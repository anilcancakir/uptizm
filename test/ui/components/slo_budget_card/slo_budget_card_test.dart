import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/slo_budget_card/index.dart';

/// Feeds the SLO card labels so [trans] returns the real English prose the
/// card renders instead of the raw key tokens.
class _SloLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.slo.error_budget': 'Error budget',
    'uptizm.slo.status_healthy': 'Healthy',
    'uptizm.slo.status_at_risk': 'At risk',
    'uptizm.slo.status_breached': 'Budget breached',
    'uptizm.slo.budget_left': ':pct% budget left',
    'uptizm.slo.budget_of': ':used of :allowed',
    'uptizm.slo.over_budget': 'Over budget by :amount this window.',
    'uptizm.slo.coverage_partial': 'Observed :hours hours of the :window window.',
    'uptizm.slo.gap_unmeasured': 'Not measured this window: :amount.',
    'uptizm.slo.window_7day': '7-day',
    'uptizm.slo.window_30day': '30-day',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_SloLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  Widget wrap(Widget widget) => MaterialApp(
    home: WindTheme(
      data: WindThemeData(),
      child: Scaffold(body: SingleChildScrollView(child: widget)),
    ),
  );

  group('computeErrorBudget', () {
    test('no downtime -> healthy (up), 100% left', () {
      final b = computeErrorBudget(99.9, downMinutes: 0);
      expect(b.tone, SloBudgetTone.up);
      expect(b.remainingPct.round(), 100);
    });

    test('2 real down minutes stay inside a 43-minute 30-day budget', () {
      // The production defect this rebuild removes: monitor
      // a276e7c5-26d5-4b53-b522-f0ce3b52d226 had 2 real down minutes and read
      // "1h 9m aşıldı", because the card multiplied `1 - uptime_ratio` (99.74%
      // over 767 checks) by the full 43200-minute window. Feeding the real
      // minutes has to read as headroom, not as a breach.
      final b = computeErrorBudget(99.9, downMinutes: 2);
      expect(b.allowed, closeTo(43.2, 0.01));
      expect(b.used, 2);
      expect(b.remaining, closeTo(41.2, 0.01));
      expect(b.tone, SloBudgetTone.up);
    });

    test('the allowance is the full nominal window, not the observed one', () {
      // The rejected alternative scaled the allowance to observed coverage,
      // which turns a 15-hour-old monitor's 30-day budget into 54 seconds.
      expect(computeErrorBudget(99.9, downMinutes: 0).allowed, closeTo(43.2, 0.01));
      expect(
        computeErrorBudget(99.9, downMinutes: 0, windowDays: 7).allowed,
        closeTo(10.08, 0.01),
      );
    });

    test('most of the allowance spent -> degraded (at risk)', () {
      final b = computeErrorBudget(99.9, downMinutes: 40);
      expect(b.tone, SloBudgetTone.degraded);
      expect(b.remainingPct, lessThan(25));
    });

    test('used above allowed -> down (breached)', () {
      // Re-expresses the former `uptime below target -> down` case in minutes:
      // the comparison is unchanged (uptime < target IS used > allowed), only
      // the definition of `used` moved off the ratio.
      final b = computeErrorBudget(99.95, downMinutes: 25);
      expect(b.tone, SloBudgetTone.down);
      expect(b.used, greaterThan(b.allowed));
      expect(b.remaining, lessThan(0));
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
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 0,
          observedMinutes: 43200,
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.text('Error budget'), findsOneWidget);
    expect(find.text('Healthy'), findsOneWidget);
    expect(find.textContaining('budget left'), findsOneWidget);
  });

  testWidgets('breached card shows the over-budget note', (tester) async {
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.95,
          downMinutes: 25,
          observedMinutes: 43200,
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.text('Budget breached'), findsOneWidget);
    expect(find.textContaining('Over budget by'), findsOneWidget);
  });

  testWidgets('a partly observed window states its coverage', (tester) async {
    // 15 hours of coverage inside a 30-day window: the card prints what it
    // actually watched instead of implying the whole month was measured.
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 900,
          gapMinutes: 0,
        ),
      ),
    );
    expect(
      find.text('Observed 15 hours of the 30-day window.'),
      findsOneWidget,
    );
    // 2 minutes against a 43-minute allowance is headroom, whatever the
    // coverage: scaling the allowance to the observed window instead turns
    // this into a breach, which is the alternative this test discriminates.
    expect(find.text('Healthy'), findsOneWidget);
  });

  testWidgets('a fully observed window states no coverage line', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 43200,
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.textContaining('Observed'), findsNothing);
  });

  testWidgets('unmeasured gap minutes are stated and never spend the budget', (
    tester,
  ) async {
    // A gap after the monitor existed is uptizm's own blind spot: unknown, not
    // bad. It gets its own neutral line and leaves `used` alone, so the budget
    // reads identically to the same card with no gap at all.
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 43200,
          gapMinutes: 90,
        ),
      ),
    );
    expect(find.text('Not measured this window: 1h 30m.'), findsOneWidget);
    expect(find.text('Healthy'), findsOneWidget);
    expect(find.textContaining('95% budget left'), findsOneWidget);

    // Same budget, no gap: the readout is byte-identical, pinning the gap as
    // unknown rather than as downtime.
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 43200,
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.textContaining('Not measured'), findsNothing);
    expect(find.textContaining('95% budget left'), findsOneWidget);
  });

  // Note: SloBudgetCardPreview renders a 3-column grid that overflows the
  // default 800px test surface (each card ~266px). It is visually verified at
  // the real /preview width; the per-card smokes above cover rendering.
}
