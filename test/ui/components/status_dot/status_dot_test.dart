import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/ui/components/status_dot/index.dart';
import 'package:uptizm/ui/components/status_dot/status_dot.preview.dart';

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

  group('statusDotRecipe', () {
    test('up status emits bg-up token', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.up.name},
      );
      expect(cls, contains('bg-up'));
    });

    test('down status emits bg-down token', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.down.name},
      );
      expect(cls, contains('bg-down'));
    });

    test('degraded status emits bg-degraded token', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.degraded.name},
      );
      expect(cls, contains('bg-degraded'));
    });

    test('paused status emits bg-paused token', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.paused.name},
      );
      expect(cls, contains('bg-paused'));
    });

    test('info status emits bg-info token', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.info.name},
      );
      expect(cls, contains('bg-info'));
    });

    test('ai status emits bg-ai token', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.ai.name},
      );
      expect(cls, contains('bg-ai'));
    });

    test('default variant produces up status when nothing is passed', () {
      final cls = statusDotRecipe();
      expect(cls, contains('bg-up'));
    });

    test('base classes are present on every status', () {
      for (final status in StatusKey.values) {
        final cls = statusDotRecipe(
          variants: {kStatusDotStatusAxis: status.name},
        );
        expect(
          cls,
          contains('rounded-full'),
          reason: '${status.name} missing rounded-full',
        );
        expect(
          cls,
          contains('size-2'),
          reason: '${status.name} missing size-2',
        );
      }
    });

    test('emission order: base precedes variant classes', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.up.name},
      );
      final baseIdx = cls.indexOf('size-2');
      final variantIdx = cls.indexOf('bg-up');
      expect(baseIdx, lessThan(variantIdx));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('StatusDot renders without error for every status', (
    tester,
  ) async {
    for (final status in StatusKey.values) {
      await tester.pumpWidget(wrap(StatusDot(status)));
      expect(
        find.byType(WDiv),
        findsWidgets,
        reason: '${status.name} did not render',
      );
    }
  });

  testWidgets('StatusDot(up) applies bg-up className', (tester) async {
    await tester.pumpWidget(wrap(StatusDot(StatusKey.up)));
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-up') ?? false,
    );
    expect(dotWidget.className, contains('rounded-full'));
    expect(dotWidget.className, contains('bg-up'));
  });

  testWidgets('StatusDot(down) applies bg-down className', (tester) async {
    await tester.pumpWidget(wrap(StatusDot(StatusKey.down)));
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-down') ?? false,
    );
    expect(dotWidget.className, contains('bg-down'));
  });

  testWidgets('StatusDot(degraded) applies bg-degraded className', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusDot(StatusKey.degraded)));
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-degraded') ?? false,
    );
    expect(dotWidget.className, contains('bg-degraded'));
  });

  testWidgets('StatusDot(paused) applies bg-paused className', (tester) async {
    await tester.pumpWidget(wrap(StatusDot(StatusKey.paused)));
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-paused') ?? false,
    );
    expect(dotWidget.className, contains('bg-paused'));
  });

  testWidgets('StatusDot(info) applies bg-info className', (tester) async {
    await tester.pumpWidget(wrap(StatusDot(StatusKey.info)));
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-info') ?? false,
    );
    expect(dotWidget.className, contains('bg-info'));
  });

  testWidgets('StatusDot(ai) applies bg-ai className', (tester) async {
    await tester.pumpWidget(wrap(StatusDot(StatusKey.ai)));
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-ai') ?? false,
    );
    expect(dotWidget.className, contains('bg-ai'));
  });

  testWidgets('StatusDotPreview renders all 6 statuses', (tester) async {
    await tester.pumpWidget(wrap(const StatusDotPreview()));
    await tester.pump();
    final wdivs = tester.widgetList<WDiv>(find.byType(WDiv));
    // Each status renders a dot WDiv (plus row WDiv + column WDiv), so
    // there must be at least 6 WDiv nodes that carry a status class.
    final dotDivs = wdivs.where(
      (w) => StatusKey.values.any(
        (s) => w.className?.contains('bg-${s.name}') ?? false,
      ),
    );
    expect(dotDivs.length, greaterThanOrEqualTo(StatusKey.values.length));
  });
}
