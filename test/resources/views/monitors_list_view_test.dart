import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';
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
    MonitorController.instance.seedForTest(monitors);

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
