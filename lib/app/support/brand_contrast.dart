import 'package:flutter/widgets.dart';

/// The readable foreground for content sitting on a colour the CUSTOMER chose.
///
/// Every other colour in this app answers to a semantic token, because every
/// other colour is ours. A status page's brand colour is not: the operator picks
/// it, it reaches the runtime as a `Color` rather than as a className, and no
/// token can be right for all of it. White on a navy brand reads; white on a
/// yellow one does not.
///
/// These surfaces carried a hardcoded `text-white`, which is the Token-Only
/// rule's blocker AND a real contrast bug: a light brand colour rendered white
/// initials on a near-white tile. Deriving is the only answer that is correct
/// for a value we do not control.
///
/// The two candidates are compared by their actual WCAG contrast ratio rather
/// than against the usual 0.179 luminance threshold. That constant is the
/// crossover for PURE black and pure white, and the dark candidate here is
/// `#07090C`, the app's own near-black. The difference is small and it is not
/// nothing: at a mid-grey brand the threshold picks near-black at 4.39:1 where
/// white would have given 4.54:1, so the shortcut chooses the worse of the two
/// in exactly the band where the choice matters most. Comparing the ratios is
/// also self-correcting if either candidate is ever retuned.
Color foregroundOn(Color background) {
  const Color light = Color(0xFFFFFFFF);
  const Color dark = Color(0xFF07090C);

  return _contrast(background, light) >= _contrast(background, dark)
      ? light
      : dark;
}

/// The WCAG 2.x contrast ratio between two colours, from 1 (identical) to 21.
///
/// Flutter's [Color.computeLuminance] already returns WCAG relative luminance,
/// so this is only the ratio formula around it.
double _contrast(Color a, Color b) {
  final double first = a.computeLuminance();
  final double second = b.computeLuminance();
  final double lighter = first > second ? first : second;
  final double darker = first > second ? second : first;

  return (lighter + 0.05) / (darker + 0.05);
}
