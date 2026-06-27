import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/metrics.dart';
import 'metric_chart.recipe.dart';

/// Series stroke width in logical pixels. Chart geometry literal (PORTING.md §1
/// documented exception): geometry is not a color decision, so it does not pass
/// through the token system.
const double _seriesStrokeWidth = 2.0;

/// Band bounding-line stroke width. The band itself is the filled area between
/// the two bounding lines; the lines are kept hairline.
const double _bandStrokeWidth = 1.0;

/// Dash pattern for the band bounding lines (mirrors the React `3 3` dasharray).
const List<int> _bandDashArray = [3, 3];

/// Fill opacity of the AI expected-range band behind the series.
const double _bandFillOpacity = 0.08;

/// Anomaly dot radius and its halo stroke width, in logical pixels.
const double _anomalyDotRadius = 4.0;
const double _anomalyDotStroke = 2.0;

/// Default chart canvas height, mobile-first. Width is responsive (the chart
/// fills its parent constraints).
const double _defaultHeight = 240.0;

/// **The Monitoring Metric Time-Series Chart**
///
/// A multi-series line chart rebuilt on `fl_chart` from the design's
/// Recharts `MetricChart`. Each [MetricSeries] becomes one `LineChartBarData`
/// colored by its [ChartTone]; an optional AI-learned expected range renders as
/// a soft band ([BetweenBarsData]) behind the series, and AI-flagged anomalies
/// appear as `down`-toned dots on top.
///
/// ### Color discipline
/// `fl_chart` needs concrete `Color` values, so tone resolution is the single
/// documented dynamic-color exception (see [metricChartToneColor]). Every other
/// surface, the card chrome, uses Wind className tokens via [metricChartRecipe].
///
/// ### Example Usage:
/// ```dart
/// MetricChart(
///   data: apiResponseSeries,
///   series: apiResponseSeries_,
///   anomalies: apiResponseAnomalies,
///   unit: 'ms',
///   band: 'band',
/// )
/// ```
@immutable
class MetricChart extends StatelessWidget {
  /// X-axis ordered data points. Each [MetricDatum] carries one numeric value
  /// per series [MetricSeries.key] plus an optional `(low, high)` band.
  final List<MetricDatum> data;

  /// Series descriptors: which keys to plot and in which [ChartTone].
  final List<MetricSeries> series;

  /// Unit suffix shown in the tooltip, e.g. `"ms"` or `"%"`.
  final String? unit;

  /// When non-null and any datum carries a [MetricDatum.band], the AI-learned
  /// expected range is drawn as a soft band behind the series. The string is a
  /// label only (the React contract passes the data key here); the band values
  /// come from [MetricDatum.band].
  final String? band;

  /// Points the AI flagged as anomalous; each is marked with a `down`-toned dot.
  final List<MetricAnomaly>? anomalies;

  /// Canvas height; defaults to a mobile-first 240 logical pixels.
  final double height;

