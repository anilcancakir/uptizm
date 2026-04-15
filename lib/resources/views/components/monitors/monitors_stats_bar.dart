import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Horizontal stats bar showing total, up, down, and average response counts.
///
/// ## Usage
/// ```dart
/// MonitorsStatsBar(
///   totalCount: 8,
///   upCount: 6,
///   downCount: 2,
///   avgResponse: '245ms',
/// )
/// ```
class MonitorsStatsBar extends StatelessWidget {
  const MonitorsStatsBar({
    super.key,
    required this.totalCount,
    required this.upCount,
    required this.downCount,
    required this.avgResponse,
  });

  final int totalCount;
  final int upCount;
  final int downCount;
  final String avgResponse;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-row p-3 rounded-xl gap-3
        bg-gray-50 dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        _MiniStat(
          value: '$totalCount',
          label: trans('monitors.stats_total'),
          iconWidget: WIcon(
            Icons.monitor_heart_outlined,
            className: 'text-[14px] text-gray-500 dark:text-gray-400',
          ),
        ),
        _MiniStat(
          value: '$upCount',
          label: trans('monitors.stats_up'),
          iconWidget: WIcon(
            Icons.check_circle_outlined,
            className: 'text-[14px] text-green-600 dark:text-green-400',
          ),
        ),
        _MiniStat(
          value: '$downCount',
          label: trans('monitors.stats_down'),
          iconWidget: WIcon(
            Icons.error_outline_rounded,
            className: 'text-[14px] text-red-600 dark:text-red-400',
          ),
        ),
        _MiniStat(
          value: avgResponse,
          label: trans('monitors.stats_avg'),
          iconWidget: WIcon(
            Icons.speed_outlined,
            className: 'text-[14px] text-blue-600 dark:text-blue-400',
          ),
        ),
      ],
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({
    required this.value,
    required this.label,
    required this.iconWidget,
  });

  final String value;
  final String label;
  final Widget iconWidget;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex-1 flex flex-col items-center gap-0.5',
      children: [
        WDiv(
          className: 'flex flex-row items-center gap-1',
          children: [
            iconWidget,
            WText(
              value,
              className: '''
                text-sm font-bold
                text-gray-900 dark:text-white
              ''',
            ),
          ],
        ),
        WText(label, className: 'text-[10px] text-gray-400 dark:text-gray-500'),
      ],
    );
  }
}
