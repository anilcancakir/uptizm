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
    'uptizm.slo.budget_of': ':remaining of :allowed',
    'uptizm.slo.over_budget': 'Over budget by :amount this window.',
    'uptizm.slo.coverage_partial': 'Observed :hours hours of the :window window.',
    'uptizm.slo.coverage_partial_days':
        'Observed :days days of the :window window.',
    'uptizm.slo.gap_unmeasured': 'Not measured this window: :amount.',
    'uptizm.slo.window_7day': '7-day',
    'uptizm.slo.window_30day': '30-day',
    'uptizm.slo.unit_minutes': 'm',
    'uptizm.slo.unit_hours': 'h',
  };
}

/// The same keys with Turkish units, to pin that the duration formatter reads
/// them from the catalogue rather than hardcoding `h`/`m`. A Turkish operator
/// otherwise reads "Bu pencerede 1h 30m ölçülemedi."
class _TurkishUnitLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.slo.gap_unmeasured': 'Bu pencerede :amount ölçülemedi.',
    'uptizm.slo.unit_minutes': 'dk',
    'uptizm.slo.unit_hours': 'sa',
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

    test('a 100% target has no allowance, so any downtime spends all of it', () {
      // `slo_target` validates to max:100, and a 100% target makes the allowance
      // exactly zero. Reporting 100% left there put "100% budget left" beside
      // "Budget breached" on the same card, with a full-width bar.
      final b = computeErrorBudget(100, downMinutes: 2);
      expect(b.allowed, 0);
      expect(b.tone, SloBudgetTone.down);
      expect(b.remainingPct, 0);

      // With no downtime either, an empty allowance is still fully intact.
      expect(computeErrorBudget(100, downMinutes: 0).remainingPct, 100);
      expect(computeErrorBudget(100, downMinutes: 0).tone, SloBudgetTone.up);
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

  testWidgets('coverage past two days is counted in days, not hours', (
    tester,
  ) async {
    // Every monitor younger than a month reaches this line on its 30-day card,
    // so the hours form would print "Observed 600 hours of the 30-day window"
    // for a 25-day-old monitor. Floored either way, never rounded up.
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 36000, // 25 days
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.text('Observed 25 days of the 30-day window.'), findsOneWidget);

    // Just under the two-day threshold still reads in hours.
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 2820, // 47 hours
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.text('Observed 47 hours of the 30-day window.'), findsOneWidget);

    // A hair under the threshold is still under it. Rounding to whole minutes
    // instead of flooring turns 2879.6 into 2880 and prints two days, claiming
    // coverage that does not exist.
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 2879.6,
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.text('Observed 47 hours of the 30-day window.'), findsOneWidget);
    expect(find.textContaining('days'), findsNothing);
  });

  testWidgets('a window a hair short of full still states its coverage', (
    tester,
  ) async {
    // Rounding 43199.6 up to the full 43200 drops the coverage line entirely and
    // presents a not-quite-complete window as a fully observed one.
    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 2,
          observedMinutes: 43199.6,
          gapMinutes: 0,
        ),
      ),
    );
    expect(find.textContaining('Observed'), findsOneWidget);
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

  testWidgets('duration units come from the catalogue, not from English', (
    tester,
  ) async {
    Translator.instance.setLoader(_TurkishUnitLangLoader());
    await Translator.instance.setLocale(const Locale('tr'));

    await tester.pumpWidget(
      wrap(
        const SloBudgetCard(
          target: 99.9,
          downMinutes: 0,
          observedMinutes: 43200,
          gapMinutes: 90,
        ),
      ),
    );

    expect(find.text('Bu pencerede 1sa 30dk ölçülemedi.'), findsOneWidget);
    expect(find.textContaining('1h 30m'), findsNothing);
  });

  // Note: SloBudgetCardPreview renders a 3-column grid that overflows the
  // default 800px test surface (each card ~266px). It is visually verified at
  // the real /preview width; the per-card smokes above cover rendering.
}
