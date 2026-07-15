import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/incident.dart';
import '../../../app/models/monitor.dart';
import '../../../app/enums/incident_impact.dart' show IncidentImpact;
import '../../../app/enums/incident_lifecycle.dart' show IncidentLifecycle;
import '../../../app/enums/incident_severity.dart' show IncidentSeverity;
import '../../../app/mocks/incidents.dart';
import '../../../app/enums/chart_tone.dart' show ChartTone;
import '../../../app/mocks/metrics.dart';
import '../../../app/mocks/monitors.dart';
import '../../../app/enums/status_key.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/check_history_table/index.dart';
import '../../../ui/components/date_range_picker/index.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/incident_card/index.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/components/metric_chart/index.dart';
import '../../../ui/components/slo_budget_card/index.dart';
import '../../../ui/components/status_badge/index.dart';
import '../../../ui/components/uptime_bar/index.dart';
import '../../../ui/layouts/page_container.dart';
import 'monitor_metrics_tab.dart';

/// **The Monitor Detail screen.**
///
/// The richest read-only screen in the design lab: a header (name / URL /
/// [StatusBadge] plus Pause-Resume / Edit / Delete [Button] actions), a
/// responsive KPI row ([KpiStatCard]), the 90-day [UptimeBar], a reliability
/// section ([SloBudgetCard] error-budget gauges + a budget-burn [AiInsight]),
/// and a three-tab body (Overview / Metrics / Incidents) reusing the
/// magic_starter [Tabs].
///
/// - **Overview** carries a response-time [MetricChart] (series + anomalies)
///   with a [DateRangePicker] in its heading row, a response [AiInsight] below
///   the chart, and the recent [CheckHistoryTable].
/// - **Metrics** hosts the full [MonitorMetricsTab] orchestrator.
/// - **Incidents** lists the monitor's [IncidentCard]s, or a graceful
///   [EmptyState] when none touch it.
///
/// It resolves a monitor [id] to a fixture via [findMonitor]; when no monitor
/// matches it renders a graceful [EmptyState] rather than crashing (the route
/// supplies the id at the routing layer).
///
/// A brief loading state mirrors the React source: on mount (and whenever [id]
/// changes) a 600ms timer flips [_loading] to false. While loading the body is
/// a [Skeleton] scaffold mirroring the real layout; the header always shows so
/// the back affordance and actions stay reachable.
///
/// Layout discipline mirrors [DashboardView] / [MonitorsListView]: a plain
/// Flutter [Column] scaffolds the page body so each leaf component receives a
/// bounded, well-formed width constraint from the shared [PageContainer]
/// rather than an unbounded Wind flex-scroll regime. Wind utilities appear
/// only on leaf containers, never as the outermost flex context. This keeps
/// the dense MetricChart + CheckHistoryTable + KPI grid from overflowing on a
/// narrow phone.
///
/// The monitor inventory is wired through [MonitorController]: it seeds from
/// fixtures on [MonitorController.onInit] and then sources the live `api/v1`
/// endpoints ([MonitorController.reload], the per-monitor background refresh
/// inside [MonitorController.monitorById]). The state owned locally by this
/// widget is presentation-only: the active tab, the active range, the pending
/// confirm dialog, and the loading flag, so a plain [StatefulWidget] is
/// intentional.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/monitors/:id` content (wrapped by the shell):
/// MagicStarter.view.makeLayout(
///   'layout.app',
///   child: const MonitorDetailView(id: 'api'),
/// )
/// ```
@immutable
class MonitorDetailView extends MagicStatefulView<MonitorController> {
  /// The monitor identifier resolved against the fixtures via
  /// [MonitorController.monitorById].
  ///
  /// `null` or an unknown id renders a graceful not-found [EmptyState].
  final String? id;

  /// Creates the [MonitorDetailView] for the given monitor [id].
  const MonitorDetailView({super.key, this.id});

  @override
  State<MonitorDetailView> createState() => _MonitorDetailViewState();
}

// ---------------------------------------------------------------------------
// Tab definition
// ---------------------------------------------------------------------------

/// The three tabs shown for a monitor: Overview, Metrics, and Incidents.
enum _DetailTab {
  /// Response-time chart + recent checks.
  overview,

