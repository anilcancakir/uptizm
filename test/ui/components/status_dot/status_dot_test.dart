import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/status_key.dart';
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
          contains('shrink-0'),
          reason: '${status.name} missing shrink-0',
        );
      }
    });

    test('sm size emits size-2', () {
      final cls = statusDotRecipe(
        variants: {
          kStatusDotStatusAxis: StatusKey.up.name,
          kStatusDotSizeAxis: 'sm',
        },
      );
      expect(cls, contains('size-2'));
    });

    test('md size emits size-2.5 (default)', () {
      final cls = statusDotRecipe(
        variants: {kStatusDotStatusAxis: StatusKey.up.name},
      );
      expect(cls, contains('size-2.5'));
    });

    test('lg size emits size-3', () {
      final cls = statusDotRecipe(
        variants: {
          kStatusDotStatusAxis: StatusKey.up.name,
          kStatusDotSizeAxis: 'lg',
        },
      );
      expect(cls, contains('size-3'));
    });

    test('emission order: base precedes size precedes status variant', () {
      final cls = statusDotRecipe(
        variants: {
          kStatusDotStatusAxis: StatusKey.up.name,
          kStatusDotSizeAxis: 'sm',
        },
      );
      final shrinkIdx = cls.indexOf('shrink-0');
      final sizeIdx = cls.indexOf('size-2');
      final bgIdx = cls.indexOf('bg-up');
      expect(shrinkIdx, lessThan(sizeIdx), reason: 'base before size');
      expect(sizeIdx, lessThan(bgIdx), reason: 'size before status');
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

  testWidgets('StatusDot default size is md (size-2.5)', (tester) async {
    await tester.pumpWidget(wrap(StatusDot(StatusKey.up)));
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-up') ?? false,
    );
    expect(dotWidget.className, contains('size-2.5'));
  });

  testWidgets('StatusDot lg size emits size-3', (tester) async {
    await tester.pumpWidget(
      wrap(StatusDot(StatusKey.up, size: StatusDotSize.lg)),
    );
    final dots = tester.widgetList<WDiv>(find.byType(WDiv));
    final dotWidget = dots.firstWhere(
      (w) => w.className?.contains('bg-up') ?? false,
    );
    expect(dotWidget.className, contains('size-3'));
  });

  testWidgets('StatusDotPreview renders all 6 statuses', (tester) async {
    await tester.pumpWidget(wrap(const StatusDotPreview()));
    await tester.pump();
    final wdivs = tester.widgetList<WDiv>(find.byType(WDiv));
    // Each status must produce at least one WDiv carrying its solid bg token.
    for (final status in StatusKey.values) {
      final matching = wdivs.where(
        (w) => w.className?.contains('bg-${status.name}') ?? false,
      );
      expect(
        matching.length,
        greaterThanOrEqualTo(1),
        reason: '${status.name} not found in preview',
      );
    }
  });
}
