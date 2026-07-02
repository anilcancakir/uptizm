import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../../app/mocks/metrics.dart';
import '../../../app/mocks/status.dart';
import '../../../ui/components/empty_state/index.dart';
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
/// System metrics are read straight from [systemMetricsForMonitors] and never
/// mutate. Custom metrics are seeded once from [customMetricsForMonitors] via
/// [fromCatalog] into a *local mutable* [List] (the fixture list is never
/// mutated in place). Every create/edit/delete acts on that local list and is
/// committed through [setState].
///
/// ### Sheet interactions
///
/// - "Add metric" / "Create metric" opens [MonitorMetricForm] in create mode;
///   Save appends the new [MetricForm].
/// - Tapping a custom row opens [MonitorMetricDetail] in a [BottomSheet].
/// - The detail's Edit closes the detail sheet, then opens the form in edit
///   mode bound to that row's index; Save replaces the row in place.
/// - The detail's Delete (already confirmed inside the detail) removes the row
///   and closes the sheet.
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
  /// Read-only system metrics (response time, collected by default). These come
  /// straight from the fixture and never change for the lifetime of the tab.
  late final List<MonitorMetric> _systemMetrics;

  /// The mutable working copy of the monitor's custom metrics.
  ///
  /// Seeded once in [initState] from [customMetricsForMonitors] via
  /// [fromCatalog]; the fixture list itself is never mutated (see the MUST NOT
  /// contract). Create appends, edit replaces in place, delete removes.
  late final List<MetricForm> _metrics;

  @override
  void initState() {
    super.initState();
    _systemMetrics = systemMetricsForMonitors([widget.monitorId]);
    // Copy the fixture output into a fresh growable list so create/edit/delete
    // never touch the shared fixture (MUST NOT: mutate the fixture in place).
    _metrics = customMetricsForMonitors([widget.monitorId]).map(fromCatalog).toList();
  }

  // ---------------------------------------------------------------------------
  // State transitions (React openCreate / openEdit / save / removeDetail).
  // ---------------------------------------------------------------------------

  /// Opens the create form; Save appends the new metric to [_metrics].
  void _openCreate() {
    MonitorMetricForm.show(
      context,
      initial: kEmptyMetricForm,
      isEdit: false,
      onSave: (form) => setState(() => _metrics.add(form)),
    );
  }

  /// Opens the edit form for the metric at [index]; Save replaces it in place.
  void _openEdit(int index) {
    MonitorMetricForm.show(
      context,
      initial: _metrics[index],
      isEdit: true,
      onSave: (form) => setState(() => _metrics[index] = form),
    );
  }

  /// Opens the historical detail sheet for the metric at [index].
  ///
  /// The detail's Edit first pops the detail sheet, then opens the edit form
  /// for [index] (React `openEdit(detailIndex)` after closing the detail). Its
  /// Delete removes the metric from local state and closes the sheet.
  void _openDetail(int index) {
    final MetricForm metric = _metrics[index];
    BottomSheet.show<void>(
      context,
      title: metric.label,
      body: Builder(
        builder: (sheetContext) => MonitorMetricDetail(
          metric: metric,
          onEdit: () {
            Navigator.of(sheetContext).pop();
            _openEdit(index);
          },
          onDelete: () {
            setState(() => _metrics.removeAt(index));
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
      className: 'flex flex-row items-center justify-between gap-3 '
          'rounded-lg border border-color-border bg-surface p-3',
      children: [
        Expanded(
          child: WDiv(
            className: 'flex flex-col',
            children: [
              WText(metric.label, className: 'text-sm font-medium text-fg'),
              WText(
                trans('uptizm.monitors.metrics_system_collected_by_default'),
                className: 'font-mono text-xs text-fg-muted',
              ),
            ],
          ),
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
              Button(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                onPressed: _openCreate,
                child: WText(trans('uptizm.monitors.metrics_add')),
              ),
          ],
        ),
        if (_metrics.isEmpty) _buildEmptyState() else _buildMetricsList(),
      ],
    );
  }

  /// Builds the dashed-border empty state with the "Create metric" action.
  Widget _buildEmptyState() {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: EmptyState(
        icon: Icons.show_chart,
        title: trans('uptizm.monitors.metrics_empty_title'),
        description: trans('uptizm.monitors.metrics_empty_description'),
        action: Button(
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
        for (final (int index, MetricForm metric) in _metrics.indexed)
          _buildMetricRow(index, metric),
      ],
    );
  }

  /// Builds one clickable custom-metric row.
  ///
  /// The row shows the label and `key · path` on the left, and on the right a
  /// [StatusDot] (numeric metrics only) plus the latest value. Tapping opens
  /// the historical [MonitorMetricDetail] sheet for [index].
  Widget _buildMetricRow(int index, MetricForm metric) {
    final bool isNumeric = metric.type == 'numeric';
    final num latest = latestOf(metric);
    final StatusKey band = isNumeric
        ? bandOf(latest, metric.warn, metric.critical, metric.direction)
        : StatusKey.up;
    final String valueText = switch (metric.type) {
      'status' => 'operational',
      'string' => 'ok',
      _ => fmt(latest, metric.unit),
    };

    // WAnchor (the app's proven clickable-row primitive, as in MonitorListRow)
    // owns the tap and gives the row real button-feel on web: it sets
    // SystemMouseCursors.click on hover and drives the `hover:` state so
    // `hover:bg-surface-container` actually expands (a bare GestureDetector
    // gives neither cursor nor hover affordance, which is why the rows felt
    // inert). `transition-colors` smooths the hover, mirroring the React
    // `<button ... hover:bg-muted>` row.
    return WAnchor(
      onTap: () => _openDetail(index),
      child: WDiv(
        className: 'flex flex-row items-center justify-between gap-3 '
            'rounded-lg border border-color-border bg-surface p-3 '
            'hover:bg-surface-container transition-colors',
        children: [
          Expanded(
            child: WDiv(
              className: 'flex flex-col',
              children: [
                WText(metric.label, className: 'text-sm font-medium text-fg'),
                WText(
                  _keyPath(metric),
                  className: 'font-mono text-xs text-fg-muted',
                ),
              ],
            ),
          ),
          WDiv(
            className: 'flex flex-row items-center gap-2',
            children: [
              if (isNumeric) StatusDot(band),
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
