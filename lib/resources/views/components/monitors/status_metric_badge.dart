import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/metric_status_value.dart';

/// Colored badge showing a status metric (up/down/unknown) with label and path.
///
/// ## Usage
/// ```dart
/// StatusMetricBadge(
///   label: 'Database',
///   status: MetricStatusValue.up,
///   path: 'health.database',
/// )
/// ```
class StatusMetricBadge extends StatelessWidget {
  const StatusMetricBadge({
    super.key,
    required this.label,
    required this.status,
    this.path,
  });

  final String label;
  final MetricStatusValue status;
  final String? path;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      states: {status.value},
      className: '''
        flex flex-col gap-1.5 p-3 rounded-xl border
        up:bg-green-50 dark:up:bg-green-900/20 up:border-green-200 dark:up:border-green-800
        down:bg-red-50 dark:down:bg-red-900/20 down:border-red-200 dark:down:border-red-800
        unknown:bg-gray-50 dark:unknown:bg-gray-800 unknown:border-gray-200 dark:unknown:border-gray-700
      ''',
      children: [
        // Status dot + label
        WDiv(
          className: 'flex flex-row items-center gap-1.5',
          children: [
            WDiv(
              states: {status.value},
              className: '''
                w-2 h-2 rounded-full
                up:bg-green-500 dark:up:bg-green-400
                down:bg-red-500 dark:down:bg-red-400
                unknown:bg-gray-400 dark:unknown:bg-gray-500
              ''',
            ),
            WText(
              status.label,
              states: {status.value},
              className: '''
                text-sm font-bold
                up:text-green-700 dark:up:text-green-400
                down:text-red-700 dark:down:text-red-400
                unknown:text-gray-500 dark:unknown:text-gray-400
              ''',
            ),
          ],
        ),

        // Metric label
        WText(
          label,
          className: 'text-xs text-gray-500 dark:text-gray-400 truncate',
        ),

        // JSON path
        if (path != null)
          WText(
            path!,
            className:
                'text-[10px] font-mono text-gray-300 dark:text-gray-600 truncate',
          ),
      ],
    );
  }
}
