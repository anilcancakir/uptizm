import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/resources/views/monitors/monitor_detail_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_tab.dart';
import 'package:uptizm/ui/components/ai_analysis_card/index.dart';
import 'package:uptizm/ui/components/check_history_table/index.dart';
import 'package:uptizm/ui/components/empty_state/index.dart';
import 'package:uptizm/ui/components/incident_card/index.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/metric_chart/index.dart';
import 'package:uptizm/ui/components/slo_budget_card/index.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

/// In-memory loader feeding the monitor-detail prose so [trans] returns short,
/// wrappable strings instead of raw key tokens. Without it the StatusBadge and
/// KPI labels render the full dot-separated keys (e.g.
/// `'uptizm.status.degraded'`) as unbreakable ~30-char strings inside narrow
/// cells and overflow at the test viewport, mirroring the other view tests.
class _MonitorDetailLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.status.up': 'Operational',
      'uptizm.status.down': 'Major outage',
      'uptizm.status.degraded': 'Degraded',
      'uptizm.status.paused': 'Paused',
      'uptizm.status.info': 'Maintenance',
      'uptizm.status.ai': 'AI',
      'uptizm.monitors.back_to_monitors': 'Back to monitors',
      'uptizm.monitors.tab_overview': 'Overview',
      'uptizm.monitors.tab_metrics': 'Metrics',
      'uptizm.monitors.tab_incidents': 'Incidents',
      'uptizm.monitors.action_pause': 'Pause',
      'uptizm.monitors.action_resume': 'Resume',
      'uptizm.monitors.action_edit': 'Edit',
      'uptizm.monitors.action_delete': 'Delete',
      'uptizm.monitors.section_reliability': 'Reliability',
      'uptizm.monitors.metrics_custom_title': 'Custom metrics',
      'uptizm.monitors.metrics_add': 'Add metric',
      'uptizm.monitors.metrics_create': 'Create metric',
      'uptizm.monitors.metrics_empty_title': 'No custom metrics',
      'uptizm.monitors.metrics_empty_description': 'None yet.',
      'uptizm.monitors.metrics_system_collected_by_default': 'collected',
      'uptizm.monitors.kpi_uptime_24h': 'Uptime 24h',
      'uptizm.monitors.kpi_avg_response': 'Avg response',
      'uptizm.monitors.kpi_last_check': 'Last check',
      'uptizm.monitors.kpi_last_check_value': 'Just now',
      'uptizm.monitors.kpi_open_incidents_for_monitor': 'Open incidents',
      'uptizm.monitors.kpi_delta_ongoing': 'ongoing',
      'uptizm.monitors.kpi_hint_p50': 'p50 baseline',
      'uptizm.monitors.kpi_hint_paused': 'Paused',
      'uptizm.monitors.section_recent_checks': 'Recent checks',
      'uptizm.monitors.section_response_time': 'Response time',
      'uptizm.monitors.response_insight_anomaly': 'Anomaly flagged.',
      'uptizm.monitors.response_insight_clear': 'Holding steady.',
      'uptizm.monitors.reliability_burn_at_risk': 'Budget at risk.',
      'uptizm.monitors.reliability_burn_breached_burning': 'Budget burning.',
      'uptizm.monitors.reliability_burn_breached_recovering': 'Budget spent.',
      'uptizm.monitors.uptime_last_90_days': 'Uptime, last 90 days',
      'uptizm.monitors.uptime_90_days_ago': '90 days ago',
      'uptizm.monitors.uptime_today': 'Today',
      'uptizm.monitors.metrics_system_title': 'System metrics',
      'uptizm.monitors.no_response_data_title': 'No response data',
      'uptizm.monitors.no_response_data_description': 'No timing yet.',
      'uptizm.monitors.paused_title': 'Monitor paused',
      'uptizm.monitors.paused_description': 'Checks are paused.',
      'uptizm.monitors.error_load_title': 'Monitor not found',
      'uptizm.monitors.error_load_description': 'No monitor with that id.',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader / Tabs resolve their
    // themes via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Register the controller MonitorDetailView binds to. The view's own
    // initState calls Magic.findOrPut(MonitorController.new) too, but the
    // explicit registration here documents the dependency and is harmless
    // (findOrPut is idempotent).
    Magic.findOrPut(MonitorController.new);

    // Load short prose so trans() returns wrappable labels; without it the raw
    // 'uptizm.status.*' keys render as long unbreakable strings and overflow
    // the StatusBadge / KPI cells at the test viewport width.
    Translator.instance.setLoader(_MonitorDetailLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
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

  /// Advances past the 600ms loading skeleton so the content swaps in.
  ///
  /// The view shows a [DetailSkeleton] for the first 600ms (a Timer set in
  /// initState), so content assertions must pump past that window before the
  /// KPI row, chart, and tabs exist in the tree.
  Future<void> settleSkeleton(WidgetTester tester) async {
    await tester.pump(const Duration(milliseconds: 700));
  }

  testWidgets('MonitorDetailView renders the header with a StatusBadge', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await tester.pump();

    expect(find.byType(PageHeader), findsOneWidget);
    // The header carries one StatusBadge; the Overview's CheckHistoryTable adds
    // one per row, so scope the assertion to the header.
    expect(
      find.descendant(
        of: find.byType(PageHeader),
        matching: find.byType(StatusBadge),
      ),
      findsOneWidget,
    );
  });

  testWidgets('MonitorDetailView renders four KPI stat cards', (tester) async {
    // Match the physical surface to the declared 1280 MediaQuery so the dense
    // Overview heading row (response label + DateRangePicker) lays out at the
    // width it is told it has, rather than the default 800px test window.
    await tester.binding.setSurfaceSize(const Size(1280, 2200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await settleSkeleton(tester);

    expect(find.byType(KpiStatCard), findsNWidgets(4));
  });

  testWidgets(
    'MonitorDetailView renders a MetricChart and CheckHistoryTable on the '
    'Overview tab for a known monitor',
    (tester) async {
      // Match the physical surface to the declared 1280 MediaQuery so the dense
      // Overview heading row (response label + DateRangePicker) lays out at the
      // width it is told it has.
      await tester.binding.setSurfaceSize(const Size(1280, 2200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
      await settleSkeleton(tester);

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
    await settleSkeleton(tester);

    // Nothing in the KPI grid, MetricChart, or CheckHistoryTable may overflow
    // the narrow phone frame.
    expect(tester.takeException(), isNull);
    expect(find.byType(MetricChart), findsOneWidget);
    expect(find.byType(CheckHistoryTable), findsOneWidget);
  });

  testWidgets(
    'MonitorDetailView Metrics tab hosts the MonitorMetricsTab orchestrator',
    (tester) async {
      // Pin a desktop-class surface so the dense Metrics tab lays out without
      // clipping; this assertion targets composition, not narrow-width reflow.
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorDetailView(id: 'api'), size: const Size(1280, 4000)),
      );
      await settleSkeleton(tester);

      // Switch to the Metrics tab (index 1) and let it lay out.
      await tester.ensureVisible(
        find.text(trans('uptizm.monitors.tab_metrics')),
      );
      await tester.pump();
      await tester.tap(find.text(trans('uptizm.monitors.tab_metrics')));
      await tester.pump();

      // The Metrics tab hosts the MonitorMetricsTab orchestrator (system +
      // custom metrics); the AiAnalysisCard no longer lives here (it moved out
      // when the tab adopted MonitorMetricsTab). The Overview MetricChart is
      // gone from the tree now that the Metrics panel is selected.
      expect(tester.takeException(), isNull);
      expect(find.byType(MonitorMetricsTab), findsOneWidget);
      expect(find.byType(AiAnalysisCard), findsNothing);
    },
  );

  testWidgets(
    'MonitorDetailView shows the Reliability section for a monitor with an '
    'SLO target',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorDetailView(id: 'api'), size: const Size(1280, 4000)),
      );
      await settleSkeleton(tester);

      // The 'api' monitor is active (degraded) with an SLO target, so the
      // reliability section renders two SloBudgetCard gauges (7-day + 30-day).
      expect(tester.takeException(), isNull);
      expect(find.byType(SloBudgetCard), findsNWidgets(2));
    },
  );

  testWidgets(
    'MonitorDetailView Incidents tab lists IncidentCards for the monitor',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const MonitorDetailView(id: 'api'), size: const Size(1280, 4000)),
      );
      await settleSkeleton(tester);

      // Switch to the Incidents tab (index 2) and let it lay out.
      await tester.ensureVisible(
        find.text(trans('uptizm.monitors.tab_incidents')),
      );
      await tester.pump();
      await tester.tap(find.text(trans('uptizm.monitors.tab_incidents')));
      await tester.pump();

      // The API gateway has incidents on record, so the tab renders cards
      // rather than the empty state.
      expect(tester.takeException(), isNull);
      expect(find.byType(IncidentCard), findsWidgets);
    },
  );
}
