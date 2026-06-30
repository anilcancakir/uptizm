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

  testWidgets('KpiStatCard shows no delta glyph when delta is null', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const KpiStatCard(label: 'Uptime', value: '99.98%')),
    );
    // The footer rows are always reserved for equal-height layout (a blank
    // placeholder holds the line), but no delta glyph or value text appears.
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
    expect(find.byType(Card), findsOneWidget);
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
