import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/resources/views/dashboard_view.dart';
import 'package:uptizm/ui/components/incident_card/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/monitor_list_row/index.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader resolve their themes
    // via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a wide
  /// MediaQuery so the desktop two-column branch renders its full surface.
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

  testWidgets('DashboardView renders four KPI stat cards', (tester) async {
    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pump();

    expect(find.byType(KpiStatCard), findsNWidgets(4));
  });

  testWidgets('DashboardView renders at least one IncidentCard', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pump();

    expect(find.byType(IncidentCard), findsWidgets);
  });

  testWidgets('DashboardView renders one MonitorListRow per monitor', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pump();

    expect(find.byType(MonitorListRow), findsNWidgets(monitors.length));
  });

  testWidgets('DashboardView only lists active (non-resolved) incidents', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pump();

    final activeCount = incidents
        .where((i) => i.lifecycle != IncidentLifecycle.resolved)
        .length;
    expect(find.byType(IncidentCard), findsNWidgets(activeCount));
  });

  testWidgets('DashboardView wraps its content in a PageContainer', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const DashboardView()));
    await tester.pump();

    expect(find.byType(PageContainer), findsOneWidget);
  });
}
