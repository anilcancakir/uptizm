import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/dashboard_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/mocks/monitors.dart';
import '../../../app/mocks/status.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/components/monitor_list_row/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Monitors list screen.**
///
/// Renders the full monitor inventory from design-lab mock fixtures (no
/// controller, no network): a page header with a "New monitor" action, a KPI
/// summary row, a [SegmentedControl] status filter, and a scrollable list of
/// [MonitorListRow] cards. An [EmptyState] placeholder is shown when the active
/// filter produces zero results.
///
/// Layout follows the same discipline as [DashboardView]: a plain Flutter
/// [Column] scaffolds the page so leaf components receive a bounded
/// full-width constraint from the shared [PageContainer]; Wind utilities only
/// appear on leaf containers, never as the outermost flex-scroll context.
///
/// Composition mirrors `MonitorsListPage.tsx`:
///   header → KPI row → filter row + count → monitor list or empty state.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/monitors` content (wrapped by the app shell):
/// MagicStarter.view.makeLayout('layout.app', child: const MonitorsListView())
/// ```
@immutable
class MonitorsListView extends MagicStatefulView<MonitorController> {
  /// Creates the [MonitorsListView].
  const MonitorsListView({super.key});

  @override
  State<MonitorsListView> createState() => _MonitorsListViewState();
}

// ---------------------------------------------------------------------------
// Filter definition
// ---------------------------------------------------------------------------

/// The four status-filter tabs shown in the [SegmentedControl].
///
/// Each entry carries a display [label] and an optional [status] — `null`
/// means "All monitors regardless of status".
class _Filter {
  const _Filter({required this.label, this.status});

  /// Label shown in the segmented control tab.
  final String label;

  /// The [StatusKey] to match, or `null` for the "All" tab.
  final StatusKey? status;
}

