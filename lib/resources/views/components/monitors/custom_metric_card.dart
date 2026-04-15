import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/metric_type.dart';

/// Card displaying a custom metric with value, unit, trend, and JSON path.
///
/// ## Usage
/// ```dart
/// CustomMetricCard(
///   label: 'CPU Usage',
///   value: '42.5',
///   type: MetricType.numeric,
///   unit: '%',
///   path: 'system.cpu',
///   trend: '-3.2%',
///   trendPositive: true,
/// )
/// ```
class CustomMetricCard extends StatelessWidget {
  const CustomMetricCard({
    super.key,
    required this.label,
    required this.value,
    required this.type,
    this.unit,
    this.path,
    this.trend,
    this.trendPositive,
  });

  final String label;
  final String value;
  final MetricType type;
  final String? unit;
  final String? path;
  final String? trend;
  final bool? trendPositive;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-col gap-1 p-3 rounded-xl
        bg-gray-50 dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        // Label + type icon
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WDiv(
              className: 'flex-1',
              child: WText(
                label,
                className:
                    'text-xs font-medium text-gray-500 dark:text-gray-400 truncate',
              ),
            ),
            WIcon(
              type == MetricType.numeric
                  ? Icons.show_chart_rounded
                  : Icons.data_object_rounded,
              className: 'text-[14px] text-gray-300 dark:text-gray-600',
            ),
          ],
        ),

        // Value + unit
        WDiv(
          className: 'flex flex-row items-baseline gap-1',
          children: [
            WDiv(
              className: 'flex-1',
              child: WText(
                value,
                className:
                    'text-xl font-bold font-mono text-gray-900 dark:text-white truncate',
              ),
            ),
            if (unit != null)
              WText(
                unit!,
                className:
                    'text-xs font-medium text-gray-400 dark:text-gray-500',
              ),
          ],
        ),

        // Trend
        if (trend != null)
          WDiv(
            states: {if (trendPositive == true) 'positive' else 'negative'},
            className: 'flex flex-row items-center gap-0.5',
            children: [
              WDiv(
                states: {if (trendPositive == true) 'positive' else 'negative'},
                className: '''
                  text-[10px]
                  positive:text-green-600 dark:positive:text-green-400
                  negative:text-red-600 dark:negative:text-red-400
                ''',
                child: WIcon(
                  trendPositive == true
                      ? Icons.arrow_upward_rounded
                      : Icons.arrow_downward_rounded,
                ),
              ),
              WText(
                trend!,
                states: {if (trendPositive == true) 'positive' else 'negative'},
                className: '''
                  text-[10px] font-medium
                  positive:text-green-600 dark:positive:text-green-400
                  negative:text-red-600 dark:negative:text-red-400
                ''',
              ),
            ],
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
