import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/ui/components/uptime_bar/index.dart';
import 'package:uptizm/ui/components/uptime_bar/uptime_bar.preview.dart';

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
  // Recipe variant-class assertions
  // ---------------------------------------------------------------------------

  group('uptimeBarRecipe', () {
    test('track slot emits w-full and flex', () {
      final classes = uptimeBarRecipe();
      expect(classes['track'], contains('w-full'));
      expect(classes['track'], contains('flex'));
    });

    test('sm size variant emits h-6 on track', () {
      final classes = uptimeBarRecipe(variants: {kUptimeBarSizeAxis: 'sm'});
      expect(classes['track'], contains('h-6'));
    });

    test('md size variant emits h-9 on track', () {
      final classes = uptimeBarRecipe(variants: {kUptimeBarSizeAxis: 'md'});
      expect(classes['track'], contains('h-9'));
    });

    test('lg size variant emits h-12 on track', () {
      final classes = uptimeBarRecipe(variants: {kUptimeBarSizeAxis: 'lg'});
      expect(classes['track'], contains('h-12'));
    });

    test('default size variant is md', () {
      final classes = uptimeBarRecipe();
      expect(classes['track'], contains('h-9'));
    });

    test('up status emits bg-up on segment slot', () {
      final classes = uptimeBarRecipe(variants: {kUptimeBarStatusAxis: 'up'});
      expect(classes['segment'], contains('bg-up'));
    });

    test('down status emits bg-down on segment slot', () {
      final classes = uptimeBarRecipe(variants: {kUptimeBarStatusAxis: 'down'});
      expect(classes['segment'], contains('bg-down'));
    });

    test('degraded status emits bg-degraded on segment slot', () {
      final classes = uptimeBarRecipe(
        variants: {kUptimeBarStatusAxis: 'degraded'},
      );
      expect(classes['segment'], contains('bg-degraded'));
    });

    test('paused status emits bg-paused on segment slot', () {
      final classes = uptimeBarRecipe(
        variants: {kUptimeBarStatusAxis: 'paused'},
      );
      expect(classes['segment'], contains('bg-paused'));
    });

    test('info status emits bg-info on segment slot', () {
      final classes = uptimeBarRecipe(variants: {kUptimeBarStatusAxis: 'info'});
      expect(classes['segment'], contains('bg-info'));
    });

    test('ai status emits bg-ai on segment slot', () {
      final classes = uptimeBarRecipe(variants: {kUptimeBarStatusAxis: 'ai'});
      expect(classes['segment'], contains('bg-ai'));
    });

    test('label slot emits tabular-nums', () {
      final classes = uptimeBarRecipe();
      expect(classes['label'], contains('tabular-nums'));
    });

    test(
      'segment slot has rounded-[2px] base class (matches React original)',
      () {
        for (final status in StatusKey.values) {
          final classes = uptimeBarRecipe(
            variants: {kUptimeBarStatusAxis: status.name},
          );
          expect(
            classes['segment'],
            contains('rounded-[2px]'),
            reason: '${status.name} segment missing rounded-[2px]',
          );
        }
      },
    );
  });

  group('uptimeBarSegmentClassName', () {
    test('returns bg-up for up status', () {
      expect(uptimeBarSegmentClassName('up'), contains('bg-up'));
    });

    test('returns bg-down for down status', () {
      expect(uptimeBarSegmentClassName('down'), contains('bg-down'));
    });

    test('returns bg-degraded for degraded status', () {
      expect(uptimeBarSegmentClassName('degraded'), contains('bg-degraded'));
    });

    test('returns bg-paused for paused status', () {
      expect(uptimeBarSegmentClassName('paused'), contains('bg-paused'));
    });

    test('returns bg-info for info status', () {
      expect(uptimeBarSegmentClassName('info'), contains('bg-info'));
    });

    test('returns bg-ai for ai status', () {
      expect(uptimeBarSegmentClassName('ai'), contains('bg-ai'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('UptimeBar renders N segments as WDiv children', (tester) async {
    const segmentCount = 10;
    final segments = uptime90().take(segmentCount).toList();
    await tester.pumpWidget(wrap(UptimeBar(segments: segments)));

    // Each segment renders as a WDiv; the track is also a WDiv.
    // Filter WDivs that carry a status bg-* className (the segment divs).
    final all = tester.widgetList<WDiv>(find.byType(WDiv));
    final segmentDivs = all.where(
      (w) => StatusKey.values.any(
        (s) => w.className?.contains('bg-${s.name}') ?? false,
      ),
    );
    expect(segmentDivs.length, equals(segmentCount));
  });

  testWidgets('UptimeBar applies bg-up to up segments', (tester) async {
    final segments = [
      const UptimeSegment(status: StatusKey.up, label: '1d ago'),
    ];
    await tester.pumpWidget(wrap(UptimeBar(segments: segments)));

    final all = tester.widgetList<WDiv>(find.byType(WDiv));
    final upDiv = all.firstWhere(
      (w) => w.className?.contains('bg-up') ?? false,
    );
    expect(upDiv.className, contains('bg-up'));
  });

  testWidgets('UptimeBar applies bg-down to down segments', (tester) async {
    final segments = [
      const UptimeSegment(status: StatusKey.down, label: '1d ago'),
    ];
    await tester.pumpWidget(wrap(UptimeBar(segments: segments)));

    final all = tester.widgetList<WDiv>(find.byType(WDiv));
    final downDiv = all.firstWhere(
      (w) => w.className?.contains('bg-down') ?? false,
    );
    expect(downDiv.className, contains('bg-down'));
  });

  testWidgets('UptimeBar applies bg-degraded to degraded segments', (
    tester,
  ) async {
    final segments = [
      const UptimeSegment(status: StatusKey.degraded, label: '1d ago'),
    ];
    await tester.pumpWidget(wrap(UptimeBar(segments: segments)));

    final all = tester.widgetList<WDiv>(find.byType(WDiv));
    final degradedDiv = all.firstWhere(
      (w) => w.className?.contains('bg-degraded') ?? false,
    );
    expect(degradedDiv.className, contains('bg-degraded'));
  });

  testWidgets('UptimeBar applies bg-paused to paused segments', (tester) async {
    final segments = [
      const UptimeSegment(status: StatusKey.paused, label: '1d ago'),
    ];
    await tester.pumpWidget(wrap(UptimeBar(segments: segments)));

    final all = tester.widgetList<WDiv>(find.byType(WDiv));
    final pausedDiv = all.firstWhere(
      (w) => w.className?.contains('bg-paused') ?? false,
    );
    expect(pausedDiv.className, contains('bg-paused'));
  });

  testWidgets('UptimeBar with uptimePercent renders WText label', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        UptimeBar(
          segments: uptime90().take(30).toList(),
          uptimePercent: '99.94%',
        ),
      ),
    );

    expect(find.byType(WText), findsWidgets);
    final texts = tester.widgetList<WText>(find.byType(WText));
    final label = texts.firstWhere((t) => t.data == '99.94%');
    expect(label.className, contains('tabular-nums'));
  });

  testWidgets('UptimeBar without uptimePercent renders no WText', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(UptimeBar(segments: uptime90().take(10).toList())),
    );
    expect(find.byType(WText), findsNothing);
  });

  testWidgets('UptimeBar renders 90 segments from uptime90()', (tester) async {
    final segments = uptime90(down: [0], degraded: [10, 20]);
    await tester.pumpWidget(wrap(UptimeBar(segments: segments)));

    final all = tester.widgetList<WDiv>(find.byType(WDiv));
    final statusDivs = all.where(
      (w) => StatusKey.values.any(
        (s) => w.className?.contains('bg-${s.name}') ?? false,
      ),
    );
    expect(statusDivs.length, equals(90));
  });

  testWidgets('UptimeBarPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const UptimeBarPreview()));
    await tester.pump();
    // At minimum, the three representative bars produce status-colored segments.
    final all = tester.widgetList<WDiv>(find.byType(WDiv));
    final statusDivs = all.where(
      (w) => StatusKey.values.any(
        (s) => w.className?.contains('bg-${s.name}') ?? false,
      ),
    );
    expect(statusDivs.length, greaterThan(0));
  });
}
