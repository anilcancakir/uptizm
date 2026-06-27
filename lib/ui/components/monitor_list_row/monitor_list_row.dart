import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import '../status_badge/index.dart';
import '../uptime_bar/index.dart';
import 'monitor_list_row.recipe.dart';

/// **One row in the monitors dashboard list.**
///
/// Renders a tappable card-style row showing a monitor's name, URL,
/// [StatusBadge], 90-day [UptimeBar], latency in tabular-nums, and a compact
/// meta line (primary region + last-check label).
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
  final MonitorSummary monitor;

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

    // 2. Build the 90-segment uptime bar for this monitor.
    final uptimeBar = UptimeBar(segments: uptime90(), size: UptimeBarSize.sm);

    // 3. Build the top row: name/URL column, status badge, latency metric.
    final topRow = WDiv(
      className: slots['topRow'],
      children: [
        // Left: name + URL stacked.
        WDiv(
          className: slots['main'],
          children: [
            WText(monitor.name, className: slots['name']),
            WText(monitor.url, className: slots['url']),
          ],
        ),

        // Center-right: status badge.
        StatusBadge(monitor.status),

        // Trailing: response-time metric.
        WText(
          monitor.responseMs != null ? '${monitor.responseMs}ms' : '—',
          className: slots['metric'],
        ),
      ],
    );

    // 4. Build the meta row: first region + uptime percentage.
    final metaRow = WDiv(
      className: slots['meta'],
      children: [
        if (monitor.regions.isNotEmpty)
          WText(monitor.regions.first, className: slots['metaItem']),
        WText(monitor.uptime, className: slots['metaItem']),
      ],
    );

    // 5. Compose the full row and wrap in a WAnchor for the 44px hit target.
    return WAnchor(
      onTap: onTap,
      child: WDiv(
        className: slots['root'],
        children: [topRow, uptimeBar, metaRow],
      ),
    );
  }
}
