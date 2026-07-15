import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import '../../../app/enums/status_key.dart';
import '../status_badge/index.dart';
import '../uptime_bar/index.dart';
import 'component_status_row.recipe.dart';

/// **One component on a public status page.**
///
/// Shows the component [name] with its current [status] badge and, when
/// [segments] are provided, the 90-day [UptimeBar] with an uptime percentage
/// and a "90 days ago / Today" date range underneath. This is the customer-
/// facing, minimal counterpart to the internal/dense [MonitorListRow].
///
/// Ported 1:1 from the design lab `ComponentStatusRow`.
///
/// ### Example:
/// ```dart
/// ComponentStatusRow(
///   name: 'Website',
///   status: StatusKey.up,
///   segments: uptime90(),
///   uptimeLabel: '100.0% uptime',
/// )
/// ```
@immutable
class ComponentStatusRow extends StatelessWidget {
  /// Component name shown on the left.
  final String name;

  /// Current monitoring status, rendered as a trailing [StatusBadge].
  final StatusKey status;

  /// Optional 90-day history. When null, the bar + footer are omitted and the
  /// row shows only the name + status badge.
  final List<UptimeSegment>? segments;

  /// Optional uptime readout shown centered in the footer, e.g. "99.98% uptime".
  final String? uptimeLabel;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates a [ComponentStatusRow].
  const ComponentStatusRow({
    super.key,
    required this.name,
    required this.status,
    this.segments,
    this.uptimeLabel,
    this.className,
  });

  @override
  Widget build(BuildContext context) {
    final slots = componentStatusRowRecipe(variants: const <String, String>{});
    final rootClass = className == null
        ? slots['root']
        : '${slots['root']} $className';
    final history = segments;

    return WDiv(
      className: rootClass,
      children: [
        // Head: name (left) + status badge (right).
        WDiv(
          className: slots['head'],
          children: [
            WText(name, className: slots['name']),
            StatusBadge(status, size: StatusBadgeSize.sm),
          ],
        ),

        // Bar + footer only when history is supplied.
        if (history != null) ...[
          WDiv(
            className: slots['bar'],
            child: UptimeBar(segments: history, size: UptimeBarSize.sm),
          ),
          WDiv(
            className: slots['footer'],
            children: [
              WText('90 days ago', className: slots['footerText']),
              if (uptimeLabel != null)
                WText(uptimeLabel!, className: slots['footerText']),
              WText('Today', className: slots['footerText']),
            ],
          ),
        ],
      ],
    );
  }
}
