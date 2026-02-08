import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/analytics_series.dart';

class MetricSelector extends StatelessWidget {
  final List<AnalyticsSeries> availableMetrics;
  final List<String> selectedKeys;
  final ValueChanged<String> onToggle;
  final VoidCallback? onSelectAll;
  final VoidCallback? onClearAll;

  const MetricSelector({
    super.key,
    required this.availableMetrics,
    required this.selectedKeys,
    required this.onToggle,
    this.onSelectAll,
    this.onClearAll,
  });

  @override
  Widget build(BuildContext context) {
    if (availableMetrics.isEmpty) {
      return const SizedBox.shrink();
    }

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WDiv(
          className: 'wrap items-center justify-between gap-2',
          children: [
            WText(
              trans('analytics.select_metrics'),
              className:
                  'text-sm font-semibold text-gray-900 dark:text-gray-100',
            ),
            WDiv(
              className: 'flex flex-col lg:flex-row items-center min-w-fit',
              children: [
                if (onSelectAll != null)
                  WButton(
                    onTap: onSelectAll,
                    className: 'text-xs text-primary hover:underline px-2 py-1',
                    child: WText(trans('analytics.all_metrics')),
                  ),
                if (onClearAll != null)
                  WButton(
                    onTap: onClearAll,
                    className:
                        'text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 hover:underline px-2 py-1',
                    child: WText(trans('analytics.clear_metrics')),
                  ),
              ],
            ),
          ],
        ),
        WDiv(
          className: 'wrap gap-2',
          children: availableMetrics.map((series) {
            final isSelected = selectedKeys.contains(series.metricKey);
            return WButton(
              onTap: () => onToggle(series.metricKey),
              className:
                  '''
                px-3 py-1.5 rounded-full text-xs font-medium border transition-colors
                ${isSelected ? 'bg-primary/10 text-primary border-primary' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'}
              ''',
              child: WText(series.metricLabel),
            );
          }).toList(),
        ),
      ],
    );
  }
}
