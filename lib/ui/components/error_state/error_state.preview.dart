import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
// Hide magic_starter's ErrorState so the local uptizm ErrorState wins; only the
// Button is needed from the starter here.
import 'package:magic_starter/magic_starter.dart' hide ErrorState;

import 'error_state.dart';

/// Static preview for [ErrorState].
///
/// Mirrors the design lab `ErrorState.preview.tsx`: a failed monitors load with
/// the bare red alert glyph, copy, and a secondary retry action, wrapped in a
/// bordered surface card.
class ErrorStatePreview extends StatelessWidget {
  /// Creates the error-state preview.
  const ErrorStatePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'p-6',
      child: WDiv(
        className: 'rounded-lg border border-color-border bg-surface',
        child: ErrorState(
          title: "Couldn't load monitors",
          description:
              'We hit a snag reaching the monitoring data. Check your '
              'connection and try again.',
          action: Button(
            intent: ButtonIntent.secondary,
            onPressed: () {},
            child: const WText('Try again'),
          ),
        ),
      ),
    );
  }
}
