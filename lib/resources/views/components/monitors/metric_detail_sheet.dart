import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/check_status.dart';
import '../../../../app/enums/metric_status_value.dart';
import '../../../../app/enums/metric_time_range.dart';
import '../../../../app/enums/metric_type.dart';
import '../charts/response_time_chart.dart';
import 'metric_value_row.dart';
import 'time_range_selector.dart';
import 'uptime_bar.dart';

/// Draggable bottom sheet showing metric detail with chart and recent values.
///
/// ## Usage
/// ```dart
/// MetricDetailSheet.show(
///   context,
///   label: 'CPU Usage',
///   type: MetricType.numeric,
///   currentValue: '42.5',
///   unit: '%',
/// );
/// ```
class MetricDetailSheet extends StatefulWidget {
  const MetricDetailSheet({
    super.key,
    required this.label,
    required this.type,
    required this.currentValue,
    this.unit,
    this.path,
    this.currentStatus,
  });

  final String label;
  final MetricType type;
  final String currentValue;
  final String? unit;
  final String? path;
  final MetricStatusValue? currentStatus;

  /// Show the metric detail sheet as a modal bottom sheet.
  static Future<void> show(
    BuildContext context, {
    required String label,
    required MetricType type,
    required String currentValue,
    String? unit,
    String? path,
    MetricStatusValue? currentStatus,
  }) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => MetricDetailSheet(
        label: label,
        type: type,
        currentValue: currentValue,
        unit: unit,
        path: path,
        currentStatus: currentStatus,
      ),
    );
  }

  @override
  State<MetricDetailSheet> createState() => _MetricDetailSheetState();
}

