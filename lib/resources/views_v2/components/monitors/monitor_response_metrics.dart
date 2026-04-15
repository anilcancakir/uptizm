import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/metric_status_value.dart';
import '../../../../app/enums/metric_type.dart';
import '../ui/content_section.dart';
import 'custom_metric_card.dart';
import 'metric_detail_sheet.dart';
import 'status_metric_badge.dart';

/// Response metrics section: status, numeric, and string metric groups.
///
/// ## Usage
/// ```dart
/// MonitorResponseMetrics(
///   statusMetrics: [ResponseStatusMetric(label: 'DB', status: MetricStatusValue.up)],
///   numericMetrics: [ResponseNumericMetric(label: 'CPU', value: '42.5', unit: '%')],
///   stringMetrics: [ResponseStringMetric(label: 'Version', value: 'v2.4.1')],
/// )
/// ```
class MonitorResponseMetrics extends StatelessWidget {
  const MonitorResponseMetrics({
    super.key,
    required this.statusMetrics,
    required this.numericMetrics,
    required this.stringMetrics,
  });

  final List<ResponseStatusMetric> statusMetrics;
  final List<ResponseNumericMetric> numericMetrics;
  final List<ResponseStringMetric> stringMetrics;

  @override
  Widget build(BuildContext context) {
    final totalCount =
        statusMetrics.length + numericMetrics.length + stringMetrics.length;

    return ContentSection(
      title: 'RESPONSE METRICS',
      icon: Icons.integration_instructions_outlined,
      trailing: WText(
        '$totalCount mappings',
        className: 'text-xs text-gray-400 dark:text-gray-500',
      ),
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          // Status metrics row
          if (statusMetrics.isNotEmpty)
            WDiv(
              className: 'flex flex-row gap-2',
              children: [
                for (final metric in statusMetrics)
                  WDiv(
                    className: 'flex-1',
                    child: WButton(
                      onTap: () => MetricDetailSheet.show(
                        context,
                        label: metric.label,
                        type: MetricType.status,
                        currentValue: metric.status.label,
                        path: metric.path,
                        currentStatus: metric.status,
                      ),
                      child: StatusMetricBadge(
                        label: metric.label,
                        status: metric.status,
                        path: metric.path,
                      ),
                    ),
                  ),
              ],
            ),

          // Numeric metrics grid (2 per row)
          if (numericMetrics.isNotEmpty)
            WDiv(
              className: 'flex flex-col gap-2',
              children: [
                for (var i = 0; i < numericMetrics.length; i += 2)
                  WDiv(
                    className: 'flex flex-row gap-2',
                    children: [
                      WDiv(
                        className: 'flex-1',
                        child: WButton(
                          onTap: () => MetricDetailSheet.show(
                            context,
                            label: numericMetrics[i].label,
                            type: MetricType.numeric,
                            currentValue: numericMetrics[i].value,
                            unit: numericMetrics[i].unit,
                            path: numericMetrics[i].path,
                          ),
                          child: CustomMetricCard(
                            label: numericMetrics[i].label,
                            value: numericMetrics[i].value,
                            type: MetricType.numeric,
                            unit: numericMetrics[i].unit,
                            path: numericMetrics[i].path,
                            trend: numericMetrics[i].trend,
                            trendPositive: numericMetrics[i].trendPositive,
                          ),
                        ),
                      ),
                      if (i + 1 < numericMetrics.length)
                        WDiv(
                          className: 'flex-1',
                          child: WButton(
                            onTap: () => MetricDetailSheet.show(
                              context,
                              label: numericMetrics[i + 1].label,
                              type: MetricType.numeric,
                              currentValue: numericMetrics[i + 1].value,
                              unit: numericMetrics[i + 1].unit,
                              path: numericMetrics[i + 1].path,
                            ),
                            child: CustomMetricCard(
                              label: numericMetrics[i + 1].label,
                              value: numericMetrics[i + 1].value,
                              type: MetricType.numeric,
                              unit: numericMetrics[i + 1].unit,
                              path: numericMetrics[i + 1].path,
                              trend: numericMetrics[i + 1].trend,
                              trendPositive:
                                  numericMetrics[i + 1].trendPositive,
                            ),
                          ),
                        )
                      else
                        WDiv(className: 'flex-1'),
                    ],
                  ),
              ],
            ),

          // String metrics rows
          if (stringMetrics.isNotEmpty)
            WDiv(
              className: '''
                flex flex-col rounded-lg overflow-hidden
                border border-gray-200 dark:border-gray-700
              ''',
              children: [
                for (var i = 0; i < stringMetrics.length; i++)
                  WButton(
                    onTap: () => MetricDetailSheet.show(
                      context,
                      label: stringMetrics[i].label,
                      type: MetricType.string,
                      currentValue: stringMetrics[i].value,
                      path: stringMetrics[i].path,
                    ),
                    child: WDiv(
                      states: {if (i < stringMetrics.length - 1) 'bordered'},
                      className: '''
                        flex flex-row items-center justify-between py-2.5 px-3
                        bordered:border-b bordered:border-gray-200 dark:bordered:border-gray-700
                      ''',
                      children: [
                        WDiv(
                          className: 'flex flex-col gap-0.5',
                          children: [
                            WText(
                              stringMetrics[i].label,
                              className:
                                  'text-xs text-gray-500 dark:text-gray-400',
                            ),
                            if (stringMetrics[i].path != null)
                              WText(
                                stringMetrics[i].path!,
                                className:
                                    'text-[10px] font-mono text-gray-300 dark:text-gray-600',
                              ),
                          ],
                        ),
                        WText(
                          stringMetrics[i].value,
                          className:
                              'text-sm font-mono font-medium text-gray-900 dark:text-white',
                        ),
                      ],
                    ),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}

// -------  Data Classes  -------

/// Status metric data (e.g., Database: UP).
class ResponseStatusMetric {
  const ResponseStatusMetric({
    required this.label,
    required this.status,
    this.path,
  });

  final String label;
  final MetricStatusValue status;
  final String? path;
}

/// Numeric metric data (e.g., CPU Usage: 42.5%).
class ResponseNumericMetric {
  const ResponseNumericMetric({
    required this.label,
    required this.value,
    this.unit,
    this.path,
    this.trend,
    this.trendPositive,
  });

  final String label;
  final String value;
  final String? unit;
  final String? path;
  final String? trend;
  final bool? trendPositive;
}

/// String metric data (e.g., Version: v2.4.1).
class ResponseStringMetric {
  const ResponseStringMetric({
    required this.label,
    required this.value,
    this.path,
  });

  final String label;
  final String value;
  final String? path;
}
