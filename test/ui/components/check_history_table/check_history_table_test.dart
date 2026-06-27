import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/ui/components/check_history_table/index.dart';
import 'package:uptizm/ui/components/check_history_table/check_history_table.preview.dart';
import 'package:uptizm/ui/components/status_dot/index.dart';

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
  // Recipe slot assertions
  // ---------------------------------------------------------------------------

  group('checkHistoryTableRecipe', () {
    test('table slot emits flex and w-full', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['table'], contains('flex'));
      expect(classes['table'], contains('w-full'));
    });

    test('row slot emits border-b and border-color-border', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['row'], contains('border-b'));
      expect(classes['row'], contains('border-color-border'));
    });

    test('header slot emits border-b and border-color-border', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['header'], contains('border-b'));
      expect(classes['header'], contains('border-color-border'));
    });

    test('cellMuted slot emits tabular-nums and font-mono', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['cellMuted'], contains('tabular-nums'));
      expect(classes['cellMuted'], contains('font-mono'));
    });

    test('cellMono slot emits tabular-nums and text-right', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['cellMono'], contains('tabular-nums'));
      expect(classes['cellMono'], contains('text-right'));
    });

    test('statusCell slot emits flex and items-center', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['statusCell'], contains('flex'));
      expect(classes['statusCell'], contains('items-center'));
    });

    test('all slots carry min-w-0 overflow guard', () {
      final classes = checkHistoryTableRecipe();
      for (final slot in ['cell', 'cellMuted', 'cellMono', 'statusCell']) {
        expect(
          classes[slot],
          contains('min-w-0'),
          reason: '$slot is missing min-w-0',
        );
      }
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('CheckHistoryTable renders one StatusDot per row', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(CheckHistoryTable(rows: recentChecks)));
    await tester.pump();

    final dots = tester.widgetList<StatusDot>(find.byType(StatusDot));
    expect(dots.length, equals(recentChecks.length));
  });

  testWidgets('CheckHistoryTable renders N rows from fixture', (tester) async {
    const rowCount = 4;
    final rows = recentChecks.take(rowCount).toList();
    await tester.pumpWidget(wrap(CheckHistoryTable(rows: rows)));
    await tester.pump();

    final dots = tester.widgetList<StatusDot>(find.byType(StatusDot));
    expect(dots.length, equals(rowCount));
  });

  testWidgets('CheckHistoryTable renders 0 rows without error', (tester) async {
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: [])));
    await tester.pump();

    expect(find.byType(StatusDot), findsNothing);
  });

  testWidgets('CheckHistoryTable renders time text for each row', (
    tester,
  ) async {
    final rows = recentChecks.take(2).toList();
    await tester.pumpWidget(wrap(CheckHistoryTable(rows: rows)));
    await tester.pump();

    for (final row in rows) {
      expect(find.text(row.time), findsWidgets);
    }
  });

  testWidgets('CheckHistoryTable renders region text for each row', (
    tester,
  ) async {
    final rows = recentChecks.take(2).toList();
    await tester.pumpWidget(wrap(CheckHistoryTable(rows: rows)));
    await tester.pump();

    for (final row in rows) {
      expect(find.text(row.region), findsWidgets);
    }
  });

  testWidgets('CheckHistoryTable formats null responseMs as dash', (
    tester,
  ) async {
    const rows = [
      CheckRow(time: '14:00:00', region: 'us-east', status: StatusKey.down),
    ];
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: rows)));
    await tester.pump();

    expect(find.text('—'), findsWidgets);
  });

  testWidgets('CheckHistoryTable formats responseMs with ms suffix', (
    tester,
  ) async {
    const rows = [
      CheckRow(
        time: '14:00:00',
        region: 'us-east',
        status: StatusKey.up,
        responseMs: 142,
      ),
    ];
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: rows)));
    await tester.pump();

    expect(find.text('142ms'), findsWidgets);
  });

  testWidgets('CheckHistoryTable passes correct status to each StatusDot', (
    tester,
  ) async {
    const rows = [
      CheckRow(
        time: '14:00:00',
        region: 'us-east',
        status: StatusKey.up,
        responseMs: 100,
      ),
      CheckRow(
        time: '13:59:00',
        region: 'eu-west',
        status: StatusKey.down,
        responseMs: 5000,
      ),
      CheckRow(
        time: '13:58:00',
        region: 'ap-southeast',
        status: StatusKey.degraded,
        responseMs: 800,
      ),
    ];
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: rows)));
    await tester.pump();

    final dots = tester.widgetList<StatusDot>(find.byType(StatusDot)).toList();
    expect(dots.length, equals(3));
    expect(dots[0].status, equals(StatusKey.up));
    expect(dots[1].status, equals(StatusKey.down));
    expect(dots[2].status, equals(StatusKey.degraded));
  });

  testWidgets('CheckHistoryTablePreview renders without error', (tester) async {
    // Wrap in SingleChildScrollView to avoid vertical overflow in the fixed
    // scaffold body — the preview's 2 sections (6 + 3 rows) exceed 552 px.
    await tester.pumpWidget(
      wrap(const SingleChildScrollView(child: CheckHistoryTablePreview())),
    );
    await tester.pump();

    // Preview renders 2 sections (6 rows + 3 rows), so at least 9 StatusDots.
    final dots = tester.widgetList<StatusDot>(find.byType(StatusDot));
    expect(dots.length, greaterThanOrEqualTo(recentChecks.length));
  });
}
