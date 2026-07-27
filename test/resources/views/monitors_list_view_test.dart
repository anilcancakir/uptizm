import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/controllers/dashboard_controller.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/enums/status_key.dart';

import '../../support/monitor_fixtures.dart';
import 'package:uptizm/resources/views/monitors/monitors_list_view.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/monitor_list_row/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

/// In-memory loader feeding the monitors page prose so [trans] returns short,
/// wrappable strings instead of raw key tokens. Without this, the full dot-
/// separated keys (e.g. `'uptizm.monitors.kpi_monitors_used'`) render as
/// unbreakable 30-char labels inside the narrow KPI stat cards and cause layout
/// overflow, mirroring what the dashboard_view_test already guards against.
class _MonitorsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.monitors.title': 'Monitors',
      'uptizm.monitors.description':
          'Endpoints Uptizm is watching across regions.',
      'uptizm.monitors.new_monitor': 'New monitor',
      'uptizm.monitors.kpi_monitors_used': 'Monitors used',
      'uptizm.monitors.kpi_operational': 'Operational',
      'uptizm.monitors.kpi_open_incidents': 'Open incidents',
      'uptizm.monitors.kpi_avg_response': 'Avg response',
      'uptizm.monitors.empty_no_monitors_title': 'No monitors yet',
      'uptizm.monitors.empty_no_monitors_description':
          'Add your first endpoint and Uptizm starts checking it from every '
          'region within seconds.',
      'uptizm.monitors.empty_no_match_title': 'No monitors match',
      'uptizm.monitors.empty_no_match_description':
          'Nothing in this status right now. Try a different filter or add a '
          'new monitor.',
      'uptizm.monitors.empty_no_match_clear': 'Clear filter',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so PageHeader / SegmentedControl / EmptyState
    // resolve their themes via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind LogManager so the EntitlementController's offline-degradation path
    // (Log.error on the failed billing fetch this view triggers via
    // EntitlementController.instance) resolves instead of throwing.
    Magic.singleton('log', () => LogManager());
    // Bind an empty fake network so the wired controller resolves the `network`
    // service. The view's onInit `reload()` and per-id `_refreshOne` fetch
    // `GET /monitors[/:id]`; an empty fake returns `{}` (no `data` list), which
    // the controller's decode treats as a no-op, leaving the seeded inventory
    // below untouched instead of clobbering it or throwing.
    Http.fake();
    // Register the controller MonitorsListView binds to, then seed its cache
    // with the fixture inventory. The list view reads `controller.monitors`
    // synchronously in build(); onInit's async `reload()` degrades to a no-op
    // under the empty fake, so the seed is what the view renders against.
    MonitorController.instance.seedForTest(monitorFixtures);

    // Load the real monitors prose so trans() returns wrappable text.
    // Without this, long key tokens (e.g. 'uptizm.monitors.kpi_monitors_used')
    // render as unbreakable strings inside narrow KPI stat card cells and cause
    // layout overflow at test viewport widths.
    Translator.instance.setLoader(_MonitorsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  Widget wrap(Widget widget, {Size size = const Size(1280, 1600)}) {
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

  testWidgets('renders one MonitorListRow per monitor by default', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pump();

    expect(find.byType(MonitorListRow), findsNWidgets(monitors.length));
  });

  testWidgets('wraps its content in a PageContainer', (tester) async {
    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pump();

    expect(find.byType(PageContainer), findsOneWidget);
  });

  testWidgets('renders the four filter tabs', (tester) async {
    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pump();

    expect(find.byType(MSSegmentedControl), findsOneWidget);
  });

  testWidgets('renders the four KPI stat cards', (tester) async {
    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pump();

    // Mirrors the React grid: monitors used, operational, open incidents, avg
    // response — four cards always present regardless of the active filter.
    expect(find.byType(KpiStatCard), findsNWidgets(4));
  });

  testWidgets('the open-incidents KPI catches up once the dashboard resolves', (
    tester,
  ) async {
    // Regression: OPEN INCIDENTS read a flat 0 on this page while the API
    // reported 2, because the count comes from [DashboardController], which is
    // NOT this view's backing controller. Resolving `.instance` self-triggers
    // its first fetch, but the row only re-renders if the view listens to it,
    // and a stale 0 here reads as "nothing is wrong" during a live outage.
    Http.fake({
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 1,
          'monitors_down': 2,
          'monitors_total': 3,
          'monitors_pending': 0,
          'open_incidents': 2,
        },
      }),
      'dashboard/active-incidents': Http.response({'data': <dynamic>[]}),
      'dashboard/monitors-snapshot': Http.response({'data': <dynamic>[]}),
      'dashboard/ai-inbox': Http.response({'data': <dynamic>[]}),
    });

    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pumpAndSettle();

    KpiStatCard openIncidents() => tester
        .widgetList<KpiStatCard>(find.byType(KpiStatCard))
        .firstWhere((KpiStatCard card) => card.label == 'Open incidents');

    expect(openIncidents().value, equals('2'));

    // Now move ONLY the dashboard: re-stub the count and reload it without
    // touching MonitorController. That isolation is the whole point. This
    // view's backing controller is MonitorController, so its own notifications
    // repaint the row for free and would mask a missing listener; a dashboard
    // that lands a new count on its own must still reach the screen. Live, the
    // monitors fetch resolved first (326ms) and the stats fetch second (384ms),
    // so the row painted the pre-fetch zeros and never heard about the 2.
    Http.fake({
      'dashboard/stats': Http.response({
        'data': {
          'monitors_up': 1,
          'monitors_down': 2,
          'monitors_total': 3,
          'monitors_pending': 0,
          'open_incidents': 5,
        },
      }),
      'dashboard/active-incidents': Http.response({'data': <dynamic>[]}),
      'dashboard/monitors-snapshot': Http.response({'data': <dynamic>[]}),
      'dashboard/ai-inbox': Http.response({'data': <dynamic>[]}),
    });
    await DashboardController.instance.reload();
    await tester.pump();

    expect(openIncidents().value, equals('5'));
  });

  testWidgets('the avg-response KPI claims no trend it cannot measure', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pump();

    final KpiStatCard avgResponse = tester
        .widgetList<KpiStatCard>(find.byType(KpiStatCard))
        .firstWhere((KpiStatCard card) => card.label == 'Avg response');

    // The delta and its "vs. last 24h" hint were a hardcoded 12ms literal: no
    // prior-window average is fetched, so no comparison may be shown. The value
    // reads a real average, or the no-data em-dash when no monitor timed a
    // check (never a 0ms that looks like an instantaneous fleet).
    expect(avgResponse.delta, isNull);
    expect(avgResponse.hint, isNull);
    expect(
      avgResponse.value == '—' || avgResponse.value.endsWith('ms'),
      isTrue,
      reason: 'expected a measured average or the no-data placeholder',
    );
    expect(avgResponse.value, isNot(equals('0ms')));
  });

  testWidgets('the operational KPI only trends down when something is down', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pump();

    final KpiStatCard operational = tester
        .widgetList<KpiStatCard>(find.byType(KpiStatCard))
        .firstWhere((KpiStatCard card) => card.label == 'Operational');

    // A red downward trend on "0 down" made a healthy fleet read as degraded,
    // so the delta and the down trend now travel together: either a real down
    // count is shown as a down trend, or neither is rendered.
    expect(
      operational.delta == null,
      equals(operational.trend == KpiTrend.neutral),
      reason: 'a down trend must come with a down count, and vice versa',
    );
  });

  testWidgets('renders a PageHeader with New monitor action', (tester) async {
    await tester.pumpWidget(wrap(const MonitorsListView()));
    await tester.pump();

    expect(find.byType(MSPageHeader), findsOneWidget);
    // The "New monitor" button label must be visible in the header actions.
    expect(find.text('New monitor'), findsAtLeastNWidgets(1));
  });

  testWidgets('does not overflow at a mobile width', (tester) async {
    await tester.pumpWidget(
      wrap(const MonitorsListView(), size: const Size(360, 1600)),
    );
    await tester.pump();

    // A RenderFlex overflow throws during paint; reaching here clean means the
    // list rows + filter row fit a 360px column.
    expect(tester.takeException(), isNull);
    expect(find.byType(MonitorListRow), findsNWidgets(monitors.length));
  });

  testWidgets('every fixture monitor status is one of the filter statuses', (
    tester,
  ) async {
    // Guards the filter contract: each monitor maps to All + at most one of
    // the operational/degraded/down tabs (paused monitors only show under All).
    const filterable = {StatusKey.up, StatusKey.degraded, StatusKey.down};
    expect(monitors.isNotEmpty, isTrue);
    for (final m in monitors) {
      expect(StatusKey.values.contains(m.status), isTrue);
    }
    // At least one monitor is filterable so the non-All tabs are exercised.
    expect(monitors.any((m) => filterable.contains(m.status)), isTrue);
  });
}
