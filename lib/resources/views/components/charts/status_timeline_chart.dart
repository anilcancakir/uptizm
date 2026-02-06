import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/analytics_series.dart';

class StatusTimelineChart extends StatelessWidget {
  final AnalyticsSeries statusSeries;
  final double height;
  final bool showPercentage;

  const StatusTimelineChart({
    super.key,
    required this.statusSeries,
    this.height = 120,
    this.showPercentage = false,
  });

  @override
  Widget build(BuildContext context) {
    if (statusSeries.isEmpty) {
      return WDiv(
        className: 'h-[${height.toInt()}px] flex items-center justify-center',
        child: WText(
          trans('analytics.no_data'),
          className: 'text-gray-500 dark:text-gray-400 italic',
        ),
      );
    }

    return SizedBox(height: height, child: BarChart(_computeChartData()));
  }

  BarChartData _computeChartData() {
    final spots = statusSeries.dataPoints;

    return BarChartData(
      gridData: const FlGridData(show: false),
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
            getTitlesWidget: (value, meta) {
              if (value.toInt() < 0 || value.toInt() >= spots.length) {
                return const SizedBox.shrink();
              }
              // Show fewer labels to avoid overlapping
              if (spots.length > 10 &&
                  value.toInt() % (spots.length ~/ 5) != 0) {
                return const SizedBox.shrink();
              }

              final date = spots[value.toInt()].timestamp;
              String text = DateFormat('HH:mm').format(date);

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
        leftTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
      ),
      borderData: FlBorderData(show: false),
      barGroups: spots.asMap().entries.map((entry) {
        final index = entry.key;
        final point = entry.value;
        final up = (point.upCount ?? 0).toDouble();
        final down = (point.downCount ?? 0).toDouble();

        return BarChartGroupData(
          x: index,
          barRods: [
            BarChartRodData(
              toY: up + down,
              width: 12, // Fixed width bars
              color: Colors.transparent,
              rodStackItems: [
                BarChartRodStackItem(
                  0,
                  up,
                  const Color(0xFF009E60),
                ), // Green for Up
                BarChartRodStackItem(
                  up,
                  up + down,
                  const Color(0xFFEF4444),
                ), // Red for Down
              ],
              borderRadius: BorderRadius.circular(2),
            ),
          ],
        );
      }).toList(),
      barTouchData: BarTouchData(
        touchTooltipData: BarTouchTooltipData(
          fitInsideHorizontally: true,
          fitInsideVertically: true,
          getTooltipItem: (group, groupIndex, rod, rodIndex) {
            final point = spots[group.x.toInt()];
            final up = point.upCount ?? 0;
            final down = point.downCount ?? 0;
            final total = point.total ?? (up + down);
            final percent = total > 0
                ? (up / total * 100).toStringAsFixed(1)
                : '0';

            return BarTooltipItem(
              '${DateFormat('HH:mm').format(point.timestamp)}\n',
              const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.bold,
                fontSize: 12,
              ),
              children: [
                TextSpan(
                  text: 'Up: $up\nDown: $down\nUptime: $percent%',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 12,
                    fontWeight: FontWeight.normal,
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}
