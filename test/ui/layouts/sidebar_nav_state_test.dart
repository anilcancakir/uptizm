import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/config/wind_theme.g.dart';

/// Pins the one Wind behaviour the sidebar's nav row now depends on: a
/// state-prefixed SEMANTIC ALIAS resolves to its colour.
///
/// The sidebar used to build two whole alternative className strings, justified
/// by a comment claiming the alias expander "only expands a WHOLE unprefixed
/// token, so a state-prefixed alias like `active:bg-surface-container` never
/// resolves to a color". `bottom_nav.dart` looked like a counter-example but is
/// not: its `active:text-primary` is not one of the semantic alias keys, so it
/// says nothing about the prefixed-alias case the comment names.
///
/// This is the assertion that settles it, and it is the reason the sidebar can
/// carry one className instead of two.
void main() {
  Widget wrap(Widget child) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(aliases: designAliases),
        child: Scaffold(body: child),
      ),
    );
  }

  /// The fill the row actually paints, or null when it paints none.
  ///
  /// Tolerates the absence of a [Container] entirely: with the state off, Wind
  /// has no decoration to build and emits no container at all, which is the
  /// same answer as "no fill" for what this test asks.
  Color? backgroundOf(WidgetTester tester) {
    final Iterable<Container> containers = tester.widgetList<Container>(
      find.descendant(
        of: find.byKey(const ValueKey('nav-row')),
        matching: find.byType(Container),
      ),
    );

    for (final Container container in containers) {
      final Decoration? decoration = container.decoration;
      if (decoration is BoxDecoration && decoration.color != null) {
        return decoration.color;
      }
    }

    return null;
  }

  testWidgets('an active: prefixed alias resolves to its fill', (tester) async {
    await tester.pumpWidget(
      wrap(
        WDiv(
          key: const ValueKey('nav-row'),
          states: const {'active'},
          className: 'px-3 py-2 active:bg-surface-container',
          child: const WText('Monitors'),
        ),
      ),
    );

    expect(
      backgroundOf(tester),
      isNotNull,
      reason:
          'a state-prefixed semantic alias must resolve, or the sidebar nav '
          'row renders its active state with no fill at all',
    );
  });

  testWidgets('the same token is inert while the state is off', (tester) async {
    await tester.pumpWidget(
      wrap(
        WDiv(
          key: const ValueKey('nav-row'),
          states: const {},
          className: 'px-3 py-2 active:bg-surface-container',
          child: const WText('Monitors'),
        ),
      ),
    );

    // The mirror half: without this the test above would pass on a token that
    // paints unconditionally, which is not the behaviour the sidebar wants.
    expect(backgroundOf(tester), isNull);
  });
}
