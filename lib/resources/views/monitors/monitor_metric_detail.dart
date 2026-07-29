import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/monitor_metrics_controller.dart'
    show MetricSeriesPoint;
import '../../../app/enums/chart_tone.dart' show ChartTone;
import '../../../app/support/metric_types.dart'
    show MetricAnomaly, MetricDatum, MetricSeries;
import '../../../app/enums/status_key.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/metric_chart/index.dart';
import '../../../ui/components/status_dot/index.dart';
import 'monitor_metrics_support.dart';

/// **The Metric Detail BottomSheet body.**
///
/// Displays a full historical view for a single custom metric: a header row
/// with label, key·path, and Edit/Delete action buttons (Delete routes through
/// a [ConfirmDialog]); the latest reading in large monospace, banded by the band
/// the backend froze when it was recorded; a [MetricChart] of the metric's real
/// series; and a "Recent readings" list of the 6 newest readings.
///
/// ### Everything here is a recorded reading
///
/// [onLoadSeries] fetches `GET /monitors/:id/metrics/:metricId/series` on mount,
/// and the sheet renders one of three honest states: loading, no readings yet, or
/// the real series.
///
/// It previously synthesised all of it: [chartData] generated 24 points as
/// `base + sin(i / 3) * base * 0.18`, each was given a fabricated "learned
/// expected range" from direction-dependent multipliers, an anomaly was injected
/// at a fixed index 17 and narrated as observed, and the "latest value" was read
/// off the last fake point, so it contradicted the real reading the list showed.
///
/// ### Example
/// ```dart
/// await BottomSheet.show(
///   context,
///   body: MonitorMetricDetail(
///     metric: myMetricForm,
///     onLoadSeries: () => controller.series(monitorId, metricId),
///     onEdit: () { /* open edit sheet */ },
///     onDelete: () { /* delete metric */ },
///   ),
/// );
/// ```
@immutable
class MonitorMetricDetail extends StatefulWidget {
  /// The metric being inspected.
  final MetricForm metric;

  /// Loads this metric's recorded readings, newest last.
  ///
  /// A callback rather than a controller reference (matching the form's
  /// `onPreview`), so the sheet stays unaware of the monitor id.
  final Future<List<MetricSeriesPoint>> Function() onLoadSeries;

  /// Called when the user taps Edit.
  final VoidCallback onEdit;

  /// Called once the user confirms Delete.
  final VoidCallback onDelete;

  /// Creates a [MonitorMetricDetail].
  const MonitorMetricDetail({
    super.key,
    required this.metric,
    required this.onLoadSeries,
    required this.onEdit,
    required this.onDelete,
  });

  @override
  State<MonitorMetricDetail> createState() => _MonitorMetricDetailState();
}

class _MonitorMetricDetailState extends State<MonitorMetricDetail> {
  /// The metric's recorded readings, oldest first. Empty until the load
  /// resolves, and legitimately empty afterwards for a metric that has never
  /// extracted anything.
  List<MetricSeriesPoint> _points = const [];

  /// Whether the series fetch is still in flight.
  bool _loading = true;

  MetricForm get metric => widget.metric;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final List<MetricSeriesPoint> points = await widget.onLoadSeries();
    if (!mounted) return;

    setState(() {
      _points = points;
      _loading = false;
    });
  }

  // ---------------------------------------------------------------------------
  // Build
  // ---------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final bool isNumeric = metric.type == 'numeric';

    // The chart, the latest value and the readings list are all projections of
    // the metric's REAL recorded readings. They used to be projections of a
    // locally generated sine wave (`base + sin(i / 3) * base * 0.18`) with an
    // anomaly injected at a fixed index 17, so this sheet showed a full 24-hour
    // history, a "latest" value and a specific anomaly for a metric that might
    // have three readings and had never held any of those numbers.
    final List<MetricDatum> data = _points
        .where((MetricSeriesPoint p) => p.numericValue != null)
        .map(
          (MetricSeriesPoint p) => MetricDatum(
            label: _pointLabel(p),
            values: {'value': p.numericValue!},
          ),
        )
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        // 1. Header: label + key/path + Edit + Delete buttons.
        _buildHeader(context),
        const SizedBox(height: 16),

        // 2. Everything below depends on there being readings at all.
        ..._buildSeriesSection(data, isNumeric),
      ],
    );
  }

  /// Builds the reading-dependent sections, or an honest placeholder.
  ///
  /// Three distinct states, none of which invents data: still loading, no
  /// readings recorded yet, or the real series.
  List<Widget> _buildSeriesSection(List<MetricDatum> data, bool isNumeric) {
    if (_loading) {
      return [
        WText(
          trans('uptizm.monitors.metrics_detail_loading'),
          className: 'text-sm text-fg-muted',
        ),
      ];
    }

    if (_points.isEmpty) {
      return [
        WText(
          trans('uptizm.monitors.metrics_detail_no_readings'),
          className: 'text-sm text-fg-muted',
        ),
      ];
    }

    final MetricSeriesPoint newest = _points.last;
    final num? latest = newest.numericValue;

    return [
      // The latest reading, banded by the band the backend FROZE when it was
      // recorded, not by re-evaluating today's thresholds against old data.
      ?(latest == null
          ? null
          : _buildLatestValue(latest, _bandOf(newest), isNumeric)),
      const SizedBox(height: 16),

      // The real series. No anomaly markers: nothing detects metric anomalies,
      // so one was previously injected at a fixed index and narrated as though
      // it had been observed.
      if (isNumeric && data.length > 1) ...[
        _buildChart(data, const []),
        const SizedBox(height: 16),
      ],

      // Newest-first, from the real readings.
      _buildRecentReadings(data.reversed.take(6).toList(), isNumeric),
    ];
  }

  /// Maps a reading's frozen band to its display tone, or null when the metric
  /// carried no thresholds when the reading landed.
  StatusKey? _bandOf(MetricSeriesPoint point) => switch (point.band) {
    'critical' => StatusKey.down,
    'warn' => StatusKey.degraded,
    'ok' => StatusKey.up,
    _ => null,
  };

  /// Formats a reading's timestamp as the chart's x label.
  String _pointLabel(MetricSeriesPoint point) {
    final DateTime? at = point.recordedAt?.toLocal();
    if (at == null) return '';

    final String hh = at.hour.toString().padLeft(2, '0');
    final String mm = at.minute.toString().padLeft(2, '0');

    return '$hh:$mm';
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
              onPressed: widget.onEdit,
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
  Widget _buildLatestValue(num latest, StatusKey? band, bool isNumeric) {
    final String valueText = switch (isNumeric) {
      true => fmt(latest, metric.unit),
      false => metric.type == 'status' ? 'operational' : 'ok',
    };

    return WDiv(
      // items-end bottom-aligns the dot + meta label against the large value's
      // baseline (replacing the old per-child bottom-padding nudges).
      className: 'flex flex-row items-end gap-2',
      children: [
        // Only when the reading carried a frozen band; an unbanded reading
        // shows no dot rather than a green one.
        ?(isNumeric && band != null
            ? StatusDot(band, size: StatusDotSize.lg)
            : null),
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
    if (confirmed) widget.onDelete();
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
