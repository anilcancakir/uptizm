import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../../app/models/analytics_series.dart';

/// Data table view for metrics (alternative to chart)
class MetricDataTable extends StatelessWidget {
  final List<AnalyticsSeries> series;

  const MetricDataTable({super.key, required this.series});

  @override
  Widget build(BuildContext context) {
    if (series.isEmpty) {
      return WDiv(
        className: 'p-8 flex items-center justify-center',
        child: WText(
          trans('analytics.no_data'),
          className: 'text-gray-500 dark:text-gray-400 italic',
        ),
      );
    }

    // Get all unique timestamps across all series
    final allTimestamps = <DateTime>{};
    for (final s in series) {
      for (final p in s.dataPoints) {
        allTimestamps.add(p.timestamp);
      }
    }
    final sortedTimestamps = allTimestamps.toList()..sort();

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        headingRowColor: WidgetStateProperty.all(
          Theme.of(context).brightness == Brightness.dark
              ? wColor(context, 'gray-700')
              : wColor(context, 'gray-100'),
        ),
        columns: [
          const DataColumn(
            label: WText('Time', className: 'font-semibold text-sm'),
          ),
          ...series.map(
            (s) => DataColumn(
              label: WText(
                '${s.metricLabel}${s.unit != null ? " (${s.unit})" : ""}',
                className: 'font-semibold text-sm',
              ),
              numeric: true,
            ),
          ),
        ],
        rows: sortedTimestamps.map((timestamp) {
          return DataRow(
            cells: [
              DataCell(
                WText(
                  DateFormat('MMM d, HH:mm').format(timestamp),
                  className:
                      'text-sm text-gray-600 dark:text-gray-400 font-mono',
                ),
              ),
              ...series.map((s) {
                final point = s.dataPoints
                    .where((p) => p.timestamp == timestamp)
                    .firstOrNull;
                final value = point?.value;
                return DataCell(
                  WText(
                    value != null ? value.toStringAsFixed(1) : '-',
                    className:
                        'text-sm font-medium text-gray-900 dark:text-white font-mono',
                  ),
                );
              }),
            ],
          );
        }).toList(),
      ),
    );
  }
}
