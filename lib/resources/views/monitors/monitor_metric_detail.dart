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
  /// Shown in place of a value when a reading exists but carries no value
  /// for the metric's declared type (e.g. an extraction rule that failed on
  /// that particular check).
  static const String _noReading = '—';

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

    // The hero value is the newest point's real reading for the metric's
    // declared type: a status/string metric has no `numericValue`, so it
    // reads its own field instead of the numeric one.
    final String? latestText = switch (metric.type) {
      'status' => newest.statusValue,
      'string' => newest.stringValue,
      _ => newest.numericValue == null
          ? null
          : fmt(newest.numericValue!, metric.unit),
    };

    return [
      // The latest reading, banded by the band the backend FROZE when it was
      // recorded, not by re-evaluating today's thresholds against old data.
      //
      // A point with no reading for the declared type still renders the hero,
      // showing [_noReading] the way the table rows below do. Dropping the
      // block instead read as "this metric has never been read", which the
      // no-readings empty state above already says and this case contradicts.
      _buildLatestValue(latestText ?? _noReading, _bandOf(newest)),
      const SizedBox(height: 16),

      // The real series. No anomaly markers: nothing detects metric anomalies,
      // so one was previously injected at a fixed index and narrated as though
      // it had been observed. A non-numeric metric has no chart at all: a
      // string or status reading cannot sit on a y-axis, so the frame is
      // absent rather than rendered empty.
      if (isNumeric && data.length > 1) ...[
        _buildChart(data, const []),
        const SizedBox(height: 16),
      ],

      // Newest-first, from the RAW readings (not the numeric-filtered `data`),
      // so a string/status metric's real readings show up here too.
      _buildRecentReadings(_points.reversed.take(6).toList()),
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
  ///
  /// [valueText] is the newest reading's REAL value, already formatted for
  /// its type (numeric via [fmt], status/string as-is); this widget never
  /// fabricates one.
  Widget _buildLatestValue(String valueText, StatusKey? band) {
    return WDiv(
      // items-end bottom-aligns the dot + meta label against the large value's
      // baseline (replacing the old per-child bottom-padding nudges).
      className: 'flex flex-row items-end gap-2',
      children: [
        // Only when the reading carried a frozen band; an unbanded reading
        // shows no dot rather than a green one. Not gated on the metric being
        // numeric: a string metric bands by value-list membership now, so that
        // gate hid the band on the readings this feature exists to flag.
        ?(band == null ? null : StatusDot(band, size: StatusDotSize.lg)),
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
  ///
  /// [readings] are the RAW points (not the numeric-filtered `data`), so a
  /// string/status metric's real readings appear here too.
  Widget _buildRecentReadings(List<MetricSeriesPoint> readings) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WText(
          trans('uptizm.monitors.metrics_recent_readings').toUpperCase(),
          className: 'text-fg-muted text-xs font-medium tracking-wide',
        ),
        // The dots below are FROZEN verdicts. Without saying so, an operator who
        // has just fixed a misconfigured value list reads a red history as the
        // new configuration still failing, when it is the old one preserved.
        WText(
          trans('uptizm.monitors.metrics_recent_readings_frozen_note'),
          className: 'text-fg-muted text-xs',
        ),
        ...readings.asMap().entries.map((
          MapEntry<int, MetricSeriesPoint> entry,
        ) {
          final bool isLast = entry.key == readings.length - 1;
          return _buildReadingRow(entry.value, isLast);
        }),
      ],
    );
  }

  /// Builds one row in the "Recent readings" list.
  ///
  /// The row carries a hairline bottom border on every entry except the last,
  /// mirroring the React `last:border-b-0` pattern.
  Widget _buildReadingRow(MetricSeriesPoint point, bool isLast) {
    final num? rv = point.numericValue;

    // The band the backend FROZE on this reading, exactly like the hero above.
    // This row used to re-evaluate it client-side against today's thresholds
    // for a numeric metric and fall back to `up` for every other type, so one
    // sheet could show a frozen `critical` hero over rows claiming `ok`, and a
    // string reading was always green whatever it said.
    final StatusKey? rb = _bandOf(point);

    // Every branch is the point's REAL reading for the metric's declared
    // type, never a fabricated word.
    final String valueText = switch (metric.type) {
      'status' => point.statusValue ?? _noReading,
      'string' => point.stringValue ?? _noReading,
      _ => rv == null ? _noReading : fmt(rv, metric.unit),
    };

    return WDiv(
      className: isLast
          ? 'flex flex-row items-center justify-between py-2'
          : 'flex flex-row items-center justify-between py-2 border-b border-color-border',
      children: [
        WText(
          _pointLabel(point),
          className: 'text-fg-muted font-mono text-xs tabular-nums',
        ),
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            ?(rb == null ? null : StatusDot(rb, size: StatusDotSize.sm)),
            WText(
              valueText,
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
