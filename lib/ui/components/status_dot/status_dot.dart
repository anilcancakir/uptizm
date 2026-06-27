import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/status.dart';
import 'status_dot.recipe.dart';

/// Dot size; maps to the `size` axis of [statusDotRecipe].
enum StatusDotSize {
  /// Compact: `size-2`.
  sm,

  /// Standard: `size-2.5`.
  md,

  /// Prominent: `size-3`.
  lg,
}

/// A small solid circle visualizing a monitoring [StatusKey].
///
/// Uses [statusDotRecipe] to emit the solid token color for each status;
/// no raw hex or `Colors.*` anywhere. The dot carries no label — it is the
/// compact companion to [StatusBadge] for tight rows such as monitor lists
/// and metric bands where a full badge is too heavy.
///
/// ### Example Usage:
///
/// ```dart
/// StatusDot(StatusKey.up)
/// StatusDot(StatusKey.down, size: StatusDotSize.lg)
/// StatusDot(StatusKey.degraded)
/// ```
@immutable
class StatusDot extends StatelessWidget {
  /// The monitoring status controlling the dot color.
  final StatusKey status;

  /// Dot size variant. Defaults to [StatusDotSize.md].
  final StatusDotSize size;

  /// Creates a [StatusDot] for the given [status].
  const StatusDot(this.status, {super.key, this.size = StatusDotSize.md});

  /// Resolves the className from the recipe for the current [status] and [size].
  String _resolveClassName() {
    return statusDotRecipe(
      variants: {
        kStatusDotStatusAxis: status.name,
        kStatusDotSizeAxis: size.name,
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(className: _resolveClassName());
  }
}
