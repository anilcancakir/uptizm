import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../../app/models/monitor_metric_value.dart';
import 'status_metric_badge.dart';

class StatusMetricsPanel extends StatelessWidget {
  final List<MonitorMetricValue> metrics;
  final String? title;

  const StatusMetricsPanel({super.key, required this.metrics, this.title});

  @override
  Widget build(BuildContext context) {
    if (metrics.isEmpty) {
      return const SizedBox.shrink();
    }

    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WDiv(
                className: 'p-2 rounded-lg bg-primary/10',
                child: WIcon(
                  Icons.verified_outlined,
                  className: 'text-primary text-lg',
                ),
              ),
              const WSpacer(className: 'w-3'),
              WText(
                (title ?? trans('monitor.status_metrics')).toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),
        // Grid Content
        WDiv(
          className: 'p-5 grid grid-cols-2 md:grid-cols-4 gap-4',
          children: metrics
              .map(
                (metric) => StatusMetricBadge(
                  label: metric.metricLabel,
                  status: metric.statusValue,
                ),
              )
              .toList(),
        ),
      ],
    );
  }
}
