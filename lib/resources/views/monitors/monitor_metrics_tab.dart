import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/controllers/monitor_metrics_controller.dart';
import '../../../app/models/monitor.dart';
import '../../../app/enums/metric_direction.dart' show MetricDirection;
import '../../../app/enums/metric_kind.dart' show MetricKind;
import '../../../app/support/metric_types.dart' show MonitorMetric;
import '../../../app/enums/status_key.dart';
import '../../../ui/components/status_dot/index.dart';
import 'monitor_metric_detail.dart';
import 'monitor_metric_form.dart';
import 'monitor_metrics_support.dart';

/// **The Metrics tab orchestrator for a single monitor.**
///
/// A faithful Flutter port of the React `MonitorMetricsTab` orchestrator
/// (lines 225-386). It composes three already-built pieces into the monitor
/// Metrics tab:
///
/// - the read-only **System metrics** section (collected-by-default rows),
/// - the editable **Custom metrics** section (empty state or a clickable list),
/// - the create/edit [MonitorMetricForm] and the historical [MonitorMetricDetail]
///   BottomSheets, hosted through the `magic_starter` [BottomSheet].
///
/// ### State model
///
/// System metrics have no backend model (the `monitor_metrics` table is
/// exclusively user-defined; see `MonitorMetric.php`'s docblock), so the
/// single "response time" row stays derived from the already-live
/// [MonitorController] inventory (`responseMs`) rather than a mock fixture.
///
/// Custom metrics are sourced from [MonitorMetricsController], which fetches
/// the monitor's metric catalog + latest readings from the live `api/v1`
/// metrics endpoints (`routes/api.php:99-116`). [initState] kicks off a
/// [MonitorMetricsController.reload]; this widget listens for the
/// controller's notifications and rebuilds against [MonitorMetricsController.metricsFor].
/// Every create/edit/delete/reorder calls the matching controller action,
/// which persists the write and reloads the catalog on success (see
/// `monitor_metrics_controller.dart`).
///
/// ### Sheet interactions
///
/// - "Add metric" / "Create metric" opens [MonitorMetricForm] in create mode;
///   Save posts the new metric.
/// - Tapping a custom row opens [MonitorMetricDetail] in a [BottomSheet].
/// - The detail's Edit closes the detail sheet, then opens the form in edit
///   mode bound to that row's record; Save puts the updated fields.
/// - The detail's Delete (already confirmed inside the detail) deletes the
///   metric and closes the sheet.
///
/// No color is hardcoded: every tone flows through semantic alias keys
/// (`text-fg`, `bg-surface`, `border-color-border`, ...) and the monitoring
/// status families resolved by [StatusDot] / [bandOf].
///
/// ### Example
/// ```dart
/// const MonitorMetricsTab(monitorId: 'api')
/// ```
class MonitorMetricsTab extends StatefulWidget {
  /// The monitor whose system + custom metrics this tab renders.
  final String monitorId;

  /// Creates a [MonitorMetricsTab] for [monitorId].
  const MonitorMetricsTab({super.key, required this.monitorId});

  @override
  State<MonitorMetricsTab> createState() => _MonitorMetricsTabState();
}

class _MonitorMetricsTabState extends State<MonitorMetricsTab> {
  /// Shown in place of a value when the metric has recorded no reading.
  ///
  /// An em-dash, matching how the rest of the product renders "not measured"
  /// (the dashboard's unmeasured KPIs, a monitor's response time before its
  /// first check).
  static const String _noReading = '—';

  /// The singleton controller sourcing this monitor's custom metric catalog
  /// from the live metrics endpoints.
  late final MonitorMetricsController _controller;

  @override
  void initState() {
    super.initState();
    _controller = MonitorMetricsController.instance;
    _controller.addListener(_onControllerChanged);
    _controller.reload(widget.monitorId);
  }

  @override
  void dispose() {
    _controller.removeListener(_onControllerChanged);
    super.dispose();
  }

  /// Rebuilds against the freshest catalog once the controller notifies.
  void _onControllerChanged() {
    if (mounted) setState(() {});
  }

  /// Read-only system metrics: a single "response time" row derived from the
  /// live [MonitorController] inventory, absent when that monitor has no
  /// recorded response time yet (paused, or no check has completed).
  List<MonitorMetric> get _systemMetrics {
    final Monitor? monitor = MonitorController.instance.monitorById(
      widget.monitorId,
    );
    final int? responseMs = monitor?.responseMs;
    if (responseMs == null) return const [];

    return [
      MonitorMetric(
        monitorId: widget.monitorId,
        label: trans('uptizm.monitors.metrics_response_time'),
        key: 'response_time',
        unit: 'ms',
        value: responseMs,
        direction: MetricDirection.high,
        warn: 500,
        critical: 1000,
        kind: MetricKind.system,
      ),
    ];
  }

  /// The live custom metric catalog for this monitor.
  List<MonitorMetricRecord> get _metrics =>
      _controller.metricsFor(widget.monitorId);

