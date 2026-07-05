import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/ui/components/check_history_table/index.dart';
import 'package:uptizm/ui/components/check_history_table/check_history_table.preview.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';

/// In-memory loader feeding the short `uptizm.status.*` labels so [StatusBadge]
/// renders real prose ("Major outage") instead of the raw ~18-char i18n key
/// ("uptizm.status.down"). Without it the key string is far wider than the real
/// label and overflows the fixed status column at the test viewport, mirroring
/// the pattern the monitor-detail view test uses.
class _StatusLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.status.up': 'Operational',
      'uptizm.status.down': 'Major outage',
      'uptizm.status.degraded': 'Degraded',
      'uptizm.status.paused': 'Paused',
      'uptizm.status.info': 'Maintenance',
      'uptizm.status.ai': 'AI',
    };
  }
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_StatusLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

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
    test('table slot emits a flex column with no w-full (sizes to widest row)', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['table'], contains('flex'));
      expect(classes['table'], contains('flex-col'));
      // No w-full: the table sizes to its widest row so the overflow-x-auto
      // wrapper can scroll it as one unit on a narrow phone.
      expect(classes['table'], isNot(contains('w-full')));
    });

    test('row slot is a flex row with a bottom hairline divider', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['row'], contains('flex flex-row'));
      expect(classes['row'], contains('border-b'));
      expect(classes['row'], contains('border-color-border'));
    });

    test('header slot is a flex row with a bottom hairline divider', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['header'], contains('flex flex-row'));
      expect(classes['header'], contains('border-b'));
      expect(classes['header'], contains('border-color-border'));
    });

    test('th slot takes a fixed w-28 track and is quiet uppercase muted', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['th'], contains('w-28'));
      expect(classes['th'], contains('shrink-0'));
      expect(classes['th'], contains('uppercase'));
      expect(classes['th'], contains('tracking-wide'));
      expect(classes['th'], contains('text-fg-muted'));
    });

    test('thStatus slot takes the wider fixed w-48 track', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['thStatus'], contains('w-48'));
      expect(classes['thStatus'], contains('shrink-0'));
      expect(classes['thStatus'], contains('uppercase'));
    });

    test('numeric header slots take a fixed track and align right', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['thResponse'], contains('w-22'));
      expect(classes['thResponse'], contains('shrink-0'));
      expect(classes['thResponse'], contains('text-right'));
      expect(classes['thCode'], contains('w-14'));
      expect(classes['thCode'], contains('text-right'));
    });

    test('cellId slot takes a fixed w-28 track and emits tabular-nums font-mono', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['cellId'], contains('w-28'));
      expect(classes['cellId'], contains('shrink-0'));
      expect(classes['cellId'], contains('tabular-nums'));
      expect(classes['cellId'], contains('font-mono'));
    });

    test('numeric cell slots take a fixed track and align right', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['cellResponse'], contains('w-22'));
      expect(classes['cellResponse'], contains('tabular-nums'));
      expect(classes['cellResponse'], contains('text-right'));
      expect(classes['cellCode'], contains('w-14'));
      expect(classes['cellCode'], contains('text-right'));
    });

    test('statusCell slot is a fixed w-48 flex row', () {
      final classes = checkHistoryTableRecipe();
      expect(classes['statusCell'], contains('w-48'));
      expect(classes['statusCell'], contains('shrink-0'));
      expect(classes['statusCell'], contains('flex flex-row'));
      expect(classes['statusCell'], contains('items-center'));
    });

    test('every column slot takes a fixed shrink-0 track (scrollable as one unit)', () {
      final classes = checkHistoryTableRecipe();
      for (final slot in [
        'th',
        'thStatus',
        'thResponse',
        'thCode',
        'cellId',
        'statusCell',
        'cellResponse',
        'cellCode',
      ]) {
        expect(
          classes[slot],
          contains('shrink-0'),
          reason: '$slot must be shrink-0 so the row keeps a definite width',
        );
      }
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('CheckHistoryTable scrolls (no overflow) at a 375px mobile width', (
    tester,
  ) async {
    // Regression: the fixed-width grid (~560px) exceeds a phone viewport, so the
    // `overflow-x-auto` wrapper must scroll it as one unit rather than let a row
    // or the status pill overflow. Guards the mobile detail page from the earlier
    // RenderFlex-overflow / crash regression.
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(wrap(CheckHistoryTable(rows: recentChecks)));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.byType(Scrollable), findsWidgets);
    final badges = tester.widgetList<StatusBadge>(find.byType(StatusBadge));
    expect(badges.length, equals(recentChecks.length));
  });

  testWidgets('CheckHistoryTable renders one StatusBadge per row', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(CheckHistoryTable(rows: recentChecks)));
    await tester.pump();

    final badges = tester.widgetList<StatusBadge>(find.byType(StatusBadge));
    expect(badges.length, equals(recentChecks.length));
  });

  testWidgets('CheckHistoryTable renders N rows from fixture', (tester) async {
    const rowCount = 4;
    final rows = recentChecks.take(rowCount).toList();
    await tester.pumpWidget(wrap(CheckHistoryTable(rows: rows)));
    await tester.pump();

    final badges = tester.widgetList<StatusBadge>(find.byType(StatusBadge));
    expect(badges.length, equals(rowCount));
  });

  testWidgets('CheckHistoryTable renders 0 rows without error', (tester) async {
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: [])));
    await tester.pump();

    expect(find.byType(StatusBadge), findsNothing);
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

    // Both responseMs and statusCode are null, so two dash cells.
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

  testWidgets('CheckHistoryTable renders status code when present', (
    tester,
  ) async {
    const rows = [
      CheckRow(
        time: '14:00:00',
        region: 'us-east',
        status: StatusKey.down,
        responseMs: 5021,
        statusCode: 503,
      ),
    ];
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: rows)));
    await tester.pump();

    expect(find.text('503'), findsWidgets);
  });

  testWidgets('CheckHistoryTable renders dash for null statusCode', (
    tester,
  ) async {
    const rows = [
      CheckRow(
        time: '14:00:00',
        region: 'us-east',
        status: StatusKey.down,
        responseMs: 5021,
      ),
    ];
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: rows)));
    await tester.pump();

    expect(find.text('—'), findsWidgets);
  });

  testWidgets('CheckHistoryTable renders all five header column labels', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(CheckHistoryTable(rows: const [])));
    await tester.pump();

    // WText with `uppercase` className transforms the string to uppercase;
    // find.text must match the transformed value.
    expect(find.text('TIME'), findsOneWidget);
    expect(find.text('REGION'), findsOneWidget);
    expect(find.text('STATUS'), findsOneWidget);
    expect(find.text('RESPONSE'), findsOneWidget);
    expect(find.text('CODE'), findsOneWidget);
  });

  testWidgets('CheckHistoryTable passes correct status to each StatusBadge', (
    tester,
  ) async {
    const rows = [
      CheckRow(
        time: '14:00:00',
        region: 'us-east',
        status: StatusKey.up,
        responseMs: 100,
        statusCode: 200,
      ),
      CheckRow(
        time: '13:59:00',
        region: 'eu-west',
        status: StatusKey.down,
        responseMs: 5000,
        statusCode: 503,
      ),
      CheckRow(
        time: '13:58:00',
        region: 'ap-southeast',
        status: StatusKey.degraded,
        responseMs: 800,
        statusCode: 200,
      ),
    ];
    await tester.pumpWidget(wrap(const CheckHistoryTable(rows: rows)));
    await tester.pump();

    final badges = tester
        .widgetList<StatusBadge>(find.byType(StatusBadge))
        .toList();
    expect(badges.length, equals(3));
    expect(badges[0].status, equals(StatusKey.up));
    expect(badges[1].status, equals(StatusKey.down));
    expect(badges[2].status, equals(StatusKey.degraded));
  });

  testWidgets('CheckHistoryTablePreview renders without error', (tester) async {
    // Wrap in SingleChildScrollView to avoid vertical overflow in the fixed
    // scaffold body — the preview's 2 sections (6 + 3 rows) exceed 552 px.
    await tester.pumpWidget(
      wrap(const SingleChildScrollView(child: CheckHistoryTablePreview())),
    );
    await tester.pump();

    // Preview renders 2 sections (6 rows + 3 rows), so at least 9 StatusBadges.
    final badges = tester.widgetList<StatusBadge>(find.byType(StatusBadge));
    expect(badges.length, greaterThanOrEqualTo(recentChecks.length));
  });
}
