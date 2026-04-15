import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/check_status.dart';

/// Range options for uptime bar display.
enum UptimeBarRange {
  days30(30, '30D'),
  days60(60, '60D'),
  days90(90, '90D');

  const UptimeBarRange(this.days, this.label);

  final int days;
  final String label;
}

/// Per-day uptime data for the bar visualization.
class UptimeDayData {
  const UptimeDayData({
    required this.date,
    required this.status,
    this.uptimePercent,
  });

  final DateTime date;
  final CheckStatus? status;
  final double? uptimePercent;
}

/// Segmented uptime bar showing daily status over a time range.
///
/// ## Usage
/// ```dart
/// UptimeBar(
///   days: uptimeDays,
///   range: UptimeBarRange.days30,
///   uptimePercent: 99.95,
/// )
/// ```
class UptimeBar extends StatelessWidget {
  const UptimeBar({
    super.key,
    required this.days,
    this.range = UptimeBarRange.days30,
    this.uptimePercent,
  });

  final List<UptimeDayData> days;
  final UptimeBarRange range;
  final double? uptimePercent;

  Color _colorForStatus(CheckStatus? status, BuildContext context) {
    return switch (status) {
      CheckStatus.up => wColor(context, 'primary')!,
      CheckStatus.down => wColor(context, 'red')!,
      CheckStatus.degraded => wColor(context, 'yellow')!,
      _ => wColor(
        context,
        'gray',
        shade: 300,
        darkColorName: 'gray',
        darkShade: 600,
      )!,
    };
  }

  @override
  Widget build(BuildContext context) {
    final displayDays = _buildDisplayDays();

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WText(
              '${range.days} days ago',
              className: 'text-xs text-gray-400 dark:text-gray-500',
            ),
            if (uptimePercent != null)
              WText(
                '${uptimePercent!.toStringAsFixed(2)}% uptime',
                className:
                    'text-xs font-medium text-gray-600 dark:text-gray-300',
              ),
            WText(
              'Today',
              className: 'text-xs text-gray-400 dark:text-gray-500',
            ),
          ],
        ),
        // native: required by LayoutBuilder (pixel-precise segment widths)
        SizedBox(
          height: 32,
          child: LayoutBuilder(
            builder: (context, constraints) {
              final totalWidth = constraints.maxWidth;
              final segmentCount = displayDays.length;
              const gapWidth = 1.5;
              final totalGaps = segmentCount - 1;
              final segmentWidth =
                  (totalWidth - (totalGaps * gapWidth)) / segmentCount;

              // native: required by LayoutBuilder (Row, Container, SizedBox)
              return Row(
                children: [
                  for (var i = 0; i < displayDays.length; i++) ...[
                    if (i > 0) SizedBox(width: gapWidth),
                    Container(
                      width: segmentWidth,
                      height: 32,
                      decoration: BoxDecoration(
                        color: _colorForStatus(displayDays[i].status, context),
                        borderRadius: BorderRadius.horizontal(
                          left: i == 0 ? const Radius.circular(4) : Radius.zero,
                          right: i == displayDays.length - 1
                              ? const Radius.circular(4)
                              : Radius.zero,
                        ),
                      ),
                    ),
                  ],
                ],
              );
            },
          ),
        ),
      ],
    );
  }

  List<UptimeDayData> _buildDisplayDays() {
    if (days.length >= range.days) {
      return days.sublist(days.length - range.days);
    }

    final padding = List.generate(
      range.days - days.length,
      (i) => UptimeDayData(
        date: DateTime.now().subtract(Duration(days: range.days - i)),
        status: null,
      ),
    );

    return [...padding, ...days];
  }
}
