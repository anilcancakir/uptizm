import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/models/monitor.dart';
import 'monitor_list_row.dart';

/// Static preview for [MonitorListRow].
///
/// Renders four representative monitors (up / degraded / down / paused) in a
/// vertical list so the catalog shows the full status range in both light and
/// dark. One preview class per file is the canonical atomic-component contract.
class MonitorListRowPreview extends StatelessWidget {
  /// Creates the monitor list row preview.
  const MonitorListRowPreview({super.key});

  /// Four fixture monitors covering the representative status states. Built
  /// from raw `MonitorResource`-shaped maps through [Monitor.fromMap] so the
  /// preview exercises the same decode path the live inventory uses.
  static final List<Monitor> _monitors = [
    Monitor.fromMap(const {
      'id': 'marketing',
      'name': 'Marketing site',
      'url': 'https://uptizm.com',
      'last_status': 'up',
      'last_response_ms': 84,
    }),
    Monitor.fromMap(const {
      'id': 'api',
      'name': 'API gateway',
      'url': 'https://api.uptizm.com/health',
      'last_status': 'degraded',
      'last_response_ms': 412,
    }),
    Monitor.fromMap(const {
      'id': 'checkout',
      'name': 'Checkout service',
      'url': 'https://pay.uptizm.com',
      'last_status': 'down',
    }),
    Monitor.fromMap(const {
      'id': 'docs',
      'name': 'Docs',
      'url': 'https://docs.uptizm.com',
      'status': 'paused',
    }),
  ];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2 p-4',
      children: [
        for (final monitor in _monitors)
          MonitorListRow(
            monitor: monitor,
            // onTap is a no-op in the preview; real pages wire navigation.
            onTap: () {},
          ),
      ],
    );
  }
}
