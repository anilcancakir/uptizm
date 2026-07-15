import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import '../../../app/enums/status_key.dart';
import 'component_status_row.dart';

/// Static variant-matrix preview for [ComponentStatusRow].
///
/// Mirrors the design lab `ComponentStatusRow.preview.tsx`: a small public
/// status-page list (each status family + an outage segment) plus a no-history
/// row. One preview class per file; discovered by `previews:refresh`.
class ComponentStatusRowPreview extends StatelessWidget {
  /// Creates the ComponentStatusRow variant-matrix preview.
  const ComponentStatusRowPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        WDiv(
          className: 'flex flex-col',
          children: [
            ComponentStatusRow(
              name: 'Website',
              status: StatusKey.up,
              segments: uptime90(),
              uptimeLabel: '100.0% uptime',
            ),
            ComponentStatusRow(
              name: 'API',
              status: StatusKey.degraded,
              segments: uptime90(degraded: const [86, 87, 88]),
              uptimeLabel: '99.94% uptime',
            ),
            ComponentStatusRow(
              name: 'Dashboard',
              status: StatusKey.up,
              segments: uptime90(down: const [20]),
              uptimeLabel: '99.99% uptime',
            ),
            ComponentStatusRow(
              name: 'Payments',
              status: StatusKey.down,
              segments: uptime90(down: const [89]),
              uptimeLabel: '99.91% uptime',
            ),
          ],
        ),

        // No-history variant: name + status only.
        const ComponentStatusRow(name: 'Status only', status: StatusKey.paused),
      ],
    );
  }
}
