import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/check_status.dart';

/// Data point for response time chart.
class ResponseTimeDataPoint {
  const ResponseTimeDataPoint({
    required this.timestamp,
    required this.responseTimeMs,
    this.status = CheckStatus.up,
  });

  final DateTime timestamp;
  final int responseTimeMs;
  final CheckStatus status;
}

/// Response time line chart using fl_chart.
///
/// ## Usage
/// ```dart
/// ResponseTimeChart(dataPoints: points, height: 200)
/// ```
class ResponseTimeChart extends StatelessWidget {
  const ResponseTimeChart({
    super.key,
    required this.dataPoints,
    this.height = 200,
  });

  final List<ResponseTimeDataPoint> dataPoints;
  final double height;

  @override
  Widget build(BuildContext context) {
    if (dataPoints.isEmpty) {
      return WDiv(
        className: '''
          flex items-center justify-center w-full h-[200px] rounded-xl
          bg-gray-50 dark:bg-gray-800
        ''',
        child: WText(
          'No data available',
          className: 'text-sm text-gray-400 dark:text-gray-500',
        ),
      );
    }

    final isDark = Theme.of(context).brightness == Brightness.dark;

    final primaryColor = wColor(context, 'primary')!;
    final gridColor = wColor(
      context,
      'gray',
      shade: 100,
      darkColorName: 'gray',
      darkShade: 700,
    )!;
    final textColor = wColor(
      context,
      'gray',
      shade: 500,
      darkColorName: 'gray',
      darkShade: 400,
    )!;
    final fillColorStart = primaryColor.withValues(alpha: isDark ? 0.15 : 0.1);
    final fillColorEnd = primaryColor.withValues(alpha: 0.0);

    final spots = <FlSpot>[];
    for (var i = 0; i < dataPoints.length; i++) {
      spots.add(FlSpot(i.toDouble(), dataPoints[i].responseTimeMs.toDouble()));
    }

    final maxY =
        dataPoints
            .map((d) => d.responseTimeMs.toDouble())
            .reduce((a, b) => a > b ? a : b) *
        1.2;

    return WDiv(
      className: 'rounded-xl',
      // native: required by fl_chart (SizedBox, LineChart, Text, TextStyle)
      child: SizedBox(
        height: height,
        child: LineChart(
          LineChartData(
            gridData: FlGridData(
              show: true,
              drawVerticalLine: false,
              horizontalInterval: _calculateInterval(maxY),
              getDrawingHorizontalLine: (value) =>
                  FlLine(color: gridColor, strokeWidth: 1),
            ),
            titlesData: FlTitlesData(
              topTitles: const AxisTitles(
                sideTitles: SideTitles(showTitles: false),
              ),
              rightTitles: const AxisTitles(
                sideTitles: SideTitles(showTitles: false),
              ),
              leftTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 48,
                  interval: _calculateInterval(maxY),
                  // native: required by fl_chart
                  getTitlesWidget: (value, meta) => Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: Text(
                      '${value.toInt()}ms',
                      style: TextStyle(color: textColor, fontSize: 10),
                    ),
                  ),
                ),
              ),
              bottomTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 28,
                  interval: _calculateBottomInterval(),
                  // native: required by fl_chart
                  getTitlesWidget: (value, meta) {
                    final index = value.toInt();
                    if (index < 0 || index >= dataPoints.length) {
                      return const SizedBox.shrink();
                    }
                    final time = dataPoints[index].timestamp;
                    return Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}',
                        style: TextStyle(color: textColor, fontSize: 10),
                      ),
                    );
                  },
                ),
              ),
            ),
            borderData: FlBorderData(show: false),
            minX: 0,
            maxX: (dataPoints.length - 1).toDouble(),
            minY: 0,
            maxY: maxY,
            lineBarsData: [
              LineChartBarData(
                spots: spots,
                isCurved: true,
                curveSmoothness: 0.3,
                color: primaryColor,
                barWidth: 2.5,
                isStrokeCapRound: true,
                dotData: FlDotData(
                  show: true,
                  getDotPainter: (spot, percent, barData, index) {
                    final status = dataPoints[index].status;
                    final dotColor = switch (status) {
                      CheckStatus.up => primaryColor,
                      CheckStatus.down => wColor(context, 'red')!,
                      CheckStatus.degraded => wColor(context, 'yellow')!,
                    };
                    return FlDotCirclePainter(
                      radius: 3,
                      color: dotColor,
                      strokeWidth: 1.5,
                      strokeColor: wColor(
                        context,
                        'white',
                        darkColorName: 'gray',
                        darkShade: 800,
                      )!,
                    );
                  },
                ),
                belowBarData: BarAreaData(
                  show: true,
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [fillColorStart, fillColorEnd],
                  ),
                ),
              ),
            ],
            lineTouchData: LineTouchData(
              touchTooltipData: LineTouchTooltipData(
                getTooltipColor: (spot) => wColor(
                  context,
                  'white',
                  darkColorName: 'gray',
                  darkShade: 700,
                )!,
                tooltipBorderRadius: BorderRadius.circular(8),
                tooltipPadding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 8,
                ),
                getTooltipItems: (touchedSpots) {
                  return touchedSpots.map((spot) {
                    final index = spot.spotIndex;
                    final point = dataPoints[index];
                    final time = point.timestamp;
                    final timeStr =
                        '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}';
                    return LineTooltipItem(
                      '${point.responseTimeMs}ms\n',
                      TextStyle(
                        color: primaryColor,
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                      ),
                      children: [
                        TextSpan(
                          text: timeStr,
                          style: TextStyle(
                            color: textColor,
                            fontWeight: FontWeight.normal,
                            fontSize: 11,
                          ),
                        ),
                      ],
                    );
                  }).toList();
                },
              ),
              handleBuiltInTouches: true,
            ),
          ),
        ),
      ),
    );
  }

  // -------  Helpers  -------

  double _calculateInterval(double maxY) {
    if (maxY <= 100) return 25;
    if (maxY <= 500) return 100;
    if (maxY <= 1000) return 200;
    if (maxY <= 5000) return 1000;
    return (maxY / 5).roundToDouble();
  }

  double _calculateBottomInterval() {
    final count = dataPoints.length;
    if (count <= 6) return 1;
    if (count <= 12) return 2;
    if (count <= 24) return 4;
    return (count / 6).roundToDouble();
  }
}