  // ---------------------------------------------------------------------------
  // State transitions (React openCreate / openEdit / save / removeDetail).
  // ---------------------------------------------------------------------------

  /// Opens the create form; Save posts the new metric to the backend.
  void _openCreate() {
    MonitorMetricForm.show(
      context,
      initial: kEmptyMetricForm,
      isEdit: false,
      onSave: (form) => _controller.create(widget.monitorId, form),
      onPreview: (form) => _controller.preview(widget.monitorId, form),
      // The form owns no monitor id (see the contract on [onPreview]), so the
      // candidate browser is handed the same way: a closure the tab binds.
      onCandidates: () => _controller.candidates(widget.monitorId),
    );
  }

  /// Opens the edit form for [record]; Save puts the updated fields.
  void _openEdit(MonitorMetricRecord record) {
    MonitorMetricForm.show(
      context,
      initial: record.form,
      isEdit: true,
      onSave: (form) => _controller.update(widget.monitorId, record.id, form),
      onPreview: (form) => _controller.preview(widget.monitorId, form),
      onCandidates: () => _controller.candidates(widget.monitorId),
    );
  }

  /// Opens the historical detail sheet for [record].
  ///
  /// The detail's Edit first pops the detail sheet, then opens the edit form
  /// for [record] (React `openEdit(detailIndex)` after closing the detail).
  /// Its Delete deletes the metric and closes the sheet.
  void _openDetail(MonitorMetricRecord record) {
    MSBottomSheet.show<void>(
      context,
      title: record.form.label,
      body: Builder(
        builder: (sheetContext) => MonitorMetricDetail(
          metric: record.form,
          onLoadSeries: () =>
              _controller.series(widget.monitorId, record.id),
          onEdit: () {
            Navigator.of(sheetContext).pop();
            _openEdit(record);
          },
          onDelete: () {
            _controller.delete(widget.monitorId, record.id);
            Navigator.of(sheetContext).pop();
          },
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Build.
  // ---------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 pt-4',
      children: [
        // 1. System metrics section (only when the monitor reports any).
        if (_systemMetrics.isNotEmpty) _buildSystemSection(),

        // 2. Custom metrics section: heading + Add, then empty state or list.
        _buildCustomSection(),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // System metrics section.
  // ---------------------------------------------------------------------------

  /// Builds the read-only "System metrics" section.
  Widget _buildSystemSection() {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(
          trans('uptizm.monitors.metrics_system_title'),
          className: 'text-sm font-medium text-fg',
        ),
        ..._systemMetrics.map(_buildSystemRow),
      ],
    );
  }

  /// Builds one read-only system-metric row: label + collected-by-default note
  /// on the left, [StatusDot] + formatted value on the right.
  Widget _buildSystemRow(MonitorMetric metric) {
    final StatusKey band = bandOf(
      metric.value,
      metric.warn.toString(),
      metric.critical.toString(),
      metric.direction == MetricDirection.low ? 'low' : 'high',
    );

    return WDiv(
      className:
          'flex flex-row items-center justify-between gap-3 '
          'rounded-lg border border-color-border bg-surface p-3',
      children: [
        WDiv(
          className: 'flex-1 flex flex-col',
          children: [
            WText(metric.label, className: 'text-sm font-medium text-fg'),
            WText(
              trans('uptizm.monitors.metrics_system_collected_by_default'),
              className: 'font-mono text-xs text-fg-muted',
            ),
          ],
        ),
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            StatusDot(band),
            WText(
              fmt(metric.value, metric.unit),
              className: 'font-mono text-sm tabular-nums text-fg',
            ),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Custom metrics section.
  // ---------------------------------------------------------------------------

  /// Builds the "Custom metrics" section: heading row (with the "Add metric"
  /// button when the list is non-empty), then the empty state or the row list.
  Widget _buildCustomSection() {
    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        WDiv(
          className: 'flex flex-row items-center justify-between',
          children: [
            WText(
              trans('uptizm.monitors.metrics_custom_title'),
              className: 'text-sm font-medium text-fg',
            ),
            if (_metrics.isNotEmpty)
              MSButton(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                onPressed: _openCreate,
                child: WText(trans('uptizm.monitors.metrics_add')),
              ),
          ],
        ),
        // Loading is not emptiness: without this branch a monitor WITH custom
        // metrics showed the "no custom metrics" empty state until the catalog
        // read answered.
        if (_controller.isFirstLoad(widget.monitorId))
          _buildSkeleton()
        else if (_metrics.isEmpty)
          _buildEmptyState()
        else
          _buildMetricsList(),
      ],
    );
  }

  /// Builds the first-load placeholder: three metric rows in skeletons.
  ///
  /// Mirrors [_buildMetricRow]'s frame and internal rhythm (label + key/path on
  /// the left, dot + value on the right) so the list does not jump when the
  /// catalog lands.
  Widget _buildSkeleton() {
    return WDiv(
      className:
          'flex flex-col divide-y divide-color-border rounded-xl border '
          'border-color-border',
      children: [for (int i = 0; i < 3; i++) _buildSkeletonRow()],
    );
  }

  /// One skeleton metric row.
  Widget _buildSkeletonRow() {
    return WDiv(
      className: 'flex flex-row items-center gap-3 px-4 py-3',
      children: const [
        WDiv(
          className: 'flex flex-col flex-1 min-w-0 gap-1.5',
          children: [
            MSSkeleton(shape: SkeletonShape.text, width: 120, height: 20),
            MSSkeleton(shape: SkeletonShape.text, width: 180, height: 16),
          ],
        ),
        MSSkeleton(shape: SkeletonShape.circle, width: 8, height: 8),
        MSSkeleton(shape: SkeletonShape.text, width: 56, height: 20),
      ],
    );
  }

  /// Builds the dashed-border empty state with the "Create metric" action.
  Widget _buildEmptyState() {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
        icon: Icons.show_chart,
        title: trans('uptizm.monitors.metrics_empty_title'),
        description: trans('uptizm.monitors.metrics_empty_description'),
        action: MSButton(
          onPressed: _openCreate,
          child: WText(trans('uptizm.monitors.metrics_create')),
        ),
      ),
    );
  }

  /// Builds the clickable custom-metric list.
  Widget _buildMetricsList() {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        for (final MonitorMetricRecord record in _metrics)
          _buildMetricRow(record),
      ],
    );
  }

