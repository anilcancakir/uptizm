import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/resources/views/monitor_detail_view.dart';
import 'package:uptizm/ui/components/ai_analysis_card/index.dart';
import 'package:uptizm/ui/components/check_history_table/index.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/metric_chart/index.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader / Tabs resolve their
    // themes via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// configurable MediaQuery size so both desktop and mobile widths render.
  Widget wrap(Widget widget, {Size size = const Size(1280, 2200)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  testWidgets('MonitorDetailView renders the header with a StatusBadge', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await tester.pump();

    expect(find.byType(PageHeader), findsOneWidget);
    expect(find.byType(StatusBadge), findsOneWidget);
  });

  testWidgets('MonitorDetailView renders four KPI stat cards', (tester) async {
    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await tester.pump();

    expect(find.byType(KpiStatCard), findsNWidgets(4));
  });

  testWidgets(
    'MonitorDetailView renders a MetricChart and CheckHistoryTable on the '
    'Overview tab for a known monitor',
    (tester) async {
      await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
      await tester.pump();

      // Overview is the default tab: the response chart + recent checks table.
      expect(find.byType(MetricChart), findsOneWidget);
      expect(find.byType(CheckHistoryTable), findsOneWidget);

      // Fidelity: the Overview response chart mirrors MonitorDetailPage.tsx
      // (no AI expected-range band; series + anomalies only). The band is
      // reserved for the deeper per-metric history view on the Metrics tab.
      final MetricChart overviewChart = tester.widget<MetricChart>(
        find.byType(MetricChart),
      );
      expect(overviewChart.band, isNull);
      expect(overviewChart.unit, 'ms');
    },
  );

  testWidgets('MonitorDetailView wraps its content in a PageContainer', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await tester.pump();

    expect(find.byType(PageContainer), findsOneWidget);
  });

  testWidgets(
    'MonitorDetailView renders a graceful EmptyState for an unknown id',
    (tester) async {
      await tester.pumpWidget(wrap(const MonitorDetailView(id: 'nope')));
      await tester.pump();

      // No monitor surfaces (no KPI cards, no chart) — just the not-found body.
      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.byType(KpiStatCard), findsNothing);
      expect(find.byType(MetricChart), findsNothing);
    },
  );

  testWidgets('MonitorDetailView does not overflow at a mobile width', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const MonitorDetailView(id: 'api'), size: const Size(360, 3200)),
    );
    await tester.pump();

    // Nothing in the KPI grid, MetricChart, or CheckHistoryTable may overflow
    // the narrow phone frame.
    expect(tester.takeException(), isNull);
    expect(find.byType(MetricChart), findsOneWidget);
    expect(find.byType(CheckHistoryTable), findsOneWidget);
  });

  testWidgets(
    'MonitorDetailView Metrics tab renders the per-monitor chart and the '
    'AiAnalysisCard',
    (tester) async {
      // Pin a desktop-class surface so the dense Metrics tab (per-monitor chart
      // + AiAnalysisCard) lays out without clipping. The AiAnalysisCard is a
      // sibling component (composed here, not authored in this step) whose
      // internal rows only reflow below ~800 logical px; this assertion targets
      // composition, not that foreign component's narrow-width responsiveness.
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorDetailView(id: 'api'), size: const Size(1280, 4000)),
      );
      await tester.pump();

      // Switch to the Metrics tab (index 1) and let it lay out.
      await tester.ensureVisible(
        find.text(trans('uptizm.monitors.tab_metrics')),
      );
      await tester.pump();
      await tester.tap(find.text(trans('uptizm.monitors.tab_metrics')));
      await tester.pump();

      // The per-monitor chart and the per-monitor AI analysis both render.
      expect(tester.takeException(), isNull);
      expect(find.byType(MetricChart), findsOneWidget);
      expect(find.byType(AiAnalysisCard), findsOneWidget);

      // Fidelity: unlike the Overview chart, the Metrics-tab chart draws the
      // AI expected-range band (mirrors MonitorMetricsTab.tsx's DetailBody).
      final MetricChart metricsChart = tester.widget<MetricChart>(
        find.byType(MetricChart),
      );
      expect(metricsChart.band, isNotNull);
    },
  );
}
