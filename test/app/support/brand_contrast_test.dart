import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/app/support/brand_contrast.dart';

/// The one colour in this app that no token can answer for.
///
/// A status page's brand colour is chosen by the OPERATOR, so the foreground on
/// it has to be derived. These surfaces carried a hardcoded `text-white`, which
/// was both the Token-Only rule's blocker and a real contrast bug: white
/// initials on a light brand tile read as nothing at all.
void main() {
  /// The WCAG contrast ratio between two colours, computed here rather than
  /// asserted against a magic number, so each case states the readability it
  /// actually achieves.
  double ratio(Color a, Color b) {
    final double la = a.computeLuminance();
    final double lb = b.computeLuminance();
    final double lighter = la > lb ? la : lb;
    final double darker = la > lb ? lb : la;

    return (lighter + 0.05) / (darker + 0.05);
  }

  test('a dark brand takes white', () {
    const Color navy = Color(0xFF0B2545);

    expect(foregroundOn(navy), const Color(0xFFFFFFFF));
  });

  test('a light brand takes near-black, which white could not serve', () {
    // The case the hardcoded `text-white` got wrong: a yellow brand tile with
    // white initials on it.
    const Color yellow = Color(0xFFFFD400);

    expect(foregroundOn(yellow), const Color(0xFF07090C));
    expect(
      ratio(yellow, const Color(0xFFFFFFFF)),
      lessThan(4.5),
      reason: 'white on this brand fails WCAG AA, which is why it was a bug',
    );
  });

  test('every derived pairing clears WCAG AA for body text', () {
    // A spread across the hue circle plus the two extremes, so the threshold is
    // exercised on both sides rather than on one comfortable colour.
    const List<Color> brands = <Color>[
      Color(0xFF000000),
      Color(0xFFFFFFFF),
      Color(0xFF0B2545),
      Color(0xFFFFD400),
      Color(0xFF008560),
      Color(0xFFDF202E),
      Color(0xFF6E59E2),
      Color(0xFF7F7F7F),
    ];

    for (final Color brand in brands) {
      expect(
        ratio(brand, foregroundOn(brand)),
        greaterThanOrEqualTo(4.5),
        reason: 'the derived foreground on $brand must be readable',
      );
    }
  });

  test('the choice is the better of the two, at the threshold', () {
    // Mid-grey is where the decision is closest, so it is where a wrong
    // threshold shows. Whatever it picks must beat the alternative.
    const Color midGrey = Color(0xFF767676);
    final Color chosen = foregroundOn(midGrey);
    final Color other = chosen == const Color(0xFFFFFFFF)
        ? const Color(0xFF07090C)
        : const Color(0xFFFFFFFF);

    expect(ratio(midGrey, chosen), greaterThan(ratio(midGrey, other)));
  });
}
