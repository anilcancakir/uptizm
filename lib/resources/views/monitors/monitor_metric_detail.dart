import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/metrics.dart';
import '../../../app/mocks/status.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/metric_chart/index.dart';
import '../../../ui/components/status_dot/index.dart';
import 'monitor_metrics_support.dart';

/// **The Metric Detail BottomSheet body.**
///
/// Displays a full historical view for a single custom metric: a header row
/// with label, key·path, and Edit/Delete action buttons (Delete routes through
/// a [ConfirmDialog]); the latest value in large monospace with a [StatusDot]
/// for numeric metrics; a [MetricChart] at height 180 with the AI-learned band
/// and an injected anomaly for numeric metrics; and a "Recent readings" list
/// showing the 6 most-recent hourly readings in reverse chronological order.
///
/// ### Band + anomaly computation
///
/// This widget owns the band multiplier + anomaly injection (mirroring React
/// `DetailBody` lines 505-521). It takes the raw 24-point [chartData] from
/// [monitor_metrics_support.dart] and:
///
/// 1. Augments each [MetricDatum] with a `band` tuple using direction-dependent
///    multipliers (`'low'` → 0.78/1.14, else → 0.86/1.18).
/// 2. Replaces the value at index 17 with a spike outside the band
///    (`'low'` → `band[0] * 0.6`, else → `band[1] * 1.45`) and emits it
///    as the single [MetricAnomaly].
///
/// Use this widget as the `body` of a [BottomSheet.show] call from the
/// Metrics tab, or render it statically in tests.
///
/// ### Example
/// ```dart
/// await BottomSheet.show(
///   context,
///   body: MonitorMetricDetail(
///     metric: myMetricForm,
///     onEdit: () { /* open edit sheet */ },
///     onDelete: () { /* delete metric */ },
///   ),
/// );
/// ```
@immutable
class MonitorMetricDetail extends StatelessWidget {
  /// The metric whose history to display.
  final MetricForm metric;

  /// Called when the user taps the Edit button.
  final VoidCallback onEdit;

  /// Called after the user confirms deletion via [ConfirmDialog].
  final VoidCallback onDelete;

  /// Creates a [MonitorMetricDetail].
  const MonitorMetricDetail({
    super.key,
    required this.metric,
    required this.onEdit,
    required this.onDelete,
  });

  // ---------------------------------------------------------------------------
  // Band + anomaly augmentation
  // ---------------------------------------------------------------------------

  /// Augments the raw [chartData] output with direction-dependent band
  /// multipliers and injects a single anomaly at index 17.
  ///
  /// Band multipliers (React `DetailBody` lines 506-507):
  /// - direction `'low'`: lowMul = 0.78, highMul = 1.14
  /// - otherwise: lowMul = 0.86, highMul = 1.18
  ///
  /// Anomaly spike (React lines 515-521):
  /// - `'low'`: spike = `band[0] * 0.6` (drops below the low bound).
  /// - otherwise: spike = `band[1] * 1.45` (spikes above the high bound).
  ({List<MetricDatum> data, List<MetricAnomaly> anomalies}) _buildAugmented() {
    final bool isLow = metric.direction == 'low';
    final double lowMul = isLow ? 0.78 : 0.86;
    final double highMul = isLow ? 1.14 : 1.18;

    // 1. Augment every datum with the band tuple.
    final List<MetricDatum> raw = chartData(metric);
    final List<MetricDatum> data = raw.map((MetricDatum d) {
      final num v = d.values['value']!;
      final num lo = (v * lowMul * 10).round() / 10;
      final num hi = (v * highMul * 10).round() / 10;
      return MetricDatum(label: d.label, values: d.values, band: (lo, hi));
    }).toList();

    // 2. Compute the anomaly spike from the band at index 17.
    final (num lo17, num hi17) = data[17].band!;
    final num spike = isLow
        ? (lo17 * 0.6 * 10).round() / 10
        : (hi17 * 1.45 * 10).round() / 10;

    // 3. Replace the value at index 17 with the spike so the anomaly is visible.
    data[17] = MetricDatum(
      label: data[17].label,
      values: {'value': spike},
      band: data[17].band,
    );

    return (
      data: data,
      anomalies: [MetricAnomaly(x: data[17].label, y: spike)],
    );
  }