  /// Creates a [MetricChart] for the given [data] and [series] descriptors.
  const MetricChart({
    super.key,
    required this.data,
    required this.series,
    this.unit,
    this.band,
    this.anomalies,
    this.height = _defaultHeight,
  });

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the effective brightness (WindTheme mode first, platform
    //    brightness as the fallback) so tone -> Color tracks the active theme.
    final brightness =
        WindTheme.maybeDataOf(context)?.brightness ??
        MediaQuery.platformBrightnessOf(context);

    // 2. Assemble the chart bars: series, then band bounding lines, then the
    //    anomaly overlay. Order is deterministic so the band indices are stable.
    final seriesBars = _buildSeriesBars(brightness);
    final bandBars = _buildBandBars(brightness);
    final anomalyBar = _buildAnomalyBar(brightness);

    final lineBars = <LineChartBarData>[
      ...seriesBars,
      ...bandBars,
      ?anomalyBar,
    ];

    // 3. The band fills the area between its two bounding lines (low, high).
    final betweenBars = bandBars.length == 2
        ? [
            BetweenBarsData(
              fromIndex: seriesBars.length,
              toIndex: seriesBars.length + 1,
              color: metricChartToneColor(
                ChartTone.ai,
                brightness,
              ).withValues(alpha: _bandFillOpacity),
            ),
          ]
        : const <BetweenBarsData>[];

    // 4. Compose the fl_chart canvas inside the token-driven card chrome.
    return WDiv(
      className: metricChartRecipe(),
      children: [
        SizedBox(
          height: height,
          child: LineChart(
            LineChartData(
              lineBarsData: lineBars,
              betweenBarsData: betweenBars,
              minX: 0,
              maxX: (data.length - 1).toDouble(),
              gridData: _gridData(brightness),
              titlesData: _titlesData(brightness),
              borderData: FlBorderData(show: false),
              lineTouchData: _touchData(brightness),
            ),
          ),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Private builders
  // ---------------------------------------------------------------------------

  /// One [LineChartBarData] per [MetricSeries], colored by its resolved tone.
  List<LineChartBarData> _buildSeriesBars(Brightness brightness) {
    return [
      for (final s in series)
        LineChartBarData(
          spots: [
            for (var i = 0; i < data.length; i++)
              FlSpot(i.toDouble(), (data[i].values[s.key] ?? 0).toDouble()),
          ],
          isCurved: true,
          color: metricChartToneColor(s.tone, brightness),
          barWidth: _seriesStrokeWidth,
          dotData: const FlDotData(show: false),
          belowBarData: BarAreaData(
            show: true,
            color: metricChartToneColor(
              s.tone,
              brightness,
            ).withValues(alpha: 0.12),
          ),
        ),
    ];
  }

  /// The two bounding lines (low, high) for the AI expected-range band.
  ///
  /// Returns an empty list when no band is requested or the data carries none.
  List<LineChartBarData> _buildBandBars(Brightness brightness) {
    if (band == null) return const [];
    final hasBand = data.any((d) => d.band != null);
    if (!hasBand) return const [];

    // Dashed AI-toned hairlines bound the band; the soft fill between them is
    // the BetweenBarsData added by the caller.
    final lineColor = metricChartToneColor(
      ChartTone.ai,
      brightness,
    ).withValues(alpha: 0.35);
    LineChartBarData boundingLine(double Function(MetricDatum) pick) {
      return LineChartBarData(
        spots: [
          for (var i = 0; i < data.length; i++)
            FlSpot(i.toDouble(), pick(data[i])),
        ],
        isCurved: true,
        color: lineColor,
        barWidth: _bandStrokeWidth,
        dashArray: _bandDashArray,
        dotData: const FlDotData(show: false),
      );
    }

    return [
      boundingLine((d) => (d.band?.$1 ?? 0).toDouble()),
      boundingLine((d) => (d.band?.$2 ?? 0).toDouble()),
    ];
  }

  /// A scatter-style overlay bar carrying only the anomaly dots, or `null` when
  /// there are no anomalies. The dots are drawn in the `down` tone.
  LineChartBarData? _buildAnomalyBar(Brightness brightness) {
    final points = anomalies;
    if (points == null || points.isEmpty) return null;

    final spots = <FlSpot>[];
    for (final a in points) {
      final x = data.indexWhere((d) => d.label == a.x);
      if (x < 0) continue;
      spots.add(FlSpot(x.toDouble(), a.y.toDouble()));
    }
    if (spots.isEmpty) return null;

    final dotColor = metricChartAnomalyColor(brightness);
    // The halo matches the canvas surface so the dot reads as lifted off the
    // line, mirroring the React source's `stroke="var(--color-bg)"`.
    final haloColor = _surfaceColor(brightness);

    return LineChartBarData(
      spots: spots,
      // barWidth 0 makes this a dot-only overlay (no connecting line). This is
      // the discriminator metricChartBarIsSeries uses to exclude it.
      barWidth: 0,
      color: dotColor.withValues(alpha: 0.0),
      dotData: FlDotData(
        show: true,
        getDotPainter: (spot, percent, bar, index) => FlDotCirclePainter(
          radius: _anomalyDotRadius,
          color: dotColor,
          strokeWidth: _anomalyDotStroke,
          strokeColor: haloColor,
        ),
      ),
    );
  }

  /// Horizontal-only dashed grid in the border tone (resolved for the chart).
  FlGridData _gridData(Brightness brightness) {
    return FlGridData(
      show: true,
      drawVerticalLine: false,
      getDrawingHorizontalLine: (value) => FlLine(
        color: _axisColor(brightness).withValues(alpha: 0.25),
        strokeWidth: 1,
        dashArray: _bandDashArray,
      ),
    );
  }

  /// X-axis tick labels (every Nth datum) and a compact Y axis.
  FlTitlesData _titlesData(Brightness brightness) {
    final axisColor = _axisColor(brightness);
    final labelStyle = TextStyle(color: axisColor, fontSize: 11);

    return FlTitlesData(
      topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
      rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
      leftTitles: AxisTitles(
        sideTitles: SideTitles(
          showTitles: true,
          reservedSize: 40,
          getTitlesWidget: (value, meta) =>
              Text(value.toInt().toString(), style: labelStyle),
        ),
      ),
      bottomTitles: AxisTitles(
        sideTitles: SideTitles(
          showTitles: true,
          reservedSize: 24,
          interval: _bottomTickInterval(),
          getTitlesWidget: (value, meta) {
            final i = value.round();
            if (i < 0 || i >= data.length) return const SizedBox.shrink();
            return Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(data[i].label, style: labelStyle),
            );
          },
        ),
      ),
    );
  }

  /// Touch tooltip: one line per series at the touched x, value + unit suffix.
  LineTouchData _touchData(Brightness brightness) {
    final tooltipText = _axisColor(brightness);

    return LineTouchData(
      handleBuiltInTouches: true,
      touchTooltipData: LineTouchTooltipData(
        getTooltipColor: (spot) => _surfaceColor(brightness),
        getTooltipItems: (touchedSpots) {
          return [
            for (final spot in touchedSpots)
              if (_isSeriesBar(spot.bar))
                LineTooltipItem(
                  '${spot.y.toStringAsFixed(0)}${unit ?? ''}',
                  TextStyle(color: tooltipText, fontSize: 12),
                )
              else
                null,
          ];
        },
      ),
    );
  }

  /// X tick interval so the axis never crowds on a narrow phone screen.
  double _bottomTickInterval() {
    final n = data.length;
    if (n <= 6) return 1;
    return (n / 6).ceilToDouble();
  }

  bool _isSeriesBar(LineChartBarData bar) => metricChartBarIsSeries(bar);

  /// Muted axis/grid color. Resolved here (chart needs a Color); kept tonal.
  Color _axisColor(Brightness brightness) {
    return brightness == Brightness.dark
        ? const Color(0xFF999FA6)
        : const Color(0xFF79828A);
  }

  /// Tooltip surface color. Resolved here (chart needs a Color); tonal neutral.
  Color _surfaceColor(Brightness brightness) {
    return brightness == Brightness.dark
        ? const Color(0xFF23272B)
        : const Color(0xFFFFFFFF);
  }
}

/// True when [bar] is a plotted [MetricSeries] line (not a band bounding line
/// or the anomaly overlay).
///
/// Series bars are the only bars with the series stroke width and no dash
/// pattern; band lines are dashed hairlines and the anomaly overlay has zero
/// width. Tests use this to assert the series count stays 1:1 with input.
bool metricChartBarIsSeries(LineChartBarData bar) {
  return bar.barWidth == _seriesStrokeWidth && bar.dashArray == null;
}
