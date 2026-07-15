import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';
import 'package:uptizm/ui/components/status_badge/status_badge.preview.dart';

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
  // Recipe variant-class assertions (WindSlotRecipe returns Map<String, String>)
  // ---------------------------------------------------------------------------

  group('statusBadgeRecipe', () {
    test('up status emits bg-up-soft token on root slot', () {
      final classes = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.up.name},
      );
      expect(classes['root'], contains('bg-up-soft'));
      expect(classes['root'], contains('text-up-soft-foreground'));
      expect(classes['dot'], contains('bg-up'));
    });

    test('down status emits bg-down-soft token', () {
      final classes = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.down.name},
      );
      expect(classes['root'], contains('bg-down-soft'));
      expect(classes['root'], contains('text-down-soft-foreground'));
      expect(classes['dot'], contains('bg-down'));
    });

    test('degraded status emits bg-degraded-soft token', () {
      final classes = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.degraded.name},
      );
      expect(classes['root'], contains('bg-degraded-soft'));
      expect(classes['root'], contains('text-degraded-soft-foreground'));
    });

    test('paused status emits bg-paused-soft token', () {
      final classes = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.paused.name},
      );
      expect(classes['root'], contains('bg-paused-soft'));
      expect(classes['root'], contains('text-paused-soft-foreground'));
    });

    test('info status emits bg-info-soft token', () {
      final classes = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.info.name},
      );
      expect(classes['root'], contains('bg-info-soft'));
      expect(classes['root'], contains('text-info-soft-foreground'));
    });

    test('ai status emits bg-ai-soft token', () {
      final classes = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.ai.name},
      );
      expect(classes['root'], contains('bg-ai-soft'));
      expect(classes['root'], contains('text-ai-soft-foreground'));
    });

    test('default variant produces up status when nothing is passed', () {
      final classes = statusBadgeRecipe();
      expect(classes['root'], contains('bg-up-soft'));
      expect(classes['dot'], contains('bg-up'));
    });

    test('base classes are present on every status', () {
      for (final status in StatusKey.values) {
        final classes = statusBadgeRecipe(
          variants: {kStatusBadgeStatusAxis: status.name},
        );
        expect(
          classes['root'],
          contains('rounded-full'),
          reason: '${status.name} root missing rounded-full',
        );
        expect(
          classes['root'],
          contains('flex'),
          reason: '${status.name} root missing flex',
        );
        expect(
          classes['dot'],
          contains('rounded-full'),
          reason: '${status.name} dot missing rounded-full',
        );
      }
    });

    test('sm size emits correct geometry', () {
      final classes = statusBadgeRecipe(
        variants: {
          kStatusBadgeStatusAxis: StatusKey.up.name,
          kStatusBadgeSizeAxis: 'sm',
        },
      );
      expect(classes['root'], contains('px-2'));
      expect(classes['root'], contains('py-0.5'));
      expect(classes['root'], contains('text-xs'));
      expect(classes['dot'], contains('size-1.5'));
    });

    test('md size emits correct geometry', () {
      final classes = statusBadgeRecipe(
        variants: {
          kStatusBadgeStatusAxis: StatusKey.up.name,
          kStatusBadgeSizeAxis: 'md',
        },
      );
      expect(classes['root'], contains('px-2.5'));
      expect(classes['root'], contains('py-1'));
      expect(classes['root'], contains('text-sm'));
      expect(classes['dot'], contains('size-2'));
    });

    test('emission order: base precedes size precedes status variant', () {
      final classes = statusBadgeRecipe(
        variants: {
          kStatusBadgeStatusAxis: StatusKey.up.name,
          kStatusBadgeSizeAxis: 'sm',
        },
      );
      final root = classes['root']!;
      final flexIdx = root.indexOf('flex');
      final pxIdx = root.indexOf('px-2');
      final bgIdx = root.indexOf('bg-up-soft');
      expect(flexIdx, lessThan(pxIdx), reason: 'base before size');
      expect(pxIdx, lessThan(bgIdx), reason: 'size before status');
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('StatusBadge renders without error for every status', (
    tester,
  ) async {
    for (final status in StatusKey.values) {
      await tester.pumpWidget(wrap(StatusBadge(status)));
      expect(
        find.byType(WDiv),
        findsWidgets,
        reason: '${status.name} did not render',
      );
    }
  });

  testWidgets('StatusBadge(up) root WDiv carries bg-up-soft className', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.up)));
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    final root = divs.firstWhere(
      (w) => w.className?.contains('bg-up-soft') ?? false,
    );
    expect(root.className, contains('bg-up-soft'));
    expect(root.className, contains('text-up-soft-foreground'));
  });

  testWidgets('StatusBadge(down) root WDiv carries bg-down-soft className', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.down)));
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    final root = divs.firstWhere(
      (w) => w.className?.contains('bg-down-soft') ?? false,
    );
    expect(root.className, contains('bg-down-soft'));
    expect(root.className, contains('text-down-soft-foreground'));
  });

  testWidgets('StatusBadge(degraded) root WDiv carries bg-degraded-soft', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.degraded)));
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    final root = divs.firstWhere(
      (w) => w.className?.contains('bg-degraded-soft') ?? false,
    );
    expect(root.className, contains('bg-degraded-soft'));
  });

  testWidgets('StatusBadge(paused) root WDiv carries bg-paused-soft', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.paused)));
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    final root = divs.firstWhere(
      (w) => w.className?.contains('bg-paused-soft') ?? false,
    );
    expect(root.className, contains('bg-paused-soft'));
  });

  testWidgets('StatusBadge(info) root WDiv carries bg-info-soft', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.info)));
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    final root = divs.firstWhere(
      (w) => w.className?.contains('bg-info-soft') ?? false,
    );
    expect(root.className, contains('bg-info-soft'));
  });

  testWidgets('StatusBadge(ai) root WDiv carries bg-ai-soft', (tester) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.ai)));
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    final root = divs.firstWhere(
      (w) => w.className?.contains('bg-ai-soft') ?? false,
    );
    expect(root.className, contains('bg-ai-soft'));
  });

  testWidgets(
    'StatusBadge showDot=true renders a childless dot WDiv sized from its recipe',
    (tester) async {
      await tester.pumpWidget(wrap(StatusBadge(StatusKey.up)));
      final divs = tester.widgetList<WDiv>(find.byType(WDiv));
      // The dot is a childless WDiv (no SizedBox wrapper): it carries
      // rounded-full, bg-up, and the recipe's size-* token (sm default:
      // size-1.5) directly, per status_badge.dart's dotClass usage.
      final dot = divs.firstWhere(
        (w) =>
            (w.className?.contains('bg-up') ?? false) &&
            (w.className?.contains('rounded-full') ?? false) &&
            (w.className?.contains('size-1.5') ?? false),
      );
      expect(dot.child, isNull);
      expect(dot.children, isNull);
    },
  );

  testWidgets('StatusBadge showDot=false omits the dot WDiv', (tester) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.up, showDot: false)));
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    // No WDiv should carry the solid bg-up dot token (root uses bg-up-soft,
    // not bg-up, so this is unambiguous).
    final dots = divs.where(
      (w) =>
          (w.className?.contains('bg-up') ?? false) &&
          !(w.className?.contains('bg-up-soft') ?? false),
    );
    expect(dots.length, 0);
  });

  testWidgets('StatusBadge md size emits px-2.5 on root', (tester) async {
    await tester.pumpWidget(
      wrap(StatusBadge(StatusKey.up, size: StatusBadgeSize.md)),
    );
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    final root = divs.firstWhere(
      (w) => w.className?.contains('bg-up-soft') ?? false,
    );
    expect(root.className, contains('px-2.5'));
  });

  testWidgets('StatusBadgePreview renders all 6 statuses', (tester) async {
    await tester.pumpWidget(wrap(const StatusBadgePreview()));
    await tester.pump();
    final divs = tester.widgetList<WDiv>(find.byType(WDiv));
    // Each status must produce at least one WDiv carrying its soft bg token.
    for (final status in StatusKey.values) {
      final matching = divs.where(
        (w) => w.className?.contains('bg-${status.name}-soft') ?? false,
      );
      expect(
        matching.length,
        greaterThanOrEqualTo(1),
        reason: '${status.name} not found in preview',
      );
    }
  });
}