  /// Builds one clickable custom-metric row.
  ///
  /// The row shows the label and `key · path` on the left, and on the right the
  /// [StatusDot] for the band the backend froze on the latest reading (whatever
  /// the metric's type), plus that reading itself: `latest.numeric_value` for a
  /// numeric metric, `latest.string_value` / `latest.status_value` otherwise.
  /// Tapping opens the historical [MonitorMetricDetail] sheet for [record].
  Widget _buildMetricRow(MonitorMetricRecord record) {
    final MetricForm metric = record.form;
    final num? latest = metric.value;

    // Every branch is the metric's REAL latest reading, and an em-dash when it
    // has none. This row used to default a missing numeric reading to `0` and
    // render the LITERAL words "operational" / "ok" for every status / string
    // metric, so a rule that extracted nothing looked healthy and a status
    // metric reading `down` displayed as operational.
    final String valueText = switch (metric.type) {
      'status' => record.latestStatus ?? _noReading,
      'string' => record.latestString ?? _noReading,
      _ => latest == null ? _noReading : fmt(latest, metric.unit),
    };

    // The dot reflects the band the backend froze on that reading; a metric with
    // no reading, or no thresholds, gets no dot rather than a green one.
    //
    // It is NOT gated on the metric being numeric. It used to be, back when a
    // numeric bound was the only thing that could produce a band; a string
    // metric now bands by value-list membership, so gating on the type hid the
    // band on exactly the readings this feature exists to flag, and a critical
    // `exploded` rendered as unremarkable plain text.
    final StatusKey? band = switch (record.latestBand) {
      'critical' => StatusKey.down,
      'warn' => StatusKey.degraded,
      'ok' => StatusKey.up,
      _ => null,
    };

    // WAnchor (the app's proven clickable-row primitive, as in MonitorListRow)
    // owns the tap and gives the row real button-feel on web: it sets
    // SystemMouseCursors.click on hover and drives the `hover:` state so
    // `hover:bg-surface-container` actually expands (a bare GestureDetector
    // gives neither cursor nor hover affordance, which is why the rows felt
    // inert). `transition-colors` smooths the hover, mirroring the React
    // `<button ... hover:bg-muted>` row.
    return WAnchor(
      onTap: () => _openDetail(record),
      child: WDiv(
        className:
            'flex flex-row items-center justify-between gap-3 '
            'rounded-lg border border-color-border bg-surface p-3 '
            'hover:bg-surface-container transition-colors',
        children: [
          WDiv(
            className: 'flex-1 flex flex-col',
            children: [
              WText(metric.label, className: 'text-sm font-medium text-fg'),
              WText(
                _keyPath(metric),
                className: 'font-mono text-xs text-fg-muted',
              ),
            ],
          ),
          WDiv(
            className: 'flex flex-row items-center gap-2',
            children: [
              // Only when the reading carried a frozen band.
              ?(band == null ? null : StatusDot(band)),
              WText(
                valueText,
                className: 'font-mono text-sm tabular-nums text-fg',
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Private helpers.
// ---------------------------------------------------------------------------

/// Formats the key and optional path for a custom-metric row subtitle.
///
/// Returns `"<key> · <path>"` when [form.path] is non-empty, otherwise just
/// `"<key>"` (React `{m.key}{m.path && ` · ${m.path}`}`).
String _keyPath(MetricForm form) {
  final String path = form.path.trim();
  return path.isNotEmpty ? '${form.key} · $path' : form.key;
}
