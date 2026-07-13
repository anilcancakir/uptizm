import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/ui/components/monitor_list_row/index.dart';
import 'package:uptizm/ui/components/monitor_list_row/monitor_list_row.preview.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';

import '../../../support/monitor_fixtures.dart';

void main() {
  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] so
  /// W-widgets can resolve Wind styles without a running Magic app.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe / slot-class assertions
  // ---------------------------------------------------------------------------

  group('monitorListRowRecipe', () {
    test('base contains rounded-lg', () {
      expect(monitorListRowRecipe(), contains('rounded-lg'));
    });

    test('base contains border-color-border', () {
      expect(monitorListRowRecipe(), contains('border-color-border'));
    });

    test('base contains bg-surface', () {
      expect(monitorListRowRecipe(), contains('bg-surface'));
    });
  });

  group('monitorListRowSlots', () {
    test('root slot contains min-h-[44px]', () {
      final slots = monitorListRowSlots();
      expect(slots['root'], contains('min-h-[44px]'));
    });

    test('name slot contains truncate and font-medium', () {
      final slots = monitorListRowSlots();
      expect(slots['name'], contains('truncate'));
      expect(slots['name'], contains('font-medium'));
    });

    test('url slot contains font-mono and text-fg-muted', () {
      final slots = monitorListRowSlots();
      expect(slots['url'], contains('font-mono'));
      expect(slots['url'], contains('text-fg-muted'));
    });

    test(
      'metric slot contains tabular-nums, font-mono, shrink-0, and text-right',
      () {
        final slots = monitorListRowSlots();
        expect(slots['metric'], contains('tabular-nums'));
        expect(slots['metric'], contains('font-mono'));
        expect(slots['metric'], contains('shrink-0'));
        expect(slots['metric'], contains('text-right'));
      },
    );

    test('caller className is appended to root slot', () {
      final slots = monitorListRowSlots(className: 'shadow-md');
      expect(slots['root'], contains('shadow-md'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('MonitorListRow renders monitor name', (tester) async {
    await tester.pumpWidget(wrap(MonitorListRow(monitor: monitorFixtures.first)));
    expect(find.text(monitors.first.name!), findsOneWidget);
  });

  testWidgets('MonitorListRow renders monitor URL', (tester) async {
    await tester.pumpWidget(wrap(MonitorListRow(monitor: monitorFixtures.first)));
    expect(find.text(monitors.first.url!), findsOneWidget);
  });

  testWidgets('MonitorListRow renders a StatusBadge', (tester) async {
    await tester.pumpWidget(wrap(MonitorListRow(monitor: monitorFixtures.first)));
    expect(find.byType(StatusBadge), findsOneWidget);
  });

  testWidgets('MonitorListRow with responseMs shows formatted latency', (
    tester,
  ) async {
    final monitor = Monitor.fromMap(const {
      'id': 'test',
      'name': 'Test',
      'url': 'https://test.example.com',
      'last_status': 'up',
      'last_response_ms': 142,
      'check_interval_sec': 30,
      'regions': ['us-east'],
    });
    await tester.pumpWidget(wrap(MonitorListRow(monitor: monitor)));
    expect(find.text('142ms'), findsOneWidget);
  });

  testWidgets('MonitorListRow without responseMs shows em dash', (
    tester,
  ) async {
    final monitor = Monitor.fromMap(const {
      'id': 'test-paused',
      'name': 'Paused',
      'url': 'https://paused.example.com',
      'status': 'paused',
      'check_interval_sec': 60,
      'regions': ['eu-central'],
    });
    await tester.pumpWidget(wrap(MonitorListRow(monitor: monitor)));
    expect(find.text('—'), findsWidgets);
  });

  testWidgets('MonitorListRow onTap fires when tapped', (tester) async {
    var tapped = false;
    await tester.pumpWidget(
      wrap(MonitorListRow(monitor: monitorFixtures.first, onTap: () => tapped = true)),
    );

    // Tap the outermost WAnchor (the row shell). WDiv with hover: className
    // also auto-wraps in WAnchor, so use .first to target the row anchor.
    await tester.tap(find.byType(WAnchor).first);
    await tester.pump();

    expect(tapped, isTrue);
  });

  testWidgets(
    'MonitorListRow renders degraded StatusBadge for degraded monitor',
    (tester) async {
      final degraded = monitorFixtures.firstWhere(
        (m) => m.status == StatusKey.degraded,
      );
      await tester.pumpWidget(wrap(MonitorListRow(monitor: degraded)));

      final badge = tester.widget<StatusBadge>(find.byType(StatusBadge));
      expect(badge.status, equals(StatusKey.degraded));
    },
  );

  testWidgets('MonitorListRow renders down StatusBadge for down monitor', (
    tester,
  ) async {
    final down = monitorFixtures.firstWhere((m) => m.status == StatusKey.down);
    await tester.pumpWidget(wrap(MonitorListRow(monitor: down)));

    final badge = tester.widget<StatusBadge>(find.byType(StatusBadge));
    expect(badge.status, equals(StatusKey.down));
  });

  testWidgets('MonitorListRow renders paused StatusBadge for paused monitor', (
    tester,
  ) async {
    final paused = monitorFixtures.firstWhere((m) => m.status == StatusKey.paused);
    await tester.pumpWidget(wrap(MonitorListRow(monitor: paused)));

    final badge = tester.widget<StatusBadge>(find.byType(StatusBadge));
    expect(badge.status, equals(StatusKey.paused));
  });

  testWidgets('MonitorListRowPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const MonitorListRowPreview()));
    await tester.pump();
    // Four fixture monitors produce four rows, each with a StatusBadge.
    expect(find.byType(StatusBadge), findsNWidgets(monitors.length));
  });
}
