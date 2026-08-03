import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/resources/views/monitors/monitor_detail_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_tab.dart';
import 'package:uptizm/ui/components/ai_analysis_card/index.dart';
import 'package:uptizm/ui/components/check_history_table/index.dart';
import 'package:uptizm/ui/components/incident_card/index.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/metric_chart/index.dart';
import 'package:uptizm/ui/components/slo_budget_card/index.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';
import 'package:uptizm/ui/components/uptime_bar/index.dart';

import '../../support/monitor_fixtures.dart';
import '../../support/skeleton_matchers.dart';

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
      'uptizm.monitors.action_check_now': 'Check now',
      'uptizm.monitors.action_check_now_cooldown': 'Wait :secondss',
      'uptizm.monitors.action_pause': 'Pause',
      'uptizm.monitors.action_resume': 'Resume',
      'uptizm.monitors.action_edit': 'Edit',
      'uptizm.monitors.action_delete': 'Delete',
      'uptizm.monitors.section_reliability': 'Reliability',
      'uptizm.monitors.reliability_no_data_title': 'Not enough data yet',
      'uptizm.monitors.reliability_no_data_description':
          'Reliability metrics appear once Uptizm has been checking this '
          'monitor for a while.',
      'uptizm.monitors.metrics_custom_title': 'Custom metrics',
      'uptizm.monitors.metrics_add': 'Add metric',
      'uptizm.monitors.metrics_create': 'Create metric',
      'uptizm.monitors.metrics_empty_title': 'No custom metrics',
      'uptizm.monitors.metrics_empty_description': 'None yet.',
      'uptizm.monitors.metrics_system_collected_by_default': 'collected',
      'uptizm.monitors.kpi_uptime_24h': 'Uptime 24h',
      'uptizm.monitors.kpi_last_response': 'Last response',
      'uptizm.monitors.kpi_last_check': 'Last check',
      'uptizm.monitors.kpi_open_incidents_for_monitor': 'Open incidents',
      'uptizm.monitors.kpi_delta_ongoing': 'ongoing',
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
      // SloBudgetCard (Reliability section), now trans()-driven.
      'uptizm.slo.error_budget': 'Error budget',
      'uptizm.slo.status_healthy': 'Healthy',
      'uptizm.slo.status_at_risk': 'At risk',
      'uptizm.slo.status_breached': 'Budget breached',
      'uptizm.slo.budget_left': ':pct% budget left',
      'uptizm.slo.budget_of': ':used of :allowed',
      'uptizm.slo.over_budget': 'Over budget by :amount this window.',
      'uptizm.slo.window_7day': '7-day',
      'uptizm.slo.window_30day': '30-day',
      // DateRangePicker (response-time section), now trans()-driven.
      'uptizm.ranges.custom': 'Custom range',
      'uptizm.ranges.last_24h': 'Last 24 hours',
      'uptizm.ranges.last_7d': 'Last 7 days',
      'uptizm.ranges.last_30d': 'Last 30 days',
      'uptizm.ranges.last_90d': 'Last 90 days',
    };
  }
}

