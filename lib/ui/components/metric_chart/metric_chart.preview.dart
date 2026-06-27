import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/metrics.dart';
import 'metric_chart.dart';

/// Static variant-matrix preview for [MetricChart].
///
/// Renders the representative chart surfaces so the catalog shows the full
/// range in light and dark: the full multi-series + band + anomaly chart, a
/// no-band variant, and a single-series chart. One preview class per file is
/// the canonical atomic-component contract.
class MetricChartPreview extends StatelessWidget {
  /// Creates the metric chart variant-matrix preview.
  const MetricChartPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        // Full: three percentile series + AI band + anomaly dot.
        _Section(
          label: 'multi-series · band · anomaly',
          child: MetricChart(
            data: apiResponseSeries,
            series: apiResponseSeries_,
            anomalies: apiResponseAnomalies,
            band: 'band',
            unit: 'ms',
          ),
        ),

        // No band: same series, expected-range band suppressed.
        _Section(
          label: 'multi-series · no band',
          child: MetricChart(
            data: marketingResponseSeries,
            series: apiResponseSeries_,
            unit: 'ms',
          ),
        ),

        // Single series: just the p50 line in the up tone.
        _Section(
          label: 'single-series · up tone',
          child: MetricChart(
            data: apiResponseSeries,
            series: const [
              MetricSeries(key: 'p50', label: 'p50', tone: ChartTone.up),
            ],
            unit: 'ms',
          ),
        ),
      ],
    );
  }
}

/// Internal preview section wrapper — label + child.
class _Section extends StatelessWidget {
  final String label;
  final Widget child;

  const _Section({required this.label, required this.child});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(label, className: 'font-mono text-xs text-fg-muted'),
        child,
      ],
    );
  }
}
