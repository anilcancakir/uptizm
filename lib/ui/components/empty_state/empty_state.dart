import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'empty_state.recipe.dart';

/// **Empty-State Placeholder**
///
/// A centered placeholder for empty lists (no monitors, no incidents, no
/// notifications). Ported from the design lab `EmptyState`: a centered stack of
/// an optional BARE muted icon (no circular background), a title, an optional
/// description, and an optional action (usually a primary [Button]).
///
/// API-compatible with the `magic_starter` `EmptyState` (same `title` / `icon`
/// (IconData) / `description` / `action` parameters) so it is a drop-in
/// replacement; only the visual treatment matches the uptizm design (bare icon,
/// `text-sm` title, `max-w-sm` description) rather than the starter's filled
/// circle glyph.
///
/// ### Example Usage:
///
/// ```dart
/// EmptyState(
///   icon: Icons.desktop_windows_outlined,
///   title: 'No monitors yet',
///   description: 'Create your first monitor to start tracking uptime.',
///   action: Button(
///     intent: ButtonIntent.primary,
///     onPressed: createMonitor,
///     child: const WText('Create your first monitor'),
///   ),
/// )
/// ```
@immutable
class EmptyState extends StatelessWidget {
  /// Optional glyph rendered bare (muted, no background) above the title.
  final IconData? icon;

  /// The primary message.
  final String title;

  /// Optional secondary description.
  final String? description;

  /// Optional action widget (e.g. a primary [Button]).
  final Widget? action;

  /// Creates an [EmptyState].
  const EmptyState({
    super.key,
    required this.title,
    this.icon,
    this.description,
    this.action,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: emptyStateRootClassName(),
      children: [
        // 1. Optional bare icon (no circular wrap).
        if (icon != null)
          WDiv(
            className: emptyStateIconWrapClassName(),
            child: WIcon(icon!, className: emptyStateIconClassName()),
          ),
        // 2. Title — the focal message.
        WText(title, className: emptyStateTitleClassName()),
        // 3. Optional description.
        if (description != null)
          WText(description!, className: emptyStateDescriptionClassName()),
        // 4. Optional action, nudged down with mt-2.
        if (action != null)
          WDiv(className: emptyStateActionClassName(), child: action!),
      ],
    );
  }
}