  // ---------------------------------------------------------------------------
  // Build
  // ---------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final bool isNumeric = metric.type == 'numeric';

    // 1. Augment data with band + anomaly (the band/anomaly computation is
    //    only rendered for numeric metrics but is always computed for clarity).
    final augmented = _buildAugmented();
    final List<MetricDatum> data = augmented.data;
    final List<MetricAnomaly> anomalies = augmented.anomalies;

    // 2. Derive the latest value and health band from the augmented series.
    final num latest = data.last.values['value']!;
    final StatusKey latestBand = isNumeric
        ? bandOf(latest, metric.warn, metric.critical, metric.direction)
        : StatusKey.up;

    // 3. Build the 6 most-recent readings (last-6, reversed to newest-first).
    final List<MetricDatum> readings = data.reversed.take(6).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        // 4. Header: label + key/path + Edit + Delete buttons.
        _buildHeader(context),
        const SizedBox(height: 16),

        // 5. Latest value with optional StatusDot.
        _buildLatestValue(latest, latestBand, isNumeric),
        const SizedBox(height: 16),

        // 6. Numeric-only: AI-band time-series chart + the band-explanation
        //    insight (mirrors React DetailBody: the chart sits above an AiInsight
        //    that narrates the learned expected range and the one anomaly).
        if (isNumeric) ...[
          _buildChart(data, anomalies),
          const SizedBox(height: 12),
          _buildBandInsight(anomalies),
          const SizedBox(height: 16),
        ],

        // 7. Recent readings list.
        _buildRecentReadings(readings, isNumeric),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Header
  // ---------------------------------------------------------------------------

