import 'package:flutter_test/flutter_test.dart';
import 'package:magic_starter/magic_starter.dart';

/// Asserts that every [MSSkeleton] currently mounted actually occupies vertical
/// space.
///
/// The trap this guards is silent: [MSSkeleton] renders a `SizedBox` around a
/// childless `WDiv`, so a placeholder built without an explicit `height` has
/// nothing to measure and lays out 0px tall inside a flex column. The widget IS
/// in the tree (a `findsWidgets` assertion passes happily) while the operator
/// sees an empty screen, which is the very failure the first-load skeletons
/// exist to prevent.
///
/// Called from each screen's "shows a skeleton before the first read resolves"
/// test, right after the pending frame is pumped.
void expectVisibleSkeletons(WidgetTester tester) {
  final Finder bars = find.byType(MSSkeleton);
  final int count = bars.evaluate().length;

  for (int i = 0; i < count; i++) {
    expect(
      tester.getSize(bars.at(i)).height,
      greaterThan(0),
      reason:
          'skeleton #$i lays out 0px tall, so it is invisible: give it an '
          'explicit height (an MSSkeleton has no child to measure)',
    );
  }
}
