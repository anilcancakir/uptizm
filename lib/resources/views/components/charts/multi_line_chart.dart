import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/analytics_series.dart';

class MultiLineChart extends StatefulWidget {
  final List<AnalyticsSeries> series;
  final double height;
  final bool showLegend;
  final bool showTooltip;
  final bool showGrid;

  const MultiLineChart({
    super.key,
    required this.series,
    this.height = 300,
    this.showLegend = true,
    this.showTooltip = true,
    this.showGrid = true,
  });

  @override
  State<MultiLineChart> createState() => _MultiLineChartState();
}

class _MultiLineChartState extends State<MultiLineChart> {
  static const chartColors = [
    Color(0xFF009E60), // Primary - first metric
    Color(0xFF3B82F6), // Info blue
    Color(0xFFF59E0B), // Warning amber
    Color(0xFF8B5CF6), // Purple
    Color(0xFFEC4899), // Pink
  ];

  @override
  Widget build(BuildContext context) {
    if (widget.series.isEmpty || widget.series.every((s) => s.isEmpty)) {
      return WDiv(
        className:
            'h-[${widget.height.toInt()}px] flex items-center justify-center',
        child: WText(
          trans('analytics.no_data'),
          className: 'text-gray-500 dark:text-gray-400 italic',
        ),
      );
    }

    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        if (widget.showLegend) _buildLegend(),
        WDiv(
          className: 'h-[${widget.height.toInt()}px]',
          child: LineChart(_computeChartData()),
        ),
      ],
    );
  }

  Widget _buildLegend() {
    return WDiv(
      className: 'wrap gap-4',
      children: widget.series.asMap().entries.map((entry) {
        final index = entry.key;
        final series = entry.value;
        final color = chartColors[index % chartColors.length];

        return WDiv(
          className: 'flex flex-row items-center min-w-fit',
          children: [
            // Color dot - using DecoratedBox for dynamic color
            DecoratedBox(
              decoration: BoxDecoration(color: color, shape: BoxShape.circle),
              child: const SizedBox(width: 12, height: 12),
            ),
            const WSpacer(className: 'w-1.5'),
            WText(
              series.metricLabel,
              className: 'text-sm font-medium text-gray-700 dark:text-gray-300',
            ),
            if (series.unit != null)
              WText(
                ' (${series.unit})',
                className: 'text-xs text-gray-500 ml-1',
              ),
          ],
        );
      }).toList(),
    );
  }

  LineChartData _computeChartData() {
    final allSpots = widget.series.expand((s) => s.toChartSpots()).toList();

    if (allSpots.isEmpty) return LineChartData();

    final minX = allSpots.map((e) => e.x).reduce((a, b) => a < b ? a : b);
    final maxX = allSpots.map((e) => e.x).reduce((a, b) => a > b ? a : b);
    final minY = 0.0; // Always start from 0 for better visualization usually
    // Add some padding to maxY
    final maxYData = allSpots.map((e) => e.y).reduce((a, b) => a > b ? a : b);
    final maxY = maxYData * 1.2;

    double interval = (maxX - minX) / 5;
    if (interval <= 0) interval = 1.0;

    return LineChartData(
      gridData: FlGridData(
        show: widget.showGrid,
        drawVerticalLine: false,
        horizontalInterval: maxY > 0 ? maxY / 5 : 1,
        getDrawingHorizontalLine: (value) =>
            FlLine(color: Colors.grey.withValues(alpha: 0.1), strokeWidth: 1),
      ),
      titlesData: FlTitlesData(
        show: true,
        rightTitles: const AxisTitles(
          sideTitles: SideTitles(showTitles: false),
        ),
        topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
        bottomTitles: AxisTitles(
          sideTitles: SideTitles(
            showTitles: true,
            reservedSize: 30,
            interval: interval, // Show roughly 5 labels
            getTitlesWidget: (value, meta) {
              if (value < minX || value > maxX) return const SizedBox.shrink();
              final date = DateTime.fromMillisecondsSinceEpoch(value.toInt());
              final duration = DateTime.fromMillisecondsSinceEpoch(
                maxX.toInt(),
              ).difference(DateTime.fromMillisecondsSinceEpoch(minX.toInt()));

              String text;
              if (duration.inDays > 7) {
                text = DateFormat('MM/dd').format(date);
              } else if (duration.inDays > 1) {
                text = DateFormat('E').format(date);
              } else {
                text = DateFormat('HH:mm').format(date);
              }

              return Padding(
                padding: const EdgeInsets.only(top: 8.0),
                child: Text(
                  text,
                  style: const TextStyle(
                    color: Color(0xff68737d),
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
            getTitlesWidget: (value, meta) {
              return Text(
                value.toInt().toString(),
                style: const TextStyle(color: Color(0xff67727d), fontSize: 10),
                textAlign: TextAlign.left,
              );
            },
          ),
        ),
      ),
      borderData: FlBorderData(show: false),
      minX: minX,
      maxX: maxX,
      minY: minY,
      maxY: maxY,
      lineBarsData: widget.series.asMap().entries.map((entry) {
        final index = entry.key;
        final series = entry.value;
        final color = chartColors[index % chartColors.length];

        return LineChartBarData(
          spots: series.toChartSpots(),
          isCurved: true,
          color: color,
          barWidth: 2,
          isStrokeCapRound: true,
          dotData: const FlDotData(show: false),
          belowBarData: BarAreaData(
            show: true,
            color: color.withValues(alpha: 0.1),
          ),
        );
      }).toList(),
      lineTouchData: LineTouchData(
        enabled: widget.showTooltip,
        touchTooltipData: LineTouchTooltipData(
          fitInsideHorizontally: true,
          fitInsideVertically: true,
          getTooltipItems: (touchedSpots) {
            return touchedSpots.map((spot) {
              final seriesIndex = spot.barIndex;
              final series = widget.series[seriesIndex];
              return LineTooltipItem(
                '${series.metricLabel}: ${spot.y.toStringAsFixed(1)}${series.unit ?? ''}',
                TextStyle(
                  color: chartColors[seriesIndex % chartColors.length],
                  fontWeight: FontWeight.bold,
                ),
              );
            }).toList();
          },
        ),
      ),
    );
  }
}