void main() {
  // Captured so an individual test can layer a `stub()` on top (e.g. the
  // manual-check `/monitors/:id/test` response) without losing the shared
  // checks/response-times/incidents stubs below; `stub()` inserts at index 0,
  // so it takes priority over these without replacing them.
  late FakeNetworkDriver fakeNetwork;

  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader / Tabs resolve their
    // themes via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Stub the two live endpoints the detail now fetches: recent checks +
    // bucketed response-times. `GET /monitors[/:id]` (reload / _refreshOne) is
    // left unstubbed and degrades to a no-op in the controller, leaving the
    // seeded inventory below as the `monitorById` source; the `checks` /
    // `response-times` stubs populate the Overview chart + recent-checks table.
    fakeNetwork = Http.fake({
      '*response-times*': Http.response({
        'data': [
          {
            'response_ms': 180,
            'checked_at': '2026-07-11T19:00:00.000000Z',
            'status': 'up',
          },
          {
            'response_ms': 210,
            'checked_at': '2026-07-11T19:10:00.000000Z',
            'status': 'up',
          },
          {
            'response_ms': 195,
            'checked_at': '2026-07-11T19:20:00.000000Z',
            'status': 'up',
          },
        ],
      }),
      // The Incidents tab and the open-incident KPI read the REAL roster
      // (`GET /incidents`) filtered by monitor identity. `inc-api` names the
      // 'api' monitor both as its primary and in its component pivot; `inc-other`
      // belongs to a different monitor and must never appear on this screen.
      'incidents': Http.response({
        'data': [
          {
            'id': 'inc-api',
            'title': 'API gateway returning 503s',
            'lifecycle': 'investigating',
            'severity': 'critical',
            'impact': 'critical',
            'started_at': '2026-07-11T14:00:00Z',
            'primary_monitor_id': 'api',
            'monitors': [
              {'monitor_id': 'api', 'name': 'API gateway'},
            ],
          },
          {
            'id': 'inc-other',
            'title': 'Marketing site slow',
            'lifecycle': 'investigating',
            'severity': 'warning',
            'impact': 'minor',
            'started_at': '2026-07-11T13:00:00Z',
            'primary_monitor_id': 'marketing',
            'monitors': [
              {'monitor_id': 'marketing', 'name': 'Marketing site'},
            ],
          },
        ],
      }),
      '*checks': Http.response({
        'data': [
          {
            'id': 'c1',
            'region': 'us-east',
            'status': 'up',
            'status_code': 200,
            'response_ms': 180,
            'checked_at': '2026-07-11T19:20:00.000000Z',
          },
          {
            'id': 'c2',
            'region': 'eu-west',
            'status': 'up',
            'status_code': 200,
            'response_ms': 198,
            'checked_at': '2026-07-11T19:19:00.000000Z',
          },
        ],
      }),
    });
    // Register the controller MonitorDetailView binds to, then seed its cache
    // with the fixture inventory. The view reads `controller.monitorById(id)`
    // synchronously in build(); onInit's async `reload()` degrades to a no-op
    // under the empty fake, so the seed is what `monitorById('api')` resolves.
    MonitorController.instance.seedForTest(monitorFixtures);

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

  /// Flushes the async initState data load (recent checks + response-times) so
  /// the content swaps in from the stubbed live endpoints.
  ///
  /// The view fetches on initState and flips `_loading` off once both requests
  /// settle, so content assertions must pump the fetch microtasks + the content
  /// frame before the KPI row, chart, and tabs exist in the tree.
  Future<void> settleSkeleton(WidgetTester tester) async {
    await tester.pumpAndSettle();
  }

  testWidgets('MonitorDetailView shows skeleton bars that occupy real height '
      'on the pending first frame', (tester) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    // A single pump leaves the initState fetch unsettled, so `_loading` is
    // still true and the body is the skeleton scaffold rather than content.
    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));

    expect(find.byType(MSSkeleton), findsWidgets);
    expectVisibleSkeletons(tester);

    // Once the fetch settles the skeleton is replaced outright, so a leftover
    // placeholder can never sit alongside real data.
    await settleSkeleton(tester);
    expect(find.byType(MSSkeleton), findsNothing);
  });

  testWidgets('MonitorDetailView renders the header with a StatusBadge', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await settleSkeleton(tester);

    expect(find.byType(MSPageHeader), findsOneWidget);
    // The header carries one StatusBadge; the Overview's CheckHistoryTable adds
    // one per row, so scope the assertion to the header.
    expect(
      find.descendant(
        of: find.byType(MSPageHeader),
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

  testWidgets('MonitorDetailView renders the 90-day uptime bar from the live '
      'response-times endpoint', (tester) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await settleSkeleton(tester);

    // The stubbed `*response-times*` fixture (all `status: 'up'`) is bucketed
    // into 90 daily segments by MonitorController.loadUptime90, so the bar
    // always renders exactly 90 segments regardless of the source data shape.
    final UptimeBar bar = tester.widget<UptimeBar>(find.byType(UptimeBar));
    expect(bar.segments, hasLength(90));
  });

  testWidgets('MonitorDetailView wraps its content in a MSPageContainer', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 2200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
    await settleSkeleton(tester);

    expect(find.byType(MSPageContainer), findsOneWidget);
  });

  testWidgets(
    'MonitorDetailView renders a graceful MSEmptyState for an unknown id',
    (tester) async {
      await tester.pumpWidget(wrap(const MonitorDetailView(id: 'nope')));
      await tester.pump();

      // No monitor surfaces (no KPI cards, no chart); just the not-found body.
      expect(find.byType(MSEmptyState), findsOneWidget);
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
    'MonitorDetailView shows a no-data reliability note when uptime is '
    'unmeasured',
    (tester) async {
      // A fresh monitor: it has an SLO target but no measured uptime yet
      // (slo_uptime_7d/30d null). The reliability section must show the "no
      // data yet" note rather than fabricated gauges reading as breached.
      MonitorController.instance.seedForTest([
        Monitor.fromMap({
          'id': 'fresh',
          'name': 'Fresh Monitor',
          'url': 'https://fresh.test/health',
          'type': 'http',
          'method': 'get',
          'status': 'active',
          'last_status': 'up',
          'slo_target': 99.9,
          'check_interval_sec': 60,
          'regions': ['us-east'],
        }),
      ]);

      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          const MonitorDetailView(id: 'fresh'),
          size: const Size(1280, 4000),
        ),
      );
      await settleSkeleton(tester);

      expect(tester.takeException(), isNull);
      expect(find.byType(SloBudgetCard), findsNothing);
      expect(
        find.text(trans('uptizm.monitors.reliability_no_data_title')),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'MonitorDetailView renders the real measured 24h uptime in the KPI row',
    (tester) async {
      // The UPTIME (24h) KPI reads the backend `uptime_24h`, not a hardcoded
      // constant or the '—' placeholder.
      MonitorController.instance.seedForTest([
        Monitor.fromMap({
          'id': 'measured',
          'name': 'Measured Monitor',
          'url': 'https://measured.test/health',
          'type': 'http',
          'method': 'get',
          'status': 'active',
          'last_status': 'up',
          'uptime_24h': 99.5,
          'check_interval_sec': 60,
          'regions': ['us-east'],
        }),
      ]);

      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(
          const MonitorDetailView(id: 'measured'),
          size: const Size(1280, 4000),
        ),
      );
      await settleSkeleton(tester);

      expect(find.text('99.50%'), findsOneWidget);
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

      // This used to read the design-lab `incidentsForMonitor` fixture keyed by
      // monitor NAME, so the tab listed five invented incidents. It now filters
      // the real roster by monitor identity: exactly the one incident that
      // names this monitor, and never another monitor's.
      expect(tester.takeException(), isNull);
      expect(find.byType(IncidentCard), findsOneWidget);
      expect(find.text('API gateway returning 503s'), findsOneWidget);
      expect(find.text('Marketing site slow'), findsNothing);
    },
  );

  group('manual-check cooldown', () {
    testWidgets(
      'a faked 429 disables Check now and counts the remaining seconds down, '
      'then re-enables when the cooldown elapses',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 2200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        fakeNetwork.stub(
          'monitors/api/test',
          Http.response({
            'message': 'A manual check for this monitor was run recently.',
            'retry_after_seconds': 3,
          }, 429),
        );

        await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
        await settleSkeleton(tester);

        final Finder checkNowButton = find.byKey(
          const ValueKey('check-now-button'),
        );
        expect(tester.widget<MSButton>(checkNowButton).disabled, isFalse);

        await tester.tap(checkNowButton);
        await tester.pump();

        expect(tester.widget<MSButton>(checkNowButton).disabled, isTrue);
        expect(
          find.text(
            trans('uptizm.monitors.action_check_now_cooldown', {
              'seconds': 3,
            }),
          ),
          findsOneWidget,
        );
        expect(MonitorController.instance.cooldownSecondsFor('api'), 3);

        await tester.pump(const Duration(seconds: 1));
        expect(
          find.text(
            trans('uptizm.monitors.action_check_now_cooldown', {
              'seconds': 2,
            }),
          ),
          findsOneWidget,
        );

        // Advance past the remainder of the cooldown so the countdown's
        // Timer self-cancels (never leaks a pending Timer past this test).
        await tester.pump(const Duration(seconds: 2));

        expect(tester.widget<MSButton>(checkNowButton).disabled, isFalse);
        expect(
          find.text(trans('uptizm.monitors.action_check_now')),
          findsOneWidget,
        );
        expect(MonitorController.instance.cooldownSecondsFor('api'), isNull);
      },
    );

    testWidgets(
      'a successful 202 leaves Check now enabled with no cooldown',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 2200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        fakeNetwork.stub('monitors/api/test', Http.response(null, 202));

        await tester.pumpWidget(wrap(const MonitorDetailView(id: 'api')));
        await settleSkeleton(tester);

        final Finder checkNowButton = find.byKey(
          const ValueKey('check-now-button'),
        );
        await tester.tap(checkNowButton);
        await tester.pump();

        expect(tester.widget<MSButton>(checkNowButton).disabled, isFalse);
        expect(
          find.text(trans('uptizm.monitors.action_check_now')),
          findsOneWidget,
        );
        expect(MonitorController.instance.cooldownSecondsFor('api'), isNull);
        fakeNetwork.assertSent(
          (r) => r.method == 'POST' && r.url == '/monitors/api/test',
        );

        // Drain the result watch a 202 starts. It re-reads the monitor a few
        // times looking for the queued check to land, and the fake returns the
        // same monitor forever, so it spends its whole budget. Left pending, the
        // binding fails the test for a timer outliving the tree, which is a fair
        // complaint about the harness rather than about the cooldown this case
        // is actually asserting.
        await tester.pump(const Duration(seconds: 13));
      },
    );
  });

  group('first check reaching the screen', () {
    testWidgets(
      'a queued manual check is re-read until its result lands',
      (tester) async {
        // The other half of the same live-session defect. `runCheckNow` refreshed
        // once, immediately, and its docblock deferred anything later to "the
        // team's realtime channel". But MonitorStatusChanged fires on a status
        // TRANSITION, so a manual check confirming an already-up monitor
        // broadcasts nothing: the operator pressed the button and the table never
        // gained the row. Verified live, with the database at two checks while
        // one row showed on screen.
        //
        // The immediate refresh cannot see the result either: the probe is queued
        // and lands a second or two afterwards, the same queue-time versus
        // fire-time trap as the alert-suppression guard.
        MonitorController.instance.seedForTest(monitorFixtures);

        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            const MonitorDetailView(id: 'api'),
            size: const Size(1280, 4000),
          ),
        );
        await settleSkeleton(tester);

        int monitorReads() => fakeNetwork.recorded
            .map((entry) => entry.$1)
            .where((r) => r.method == 'GET' && r.url == '/monitors/api')
            .length;

        final int beforeReads = monitorReads();

        await MonitorController.instance.runCheckNow('api');
        await tester.pump();

        final int afterImmediate = monitorReads();
        expect(
          afterImmediate,
          greaterThan(beforeReads),
          reason: 'the immediate refresh still happens',
        );

        // Let the watch tick. Each tick re-reads the monitor, and each read
        // notifies, which is what carries a landed check onto the screen.
        await tester.pump(const Duration(seconds: 5));

        expect(
          monitorReads(),
          greaterThan(afterImmediate),
          reason:
              'the queued result is looked for AFTER the request, not only at '
              'the moment it was made',
        );

        // Bounded, not a forever poll: drain the rest of the budget so the
        // binding sees no timer outliving the tree.
        await tester.pump(const Duration(seconds: 13));
      },
    );

    testWidgets(
      'a monitor with no check yet says so instead of an empty table',
      (tester) async {
        // Reported from a live session: opened right after a create, the KPI row
        // showed a real latency while the checks table below rendered a header
        // and nothing else, which reads as a half-broken page.
        //
        // The shared harness stubs `*checks` with two rows for EVERY monitor, so
        // a brand-new monitor has to be given the empty history it really has;
        // otherwise this asserts against a fixture no fresh monitor could hold.
        Http.fake({
          '*checks': Http.response({'data': const <Map<String, dynamic>>[]}),
          '*response-times*': Http.response({
            'data': const <Map<String, dynamic>>[],
          }),
        });

        MonitorController.instance.seedForTest([
          Monitor.fromMap({
            'id': 'brand-new',
            'name': 'Brand New',
            'url': 'https://brand-new.test/health',
            'type': 'http',
            'method': 'get',
            'status': 'active',
            'check_interval_sec': 180,
            'regions': ['eu-central'],
            // No last_checked_at, no last_status: the first probe is queued.
          }),
        ]);

        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            const MonitorDetailView(id: 'brand-new'),
            size: const Size(1280, 4000),
          ),
        );
        await settleSkeleton(tester);

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.monitors.checks_pending_title')),
          findsOneWidget,
          reason: 'the waiting state replaces the bare table',
        );
        expect(
          find.byType(CheckHistoryTable),
          findsNothing,
          reason: 'a header with no rows is what looked broken',
        );
      },
    );

    testWidgets(
      'a newly landed check refetches the history without a remount',
      (tester) async {
        // The core of the live-session defect: the three check-derived lists
        // were fetched once per mount, so the realtime reload refreshed the
        // monitor resource (hence the KPI) while the chart and table kept the
        // empty result of the mount fetch forever.
        Monitor seed(String? checkedAt) => Monitor.fromMap({
          'id': 'landing',
          'name': 'Landing',
          'url': 'https://landing.test/health',
          'type': 'http',
          'method': 'get',
          'status': 'active',
          'last_status': 'up',
          'check_interval_sec': 180,
          'regions': ['eu-central'],
          'last_checked_at': ?checkedAt,
        });

        MonitorController.instance.seedForTest([seed(null)]);

        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            const MonitorDetailView(id: 'landing'),
            size: const Size(1280, 4000),
          ),
        );
        await settleSkeleton(tester);

        int checkFetches() => fakeNetwork.recorded
            .map((entry) => entry.$1)
            .where((r) => r.method == 'GET' && r.url.contains('/checks'))
            .length;

        final int beforeFetches = checkFetches();

        // The first check lands: the backend broadcast makes RealtimeService
        // reload the controller, which republishes the monitor with a
        // last_checked_at. That notify is the only signal this screen gets.
        MonitorController.instance.seedForTest([
          seed('2026-08-03T12:00:00.000000Z'),
        ]);
        await tester.pumpAndSettle();

        expect(
          checkFetches(),
          greaterThan(beforeFetches),
          reason:
              'a new last_checked_at must re-fetch the history; without it the '
              'table stays at whatever the mount fetch returned',
        );
      },
    );

    testWidgets(
      'an unrelated controller notify does not refetch the history',
      (tester) async {
        // The gate matters as much as the refetch: the controller notifies for
        // plenty of reasons that leave this monitor's history untouched, and
        // three HTTP fetches per notify would be a self-inflicted load.
        Monitor seed(String name) => Monitor.fromMap({
          'id': 'steady',
          'name': name,
          'url': 'https://steady.test/health',
          'type': 'http',
          'method': 'get',
          'status': 'active',
          'last_status': 'up',
          'check_interval_sec': 180,
          'regions': ['eu-central'],
          'last_checked_at': '2026-08-03T12:00:00.000000Z',
        });

        MonitorController.instance.seedForTest([seed('Steady')]);

        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          wrap(
            const MonitorDetailView(id: 'steady'),
            size: const Size(1280, 4000),
          ),
        );
        await settleSkeleton(tester);

        int checkFetches() => fakeNetwork.recorded
            .map((entry) => entry.$1)
            .where((r) => r.method == 'GET' && r.url.contains('/checks'))
            .length;

        final int beforeFetches = checkFetches();

        // Same last_checked_at, different name: a notify with no new check.
        MonitorController.instance.seedForTest([seed('Steady renamed')]);
        await tester.pumpAndSettle();

        expect(
          checkFetches(),
          beforeFetches,
          reason: 'no new check means no refetch',
        );
      },
    );
  });
}
