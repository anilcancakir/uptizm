import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/monitor_metrics_controller.dart'
    show MetricSeriesPoint;
import '../../../app/enums/chart_tone.dart' show ChartTone;
import '../../../app/support/metric_types.dart'
    show MetricAnomaly, MetricDatum, MetricSeries;
import '../../../app/enums/status_key.dart';
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

  /// Opens a pager over this metric's whole reading history, newest first.
  ///
  /// A factory rather than a paginator, and a callback for the same reason
  /// [onLoadSeries] is one: the paginator needs a URL, the URL needs the
  /// monitor id, and this sheet is deliberately unaware of it. The sheet owns
  /// what the factory returns and disposes it.
  ///
  /// SEPARATE FROM THE SERIES on purpose. The series is a windowed, capped
  /// slice a chart can draw; this is a history a person walks back through, and
  /// the two would fight over page size and ordering if they shared a call.
  final MagicPaginator<MetricSeriesPoint> Function() onCreateReadings;

  /// Whether this metric is one the operator can change.
  ///
  /// False for a SYSTEM metric (response time), which uptizm records on every
  /// check whether anybody asked for it or not: there is no definition to edit
  /// and nothing to delete, so offering either would be a button that cannot
  /// work. It also changes what the readings note can honestly claim; see
  /// [_buildRecentReadings].
  final bool editable;

  /// Called when the user taps Edit. Unused when [editable] is false.
  final VoidCallback onEdit;

  /// Called once the user confirms Delete.
  final VoidCallback onDelete;

  /// Creates a [MonitorMetricDetail].
  const MonitorMetricDetail({
    super.key,
    required this.metric,
    required this.onLoadSeries,
    required this.onCreateReadings,
    this.editable = true,
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

  /// The pager behind the readings table. Owned here, so disposed here.
  late final MagicPaginator<MetricSeriesPoint> _readings;

  MetricForm get metric => widget.metric;

  @override
  void initState() {
    super.initState();
    _readings = widget.onCreateReadings();
    // The list view only LISTENS; the first page is the owner's to ask for.
    // Without this the table renders its empty state forever, on a metric with
    // thousands of readings, and nothing anywhere says a request was never
    // made. `MonitorDetailView` does the same for its check history.
    unawaited(_readings.refresh());
    _load();
  }

  @override
  void dispose() {
    _readings.dispose();
    super.dispose();
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
      // An empty SERIES is not an empty history, and conflating the two hid
      // real data. The series is windowed (24h) while the readings table pages
      // the whole history, so a metric last recorded two days ago has nothing
      // to chart and plenty to list. This branch used to return the "no
      // readings recorded yet" line alone, which stated the stronger claim and
      // dropped the table that would have disproved it.
      return [
        WText(
          trans('uptizm.monitors.metrics_detail_no_readings_in_window'),
          className: 'text-sm text-fg-muted',
        ),
        const SizedBox(height: 16),
        _buildRecentReadings(),
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
      _buildRecentReadings(),
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
        // Absent entirely on a system metric: there is no definition behind it
        // to edit, and disabling them would offer an affordance that never
        // becomes available.
        ?(!widget.editable
            ? null
            : WDiv(
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
        )),
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
    // Two alignments, because the three things here are not the same kind of
    // thing and `items-end` treated them as if they were.
    //
    // `items-end` aligns BOX BOTTOMS. The value is 30px of monospace and the
    // meta label is 12px of sans, so their boxes carry different descender
    // room and bottom-aligning them left the small text sitting below the
    // value's baseline rather than on it. The dot, having no baseline at all,
    // then hung off the bottom of a tall line box: the asymmetry a reader
    // notices without being able to name it.
    //
    // So: the dot is CENTERED against the value, which is what a bullet beside
    // a number should do, and the two texts sit on a shared BASELINE, which is
    // what makes a unit or a caption look attached to a number rather than
    // dropped next to it.
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        // Only when the reading carried a frozen band; an unbanded reading
        // shows no dot rather than a green one. Not gated on the metric being
        // numeric: a string metric bands by value-list membership now, so that
        // gate hid the band on the readings this feature exists to flag.
        ?(band == null ? null : StatusDot(band, size: StatusDotSize.lg)),
        WDiv(
          className: 'flex flex-row items-baseline gap-2',
          children: [
            WText(
              valueText,
              className: 'text-fg font-mono text-3xl font-semibold tabular-nums',
            ),
            WText(
              trans('uptizm.monitors.metrics_detail_latest'),
              className: 'text-fg-muted text-xs',
            ),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Chart
  // ---------------------------------------------------------------------------

  /// Builds the [MetricChart] for the metric's readings.
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
  Widget _buildRecentReadings() {
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
          // A custom metric's band is FROZEN against the thresholds in force
          // when the check ran, so editing them later does not re-judge
          // history, and the note says so. A system metric has no editable
          // thresholds to freeze against, so that sentence would describe a
          // guarantee nobody can act on.
          trans(
            widget.editable
                ? 'uptizm.monitors.metrics_recent_readings_frozen_note'
                : 'uptizm.monitors.metrics_recent_readings_system_note',
          ),
          className: 'text-fg-muted text-xs',
        ),
        const SizedBox(height: 8),
        // PAGED, and bounded, matching the monitor screen's check history.
        //
        // This used to be the six newest points from the CHART's series, which
        // made the section a preview of a preview: the series is a windowed,
        // capped slice, so "recent readings" could not reach past its window and
        // there was no way to look further back at all. It pages its own
        // endpoint now, and the reader scrolls.
        //
        // The height bound is what makes that possible: a ListView needs one,
        // and this sheet is already inside a scroll view, so an unbounded list
        // here would either overflow or nest a second scroll area inside the
        // first.
        SizedBox(
          height: _readingsViewportHeight,
          child: MagicPaginatedListView<MetricSeriesPoint>(
            paginator: _readings,
            itemBuilder: (BuildContext context, MetricSeriesPoint point, int i) {
              // Never `isLast`: the list is lazy and pages, so no row is the
              // last one until the history ends. The separator belongs to the
              // row above it, uniformly.
              return _buildReadingRow(point, false);
            },
            emptyState: WText(
              trans('uptizm.monitors.metrics_detail_no_readings'),
              className: 'text-sm text-fg-muted',
            ),
          ),
        ),
      ],
    );
  }

  /// Height of the scrolling readings body.
  ///
  /// Roughly a dozen rows, the same reasoning `CheckHistoryTable.paginated`
  /// applies: enough that it reads as a table rather than a peek, short enough
  /// that the sheet around it stays reachable.
  static const double _readingsViewportHeight = 420;

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
