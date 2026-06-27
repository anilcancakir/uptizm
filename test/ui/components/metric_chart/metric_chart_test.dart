import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/metrics.dart';
import 'package:uptizm/ui/components/metric_chart/index.dart';
import 'package:uptizm/ui/components/metric_chart/metric_chart.preview.dart';

void main() {
  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] so
  /// W-widgets and the chart can resolve their brightness without a running
  /// Magic app. A fixed-size surface keeps the chart laid out for the test.
  Widget wrap(Widget widget, {Brightness brightness = Brightness.light}) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(brightness: brightness),
        child: Scaffold(
          body: SingleChildScrollView(
            child: SizedBox(width: 400, child: widget),
          ),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // ChartTone -> Color resolver (the documented dynamic-color exception)
  // ---------------------------------------------------------------------------

  group('metricChartToneColor', () {
    test('resolves the light hex for each tone in light mode', () {
      expect(
        metricChartToneColor(ChartTone.primary, Brightness.light),
        const Color(0xFF009A6F),
      );
      expect(
        metricChartToneColor(ChartTone.up, Brightness.light),
        const Color(0xFF30A556),
      );
      expect(
        metricChartToneColor(ChartTone.info, Brightness.light),
        const Color(0xFF207FE8),
      );
      expect(
        metricChartToneColor(ChartTone.degraded, Brightness.light),
        const Color(0xFFE69825),
      );
      expect(
        metricChartToneColor(ChartTone.ai, Brightness.light),
        const Color(0xFF6E59E2),
      );
    });

    test('resolves the dark hex for each tone in dark mode', () {
      expect(
        metricChartToneColor(ChartTone.primary, Brightness.dark),
        const Color(0xFF00C292),
      );
      expect(
        metricChartToneColor(ChartTone.up, Brightness.dark),
        const Color(0xFF45C06A),
      );
      expect(
        metricChartToneColor(ChartTone.degraded, Brightness.dark),
        const Color(0xFFF5AE39),
      );
      expect(
        metricChartToneColor(ChartTone.ai, Brightness.dark),
        const Color(0xFF9E8AFA),
      );
    });

    test('anomaly color is the down red, by brightness', () {
      expect(
        metricChartAnomalyColor(Brightness.light),
        const Color(0xFFDF202E),
      );
      expect(metricChartAnomalyColor(Brightness.dark), const Color(0xFFFF645F));
    });

    test('neutral chrome colors match the border/axis/surface tokens', () {
      // Border tone is the border-color-border token (grid, cursor, tooltip edge).
      expect(metricChartBorderColor(Brightness.light), const Color(0xFFDEE2E5));
      expect(metricChartBorderColor(Brightness.dark), const Color(0xFF2A2E33));
      // Surface tone is the page surface (tooltip background + anomaly halo).
      expect(
        metricChartSurfaceColor(Brightness.light),
        const Color(0xFFF9FAFB),
      );
      expect(metricChartSurfaceColor(Brightness.dark), const Color(0xFF07090C));
      // Axis-tick text is the muted-foreground neutral.
      expect(metricChartAxisColor(Brightness.light), const Color(0xFF79828A));
      expect(metricChartAxisColor(Brightness.dark), const Color(0xFF999FA6));
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('MetricChart renders a LineChart with fixture data', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        MetricChart(
          data: apiResponseSeries,
          series: apiResponseSeries_,
          anomalies: apiResponseAnomalies,
          unit: 'ms',
        ),
      ),
    );

    expect(find.byType(LineChart), findsOneWidget);
  });

  testWidgets('MetricChart series count matches the input descriptors', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(MetricChart(data: apiResponseSeries, series: apiResponseSeries_)),
    );

    final chart = tester.widget<LineChart>(find.byType(LineChart));
    // One LineChartBarData per descriptor. Band bounding lines and the anomaly
    // overlay are tracked separately so the series count stays 1:1 with input.
    final seriesBars = chart.data.lineBarsData
        .where((bar) => metricChartBarIsSeries(bar))
        .length;
    expect(seriesBars, equals(apiResponseSeries_.length));
  });

  testWidgets('MetricChart series fill with a top-down area gradient', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(MetricChart(data: apiResponseSeries, series: apiResponseSeries_)),
    );

    final chart = tester.widget<LineChart>(find.byType(LineChart));
    final seriesBars = chart.data.lineBarsData
        .where((bar) => metricChartBarIsSeries(bar))
        .toList();

    for (final bar in seriesBars) {
      final gradient = bar.belowBarData.gradient;
      // Each series fills via a vertical LinearGradient (top opaque tone ->
      // transparent baseline), mirroring the React per-series linearGradient.
      expect(gradient, isA<LinearGradient>());
      final linear = gradient! as LinearGradient;
      expect(linear.begin, Alignment.topCenter);
      expect(linear.end, Alignment.bottomCenter);
      expect(linear.colors.first.a, greaterThan(0.0));
      expect(linear.colors.last.a, 0.0);
      // The flat solid color must be absent when a gradient is used.
      expect(bar.belowBarData.color, isNull);
    }
  });

  testWidgets('MetricChart with a band adds BetweenBarsData', (tester) async {
    await tester.pumpWidget(
      wrap(
        MetricChart(
          data: apiResponseSeries,
          series: apiResponseSeries_,
          band: 'band',
        ),
      ),
    );

    final chart = tester.widget<LineChart>(find.byType(LineChart));
    expect(chart.data.betweenBarsData, isNotEmpty);
  });

  testWidgets('MetricChart without a band has no BetweenBarsData', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        MetricChart(data: marketingResponseSeries, series: apiResponseSeries_),
      ),
    );

    final chart = tester.widget<LineChart>(find.byType(LineChart));
    expect(chart.data.betweenBarsData, isEmpty);
  });

  testWidgets('MetricChart adds an anomaly overlay bar when anomalies exist', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        MetricChart(
          data: apiResponseSeries,
          series: apiResponseSeries_,
          anomalies: apiResponseAnomalies,
        ),
      ),
    );

    final chart = tester.widget<LineChart>(find.byType(LineChart));
    final overlayBars = chart.data.lineBarsData.where(
      (bar) => !metricChartBarIsSeries(bar),
    );
    // At least the anomaly overlay (and the two band lines are absent here only
    // if no band; with a band there are also two bounding lines).
    expect(overlayBars, isNotEmpty);
  });

  testWidgets('MetricChartPreview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const MetricChartPreview()));
    await tester.pump();
    expect(find.byType(MetricChart), findsWidgets);
    expect(find.byType(LineChart), findsWidgets);
  });
}