class _MetricDetailSheetState extends State<MetricDetailSheet> {
  MetricTimeRange _selectedRange = MetricTimeRange.hour24;

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.75,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      builder: (context, scrollController) {
        return WDiv(
          className: '''
            flex flex-col rounded-t-2xl
            bg-white dark:bg-gray-900
          ''',
          children: [
            // Grabber
            WDiv(
              className: 'pt-3 pb-1',
              child: Center(
                child: WDiv(
                  className:
                      'w-9 h-1 rounded-full bg-gray-300 dark:bg-gray-600',
                ),
              ),
            ),

            // Header (fixed)
            _buildHeader(),

            // Scrollable content
            WDiv(
              className: 'flex-1 overflow-y-auto',
              scrollPrimary: true,
              child: WDiv(
                className: 'flex flex-col gap-6 px-4 pb-8',
                children: [
                  TimeRangeSelector(
                    selected: _selectedRange,
                    onChanged: (range) {
                      setState(() => _selectedRange = range);
                    },
                  ),
                  if (widget.type == MetricType.status)
                    _buildStatusTimeline()
                  else
                    _buildNumericChart(),
                  _buildRecentValues(),
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  // -------  Header  -------

  Widget _buildHeader() {
    return WDiv(
      className: 'flex flex-col gap-3 px-4 pb-4',
      children: [
        // Title row
        WDiv(
          className: 'flex flex-row items-start justify-between gap-3',
          children: [
            WDiv(
              className: 'flex-1 flex flex-col gap-1',
              children: [
                WDiv(
                  className: 'flex flex-row items-center gap-2',
                  children: [
                    WDiv(
                      className: 'flex-1',
                      child: WText(
                        widget.label,
                        className: '''
                          text-lg font-semibold truncate
                          text-gray-900 dark:text-white
                        ''',
                      ),
                    ),
                    _buildTypeBadge(),
                  ],
                ),
                if (widget.path != null)
                  WText(
                    widget.path!,
                    className:
                        'text-xs font-mono text-gray-400 dark:text-gray-500',
                  ),
              ],
            ),
          ],
        ),

        // Current value
        WDiv(
          className: '''
            flex flex-row items-baseline gap-2 p-3 rounded-xl
            bg-gray-50 dark:bg-gray-800
          ''',
          children: [
            WText(
              'Current',
              className: 'text-xs text-gray-400 dark:text-gray-500',
            ),
            WDiv(className: 'flex-1'),
            if (widget.type == MetricType.status &&
                widget.currentStatus != null)
              _buildCurrentStatusBadge()
            else ...[
              WText(
                widget.currentValue,
                className: '''
                  text-2xl font-bold font-mono
                  text-gray-900 dark:text-white
                ''',
              ),
              if (widget.unit != null)
                WText(
                  widget.unit!,
                  className:
                      'text-sm font-medium text-gray-400 dark:text-gray-500',
                ),
            ],
          ],
        ),
      ],
    );
  }

  Widget _buildTypeBadge() {
    final label = switch (widget.type) {
      MetricType.numeric => 'Numeric',
      MetricType.string => 'String',
      MetricType.status => 'Status',
    };
    final icon = switch (widget.type) {
      MetricType.numeric => Icons.show_chart_rounded,
      MetricType.string => Icons.data_object_rounded,
      MetricType.status => Icons.toggle_on_outlined,
    };

    return WDiv(
      className: '''
        flex flex-row items-center gap-1 px-2 py-0.5 rounded-full
        bg-gray-100 dark:bg-gray-800
      ''',
      children: [
        WIcon(icon, className: 'text-[12px] text-gray-400 dark:text-gray-500'),
        WText(
          label,
          className: 'text-[10px] font-medium text-gray-500 dark:text-gray-400',
        ),
      ],
    );
  }

  Widget _buildCurrentStatusBadge() {
    final status = widget.currentStatus!;
    return WDiv(
      states: {status.value},
      className: '''
        flex flex-row items-center gap-1.5 px-3 py-1 rounded-full
        up:bg-green-100 dark:up:bg-green-900/30
        down:bg-red-100 dark:down:bg-red-900/30
        unknown:bg-gray-100 dark:unknown:bg-gray-800
      ''',
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
    );
  }

  // -------  Numeric Chart  -------

  Widget _buildNumericChart() {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(
          'TREND',
          className:
              'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
        ),
        WDiv(
          className: '''
            rounded-xl p-3
            bg-gray-50 dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
          ''',
          child: ResponseTimeChart(dataPoints: _mockChartData(), height: 180),
        ),
      ],
    );
  }

  // -------  Status Timeline  -------

  Widget _buildStatusTimeline() {
    final uptimeDays = _mockStatusTimeline();
    final upCount = uptimeDays.where((d) => d.status == CheckStatus.up).length;
    final uptimePercent = uptimeDays.isNotEmpty
        ? (upCount / uptimeDays.length * 100)
        : 0.0;

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WText(
              'STATUS TIMELINE',
              className:
                  'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
            ),
            WDiv(
              className: 'flex flex-row items-center gap-1.5',
              children: [
                WText(
                  '${uptimePercent.toStringAsFixed(1)}%',
                  className: 'text-sm font-bold text-primary',
                ),
                WText(
                  'uptime',
                  className: 'text-xs text-gray-400 dark:text-gray-500',
                ),
              ],
            ),
          ],
        ),
        WDiv(
          className: '''
            rounded-xl p-4
            bg-gray-50 dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
          ''',
          child: UptimeBar(
            days: uptimeDays,
            range: UptimeBarRange.days30,
            uptimePercent: uptimePercent,
          ),
        ),

        // Status change summary
        WDiv(
          className: 'flex flex-row gap-3',
          children: [
            WDiv(
              className: '''
                flex-1 flex flex-col items-center gap-1 p-3 rounded-xl
                bg-green-50 dark:bg-green-900/20
                border border-green-200 dark:border-green-800
              ''',
              children: [
                WText(
                  '$upCount',
                  className:
                      'text-xl font-bold text-green-700 dark:text-green-400',
                ),
                WText(
                  'Checks UP',
                  className: 'text-xs text-green-600 dark:text-green-400',
                ),
              ],
            ),
            WDiv(
              className: '''
                flex-1 flex flex-col items-center gap-1 p-3 rounded-xl
                bg-red-50 dark:bg-red-900/20
                border border-red-200 dark:border-red-800
              ''',
              children: [
                WText(
                  '${uptimeDays.length - upCount}',
                  className: 'text-xl font-bold text-red-700 dark:text-red-400',
                ),
                WText(
                  'Checks DOWN',
                  className: 'text-xs text-red-600 dark:text-red-400',
                ),
              ],
            ),
          ],
        ),
      ],
    );
  }

