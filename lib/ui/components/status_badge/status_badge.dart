import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/status.dart';
import 'status_badge.recipe.dart';

/// Badge size; maps to the `size` axis of [statusBadgeRecipe].
enum StatusBadgeSize {
  /// Compact: `gap-1.5 px-2 py-0.5 text-xs`, dot `size-1.5`.
  sm,

  /// Default: `gap-2 px-2.5 py-1 text-sm`, dot `size-2`.
  md,
}

/// A soft pill badge visualizing a monitoring [StatusKey].
///
/// Renders as a [flex flex-row] pill with an optional leading solid dot and a
/// status label sourced from `uptizm.status.<key>` i18n key. Uses
/// [statusBadgeRecipe] (a [WindSlotRecipe]) to emit the correct soft background,
/// text color, geometry, and dot color for each status + size combination.
///
/// ### Example Usage:
///
/// ```dart
/// // Default: size sm, dot visible, i18n label
/// StatusBadge(StatusKey.up)
///
/// // Larger pill
/// StatusBadge(StatusKey.degraded, size: StatusBadgeSize.md)
///
/// // Hide the leading dot
/// StatusBadge(StatusKey.down, showDot: false)
///
/// // Custom label
/// StatusBadge(StatusKey.ai, label: 'Anomaly detected')
/// ```
@immutable
class StatusBadge extends StatelessWidget {
  /// The monitoring status controlling background, text color, and dot color.
  final StatusKey status;

  /// Pill size variant. Defaults to [StatusBadgeSize.sm].
  final StatusBadgeSize size;

  /// Whether to show the leading solid dot. Defaults to `true`.
  final bool showDot;

  /// Optional override label. When `null`, falls back to the i18n key
  /// `uptizm.status.<status.name>`.
  final String? label;

  /// Creates a [StatusBadge] for the given [status].
  const StatusBadge(
    this.status, {
    super.key,
    this.size = StatusBadgeSize.sm,
    this.showDot = true,
    this.label,
  });

  /// Resolves the slot classNames from the recipe.
  Map<String, String> _resolveClasses() {
    return statusBadgeRecipe(
      variants: {
        kStatusBadgeStatusAxis: status.name,
        kStatusBadgeSizeAxis: size.name,
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve slot classNames once.
    final classes = _resolveClasses();
    final rootClass = classes['root'] ?? '';
    final dotClass = classes['dot'] ?? '';

    // 2. Resolve the display label (i18n or override).
    final displayLabel = label ?? trans('uptizm.status.${status.name}');

    // 3. Build: pill row optionally containing a leading dot + text.
    //    NOTE: `flex flex-row` (NOT `inline-flex`) — Wind renders inline-flex
    //    as a centered vertical column; flex flex-row is correct for a row.
    //    The dot is a childless WDiv that renders from its recipe `size-*`
    //    token (size-1.5 for sm, size-2 for md).
    return WDiv(
      className: rootClass,
      children: [
        if (showDot) WDiv(className: dotClass),
        WText(displayLabel),
      ],
    );
  }
}
