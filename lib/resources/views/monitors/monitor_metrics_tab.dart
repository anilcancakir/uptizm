import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/controllers/monitor_metrics_controller.dart';
import '../../../app/models/monitor.dart';
import '../../../app/enums/ai_level.dart' show AiLevel;
import '../../../app/enums/metric_direction.dart' show MetricDirection;
import '../../../app/support/billing_types.dart' show PlanLimits;
import '../../../app/enums/metric_kind.dart' show MetricKind;
import '../../../app/support/metric_types.dart' show MonitorMetric;
import '../../../app/enums/status_key.dart';
import '../../../ui/components/status_dot/index.dart';
import 'monitor_form_support.dart' show AiMetricSeed;
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

  /// Suggestions from the last discovery run, or null before one has been asked
  /// for.
  ///
  /// Deliberately three states rather than an empty list plus a bool: null is
  /// "not asked", empty is "asked, nothing to suggest", and [_discoverFailed]
  /// is "asked, the round trip broke". They read identically as a list and mean
  /// three different things to the operator.
  List<AiMetricSeed>? _suggestions;

  /// Whether a discovery round trip is in flight.
  bool _discovering = false;

  /// Whether the last discovery run failed at the transport, as opposed to
  /// answering with nothing.
  bool _discoverFailed = false;

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

  /// The interval this monitor checks on, which is the honest bound for calling a
  /// reading stale: a monitor that checks every 30s and has said nothing for ten
  /// minutes is not reporting. Zero when the monitor is not in the inventory yet,
  /// and [isReadingStale] treats that as "do not guess".
  int get _checkIntervalSec =>
      MonitorController.instance.monitorById(widget.monitorId)?.checkIntervalSec ??
      0;

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

  /// Asks the backend which metrics are worth adding and holds the answer.
  ///
  /// Re-runnable on purpose: the page changes and the operator changes their
  /// mind, which is the same reason the endpoint is gated on the team's AI
  /// level rather than on the create wizard's one-off metered allowance.
  Future<void> _discover() async {
    setState(() {
      _discovering = true;
      _discoverFailed = false;
    });

    final List<AiMetricSeed>? seeds = await _controller.discover(
      widget.monitorId,
    );

    if (!mounted) return;
    setState(() {
      _discovering = false;
      _discoverFailed = seeds == null;
      _suggestions = seeds ?? const [];
    });
  }

  /// Opens the create form prefilled from [seed].
  ///
  /// Nothing is written until the operator saves: a suggestion is a filled-in
  /// form, never a metric that appeared on its own. That is the same contract
  /// the create wizard's pills hold, and it is what makes an AI proposal
  /// something the operator approves rather than something they discover
  /// afterwards.
  void _acceptSeed(AiMetricSeed seed) {
    MonitorMetricForm.show(
      context,
      initial: metricFormFromSeed(seed),
      isEdit: false,
      onSave: (form) => _controller.create(widget.monitorId, form),
      onPreview: (form) => _controller.preview(widget.monitorId, form),
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

        // 3. Discovery: what the backend thinks is worth adding, behind the
        //    team's AI level.
        _buildSuggestSection(),
      ],
    );
  }

  /// Builds the discovery panel: the plan gate, the ask, and the answer.
  ///
  /// Wrapped in a [ListenableBuilder] on [EntitlementController] for the reason
  /// every other gated surface here is: the real plan lands after the first
  /// frame, and gates read permissively until it does, so a panel that did not
  /// re-gate would show the ask to a Free team for as long as the page stayed
  /// open.
  Widget _buildSuggestSection() {
    return ListenableBuilder(
      listenable: EntitlementController.instance,
      builder: (context, _) {
        final EntitlementController entitlement = EntitlementController.instance;

        if (!entitlement.aiLevelAllows(AiLevel.analysis)) {
          bool unlocksAnalysis(PlanLimits limits) =>
              limits.ai.index >= AiLevel.analysis.index;
          final String requiredPlan = entitlement.planNameUnlocking(
            unlocksAnalysis,
          );

          return MSUpgradeNudge(
            message: trans('uptizm.monitors.metrics_suggest_gated', {
              'plan': requiredPlan,
            }),
            requiredPlan: requiredPlan,
            onUpgrade: () => UpgradePrompt.startUpgrade(
              entitlement.planIdUnlocking(unlocksAnalysis),
            ),
          );
        }

        return WDiv(
          className: 'flex flex-col gap-3',
          children: [
            WDiv(
              className: 'flex flex-row items-center justify-between gap-3',
              children: [
                WText(
                  trans('uptizm.monitors.metrics_suggest_title'),
                  className: 'text-sm font-medium text-fg',
                ),
                MSButton(
                  intent: ButtonIntent.secondary,
                  size: ButtonSize.sm,
                  isLoading: _discovering,
                  onPressed: _discovering ? null : _discover,
                  child: WText(
                    trans(
                      _suggestions == null
                          ? 'uptizm.monitors.metrics_suggest_action'
                          : 'uptizm.monitors.metrics_suggest_again',
                    ),
                  ),
                ),
              ],
            ),
            ?_buildSuggestResult(),
          ],
        );
      },
    );
  }

  /// The answer half of the discovery panel, or null before anything was asked.
  ///
  /// Three outcomes render as three different sentences rather than one empty
  /// list: a transport failure says so, an empty answer says there is nothing
  /// to suggest (which a monitor with nothing archived yet, a spent AI budget
  /// and output the gateway would not trust all legitimately produce), and a
  /// non-empty answer renders the pills.
  Widget? _buildSuggestResult() {
    if (_discoverFailed) {
      return WText(
        trans('uptizm.monitors.metrics_suggest_failed'),
        className: 'text-xs text-fg-muted',
      );
    }

    final List<AiMetricSeed>? seeds = _suggestions;
    if (seeds == null) return null;

    if (seeds.isEmpty) {
      return WText(
        trans('uptizm.monitors.metrics_suggest_empty'),
        className: 'text-xs text-fg-muted',
      );
    }

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WDiv(
          className: 'flex flex-row flex-wrap items-center gap-2',
          children: [for (final AiMetricSeed seed in seeds) _buildSeedPill(seed)],
        ),
        WText(
          trans('uptizm.monitors.metrics_suggest_help'),
          className: 'text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// One suggestion, as a pill that opens the metric form prefilled.
  ///
  /// A rule-authored seed carries its own quiet marker. The backend proposes
  /// the service's own health verdict deterministically, with no model
  /// involved, and letting that row sit unlabelled among the model's would
  /// credit an AI with work it did not do.
  Widget _buildSeedPill(AiMetricSeed seed) {
    return WAnchor(
      onTap: () => _acceptSeed(seed),
      child: WDiv(
        className:
            'flex flex-row items-center gap-1 rounded-md border '
            'border-color-border bg-surface px-2 py-0.5 '
            'hover:bg-surface-container transition-colors',
        children: [
          WText(seed.label, className: 'text-xs text-fg'),
          if (seed.sampleValue.isNotEmpty)
            WText(
              trans('uptizm.monitors.create_ai_metric_observed', {
                'observed': seed.unit.isEmpty
                    ? seed.sampleValue
                    : '${seed.sampleValue} ${seed.unit}',
              }),
              className: 'font-mono text-xs text-fg-muted',
            ),
          if (seed.isRule)
            WText(
              trans('uptizm.monitors.metrics_suggest_rule_badge'),
              className: 'text-xs text-fg-disabled',
            ),
        ],
      ),
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
          // `divide-*` is not a family wind implements at all, so the two
          // tokens here did nothing and the skeleton drew three rows as one
          // undivided block while the real list it stands in for renders
          // separated cards. Matching that list's own `gap-2` rhythm removes
          // the layout jump when the catalog lands.
          'flex flex-col gap-2 rounded-xl border border-color-border p-2',
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

    // A reading that stopped arriving is not the metric's current state. Rename
    // the key a rule extracts in a monitored deploy and no new value is recorded,
    // so this row used to show the last good value with its last good band
    // forever: "94ms, green" for something nobody had measured in a week. The
    // value stays on screen, because the last known reading is still information,
    // but it is labelled and its band is dropped.
    final bool isStale = isReadingStale(
      record.latestRecordedAt,
      checkIntervalSec: _checkIntervalSec,
    );

    // The dot reflects the band the backend froze on that reading; a metric with
    // no reading, no thresholds, or a reading gone stale gets no dot rather than
    // a green one.
    //
    // It is NOT gated on the metric being numeric. It used to be, back when a
    // numeric bound was the only thing that could produce a band; a string
    // metric now bands by value-list membership, so gating on the type hid the
    // band on exactly the readings this feature exists to flag, and a critical
    // `exploded` rendered as unremarkable plain text.
    final StatusKey? band = isStale
        ? null
        : switch (record.latestBand) {
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
                isStale
                    ? trans('uptizm.monitors.metrics_reading_stale')
                    : _keyPath(metric),
                className: isStale
                    ? 'text-xs text-degraded'
                    : 'font-mono text-xs text-fg-muted',
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
                className: isStale
                    ? 'font-mono text-sm tabular-nums text-fg-disabled'
                    : 'font-mono text-sm tabular-nums text-fg',
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
