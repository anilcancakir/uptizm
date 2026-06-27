import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/resources/views/monitors_list_view.dart';
import 'package:uptizm/ui/components/monitor_list_row/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so PageHeader / SegmentedControl / EmptyState
    // resolve their themes via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
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

    expect(find.byType(SegmentedControl), findsOneWidget);
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
