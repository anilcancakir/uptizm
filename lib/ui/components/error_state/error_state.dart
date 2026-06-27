import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'error_state.recipe.dart';

/// **Error-State Placeholder**
///
/// The failure sibling of `EmptyState`: a centered placeholder shown when a
/// list or detail page fails to load. Ported from the design lab `ErrorState`:
/// a centered stack of a BARE `down`-toned (red) alert-triangle glyph (no
/// circular background), a title in the neutral foreground, an optional
/// description, and a retry action (usually a secondary [Button]).
///
/// API-compatible with the `magic_starter` `ErrorState` (`title` / `icon`
/// (IconData) / `description` / `action`), but [title] and [description] are
/// optional here and fall back to a generic, honest message (matching the
/// React defaults); only the visual treatment matches the uptizm design (bare
/// red triangle, `text-fg` title, secondary action) rather than the starter's
/// filled-circle glyph and red title.
///
/// ### Example Usage:
///
/// ```dart
/// ErrorState(
///   title: "Couldn't load monitors",
///   description: 'We hit a snag reaching the monitoring data.',
///   action: Button(
///     intent: ButtonIntent.secondary,
///     onPressed: retry,
///     child: const WText('Try again'),
///   ),
/// )
/// ```
@immutable
class ErrorState extends StatelessWidget {
  /// Optional glyph rendered bare (red, no background) above the title.
  /// Defaults to an alert triangle.
  final IconData? icon;

  /// The primary message. Defaults to a generic failure title.
  final String? title;

  /// Secondary description. Defaults to a generic connection-check message.
  final String? description;

  /// Optional action widget (e.g. a secondary [Button] retry control).
  final Widget? action;

  /// Creates an [ErrorState].
  const ErrorState({
    super.key,
    this.icon,
    this.title,
    this.description,
    this.action,
  });

  /// Default alert glyph (extracted as a const for Flutter web tree-shaking).
  static const IconData _defaultIcon = Icons.warning_amber_rounded;

  /// Default title when none is supplied.
  static const String _defaultTitle = "Couldn't load this";

  /// Default description when none is supplied.
  static const String _defaultDescription =
      'Something went wrong on our end. Check your connection and try again.';

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: errorStateRootClassName(),
      children: [
        // 1. Bare red alert glyph (no circular wrap).
        WDiv(
          className: errorStateIconWrapClassName(),
          child: WIcon(
            icon ?? _defaultIcon,
            className: errorStateIconClassName(),
          ),
        ),
        // 2. Title — the focal message, neutral foreground.
        WText(title ?? _defaultTitle, className: errorStateTitleClassName()),
        // 3. Description.
        WText(
          description ?? _defaultDescription,
          className: errorStateDescriptionClassName(),
        ),
        // 4. Optional retry action, nudged down with mt-2.
        if (action != null)
          WDiv(className: errorStateActionClassName(), child: action!),
      ],
    );
  }
}
