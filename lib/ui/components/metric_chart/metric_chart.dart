import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/chart_tone.dart' show ChartTone;
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

/// Radius of the active (touched) series dot. Mirrors the React source's
/// `activeDot={{ r: 3 }}`.
const double _activeDotRadius = 3.0;

/// Series area-fill gradient stops. The React source fills each series with a
/// vertical `linearGradient` running from `stopOpacity 0.25` at the top of the
/// plot to `0` at the baseline (`x1=0 y1=0 x2=0 y2=1`); these mirror that.
const double _seriesFillTopOpacity = 0.25;
const double _seriesFillBottomOpacity = 0.0;

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
    return [for (final s in series) _seriesBar(s, brightness)];
  }

  /// A single series line with a vertical area-fill gradient.
  ///
  /// The fill runs from the tone at [_seriesFillTopOpacity] near the line down
  /// to transparent at the baseline, the fl_chart equivalent of the React
  /// source's per-series top-to-bottom `linearGradient`.
  LineChartBarData _seriesBar(MetricSeries s, Brightness brightness) {
    final toneColor = metricChartToneColor(s.tone, brightness);
    return LineChartBarData(
      spots: [
        for (var i = 0; i < data.length; i++)
          FlSpot(i.toDouble(), (data[i].values[s.key] ?? 0).toDouble()),
      ],
      isCurved: true,
      color: toneColor,
      barWidth: _seriesStrokeWidth,
      dotData: const FlDotData(show: false),
      belowBarData: BarAreaData(
        show: true,
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            toneColor.withValues(alpha: _seriesFillTopOpacity),
            toneColor.withValues(alpha: _seriesFillBottomOpacity),
          ],
        ),
      ),
    );
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

  /// Horizontal-only dashed grid in the border tone.
  ///
  /// Mirrors the React `CartesianGrid` (`vertical={false}`,
  /// `stroke="var(--color-border)"`, `strokeDasharray="3 3"`): only horizontal
  /// guides, drawn as 3-3 dashes in the border tone.
  FlGridData _gridData(Brightness brightness) {
    return FlGridData(
      show: true,
      drawVerticalLine: false,
      getDrawingHorizontalLine: (value) => FlLine(
        color: _borderColor(brightness),
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

  /// Touch tooltip and cursor.
  ///
  /// Mirrors the React `Tooltip` + per-series `activeDot={{ r: 3 }}`: a border-
  /// tone cursor line, a series-toned active dot at the touched point, and one
  /// tooltip row per series (`label  value+unit`) tinted in the series tone.
  /// Band bounding lines and the anomaly overlay are excluded from both.
  LineTouchData _touchData(Brightness brightness) {
    final labelColor = _axisColor(brightness);

    return LineTouchData(
      handleBuiltInTouches: true,
      getTouchedSpotIndicator: (bar, spotIndexes) {
        // Series get a border-tone cursor line and a tone-colored r=3 dot;
        // band/anomaly bars get no indicator (return null per index).
        if (!_isSeriesBar(bar)) {
          return [for (final _ in spotIndexes) null];
        }
        final toneColor = bar.color ?? labelColor;
        return [
          for (final _ in spotIndexes)
            TouchedSpotIndicatorData(
              FlLine(color: _borderColor(brightness), strokeWidth: 1),
              FlDotData(
                getDotPainter: (spot, percent, b, index) => FlDotCirclePainter(
                  radius: _activeDotRadius,
                  color: toneColor,
                  strokeWidth: 0,
                ),
              ),
            ),
        ];
      },
      touchTooltipData: LineTouchTooltipData(
        getTooltipColor: (spot) => _surfaceColor(brightness),
        tooltipBorder: BorderSide(color: _borderColor(brightness)),
        getTooltipItems: (touchedSpots) {
          return [
            for (final spot in touchedSpots)
              if (_isSeriesBar(spot.bar))
                LineTooltipItem(
                  '${_seriesLabelFor(spot.barIndex)}  '
                  '${spot.y.toStringAsFixed(0)}${unit ?? ''}',
                  TextStyle(color: spot.bar.color ?? labelColor, fontSize: 12),
                )
              else
                null,
          ];
        },
      ),
    );
  }

  /// The legend label for the series at [barIndex] in `lineBarsData`.
  ///
  /// Series bars occupy the leading slots (band bounding lines and the anomaly
  /// overlay follow), so the descriptor index equals the bar index for any
  /// series spot.
  String _seriesLabelFor(int barIndex) {
    if (barIndex < 0 || barIndex >= series.length) return '';
    return series[barIndex].label;
  }

  /// X tick interval so the axis never crowds on a narrow phone screen.
  double _bottomTickInterval() {
    final n = data.length;
    if (n <= 6) return 1;
    return (n / 6).ceilToDouble();
  }

  bool _isSeriesBar(LineChartBarData bar) => metricChartBarIsSeries(bar);

  /// Muted axis-tick text color (delegates to the recipe-file resolver).
  Color _axisColor(Brightness brightness) => metricChartAxisColor(brightness);

  /// Border-tone color for grid lines, the touch cursor, and the tooltip edge.
  ///
  /// Mirrors `var(--color-border)` from the React source (delegates to the
  /// recipe-file resolver, which keeps it in lockstep with the
  /// `border-color-border` token).
  Color _borderColor(Brightness brightness) =>
      metricChartBorderColor(brightness);

  /// Tooltip surface color (delegates to the recipe-file resolver).
  Color _surfaceColor(Brightness brightness) =>
      metricChartSurfaceColor(brightness);
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
