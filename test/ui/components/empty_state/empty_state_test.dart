import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/empty_state/empty_state.dart';
import 'package:uptizm/ui/components/empty_state/empty_state.recipe.dart';

void main() {
  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe assertions
  // ---------------------------------------------------------------------------

  group('emptyStateRecipe', () {
    test('root is a centered flex column', () {
      final cls = emptyStateRootClassName();
      expect(cls, contains('flex flex-col'));
      expect(cls, contains('items-center'));
      expect(cls, contains('justify-center'));
      expect(cls, contains('text-center'));
    });

    test('icon wrap is bare and muted (no circular background)', () {
      final cls = emptyStateIconWrapClassName();
      expect(cls, contains('text-fg-muted'));
      // The design renders the glyph bare; no filled circle.
      expect(cls, isNot(contains('rounded-full')));
      expect(cls, isNot(contains('bg-surface-container')));
    });

    test('title is the focal text-sm medium label', () {
      final cls = emptyStateTitleClassName();
      expect(cls, contains('text-sm'));
      expect(cls, contains('font-medium'));
      expect(cls, contains('text-fg'));
    });

    test('description is muted and capped at max-w-sm', () {
      final cls = emptyStateDescriptionClassName();
      expect(cls, contains('text-fg-muted'));
      expect(cls, contains('max-w-sm'));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('EmptyState renders the title', (tester) async {
    await tester.pumpWidget(wrap(const EmptyState(title: 'No monitors yet')));
    expect(find.text('No monitors yet'), findsOneWidget);
  });

  testWidgets('EmptyState renders the description when provided', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const EmptyState(title: 'No monitors yet', description: 'Add one.')),
    );
    expect(find.text('Add one.'), findsOneWidget);
  });

  testWidgets('EmptyState renders a bare icon (WIcon) when provided', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        const EmptyState(
          title: 'No monitors yet',
          icon: Icons.monitor_outlined,
        ),
      ),
    );
    expect(find.byType(WIcon), findsOneWidget);
  });

  testWidgets('EmptyState omits the icon when null', (tester) async {
    await tester.pumpWidget(wrap(const EmptyState(title: 'No monitors yet')));
    expect(find.byType(WIcon), findsNothing);
  });

  testWidgets('EmptyState renders the action when provided', (tester) async {
    await tester.pumpWidget(
      wrap(
        const EmptyState(
          title: 'No monitors yet',
          action: WText('Create your first monitor'),
        ),
      ),
    );
    expect(find.text('Create your first monitor'), findsOneWidget);
  });
}