  /// System + custom metrics orchestrator ([MonitorMetricsTab]).
  metrics,

  /// Incidents that touch this monitor.
  incidents,
}

class _MonitorDetailViewState
    extends MagicStatefulViewState<MonitorController, MonitorDetailView> {

  /// The currently selected tab index.
  int _tabIndex = _DetailTab.overview.index;

  /// The active response-time range preset (matches [kDateRangePresets] values).
  String _range = '24h';

  /// Whether the loading skeleton is currently shown.
  bool _loading = true;

  /// Live recent-check rows from `GET /monitors/:id/checks`.
  List<CheckRow> _recentChecks = const [];

  /// Live response-time points from `GET /monitors/:id/response-times`. The
  /// endpoint aggregates one `response_ms` per time bucket, so the chart plots a
  /// single line (not the design-lab p50/p95/p99 trio).
  List<MetricDatum> _responseData = const [];

  /// Live 90-day uptime history from `GET
  /// /monitors/:id/response-times?range=90d`, bucketed into daily segments by
  /// [MonitorController.loadUptime90]. Empty while loading or on failure, in
  /// which case [UptimeBar] renders its own empty track.
  List<UptimeSegment> _uptimeSegments = const [];

  /// Single-series descriptor for the live response-time chart.
  static const List<MetricSeries> _liveResponseSeries = [
    MetricSeries(key: 'response', label: 'Response', tone: ChartTone.up),
  ];

  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(MonitorController.new);
    super.initState();
    _startLoading();
  }

  @override
  void didUpdateWidget(covariant MonitorDetailView oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Reset the skeleton whenever the resolved monitor changes (React's
    // `useEffect(..., [id])`), so navigating between monitors replays the brief
    // loading state instead of showing stale content.
    if (oldWidget.id != widget.id) {
      _startLoading();
    }
  }

  /// (Re)starts the data load: shows the skeleton, clears any prior data, then
  /// fetches this monitor's recent checks + response-time series from the live
  /// `api/v1` endpoints and swaps the content in once both settle.
  ///
  /// [_loading] is set directly (no [setState]): this runs from [initState] and
  /// [didUpdateWidget], both of which schedule a build of their own; only the
  /// deferred [_fetchData] completion (outside a build) calls [setState].
  void _startLoading() {
    // Refresh this monitor's single-resource fields ONCE per mount / id change
    // (never from build; see [MonitorController.refreshOne]). Fire-and-forget:
    // its `refreshUI()` lands on a later frame, not during this build cycle.
    final String? id = widget.id;
    if (id != null) {
      controller.refreshOne(id);
    }
    _loading = true;
    _recentChecks = const [];
    _responseData = const [];
    _uptimeSegments = const [];
    unawaited(_fetchData());
  }

  /// Fetches the recent checks, response-time series, and 90-day uptime
  /// history for the current [id] and publishes them, flipping [_loading]
  /// off once all three settle.
  Future<void> _fetchData() async {
    final String? id = widget.id;
    if (id == null) {
      if (mounted) setState(() => _loading = false);
      return;
    }

    final List<CheckRow> checks = await _loadChecks(id);
    final List<MetricDatum> series = await _loadResponseSeries(id);
    final List<UptimeSegment> uptimeSegments = await controller.loadUptime90(
      id,
    );

    if (!mounted) return;
    setState(() {
      _recentChecks = checks;
      _responseData = series;
      _uptimeSegments = uptimeSegments;
      _loading = false;
    });
  }

  /// Loads `GET /monitors/:id/checks` into [CheckRow]s. A failure degrades to an
  /// empty list (logged) so the table renders its empty state, never a mock.
  Future<List<CheckRow>> _loadChecks(String id) async {
    try {
      final response = await Http.get('/monitors/$id/checks');
      if (!response.successful) return const [];
      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return const [];
      return raw
          .whereType<Map<String, dynamic>>()
          .map(CheckRow.fromMap)
          .toList();
    } catch (error) {
      Log.error('[MonitorDetailView] checks load failed: $error');
      return const [];
    }
  }

  /// Loads `GET /monitors/:id/response-times` (one bucketed `response_ms` per
  /// point) into a single-series [MetricDatum] list. Degrades to empty on error.
  Future<List<MetricDatum>> _loadResponseSeries(String id) async {
    try {
      final response = await Http.get(
        '/monitors/$id/response-times?range=$_range',
      );
      if (!response.successful) return const [];
      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return const [];
      final List<MetricDatum> out = [];
      for (final Map<String, dynamic> row
          in raw.whereType<Map<String, dynamic>>()) {
        final num? ms = row['response_ms'] as num?;
        if (ms == null) continue;
        out.add(
          MetricDatum(
            label: _formatHourMinute(row['checked_at'] as String?),
            values: {'response': ms},
          ),
        );
      }
      return out;
    } catch (error) {
      Log.error('[MonitorDetailView] response-times load failed: $error');
      return const [];
    }
  }

  /// Reduces an ISO-8601 timestamp to a local `HH:mm` chart-axis label.
  String _formatHourMinute(String? iso) {
    if (iso == null) return '';
    final DateTime? dt = DateTime.tryParse(iso)?.toLocal();
    if (dt == null) return '';
    return '${dt.hour.toString().padLeft(2, '0')}:'
        '${dt.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the monitor; a null / unknown id falls back to a graceful
    //    not-found state so the screen never crashes when the route passes an
    //    id with no fixture behind it.
    final Monitor? monitor = controller.monitorById(widget.id);
    if (monitor == null) {
      return _buildNotFound();
    }

    final bool paused = monitor.status == StatusKey.paused;

    // 2. A Wind flex column scaffolds the page body (24px header->body rhythm
    //    via gap-6); each leaf receives a bounded width from PageContainer,
    //    keeping the dense leaves from overflowing on mobile. The header always
    //    shows; only the body below it gates on _loading.
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // 3. Header: name + StatusBadge as a title suffix, URL as subtitle,
          //    Pause-Resume / Edit / Delete actions on the trailing edge.
          MSPageHeader(
            title: monitor.name ?? '',
            subtitle: monitor.url,
            titleSuffix: StatusBadge(monitor.status),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
            actions: _buildHeaderActions(monitor, paused),
          ),

          // 4. Body: skeleton while loading, otherwise the full content.
          if (_loading)
            _buildLoadingSkeleton()
          else
            _buildContent(monitor, paused),
        ],
      ),
    );
  }

  /// Builds the full monitor body (KPI row, uptime, reliability, tabs).
  Widget _buildContent(Monitor monitor, bool paused) {
    // Uniform 32px (gap-8) between every section; the reliability block drops
    // in conditionally without a manual spacer.
    return WDiv(
      className: 'flex flex-col gap-8',
      children: [
        // 1. KPI summary row.
        _buildKpiRow(monitor, paused),

        // 2. 90-day uptime timeline.
        _buildUptimeSection(monitor),

        // 3. Reliability section: only for active monitors with an SLO target.
        if (!paused && monitor.sloTarget != null)
          _buildReliabilitySection(monitor, monitor.sloTarget!),

        // 4. Overview / Metrics / Incidents tabs.
        _buildTabs(monitor, paused),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Header actions
  // ---------------------------------------------------------------------------

  /// Builds the trailing header actions: Pause-Resume, Edit, and Delete.
  ///
  /// - **Pause/Resume**: a secondary [Button]. A paused monitor shows "Resume"
  ///   and surfaces a resumed toast immediately; an active monitor shows
  ///   "Pause" and opens a pause [MagicStarterConfirmDialog].
  /// - **Edit**: a secondary [Button] that navigates to `/monitors/:id/edit`.
  /// - **Delete**: a destructive [Button] that opens a delete confirm dialog,
  ///   then surfaces a deleted toast and returns to the monitors list.
  List<Widget> _buildHeaderActions(Monitor monitor, bool paused) {
    // The PageHeader lays its actions in a bare non-wrapping flex-row, so the
    // three buttons would overflow a narrow phone. Wrapping them in a single
    // `wrap` leaf container lets them flow onto a second line below ~360px
    // (mirroring the React header's wrapping actions) while staying a single
    // row on wider surfaces.
    return [
      WDiv(
        className: 'wrap items-center gap-2',
        children: [
          MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: () => _onPauseResume(monitor, paused),
            child: WText(
              paused
                  ? trans('uptizm.monitors.action_resume')
                  : trans('uptizm.monitors.action_pause'),
            ),
          ),
          MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: () => _onEdit(monitor),
            child: WText(trans('uptizm.monitors.action_edit')),
          ),
          MSButton(
            intent: ButtonIntent.destructive,
            size: ButtonSize.sm,
            onPressed: () => _onDelete(monitor),
            child: WText(trans('uptizm.monitors.action_delete')),
          ),
        ],
      ),
    ];
  }

  /// Resumes a paused monitor, or opens the pause confirm dialog before pausing.
  ///
  /// The confirm dialog needs a [BuildContext], so it stays view-side; the
  /// toast (and, for delete, the navigation) live in [MonitorController].
  Future<void> _onPauseResume(Monitor monitor, bool paused) async {
    if (paused) {
      controller.resume(monitor.id);
      return;
    }

    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.monitors.confirm_pause_title', {
        'name': monitor.name,
      }),
      description: trans('uptizm.monitors.confirm_pause_description'),
      confirmLabel: trans('uptizm.monitors.confirm_pause_label'),
    );
    if (!confirmed) return;

    controller.pause(monitor.id);
  }

  /// Handles the Edit action by navigating to the edit route for the current
  /// monitor. Requires the monitor to be resolved before calling.
  void _onEdit(Monitor monitor) {
    MagicRoute.to('/monitors/${monitor.id}/edit');
  }

  /// Opens the delete confirm dialog; on confirm, delegates to the controller
  /// which surfaces the deleted toast and returns to the monitors list.
  Future<void> _onDelete(Monitor monitor) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.monitors.confirm_delete_title', {
        'name': monitor.name,
      }),
      description: trans('uptizm.monitors.confirm_delete_description'),
      confirmLabel: trans('uptizm.monitors.confirm_delete_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;

    controller.delete(monitor.id);
  }

  // ---------------------------------------------------------------------------
  // Loading skeleton
  // ---------------------------------------------------------------------------

  /// Builds the loading skeleton that mirrors the detail layout: a four-card KPI
  /// grid, the uptime block, and a tall response block. Matches the React
  /// `DetailSkeleton`.
  Widget _buildLoadingSkeleton() {
    // Uniform 32px (gap-8) between the KPI grid, the uptime block, and the
    // response block.
    return WDiv(
      className: 'flex flex-col gap-8',
      children: [
        // 1. KPI grid: four equal-height cards.
        WDiv(
          className: 'grid grid-cols-2 lg:grid-cols-4 gap-4',
          children: const [
            MSSkeleton(height: 88),
            MSSkeleton(height: 88),
            MSSkeleton(height: 88),
            MSSkeleton(height: 88),
          ],
        ),

        // 2. Uptime block: a short text line + a thin bar.
        WDiv(
          className: 'flex flex-col gap-3',
          children: const [
            MSSkeleton(shape: SkeletonShape.text, width: 160),
            MSSkeleton(height: 40),
          ],
        ),

        // 3. Response block: a wider text line + a tall chart placeholder.
        WDiv(
          className: 'flex flex-col gap-3',
          children: const [
            MSSkeleton(shape: SkeletonShape.text, width: 192),
            MSSkeleton(height: 240),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // KPI row
  // ---------------------------------------------------------------------------

  /// Builds the four-card KPI row from the monitor fixture.
  ///
  /// Single-column base, widening to two columns at `sm:` then four at `lg:`
  /// so the grid never forces a multi-column layout onto a narrow phone.
  Widget _buildKpiRow(Monitor monitor, bool paused) {
    // 1. Derive the headline metrics directly from the fixtures.
    final int openIncidents = incidentsForMonitor(
      monitor.name ?? '',
    ).where((i) => i.lifecycle != IncidentLifecycle.resolved).length;
    final String avgResponse = monitor.responseMs != null
        ? '${monitor.responseMs}ms'
        : '-';

    // 2. Single-column base; widen to two then four columns at breakpoints.
    return WDiv(
      className:
          'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_uptime_24h'),
          value: monitor.uptime,
          delta: paused ? null : '0.01%',
          trend: paused ? KpiTrend.neutral : KpiTrend.up,
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_avg_response'),
          value: avgResponse,
          hint: paused
              ? trans('uptizm.monitors.kpi_hint_paused')
              : trans('uptizm.monitors.kpi_hint_p50'),
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_last_check'),
          value: paused
              ? trans('uptizm.status.paused')
              : trans('uptizm.monitors.kpi_last_check_value'),
          hint: trans('uptizm.monitors.kpi_hint_interval', {
            'interval': monitor.intervalLabel,
          }),
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_open_incidents_for_monitor'),
          value: '$openIncidents',
          delta: openIncidents > 0
              ? trans('uptizm.monitors.kpi_delta_ongoing')
              : null,
          trend: openIncidents > 0 ? KpiTrend.down : KpiTrend.neutral,
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Uptime timeline
  // ---------------------------------------------------------------------------

  /// Builds the 90-day uptime section: a heading row with the trailing uptime
  /// figure, the [UptimeBar], and the 90-days-ago / today axis labels.
  ///
  /// [_uptimeSegments] is sourced from the live `GET
  /// /monitors/:id/response-times?range=90d` endpoint (see [_fetchData] and
  /// [MonitorController.loadUptime90]); it is empty while loading or on a
  /// failed fetch, in which case [UptimeBar] renders its own empty track.
  Widget _buildUptimeSection(Monitor monitor) {
    // Uniform 8px (gap-2) between the heading, the bar, and the axis labels.
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        // 1. Heading row: section title + trailing uptime figure.
        WDiv(
          className: 'flex flex-row items-center justify-between gap-3',
          children: [
            WText(
              trans('uptizm.monitors.uptime_last_90_days'),
              className: 'text-sm font-medium text-fg',
            ),
            WText(
              monitor.uptime,
              className: 'font-mono text-xs tabular-nums text-fg-muted',
            ),
          ],
        ),

        // 2. The 90-day bar (prominent height for the detail header).
        UptimeBar(segments: _uptimeSegments, size: UptimeBarSize.lg),

        // 3. Axis labels: 90 days ago (left) and today (right).
        WDiv(
          className: 'flex flex-row items-center justify-between gap-3',
          children: [
            WText(
              trans('uptizm.monitors.uptime_90_days_ago'),
              className: 'font-mono text-xs text-fg-muted',
            ),
            WText(
              trans('uptizm.monitors.uptime_today'),
              className: 'font-mono text-xs text-fg-muted',
            ),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Reliability
  // ---------------------------------------------------------------------------

  /// Builds the reliability section: a heading, a responsive two-column grid of
  /// 7-day and 30-day [SloBudgetCard]s, and a budget-burn [AiInsight] when the
  /// 30-day budget is at risk or breached.
  ///
  /// Shown only for active monitors with a configured [sloTarget] (the caller
  /// gates on `!paused && sloTarget != null`).
  Widget _buildReliabilitySection(Monitor monitor, double sloTarget) {
    // 1. Resolve the two uptime windows, mirroring the React fallback chain:
    //    u30 = sloUptime30d ?? parse(uptime); u7 = sloUptime7d ?? u30.
    final double u30 = monitor.sloUptime30d ?? _parseUptime(monitor.uptime);
    final double u7 = monitor.sloUptime7d ?? u30;

    // 2. Compute both error budgets so the copy variant can compare tones.
    final SloErrorBudget budget7 = computeErrorBudget(
      sloTarget,
      u7,
      windowDays: 7,
    );
    final SloErrorBudget budget30 = computeErrorBudget(
      sloTarget,
      u30,
      windowDays: 30,
    );

    // Uniform 12px (gap-3) between the heading, the gauge grid, and the
    // conditional budget-burn insight.
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        // 3. Section heading.
        WText(
          trans('uptizm.monitors.section_reliability'),
          className: 'text-sm font-medium text-fg',
        ),

        // 4. Responsive 2-col grid of the 7-day + 30-day budget gauges.
        WDiv(
          className: 'grid grid-cols-1 sm:grid-cols-2 gap-4',
          children: [
            SloBudgetCard(
              target: sloTarget,
              uptimePct: u7,
              windowDays: 7,
              windowLabel: '7-day',
            ),
            SloBudgetCard(
              target: sloTarget,
              uptimePct: u30,
              windowDays: 30,
              windowLabel: '30-day',
            ),
          ],
        ),

        // 5. Budget-burn insight, shown only when the 30-day budget is at risk
        //    or breached (tone != up).
        if (budget30.tone != SloBudgetTone.up)
          AiInsight(
            child: WText(
              _budgetBurnCopy(monitor, sloTarget, budget7, budget30),
            ),
          ),
      ],
    );
  }

  /// Picks the budget-burn copy variant from the 7-day / 30-day budget tones,
  /// mirroring the React `MonitorDetailPage` branch (lines ~226-232):
  ///
  /// - 30-day breached + 7-day recovered → "back inside SLO, budget spent".
  /// - 30-day breached + still burning → "spent and still burning".
  /// - 30-day at risk (degraded) → "burned most of its 30-day budget".
  String _budgetBurnCopy(
    Monitor monitor,
    double sloTarget,
    SloErrorBudget budget7,
    SloErrorBudget budget30,
  ) {
    final Map<String, Object> args = {
      'name': monitor.name ?? '',
      'slo': _formatSloTarget(sloTarget),
    };

    if (budget30.tone == SloBudgetTone.down) {
      return budget7.tone == SloBudgetTone.up
          ? trans('uptizm.monitors.reliability_burn_breached_recovering', args)
          : trans('uptizm.monitors.reliability_burn_breached_burning', args);
    }
    return trans('uptizm.monitors.reliability_burn_at_risk', args);
  }

  // ---------------------------------------------------------------------------
  // Tabs
  // ---------------------------------------------------------------------------

  /// Builds the Overview / Metrics / Incidents tab strip and its panels.
  ///
  /// The `list` slot overrides the recipe's default `flex flex-row` with `wrap`
  /// so a long or localized tab label wraps to the next line instead of
  /// overflowing the strip on a narrow phone (the recipe's bare flex-row sizes
  /// to content under loose constraints). With the production labels this stays
  /// a single row; the wrap only engages defensively.
  ///
  /// The list spans the full content-column width via [WTabs]'s default
  /// `fullWidthList: true` (wind #128), so the `border-b` underline runs
  /// edge-to-edge without a manual `w-full` override.
  Widget _buildTabs(Monitor monitor, bool paused) {
    return MSTabs(
      tabs: [
        trans('uptizm.monitors.tab_overview'),
        trans('uptizm.monitors.tab_metrics'),
        trans('uptizm.monitors.tab_incidents'),
      ],
      selectedIndex: _tabIndex,
      onChanged: (i) => setState(() => _tabIndex = i),
      classNames: const {'list': 'wrap border-b border-color-border'},
      panelBuilder: (index) => switch (_DetailTab.values[index]) {
        _DetailTab.overview => _buildOverviewTab(monitor, paused),
        _DetailTab.metrics => MonitorMetricsTab(monitorId: monitor.id),
        _DetailTab.incidents => _buildIncidentsTab(monitor),
      },
    );
  }

  /// Builds the Overview panel: a response-time [MetricChart] (or a paused /
  /// no-data state) with a [DateRangePicker] in its heading row and a response
  /// [AiInsight] below the chart, followed by the recent [CheckHistoryTable].
  Widget _buildOverviewTab(Monitor monitor, bool paused) {
    final List<MetricDatum>? series = _responseSeriesFor(monitor);

    // The panel opens with a 16px top gap (pt-4) and separates its two groups
    // by 32px (gap-8); each group nests a 12px rhythm (gap-3).
    return WDiv(
      className: 'flex flex-col gap-8 pt-4',
      children: [
        // 1. Response-time group: heading row + chart/insight surface.
        WDiv(
          className: 'flex flex-col gap-3',
          children: [
            // Heading row: title + range picker (only when there is a series to
            // scope). Uses `wrap` so the picker stays beside the heading on a
            // wide surface but flows onto its own line on a narrow phone instead
            // of overflowing the row.
            WDiv(
              className: 'wrap items-center gap-3',
              children: [
                WText(
                  trans('uptizm.monitors.section_response_time', {
                    'range': _rangeShort(),
                  }),
                  className: 'text-sm font-medium text-fg',
                ),
                if (series != null)
                  DateRangePicker(
                    value: _range,
                    onChanged: (next) {
                      setState(() => _range = next);
                      unawaited(_fetchData());
                    },
                  ),
              ],
            ),

            // The chart + response insight, or a calm bordered state.
            _buildResponseSurface(monitor, paused, series),
          ],
        ),

        // 2. Recent checks group: heading + table.
        WDiv(
          className: 'flex flex-col gap-3',
          children: [
            WText(
              trans('uptizm.monitors.section_recent_checks'),
              className: 'text-sm font-medium text-fg',
            ),
            CheckHistoryTable(rows: _recentChecks),
          ],
        ),
      ],
    );
  }

  /// Builds the response-time surface for the Overview tab.
  ///
  /// Renders the [MetricChart] plus a response [AiInsight] (anomaly copy when
  /// anomalies are present, otherwise no-anomaly copy) when the monitor reports
  /// a response series; shows a paused or no-data [EmptyState] otherwise so the
  /// section never renders an empty chart frame.
  Widget _buildResponseSurface(
    Monitor monitor,
    bool paused,
    List<MetricDatum>? series,
  ) {
    if (series != null) {
      final List<MetricAnomaly> anomalies = _anomaliesFor(monitor);

      // The Overview response chart mirrors the React source: series + unit +
      // anomalies, but no AI expected-range band. The band belongs to the
      // deeper per-metric history view on the Metrics tab.
      return WDiv(
        className: 'flex flex-col gap-3',
        children: [
          MetricChart(
            data: series,
            series: _liveResponseSeries,
            unit: 'ms',
            anomalies: anomalies,
          ),
          AiInsight(
            child: WText(
              anomalies.isNotEmpty
                  ? trans('uptizm.monitors.response_insight_anomaly', {
                      'name': monitor.name,
                    })
                  : trans('uptizm.monitors.response_insight_clear', {
                      'name': monitor.name,
                    }),
            ),
          ),
        ],
      );
    }

    if (paused) {
      return _buildBorderedState(
        trans('uptizm.monitors.paused_title'),
        trans('uptizm.monitors.paused_description'),
      );
    }

    return _buildBorderedState(
      trans('uptizm.monitors.no_response_data_title'),
      trans('uptizm.monitors.no_response_data_description'),
    );
  }

  /// Builds the Incidents panel: a responsive grid of [IncidentCard]s for the
  /// incidents that touch this monitor, or a graceful [EmptyState] when none.
  Widget _buildIncidentsTab(Monitor monitor) {
    final List<IncidentSummary> monitorIncidents = incidentsForMonitor(
      monitor.name ?? '',
    );

    if (monitorIncidents.isEmpty) {
      return WDiv(
        className: 'pt-4',
        child: EmptyState(
          icon: Icons.check_circle_outline,
          title: trans('uptizm.monitors.no_incidents_title'),
          description: trans('uptizm.monitors.no_incidents_description'),
        ),
      );
    }

    return WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-2 gap-3 pt-4',
      children: [
        for (final incident in monitorIncidents)
          IncidentCard(
            incident: _asIncidentCardModel(incident),
            onTap: () => MagicRoute.to('/incidents/${incident.id}'),
          ),
      ],
    );
  }

  /// Projects a design-lab [IncidentSummary] onto an [Incident] model so the
  /// migrated [IncidentCard] (which now takes the ORM model) renders the
  /// design-lab incidents that touch this monitor.
  ///
  /// This screen still sources its incidents from the [incidentsForMonitor]
  /// fixtures (no per-monitor incident endpoint is wired yet); the projection
  /// only bridges the DTO onto the card's new model type. It is a type-only
  /// cascade from the incident-model migration, mapping the DTO enums back to
  /// their backend wire values so the model's wire-bridge decode reproduces the
  /// same impact / severity / lifecycle the DTO carried.
  Incident _asIncidentCardModel(IncidentSummary summary) {
    final int primaryIndex = summary.affectedMonitors.indexWhere(
      (m) => m.name == summary.monitorName,
    );
    return Incident.fromMap(<String, dynamic>{
      'id': summary.id,
      'title': summary.title,
      'impact': switch (summary.impact) {
        IncidentImpact.down => 'critical',
        IncidentImpact.degraded => 'minor',
        IncidentImpact.info => 'none',
      },
      'severity': switch (summary.severity) {
        IncidentSeverity.critical => 'critical',
        IncidentSeverity.warning => 'warn',
        IncidentSeverity.info => 'info',
      },
      'lifecycle': summary.lifecycle.name,
      'ai_owned': summary.aiOwned,
      'started_at': '2026-07-11T14:00:00Z',
      if (summary.lifecycle == IncidentLifecycle.resolved)
        'resolved_at': '2026-07-11T15:00:00Z',
      'primary_monitor_id': primaryIndex >= 0 ? 'm$primaryIndex' : 'm0',
      'monitors': <Map<String, dynamic>>[
        for (int i = 0; i < summary.affectedMonitors.length; i++)
          <String, dynamic>{
            'monitor_id': 'm$i',
            'name': summary.affectedMonitors[i].name,
          },
      ],
    });
  }

  // ---------------------------------------------------------------------------
  // Not-found
  // ---------------------------------------------------------------------------

  /// Builds the graceful not-found state shown when [findMonitor] returns null.
  ///
  /// Reuses the monitors error-load copy as a calm "couldn't load this
  /// monitor" message rather than crashing on an unknown route id.
  Widget _buildNotFound() {
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('uptizm.monitors.error_load_title'),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),
          EmptyState(
            title: trans('uptizm.monitors.error_load_title'),
            description: trans('uptizm.monitors.error_load_description'),
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /// The 24-hour response-time series for [monitor], or `null` when the monitor
  /// reports no response data (paused / down monitors with no timing).
  ///
  /// Only the fixtured monitors carry a series; everything else has none, which
  /// drives the no-data / paused surface.
  List<MetricDatum>? _responseSeriesFor(Monitor monitor) {
    return _responseData.isEmpty ? null : _responseData;
  }

  /// The anomaly markers for [monitor]'s response chart.
  ///
  /// Only the API gateway carries an anomaly fixture (the single pinned 13:00
  /// p99 spike), matching the React `responseAnomaliesFor` (`m.id !== "api"`
  /// returns none). Every other monitor charts a clean band.
  List<MetricAnomaly> _anomaliesFor(Monitor monitor) {
    // No live anomaly-detection source is wired into the detail chart yet, so
    // no markers are drawn (the AI suggestion inbox is the anomaly surface).
    return const [];
  }

  /// The compact label for the active range, e.g. "24h", resolved from
  /// [kDateRangePresets]; falls back to the raw value when no preset matches.
  String _rangeShort() {
    for (final preset in kDateRangePresets) {
      if (preset.value == _range) return preset.short;
    }
    return _range;
  }

  /// Parses a human uptime string (e.g. `"99.94%"`) into a double percentage.
  ///
  /// Returns `0` for the em-dash placeholder or any unparseable value, matching
  /// the React `parseFloat(monitor.uptime)` fallback semantics (the caller only
  /// reaches this path for monitors that already passed the SLO gate).
  double _parseUptime(String uptime) {
    return double.tryParse(uptime.replaceAll('%', '').trim()) ?? 0;
  }

  /// Formats an SLO target as a trimmed percentage string (e.g. `99.9` →
  /// `"99.9"`, `99.0` → `"99"`), dropping a trailing `.0`.
  String _formatSloTarget(double target) {
    if (target == target.roundToDouble()) return target.toStringAsFixed(0);
    return target.toString();
  }

  /// Builds a calm bordered placeholder for the response section when there is
  /// no chart to show (paused or no-response-data).
  Widget _buildBorderedState(String title, String description) {
    return WDiv(
      className: 'rounded-lg border border-color-border',
      child: EmptyState(title: title, description: description),
    );
  }
}
