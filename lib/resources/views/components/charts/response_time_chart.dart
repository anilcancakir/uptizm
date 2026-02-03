import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'chart_theme.dart';

/// Data point for response time chart
class ChartDataPoint {
  const ChartDataPoint({
    required this.timestamp,
    required this.value,
    this.status,
  });

  final DateTime timestamp;
  final int value;
  final String? status;
}

/// Full-size response time line chart
///
/// Interactive chart with touch tooltips, responsive sizing.
/// Designed for monitor show page Performance section.
class ResponseTimeChart extends StatefulWidget {
  const ResponseTimeChart({
    super.key,
    required this.dataPoints,
    this.height = 200,
    this.showTooltip = true,
    this.showGrid = true,
    this.showDots = true,
  });

  /// Data points with timestamp and response time
  final List<ChartDataPoint> dataPoints;

  /// Chart height
  final double height;

  /// Whether to show tooltip on touch
  final bool showTooltip;

  /// Whether to show grid lines
  final bool showGrid;

  /// Whether to show dots at data points
  final bool showDots;

  @override
  State<ResponseTimeChart> createState() => _ResponseTimeChartState();
}

class _ResponseTimeChartState extends State<ResponseTimeChart> {
  int? _touchedIndex;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (widget.dataPoints.isEmpty) {
      return SizedBox(
        height: widget.height,
        child: Center(
          child: Text(
            'No data available',
            style: TextStyle(
              color: isDark
                  ? UptizmChartTheme.textDark
                  : UptizmChartTheme.textLight,
              fontSize: 12,
            ),
          ),
        ),
      );
    }

    final spots = _buildSpots();
    final maxY = _getMaxY();
    final minY = _getMinY();

    return SizedBox(
      height: widget.height,
      child: LineChart(
        LineChartData(
          gridData: FlGridData(
            show: widget.showGrid,
            drawVerticalLine: false,
            horizontalInterval: _getGridInterval(maxY, minY),
            getDrawingHorizontalLine: (value) {
              return FlLine(
                color: isDark
                    ? UptizmChartTheme.gridDark
                    : UptizmChartTheme.gridLight,
                strokeWidth: 1,
                dashArray: [5, 5],
              );
            },
          ),
          titlesData: FlTitlesData(
            topTitles: const AxisTitles(
              sideTitles: SideTitles(showTitles: false),
            ),
            rightTitles: const AxisTitles(
              sideTitles: SideTitles(showTitles: false),
            ),
            bottomTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 22,
                interval: _getTimeInterval(),
                getTitlesWidget: (value, meta) {
                  final index = value.toInt();
                  if (index < 0 || index >= widget.dataPoints.length) {
                    return const SizedBox.shrink();
                  }
                  final point = widget.dataPoints[index];
                  return Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      _formatTime(point.timestamp),
                      style: TextStyle(
                        color: isDark
                            ? UptizmChartTheme.textDark
                            : UptizmChartTheme.textLight,
                        fontSize: 10,
                      ),
                    ),
                  );
                },
              ),
            ),
            leftTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 40,
                interval: _getGridInterval(maxY, minY),
                getTitlesWidget: (value, meta) {
                  return Text(
                    '${value.toInt()}ms',
                    style: TextStyle(
                      color: isDark
                          ? UptizmChartTheme.textDark
                          : UptizmChartTheme.textLight,
                      fontSize: 10,
                      fontFamily: 'monospace',
                    ),
                  );
                },
              ),
            ),
          ),
          borderData: FlBorderData(show: false),
          lineTouchData: LineTouchData(
            enabled: widget.showTooltip,
            touchCallback: (event, response) {
              setState(() {
                if (response?.lineBarSpots != null &&
                    response!.lineBarSpots!.isNotEmpty) {
                  _touchedIndex = response.lineBarSpots!.first.spotIndex;
                } else {
                  _touchedIndex = null;
                }
              });
            },
            touchTooltipData: LineTouchTooltipData(
              getTooltipColor: (spot) =>
                  isDark ? UptizmChartTheme.bgDark : Colors.white,
              tooltipBorderRadius: BorderRadius.circular(
                UptizmChartTheme.tooltipRadius,
              ),
              tooltipPadding: const EdgeInsets.symmetric(
                horizontal: 12,
                vertical: 8,
              ),
              getTooltipItems: (touchedSpots) {
                return touchedSpots.map((spot) {
                  final index = spot.spotIndex;
                  if (index < 0 || index >= widget.dataPoints.length) {
                    return null;
                  }
                  final point = widget.dataPoints[index];
                  return LineTooltipItem(
                    '${point.value}ms\n',
                    TextStyle(
                      color: UptizmChartTheme.primary,
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                      fontFamily: 'monospace',
                    ),
                    children: [
                      TextSpan(
                        text: _formatFullTime(point.timestamp),
                        style: TextStyle(
                          color: isDark
                              ? UptizmChartTheme.textDark
                              : UptizmChartTheme.textLight,
                          fontWeight: FontWeight.normal,
                          fontSize: 11,
                        ),
                      ),
                    ],
                  );
                }).toList();
              },
            ),
          ),
          minY: minY * 0.9,
          maxY: maxY * 1.1,
          lineBarsData: [
            LineChartBarData(
              spots: spots,
              isCurved: true,
              curveSmoothness: 0.25,
              color: UptizmChartTheme.primary,
              barWidth: UptizmChartTheme.lineWidth,
              isStrokeCapRound: true,
              dotData: FlDotData(
                show: widget.showDots,
                getDotPainter: (spot, percent, bar, index) {
                  final isSelected = index == _touchedIndex;
                  final status = index < widget.dataPoints.length
                      ? widget.dataPoints[index].status
                      : null;
                  final dotColor = UptizmChartTheme.getStatusColor(status);

                  return FlDotCirclePainter(
                    radius: isSelected
                        ? UptizmChartTheme.dotRadius * 1.5
                        : UptizmChartTheme.dotRadius,
                    color: dotColor,
                    strokeWidth: isSelected ? 2 : 0,
                    strokeColor: Colors.white,
                  );
                },
              ),
              belowBarData: BarAreaData(
                show: true,
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: UptizmChartTheme.primaryGradient,
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
    return widget.dataPoints.asMap().entries.map((entry) {
      return FlSpot(entry.key.toDouble(), entry.value.value.toDouble());
    }).toList();
  }

  double _getMaxY() {
    if (widget.dataPoints.isEmpty) return 100;
    return widget.dataPoints
        .map((p) => p.value)
        .reduce((a, b) => a > b ? a : b)
        .toDouble();
  }

  double _getMinY() {
    if (widget.dataPoints.isEmpty) return 0;
    return widget.dataPoints
        .map((p) => p.value)
        .reduce((a, b) => a < b ? a : b)
        .toDouble();
  }

  double _getGridInterval(double maxY, double minY) {
    final range = maxY - minY;
    if (range <= 50) return 10;
    if (range <= 100) return 25;
    if (range <= 500) return 100;
    return 200;
  }

  double _getTimeInterval() {
    final count = widget.dataPoints.length;
    if (count <= 5) return 1;
    if (count <= 10) return 2;
    if (count <= 20) return 4;
    return (count / 5).ceilToDouble();
  }

  String _formatTime(DateTime dt) {
    return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }

  String _formatFullTime(DateTime dt) {
    return '${dt.day}/${dt.month} ${_formatTime(dt)}';
  }
}
