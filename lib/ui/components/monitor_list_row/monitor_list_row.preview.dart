import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import 'monitor_list_row.dart';

/// Static preview for [MonitorListRow].
///
/// Renders the four fixture monitors (up / degraded / down / paused) in a
/// vertical list so the catalog shows the full status range in both light and
/// dark. One preview class per file is the canonical atomic-component contract.
class MonitorListRowPreview extends StatelessWidget {
  /// Creates the monitor list row preview.
  const MonitorListRowPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2 p-4',
      children: [
        for (final monitor in monitors)
          MonitorListRow(
            monitor: monitor,
            // onTap is a no-op in the preview; real pages wire navigation.
            onTap: () {},
          ),
      ],
    );
  }
}
