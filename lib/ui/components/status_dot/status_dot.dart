import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/status.dart';
import 'status_dot.recipe.dart';

/// A small solid circle visualizing a monitoring [StatusKey].
///
/// Uses [statusDotRecipe] to emit the solid token color for each status;
/// no raw hex or `Colors.*` anywhere. The dot carries no label — it is the
/// compact companion to [StatusBadge] for tight rows such as monitor lists
/// and metric bands where a full badge is too heavy.
///
/// ```dart
/// StatusDot(StatusKey.up)
/// StatusDot(StatusKey.down)
/// StatusDot(StatusKey.degraded)
/// ```
@immutable
class StatusDot extends StatelessWidget {
  /// The monitoring status controlling the dot color.
  final StatusKey status;

  /// Creates a [StatusDot] for the given [status].
  const StatusDot(this.status, {super.key});

  /// Resolves the className from the recipe for the current [status].
  String _resolveClassName() {
    return statusDotRecipe(variants: {kStatusDotStatusAxis: status.name});
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(className: _resolveClassName());
  }
}
