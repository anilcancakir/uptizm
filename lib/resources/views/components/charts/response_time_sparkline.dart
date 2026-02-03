import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'chart_theme.dart';

/// Compact sparkline chart for response times
///
/// A small, inline chart designed for stat cards and list items.
/// Shows response time trend with gradient fill.
class ResponseTimeSparkline extends StatelessWidget {
  const ResponseTimeSparkline({
    super.key,
    required this.data,
    this.height = 40,
    this.width = 100,
    this.showDots = false,
    this.color,
  });

  /// Response time values in milliseconds
  final List<int> data;

  /// Chart height
  final double height;

  /// Chart width (null = expand to fill)
  final double? width;

  /// Whether to show dots at data points
  final bool showDots;

  /// Line color (defaults to primary)
  final Color? color;

  @override
  Widget build(BuildContext context) {
    if (data.isEmpty) {
      return SizedBox(height: height, width: width);
    }

    final lineColor = color ?? UptizmChartTheme.primary;
    final spots = _buildSpots();
    final maxY = _getMaxY();
    final minY = _getMinY();

    return SizedBox(
      height: height,
      width: width,
      child: LineChart(
        LineChartData(
          gridData: const FlGridData(show: false),
          titlesData: const FlTitlesData(show: false),
          borderData: FlBorderData(show: false),
          lineTouchData: const LineTouchData(enabled: false),
          minY: minY * 0.9,
          maxY: maxY * 1.1,
          lineBarsData: [
            LineChartBarData(
              spots: spots,
              isCurved: true,
              curveSmoothness: 0.3,
              color: lineColor,
              barWidth: UptizmChartTheme.lineWidth,
              isStrokeCapRound: true,
              dotData: FlDotData(
                show: showDots,
                getDotPainter: (spot, percent, bar, index) {
                  return FlDotCirclePainter(
                    radius: UptizmChartTheme.dotRadius,
                    color: lineColor,
                    strokeWidth: 0,
                  );
                },
              ),
              belowBarData: BarAreaData(
                show: true,
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    lineColor.withValues(alpha: 0.3),
                    lineColor.withValues(alpha: 0.05),
                  ],
                ),
              ),
            ),
          ],
        ),
        duration: UptizmChartTheme.tooltipDuration,
      ),
    );
  }

  List<FlSpot> _buildSpots() {
    return data.asMap().entries.map((entry) {
      return FlSpot(entry.key.toDouble(), entry.value.toDouble());
    }).toList();
  }

  double _getMaxY() {
    if (data.isEmpty) return 100;
    return data.reduce((a, b) => a > b ? a : b).toDouble();
  }

  double _getMinY() {
    if (data.isEmpty) return 0;
    return data.reduce((a, b) => a < b ? a : b).toDouble();
  }
}