class _MonitorsListViewState
    extends MagicStatefulViewState<MonitorController, MonitorsListView> {
  /// The four filter tabs: All, Operational, Degraded, Down.
  static const List<_Filter> _filters = [
    _Filter(label: 'All'),
    _Filter(label: 'Operational', status: StatusKey.up),
    _Filter(label: 'Degraded', status: StatusKey.degraded),
    _Filter(label: 'Down', status: StatusKey.down),
  ];

  /// The index of the currently active filter tab (ephemeral, per-screen input).
  int _filterIndex = 0;

  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(MonitorController.new);
    super.initState();
  }

  /// Monitors that satisfy the active filter.
  List<MonitorSummary> get _visible {
    final selected = _filters[_filterIndex].status;
    if (selected == null) return controller.monitors;
    return controller.monitors.where((m) => m.status == selected).toList();
  }

  @override
  Widget build(BuildContext context) {
    // The page body is a Wind flex column: section rhythm is carried by `gap-*`,
    // not SizedBox spacers. The outer gap-8 (32px) separates the header+KPI
    // group from the filter+list group; each inner group nests its own rhythm
    // (gap-6 = 24px header->KPI, gap-4 = 16px filter->list).
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-8',
        children: [
          // 1. Header + KPI summary row (24px apart).
          WDiv(
            className: 'flex flex-col gap-6',
            children: [
              // Page header with a "New monitor" action button.
              MSPageHeader(
                title: trans('uptizm.monitors.title'),
                subtitle: trans('uptizm.monitors.description'),
                actions: [
                  MSButton(
                    onPressed: () => MagicRoute.to('/monitors/new'),
                    child: WText(trans('uptizm.monitors.new_monitor')),
                  ),
                ],
              ),
              // KPI summary row: monitors used, operational, open incidents,
              // average response time. Mirrors the React grid above the filter.
              _buildKpiRow(),
            ],
          ),

          // 2. Status filter + list (16px apart).
          WDiv(
            className: 'flex flex-col gap-4',
            children: [_buildFilterRow(), _buildList()],
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // KPI row
  // ---------------------------------------------------------------------------

  /// Builds the four KPI stat cards that mirror the React `grid grid-cols-2
  /// lg:grid-cols-4 gap-4` row: monitors used, operational, open incidents,
  /// and average response time.
  Widget _buildKpiRow() {
    // 1. Derive headline metrics from the mock fixtures.
    final List<MonitorSummary> allMonitors = controller.monitors;
    final int upCount = allMonitors
        .where((m) => m.status == StatusKey.up)
        .length;
    final int downCount = allMonitors
        .where((m) => m.status == StatusKey.down)
        .length;
    // Live open-incident counts from the dashboard controller (a warm
    // singleton), NOT the design-lab `incidents` fixture: the monitors KPI must
    // agree with the dashboard's OPEN INCIDENTS. The realtime coalesced reload
    // refreshes both controllers together, so an open/resolve updates both.
    final DashboardController dashboard = DashboardController.instance;
    final int openIncidentCount = dashboard.openIncidentsCount;
    final int aiActive = dashboard.aiActiveCount;
    final List<MonitorSummary> responders = allMonitors
        .where((m) => m.responseMs != null)
        .toList();
    final int avgResponse = responders.isEmpty
        ? 0
        : (responders.fold<int>(0, (sum, m) => sum + m.responseMs!) /
                  responders.length)
              .round();

    // 2. Single-column base; widen to two then four columns at breakpoints.
    return WDiv(
      className: 'grid grid-cols-2 lg:grid-cols-4 gap-4',
      children: [
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_monitors_used'),
          value: '${allMonitors.length} / 50',
          hint: 'Pro plan',
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_operational'),
          value: '$upCount / ${allMonitors.length}',
          delta: '$downCount down',
          trend: KpiTrend.down,
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_open_incidents'),
          value: '$openIncidentCount',
          hint: '$aiActive AI-detected',
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_avg_response'),
          value: '${avgResponse}ms',
          delta: '12ms',
          hint: 'vs. last 24h',
          trend: KpiTrend.down,
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Filter row
  // ---------------------------------------------------------------------------

  /// Builds the status filter row: [SegmentedControl] on the left, a tabular
  /// visible/total count on the right.
  ///
  /// A Flutter [Row] with the SegmentedControl in a [Flexible] (loose) slot
  /// lets the pill shrink-wrap naturally without forcing the Row to overflow.
  Widget _buildFilterRow() {
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        // The Flexible bounds the pill's width so its `overflow-x-auto` root can
        // scroll the segments horizontally on narrow phones instead of forcing
        // the row to overflow (FlexFit.loose has no Wind className equivalent, so
        // the wrapper stays structural).
        Flexible(
          child: MSSegmentedControl(
            options: _filters.map((f) => f.label).toList(),
            selectedIndex: _filterIndex,
            onChanged: (i) => setState(() => _filterIndex = i),
            classNames: const {'root': 'overflow-x-auto'},
          ),
        ),
        // The count is desktop-only: on mobile it eats width the tabs need, so
        // hide it below the md breakpoint and let the tabs use the full row.
        WText(
          '${_visible.length} of ${controller.monitors.length}',
          className:
              'hidden md:flex font-mono text-xs tabular-nums text-fg-muted',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Monitor list
  // ---------------------------------------------------------------------------

  /// Builds the scrollable monitor list, or an [EmptyState] when the active
  /// filter matches no monitors.
  Widget _buildList() {
    final visible = _visible;

    if (visible.isEmpty) {
      return _buildEmptyState();
    }

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        for (final monitor in visible)
          MonitorListRow(
            monitor: monitor,
            onTap: () => MagicRoute.to('/monitors/${monitor.id}'),
          ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the appropriate [EmptyState] for the current situation:
  ///   - No monitors at all: invite the user to add their first endpoint.
  ///   - Filter active with no matches: invite the user to clear the filter.
  ///
  /// The dashed-border container mirrors `rounded-xl border-dashed border-border`
  /// from the React source.
  Widget _buildEmptyState() {
    final bool noMonitorsAtAll = controller.monitors.isEmpty;

    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: EmptyState(
        title: noMonitorsAtAll
            ? trans('uptizm.monitors.empty_no_monitors_title')
            : trans('uptizm.monitors.empty_no_match_title'),
        description: noMonitorsAtAll
            ? trans('uptizm.monitors.empty_no_monitors_description')
            : trans('uptizm.monitors.empty_no_match_description'),
        action: noMonitorsAtAll
            ? MSButton(
                onPressed: () => MagicRoute.to('/monitors/new'),
                child: WText(trans('uptizm.monitors.new_monitor')),
              )
            : MSButton(
                intent: ButtonIntent.secondary,
                onPressed: () => setState(() => _filterIndex = 0),
                child: WText(trans('uptizm.monitors.empty_no_match_clear')),
              ),
      ),
    );
  }
}
