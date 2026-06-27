import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../app/mocks/incidents.dart';
import '../../app/mocks/monitors.dart';
import '../../app/mocks/status.dart';
import '../../ui/components/empty_state/index.dart';
import '../../ui/components/kpi_stat_card/index.dart';
import '../../ui/components/monitor_list_row/index.dart';
import '../../ui/layouts/page_container.dart';

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
class MonitorsListView extends StatefulWidget {
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

class _MonitorsListViewState extends State<MonitorsListView> {
  /// The four filter tabs: All, Operational, Degraded, Down.
  static const List<_Filter> _filters = [
    _Filter(label: 'All'),
    _Filter(label: 'Operational', status: StatusKey.up),
    _Filter(label: 'Degraded', status: StatusKey.degraded),
    _Filter(label: 'Down', status: StatusKey.down),
  ];

  /// The index of the currently active filter tab.
  int _filterIndex = 0;

  /// Monitors that satisfy the active filter.
  List<MonitorSummary> get _visible {
    final selected = _filters[_filterIndex].status;
    if (selected == null) return monitors;
    return monitors.where((m) => m.status == selected).toList();
  }

  @override
  Widget build(BuildContext context) {
    // A plain Flutter Column scaffolds the page body so each descendant gets a
    // proper bounded width from PageContainer (same discipline as DashboardView).
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 1. Page header with a "New monitor" action button.
          PageHeader(
            title: trans('uptizm.monitors.title'),
            subtitle: trans('uptizm.monitors.description'),
            actions: [
              Button(
                onPressed: () => MagicRoute.to('/monitors/new'),
                child: WText(trans('uptizm.monitors.new_monitor')),
              ),
            ],
          ),
          const SizedBox(height: 24),

          // 2. KPI summary row: monitors used, operational, open incidents,
          //    average response time. Mirrors the React grid above the filter.
          _buildKpiRow(),
          const SizedBox(height: 32),

          // 3. Status filter + visible count.
          _buildFilterRow(),
          const SizedBox(height: 16),

          // 4. Monitor list, or an empty state when zero rows match.
          _buildList(),
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
    final int upCount = monitors.where((m) => m.status == StatusKey.up).length;
    final int downCount = monitors
        .where((m) => m.status == StatusKey.down)
        .length;
    final List<IncidentSummary> openIncidents = incidents
        .where((i) => i.lifecycle != IncidentLifecycle.resolved)
        .toList();
    final int aiActive = openIncidents.where((i) => i.aiOwned).length;
    final List<MonitorSummary> responders = monitors
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
          value: '${monitors.length} / 50',
          hint: 'Pro plan',
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_operational'),
          value: '$upCount / ${monitors.length}',
          delta: '$downCount down',
          trend: KpiTrend.down,
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_open_incidents'),
          value: '${openIncidents.length}',
          delta: '1 new',
          hint: '$aiActive AI-detected',
          trend: KpiTrend.down,
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
    return Row(
      children: [
        // The Flexible shrink-wraps the pill and lets it yield width on very
        // narrow screens rather than forcing the Row to overflow.
        Flexible(
          child: SegmentedControl(
            options: _filters.map((f) => f.label).toList(),
            selectedIndex: _filterIndex,
            onChanged: (i) => setState(() => _filterIndex = i),
          ),
        ),
        const SizedBox(width: 12),
        WText(
          '${_visible.length} of ${monitors.length}',
          className: 'font-mono text-xs tabular-nums text-fg-muted',
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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final monitor in visible) ...[
          MonitorListRow(
            monitor: monitor,
            onTap: () => MagicRoute.to('/monitors/${monitor.id}'),
          ),
          if (monitor != visible.last) const SizedBox(height: 8),
        ],
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
    final bool noMonitorsAtAll = monitors.isEmpty;

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
            ? Button(
                onPressed: () => MagicRoute.to('/monitors/new'),
                child: WText(trans('uptizm.monitors.new_monitor')),
              )
            : Button(
                intent: ButtonIntent.secondary,
                onPressed: () => setState(() => _filterIndex = 0),
                child: WText(trans('uptizm.monitors.empty_no_match_clear')),
              ),
      ),
    );
  }
}