  // -------  Recent Values  -------

  Widget _buildRecentValues() {
    final values = _mockRecentValues();

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WText(
              'RECENT VALUES',
              className:
                  'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
            ),
            WText(
              '${values.length} records',
              className: 'text-xs text-gray-400 dark:text-gray-500',
            ),
          ],
        ),
        WDiv(
          className: '''
            flex flex-col rounded-xl overflow-hidden
            bg-gray-50 dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
          ''',
          children: [
            for (var i = 0; i < values.length; i++)
              MetricValueRow(
                value: values[i].value,
                type: widget.type,
                checkedAt: values[i].checkedAt,
                unit: widget.unit,
                statusValue: values[i].statusValue,
                isLast: i == values.length - 1,
              ),
          ],
        ),

        // Load more button
        WButton(
          onTap: () {},
          className: '''
            w-full py-3.5 rounded-xl no-underline
            bg-white dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
          ''',
          child: WDiv(
            className: 'flex flex-row items-center justify-center',
            child: WText(
              'Load More',
              className:
                  'text-sm font-medium no-underline text-gray-600 dark:text-gray-300',
            ),
          ),
        ),
      ],
    );
  }

  // -------  Mock Data  -------

  List<ResponseTimeDataPoint> _mockChartData() {
    final now = DateTime.now();
    const values = [
      42.5, 44.1, 39.8, 43.2, 67.8, 45.6, 41.3, 38.9, 42.1, 44.7, //
      43.5, 41.8, 40.2, 39.5, 44.3, 42.8, 41.1, 39.7, 43.6, 42.5,
    ];
    return List.generate(values.length, (i) {
      return ResponseTimeDataPoint(
        timestamp: now.subtract(Duration(minutes: (values.length - i) * 3)),
        responseTimeMs: (values[i] * 10).toInt(),
        status: values[i] > 60 ? CheckStatus.degraded : CheckStatus.up,
      );
    });
  }

  List<UptimeDayData> _mockStatusTimeline() {
    final now = DateTime.now();
    return List.generate(30, (i) {
      final date = now.subtract(Duration(days: 29 - i));
      CheckStatus status;
      if (i == 12 || i == 22) {
        status = CheckStatus.down;
      } else {
        status = CheckStatus.up;
      }
      return UptimeDayData(
        date: date,
        status: status,
        uptimePercent: status == CheckStatus.up ? 100.0 : 0.0,
      );
    });
  }

  List<_MockRecentValue> _mockRecentValues() {
    final now = DateTime.now();

    if (widget.type == MetricType.status) {
      return List.generate(10, (i) {
        final checkedAt = now.subtract(Duration(minutes: i * 3));
        return _MockRecentValue(
          value: i == 3 || i == 7 ? 'DOWN' : 'UP',
          checkedAt: checkedAt,
          statusValue: i == 3 || i == 7
              ? MetricStatusValue.down
              : MetricStatusValue.up,
        );
      });
    }

    const numericValues = [
      '42.5', '44.1', '39.8', '43.2', '67.8', //
      '45.6', '41.3', '38.9', '42.1', '44.7',
    ];
    return List.generate(10, (i) {
      return _MockRecentValue(
        value: numericValues[i],
        checkedAt: now.subtract(Duration(minutes: i * 3)),
      );
    });
  }
}

class _MockRecentValue {
  const _MockRecentValue({
    required this.value,
    required this.checkedAt,
    this.statusValue,
  });

  final String value;
  final DateTime checkedAt;
  final MetricStatusValue? statusValue;
}
