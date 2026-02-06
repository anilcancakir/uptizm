import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../../app/enums/metric_status_value.dart';

class StatusMetricBadge extends StatelessWidget {
  final String label;
  final MetricStatusValue? status;

  const StatusMetricBadge({
    super.key,
    required this.label,
    required this.status,
  });

  @override
  Widget build(BuildContext context) {
    // Mini Stat Card Design
    return WDiv(
      className:
          'flex flex-col gap-2 p-3 rounded-xl border ${_getBorderClass()} ${_getBackgroundClass()}',
      children: [
        // Header: Label + Dot
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WText(
              label.toUpperCase(),
              className:
                  'flex-1 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 line-clamp-1',
            ),
            WDiv(className: 'w-2 h-2 rounded-full ${_getDotClass()}'),
          ],
        ),
        // Body: Status Text
        WText(
          _getStatusText(),
          className: 'text-lg font-bold ${_getStatusTextClass()}',
        ),
      ],
    );
  }

  String _getStatusText() {
    switch (status) {
      case MetricStatusValue.up:
        return 'UP';
      case MetricStatusValue.down:
        return 'DOWN';
      case MetricStatusValue.unknown:
        return 'UNKNOWN';
      case null:
        return '-';
    }
  }

  String _getBorderClass() {
    switch (status) {
      case MetricStatusValue.up:
        return 'border-green-200 dark:border-green-800/50';
      case MetricStatusValue.down:
        return 'border-red-200 dark:border-red-800/50';
      case MetricStatusValue.unknown:
        return 'border-gray-200 dark:border-gray-700';
      case null:
        return 'border-gray-200 dark:border-gray-700';
    }
  }

  String _getBackgroundClass() {
    switch (status) {
      case MetricStatusValue.up:
        return 'bg-green-50 dark:bg-green-900/10';
      case MetricStatusValue.down:
        return 'bg-red-50 dark:bg-red-900/10';
      case MetricStatusValue.unknown:
        return 'bg-gray-50 dark:bg-gray-800/50';
      case null:
        return 'bg-gray-50 dark:bg-gray-800/50';
    }
  }

  String _getDotClass() {
    switch (status) {
      case MetricStatusValue.up:
        return 'bg-green-500';
      case MetricStatusValue.down:
        return 'bg-red-500';
      case MetricStatusValue.unknown:
        return 'bg-gray-400 dark:bg-gray-500';
      case null:
        return 'bg-gray-400 dark:bg-gray-500';
    }
  }

  String _getStatusTextClass() {
    switch (status) {
      case MetricStatusValue.up:
        return 'text-green-600 dark:text-green-400';
      case MetricStatusValue.down:
        return 'text-red-600 dark:text-red-400';
      case MetricStatusValue.unknown:
        return 'text-gray-500 dark:text-gray-500';
      case null:
        return 'text-gray-500 dark:text-gray-500';
    }
  }
}
