import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/metric_status_value.dart';
import '../../../../app/enums/metric_type.dart';

/// Row displaying a single metric value with timestamp.
///
/// ## Usage
/// ```dart
/// MetricValueRow(
///   value: '245',
///   type: MetricType.numeric,
///   checkedAt: DateTime.now(),
///   unit: 'ms',
/// )
/// ```
class MetricValueRow extends StatelessWidget {
  const MetricValueRow({
    super.key,
    required this.value,
    required this.type,
    required this.checkedAt,
    this.unit,
    this.statusValue,
    this.isLast = false,
  });

  final String value;
  final MetricType type;
  final DateTime checkedAt;
  final String? unit;
  final MetricStatusValue? statusValue;
  final bool isLast;

  String _formatTime(DateTime time) {
    final hour = time.hour.toString().padLeft(2, '0');
    final minute = time.minute.toString().padLeft(2, '0');
    final day = time.day.toString().padLeft(2, '0');
    final month = time.month.toString().padLeft(2, '0');
    return '$day/$month $hour:$minute';
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      states: {if (!isLast) 'bordered'},
      className: '''
        flex flex-row items-center justify-between py-3.5 px-4
        bordered:border-b bordered:border-gray-200 dark:bordered:border-gray-700
      ''',
      children: [
        WText(
          _formatTime(checkedAt),
          className: 'text-xs font-mono text-gray-400 dark:text-gray-500',
        ),
        if (type == MetricType.status && statusValue != null)
          WDiv(
            states: {statusValue!.value},
            className: '''
              flex flex-row items-center gap-1.5 px-2.5 py-0.5 rounded-full
              up:bg-green-100 dark:up:bg-green-900/30
              down:bg-red-100 dark:down:bg-red-900/30
              unknown:bg-gray-100 dark:unknown:bg-gray-800
            ''',
            children: [
              WDiv(
                states: {statusValue!.value},
                className: '''
                  w-1.5 h-1.5 rounded-full
                  up:bg-green-500 dark:up:bg-green-400
                  down:bg-red-500 dark:down:bg-red-400
                  unknown:bg-gray-400 dark:unknown:bg-gray-500
                ''',
              ),
              WText(
                statusValue!.label,
                states: {statusValue!.value},
                className: '''
                  text-xs font-semibold
                  up:text-green-700 dark:up:text-green-400
                  down:text-red-700 dark:down:text-red-400
                  unknown:text-gray-500 dark:unknown:text-gray-400
                ''',
              ),
            ],
          )
        else
          WDiv(
            className: 'flex flex-row items-baseline gap-1',
            children: [
              WText(
                value,
                className:
                    'text-sm font-mono font-medium text-gray-900 dark:text-white',
              ),
              if (unit != null)
                WText(
                  unit!,
                  className: 'text-xs text-gray-400 dark:text-gray-500',
                ),
            ],
          ),
      ],
    );
  }
}