  /// Builds the title row: label + key/path on the left, Edit/Delete on the
  /// right.
  Widget _buildHeader(BuildContext context) {
    return WDiv(
      className: 'flex flex-row items-start gap-3',
      children: [
        // Label + key·path (flex-1 takes the remaining width; min-w-0 lets the
        // mono key·path truncate instead of overflowing the row).
        WDiv(
          className: 'flex flex-col min-w-0 flex-1',
          children: [
            WText(
              metric.label,
              className: 'text-fg text-base font-semibold truncate',
            ),
            WText(
              _keyPath(metric),
              className: 'text-fg-muted text-xs font-mono truncate',
            ),
          ],
        ),

        // Action buttons: Edit (secondary) + Delete (ghost → ConfirmDialog).
        WDiv(
          className: 'flex flex-row gap-2 shrink-0',
          children: [
            MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: onEdit,
              child: WText(trans('uptizm.monitors.action_edit')),
            ),
            MSButton(
              intent: ButtonIntent.ghost,
              size: ButtonSize.sm,
              onPressed: () => _confirmDelete(context),
              child: WText(trans('uptizm.monitors.action_delete')),
            ),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Latest value
  // ---------------------------------------------------------------------------

  /// Builds the latest-value row: optional [StatusDot] + large mono value +
  /// "latest · last 24h" label.
  Widget _buildLatestValue(num latest, StatusKey band, bool isNumeric) {
    final String valueText = switch (isNumeric) {
      true => fmt(latest, metric.unit),
      false => metric.type == 'status' ? 'operational' : 'ok',
    };

    return WDiv(
      // items-end bottom-aligns the dot + meta label against the large value's
      // baseline (replacing the old per-child bottom-padding nudges).
      className: 'flex flex-row items-end gap-2',
      children: [
        if (isNumeric) StatusDot(band, size: StatusDotSize.lg),
        WText(
          valueText,
          className: 'text-fg font-mono text-3xl font-semibold tabular-nums',
        ),
        WText(
          trans('uptizm.monitors.metrics_detail_latest'),
          className: 'text-fg-muted text-xs',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Chart
  // ---------------------------------------------------------------------------

  /// Builds the band-explanation [AiInsight] shown under the chart.
  ///
  /// Mirrors React `DetailBody`: narrates the learned expected range and the
  /// single injected anomaly, with the direction-specific phrase
  /// (`'low'` → "dropping below", else "spiking above").
  Widget _buildBandInsight(List<MetricAnomaly> anomalies) {
    final String time = anomalies.isNotEmpty ? anomalies.first.x : '';
    final String phrase = metric.direction == 'low'
        ? trans('uptizm.monitors.metrics_detail_band_drop')
        : trans('uptizm.monitors.metrics_detail_band_spike');
    return AiInsight(
      child: WText(
        trans('uptizm.monitors.metrics_detail_band_insight', {
          'label': metric.label,
          'time': time,
          'phrase': phrase,
        }),
      ),
    );
  }

  /// Builds the [MetricChart] with the AI-learned band and anomaly overlay.
  Widget _buildChart(List<MetricDatum> data, List<MetricAnomaly> anomalies) {
    return MetricChart(
      data: data,
      series: [
        MetricSeries(
          key: 'value',
          label: metric.label,
          tone: ChartTone.primary,
        ),
      ],
      unit: kUnitSuffix[metric.unit],
      band: 'band',
      anomalies: anomalies,
      height: 180,
    );
  }

  // ---------------------------------------------------------------------------
  // Recent readings
  // ---------------------------------------------------------------------------

  /// Builds the "Recent readings" section header and row list.
  Widget _buildRecentReadings(List<MetricDatum> readings, bool isNumeric) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WText(
          trans('uptizm.monitors.metrics_recent_readings').toUpperCase(),
          className: 'text-fg-muted text-xs font-medium tracking-wide',
        ),
        ...readings.asMap().entries.map((MapEntry<int, MetricDatum> entry) {
          final bool isLast = entry.key == readings.length - 1;
          return _buildReadingRow(entry.value, isNumeric, isLast);
        }),
      ],
    );
  }

  /// Builds one row in the "Recent readings" list.
  ///
  /// The row carries a hairline bottom border on every entry except the last,
  /// mirroring the React `last:border-b-0` pattern.
  Widget _buildReadingRow(MetricDatum datum, bool isNumeric, bool isLast) {
    final num rv = datum.values['value']!;
    final StatusKey rb = isNumeric
        ? bandOf(rv, metric.warn, metric.critical, metric.direction)
        : StatusKey.up;

    return WDiv(
      className: isLast
          ? 'flex flex-row items-center justify-between py-2'
          : 'flex flex-row items-center justify-between py-2 border-b border-color-border',
      children: [
        WText(
          datum.label,
          className: 'text-fg-muted font-mono text-xs tabular-nums',
        ),
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            if (isNumeric) StatusDot(rb, size: StatusDotSize.sm),
            WText(
              isNumeric ? fmt(rv, metric.unit) : 'ok',
              className: 'text-fg font-mono text-sm tabular-nums',
            ),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Delete confirmation
  // ---------------------------------------------------------------------------

  /// Opens the delete [MagicStarterConfirmDialog] imperatively; calls
  /// [onDelete] when confirmed.
  Future<void> _confirmDelete(BuildContext context) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.monitors.metrics_confirm_delete_title', {
        'name': metric.label,
      }),
      description: trans('uptizm.monitors.metrics_confirm_delete_description'),
      confirmLabel: trans('uptizm.monitors.metrics_confirm_delete_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (confirmed) onDelete();
  }
}

// ---------------------------------------------------------------------------
// Private helpers
// ---------------------------------------------------------------------------

/// Formats the key and optional path for the header subtitle.
///
/// Returns `"<key> · <path>"` when [form.path] is non-empty, otherwise just
/// `"<key>"`.
String _keyPath(MetricForm form) {
  final String path = form.path.trim();
  return path.isNotEmpty ? '${form.key} · $path' : form.key;
}
