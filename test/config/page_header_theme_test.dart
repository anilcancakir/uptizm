import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/config/page_header_theme.dart';
import 'package:uptizm/config/uptizm_status_tokens.dart';
import 'package:uptizm/config/wind_theme.g.dart';

/// Measures the responsive half of [uptizmPageHeaderTheme].
///
/// Every value in that theme changes at `lg`, and a `lg:` prefix that resolves
/// to nothing is invisible on the surface it was written for: a phone looks
/// right while the desktop silently loses its divider. These tests pin the
/// breakpoint from both sides so the mobile tightening cannot quietly become a
/// desktop regression.
void main() {
  setUpAll(() {
    MagicStarter.usePageHeaderTheme(uptizmPageHeaderTheme);
  });

  /// Renders [MSPageHeader] at [width] inside uptizm's own Wind theme.
  Future<void> pumpHeaderAt(WidgetTester tester, double width) {
    return tester.pumpWidget(
      MaterialApp(
        home: MediaQuery(
          data: MediaQueryData(size: Size(width, 900)),
          child: WindTheme(
            data: WindThemeData(
              colors: designColors,
              aliases: {...designAliases, ...uptizmStatusAliases},
            ),
            child: const Scaffold(
              body: MSPageHeader(
                title: 'iOS Sweep Monitor',
                subtitle: 'https://example.com',
                inlineActions: true,
              ),
            ),
          ),
        ),
      ),
    );
  }

  /// The bottom border widths every decoration in the header carries.
  Iterable<double> bottomBorderWidths(WidgetTester tester) {
    return tester
        .widgetList<DecoratedBox>(find.byType(DecoratedBox))
        .map((box) => box.decoration)
        .whereType<BoxDecoration>()
        .map((decoration) => decoration.border?.bottom.width ?? 0)
        .where((width) => width > 0);
  }

  testWidgets('a desktop width keeps the divider under the header', (
    tester,
  ) async {
    await pumpHeaderAt(tester, 1280);

    expect(
      bottomBorderWidths(tester),
      isNotEmpty,
      reason: 'the header separates itself from the content above `lg`, and '
          'a `lg:border-b` that never resolved would look identical to a '
          'deliberate removal',
    );
  });

  testWidgets('a phone drops the divider under the header', (tester) async {
    await pumpHeaderAt(tester, 402);

    expect(
      bottomBorderWidths(tester),
      isEmpty,
      reason: 'the cards below the header already imply the separation, and '
          'the rule cost a phone a full-width line it did not need',
    );
  });

  testWidgets('the title steps down a size on a phone', (tester) async {
    await pumpHeaderAt(tester, 402);
    final double mobile = tester
        .renderObject<RenderBox>(find.text('iOS Sweep Monitor'))
        .size
        .height;

    await pumpHeaderAt(tester, 1280);
    final double desktop = tester
        .renderObject<RenderBox>(find.text('iOS Sweep Monitor'))
        .size
        .height;

    expect(
      mobile,
      lessThan(desktop),
      reason: 'mobile runs at the DESIGN.md `title-lg` step and desktop at the '
          'display step, so the line box cannot be the same height',
    );
  });
}
