import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/status.dart';
import 'status_badge.recipe.dart';

/// A soft pill badge visualizing a monitoring [StatusKey].
///
/// Uses [statusBadgeRecipe] to emit a soft background + matching foreground
/// className pair for each status; no raw hex or `Colors.*` anywhere. The
/// label is resolved from the `uptizm.status.<key>` i18n key so it tracks
/// locale changes automatically.
///
/// ```dart
/// StatusBadge(StatusKey.up)
/// StatusBadge(StatusKey.degraded)
/// StatusBadge(StatusKey.down)
/// ```
@immutable
class StatusBadge extends StatelessWidget {
  /// The monitoring status controlling background and text color.
  final StatusKey status;

  /// Creates a [StatusBadge] for the given [status].
  const StatusBadge(this.status, {super.key});

  /// Resolves the className from the recipe for the current [status].
  String _resolveClassName() {
    return statusBadgeRecipe(variants: {kStatusBadgeStatusAxis: status.name});
  }

  @override
  Widget build(BuildContext context) {
    return WBadge(
      trans('uptizm.status.${status.name}'),
      className: _resolveClassName(),
    );
  }
}
