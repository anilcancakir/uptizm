import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../app/mocks/monitors.dart';
import '../../app/mocks/status.dart';
import '../../ui/components/monitor_list_row/index.dart';
import '../../ui/layouts/page_container.dart';

/// **The Monitors list screen.**
///
/// Renders the full monitor inventory from design-lab mock fixtures (no
/// controller, no network): a page header, a [SegmentedControl] status filter,
/// and a scrollable list of [MonitorListRow] cards. An [EmptyState] placeholder
/// is shown when the active filter produces zero results.
///
/// Layout follows the same discipline as [DashboardView]: a plain Flutter
/// [Column] scaffolds the page so leaf components receive a bounded
/// full-width constraint from the shared [PageContainer]; Wind utilities only
/// appear on leaf containers, never as the outermost flex-scroll context.
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
          // 1. Page header.
          PageHeader(
            title: trans('uptizm.monitors.title'),
            subtitle: trans('uptizm.monitors.description'),
          ),
          const SizedBox(height: 24),

          // 2. Status filter + visible count.
          _buildFilterRow(),
          const SizedBox(height: 16),

          // 3. Monitor list, or an empty state when zero rows match.
          _buildList(),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Filter row
  // ---------------------------------------------------------------------------

  /// Builds the status filter row: [SegmentedControl] on the left, a tabular
  /// visible/total count on the right (hidden on narrow screens to save width).
  Widget _buildFilterRow() {
    return Row(
      children: [
        // The segmented control scrolls horizontally on very small screens via
        // a constrained shrink-wrap; min-w-0 keeps it from forcing the row wide.
        Flexible(
          child: SegmentedControl(
            options: _filters.map((f) => f.label).toList(),
            selectedIndex: _filterIndex,
            onChanged: (i) => setState(() => _filterIndex = i),
          ),
        ),
        const SizedBox(width: 12),
        WText(
          '${_visible.length} / ${monitors.length}',
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
  Widget _buildEmptyState() {
    final bool noMonitorsAtAll = monitors.isEmpty;

    return WDiv(
      className: 'rounded-lg border border-color-border',
      child: EmptyState(
        title: noMonitorsAtAll
            ? trans('uptizm.monitors.empty_title')
            : trans('uptizm.monitors.empty_filter_title'),
        description: noMonitorsAtAll
            ? trans('uptizm.monitors.empty_description')
            : trans('uptizm.monitors.empty_filter_description'),
      ),
    );
  }
}
