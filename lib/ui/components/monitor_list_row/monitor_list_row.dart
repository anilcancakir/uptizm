import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/models/monitor.dart';
import '../status_badge/index.dart';
import 'monitor_list_row.recipe.dart';

/// **One row in the monitors list.**
///
/// Renders a tappable card-style row showing a monitor's name, URL, latency in
/// tabular-nums, and a [StatusBadge]. Compact by design (matching the design
/// lab): the 90-day uptime bar and the region breakdown live on the monitor
/// detail screen, not in the list rows.
///
/// Navigation is intentionally decoupled: pass an [onTap] callback; the page
/// is responsible for wiring it to `MagicRoute.to('/monitors/<id>')`.
///
/// ### Example:
/// ```dart
/// MonitorListRow(
///   monitor: monitors.first,
///   onTap: () => MagicRoute.to('/monitors/${monitors.first.id}'),
/// )
/// ```
@immutable
class MonitorListRow extends StatelessWidget {
  /// The monitor data to display.
  final Monitor monitor;

  /// Tapped when the whole row is pressed.
  ///
  /// The caller wires this to the monitor-detail route; the row itself does
  /// not perform navigation.
  final VoidCallback? onTap;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates a [MonitorListRow] for the given [monitor].
  const MonitorListRow({
    super.key,
    required this.monitor,
    this.onTap,
    this.className,
  });

  @override
  Widget build(BuildContext context) {
    // 1. Resolve slot classNames.
    final slots = monitorListRowSlots(className: className);

    // 2. The row: name/URL column, latency metric, status badge. Wrapped in a
    //    WAnchor for the 44px hit target; the card surface + padding come from
    //    the root slot.
    return WAnchor(
      onTap: onTap,
      child: WDiv(
        className: slots['root'],
        children: [
          // Left: name + URL stacked.
          WDiv(
            className: slots['main'],
            children: [
              WText(monitor.name ?? '', className: slots['name']),
              WText(monitor.url ?? '', className: slots['url']),
            ],
          ),

          // Center-right: response-time metric (right-aligned, fixed width).
          WText(
            monitor.responseMs != null ? '${monitor.responseMs}ms' : '—',
            className: slots['metric'],
          ),

          // Trailing: status badge (rightmost, per the React original).
          StatusBadge(monitor.status),
        ],
      ),
    );
  }
}
