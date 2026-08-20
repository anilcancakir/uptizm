import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/kpi_stat_card/kpi_stat_card.preview.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card can resolve its recipe via
    // MagicStarter.cardTheme without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe variant-class assertions
  // ---------------------------------------------------------------------------

  group('kpiStatCardRecipe', () {
    test('up trend emits text-up token', () {
      final cls = kpiStatCardRecipe(
        variants: {kKpiStatCardTrendAxis: KpiTrend.up.name},
      );
      expect(cls, contains('text-up'));
    });

    test('down trend emits text-down token', () {
      final cls = kpiStatCardRecipe(
        variants: {kKpiStatCardTrendAxis: KpiTrend.down.name},
      );
      expect(cls, contains('text-down'));
    });

    test('neutral trend emits text-fg-muted token', () {
      final cls = kpiStatCardRecipe(
        variants: {kKpiStatCardTrendAxis: KpiTrend.neutral.name},
      );
      expect(cls, contains('text-fg-muted'));
    });

    test('default variant resolves to neutral (text-fg-muted)', () {
      final cls = kpiStatCardRecipe();
      expect(cls, contains('text-fg-muted'));
    });

    test('base classes are present on every trend', () {
      for (final trend in KpiTrend.values) {
        final cls = kpiStatCardRecipe(
          variants: {kKpiStatCardTrendAxis: trend.name},
        );
        expect(
          cls,
          contains('font-mono'),
          reason: '${trend.name} missing font-mono',
        );
        expect(
          cls,
          contains('tabular-nums'),
          reason: '${trend.name} missing tabular-nums',
        );
      }
    });

    test('emission order: base precedes variant classes', () {
      final cls = kpiStatCardRecipe(
        variants: {kKpiStatCardTrendAxis: KpiTrend.up.name},
      );
      final baseIdx = cls.indexOf('font-mono');
      final variantIdx = cls.indexOf('text-up');
      expect(baseIdx, lessThan(variantIdx));
    });
  });

  // ---------------------------------------------------------------------------
  // KpiTrend enum
  // ---------------------------------------------------------------------------

  group('KpiTrend', () {
    test('has 3 values: up, down, neutral', () {
      expect(KpiTrend.values, hasLength(3));
      expect(
        KpiTrend.values,
        containsAll([KpiTrend.up, KpiTrend.down, KpiTrend.neutral]),
      );
    });

    test('enum names match recipe axis keys', () {
      expect(KpiTrend.up.name, 'up');
      expect(KpiTrend.down.name, 'down');
      expect(KpiTrend.neutral.name, 'neutral');
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('KpiStatCard renders label and value as WText widgets', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const KpiStatCard(label: 'Monitors up', value: '48 / 50')),
    );
    // The label uses `uppercase` transformation so find.text('Monitors up')
    // would not match. Verify the WText data prop directly.
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == 'Monitors up'),
      isTrue,
      reason: 'label WText not found',
    );
    expect(
      texts.any((w) => w.data == '48 / 50'),
      isTrue,
      reason: 'value WText not found',
    );
  });

  testWidgets('the metric value stops scaling before it splits its own cell', (
    tester,
  ) async {
    // Two of these sit side by side on a 402pt phone, so a value gets about
    // 178pt minus the card padding. At an iOS accessibility text scale the
    // 24px value grew past that and WRAPPED MID-NUMBER: an iPhone showed
    // "98.90%" as "98." over "90" and "61ms" as "61m" over "s". A number broken
    // across two lines is not a smaller number, it is a different one.
    //
    // 1.4 is measured, not chosen for taste: the widest realistic value is
    // seven monospace characters ("100.00%"), Geist Mono advances at about
    // 0.6em, and 7 x 0.6 x (24 x 1.4) = 141pt against the ~146pt a card cell
    // leaves. The label above it keeps scaling without a cap.
    await tester.pumpWidget(
      MaterialApp(
        home: MediaQuery(
          data: const MediaQueryData(textScaler: TextScaler.linear(3)),
          child: WindTheme(
            data: WindThemeData(),
            child: const Scaffold(
              body: SingleChildScrollView(
                child: KpiStatCard(label: 'Uptime (24h)', value: '100.00%'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pump();

    final BuildContext value = tester.element(find.text('100.00%'));

    expect(
      MediaQuery.textScalerOf(value).scale(10),
      lessThanOrEqualTo(14.0),
      reason: 'a 3x scale must not reach the metric value unclamped',
    );
  });

  testWidgets('KpiStatCard shows no delta glyph when delta is null', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const KpiStatCard(label: 'Uptime', value: '99.98%')),
    );
    // Equal height comes from the caller's `grid ... items-stretch` (wind
    // #139), so the card renders only the rows it has: no delta row (and thus
    // no glyph) when delta is null.
    expect(find.text('▲'), findsNothing);
    expect(find.text('▼'), findsNothing);
  });

  testWidgets('KpiStatCard shows no hint text when hint is null', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const KpiStatCard(label: 'Uptime', value: '99.98%')),
    );
    expect(find.text('vs. last 24h'), findsNothing);
  });

  testWidgets('KpiStatCard without delta or hint renders no placeholder row', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const KpiStatCard(label: 'Open incidents', value: '3')),
    );
    // With neither delta nor hint, only the label + value rows render; the
    // delta/hint rows are omitted entirely (no reserved placeholder line).
    expect(tester.widgetList<WText>(find.byType(WText)).length, 2);
  });

  testWidgets('KpiStatCard renders delta text when provided', (tester) async {
    await tester.pumpWidget(
      wrap(
        const KpiStatCard(
          label: 'Uptime',
          value: '99.98%',
          delta: '+0.01%',
          trend: KpiTrend.up,
        ),
      ),
    );
    // Glyph + delta render as a single text ("▲ +0.01%").
    expect(find.textContaining('+0.01%'), findsOneWidget);
  });

  testWidgets('KpiStatCard renders up-trend glyph for KpiTrend.up', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const KpiStatCard(
          label: 'Uptime',
          value: '99.98%',
          delta: '+0.01%',
          trend: KpiTrend.up,
        ),
      ),
    );
    expect(find.textContaining('▲'), findsOneWidget);
  });

  testWidgets('KpiStatCard renders down-trend glyph for KpiTrend.down', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const KpiStatCard(
          label: 'p95 response',
          value: '142ms',
          delta: '+18ms',
          trend: KpiTrend.down,
        ),
      ),
    );
    expect(find.textContaining('▼'), findsOneWidget);
  });

  testWidgets('KpiStatCard renders no glyph for KpiTrend.neutral', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const KpiStatCard(
          label: 'Incidents',
          value: '3',
          delta: 'unchanged',
          trend: KpiTrend.neutral,
        ),
      ),
    );
    expect(find.text('▲'), findsNothing);
    expect(find.text('▼'), findsNothing);
  });

  testWidgets('KpiStatCard renders hint text when provided', (tester) async {
    await tester.pumpWidget(
      wrap(
        const KpiStatCard(
          label: 'Uptime',
          value: '99.98%',
          hint: 'vs. last 24h',
        ),
      ),
    );
    expect(find.text('vs. last 24h'), findsOneWidget);
  });

  testWidgets('KpiStatCard applies card shell via magic_starter Card', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const KpiStatCard(label: 'Test', value: '42')),
    );
    // The magic_starter Card is the container.
    expect(find.byType(MSCard), findsOneWidget);
  });

  testWidgets('KpiStatCard default trend is neutral (no glyph)', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const KpiStatCard(
          label: 'Open incidents',
          value: '3',
          delta: 'unchanged',
        ),
      ),
    );
    // Default trend is neutral — no arrow glyph.
    expect(find.text('▲'), findsNothing);
    expect(find.text('▼'), findsNothing);
    expect(find.text('unchanged'), findsOneWidget);
  });

  testWidgets('KpiStatCardPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const KpiStatCardPreview()));
    await tester.pump();
    expect(find.byType(KpiStatCard), findsWidgets);
  });
}
