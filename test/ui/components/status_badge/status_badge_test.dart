import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/status.dart';
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
  // Recipe variant-class assertions
  // ---------------------------------------------------------------------------

  group('statusBadgeRecipe', () {
    test('up status emits bg-up-soft token', () {
      final cls = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.up.name},
      );
      expect(cls, contains('bg-up-soft'));
      expect(cls, contains('text-up-soft-foreground'));
    });

    test('down status emits bg-down-soft token', () {
      final cls = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.down.name},
      );
      expect(cls, contains('bg-down-soft'));
      expect(cls, contains('text-down-soft-foreground'));
    });

    test('degraded status emits bg-degraded-soft token', () {
      final cls = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.degraded.name},
      );
      expect(cls, contains('bg-degraded-soft'));
      expect(cls, contains('text-degraded-soft-foreground'));
    });

    test('paused status emits bg-paused-soft token', () {
      final cls = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.paused.name},
      );
      expect(cls, contains('bg-paused-soft'));
      expect(cls, contains('text-paused-soft-foreground'));
    });

    test('info status emits bg-info-soft token', () {
      final cls = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.info.name},
      );
      expect(cls, contains('bg-info-soft'));
      expect(cls, contains('text-info-soft-foreground'));
    });

    test('ai status emits bg-ai-soft token', () {
      final cls = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.ai.name},
      );
      expect(cls, contains('bg-ai-soft'));
      expect(cls, contains('text-ai-soft-foreground'));
    });

    test('default variant produces up status when nothing is passed', () {
      final cls = statusBadgeRecipe();
      expect(cls, contains('bg-up-soft'));
    });

    test('base classes are present on every status', () {
      for (final status in StatusKey.values) {
        final cls = statusBadgeRecipe(
          variants: {kStatusBadgeStatusAxis: status.name},
        );
        expect(
          cls,
          contains('rounded-full'),
          reason: '${status.name} missing rounded-full',
        );
        expect(
          cls,
          contains('inline-flex'),
          reason: '${status.name} missing inline-flex',
        );
      }
    });

    test('emission order: base precedes variant classes', () {
      final cls = statusBadgeRecipe(
        variants: {kStatusBadgeStatusAxis: StatusKey.up.name},
      );
      final baseIdx = cls.indexOf('inline-flex');
      final variantIdx = cls.indexOf('bg-up-soft');
      expect(baseIdx, lessThan(variantIdx));
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
        find.byType(WBadge),
        findsOneWidget,
        reason: '${status.name} did not render a WBadge',
      );
    }
  });

  testWidgets('StatusBadge(up) applies bg-up-soft className', (tester) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.up)));
    final badge = tester.widget<WBadge>(find.byType(WBadge));
    expect(badge.className, contains('bg-up-soft'));
    expect(badge.className, contains('text-up-soft-foreground'));
  });

  testWidgets('StatusBadge(down) applies bg-down-soft className', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.down)));
    final badge = tester.widget<WBadge>(find.byType(WBadge));
    expect(badge.className, contains('bg-down-soft'));
    expect(badge.className, contains('text-down-soft-foreground'));
  });

  testWidgets('StatusBadge(degraded) applies bg-degraded-soft className', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.degraded)));
    final badge = tester.widget<WBadge>(find.byType(WBadge));
    expect(badge.className, contains('bg-degraded-soft'));
  });

  testWidgets('StatusBadge(paused) applies bg-paused-soft className', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.paused)));
    final badge = tester.widget<WBadge>(find.byType(WBadge));
    expect(badge.className, contains('bg-paused-soft'));
  });

  testWidgets('StatusBadge(info) applies bg-info-soft className', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.info)));
    final badge = tester.widget<WBadge>(find.byType(WBadge));
    expect(badge.className, contains('bg-info-soft'));
  });

  testWidgets('StatusBadge(ai) applies bg-ai-soft className', (tester) async {
    await tester.pumpWidget(wrap(StatusBadge(StatusKey.ai)));
    final badge = tester.widget<WBadge>(find.byType(WBadge));
    expect(badge.className, contains('bg-ai-soft'));
  });

  testWidgets('StatusBadgePreview renders all 6 statuses', (tester) async {
    await tester.pumpWidget(wrap(const StatusBadgePreview()));
    await tester.pump();
    final badges = tester.widgetList<WBadge>(find.byType(WBadge));
    expect(badges.length, greaterThanOrEqualTo(StatusKey.values.length));
  });
}
