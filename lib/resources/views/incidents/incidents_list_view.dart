import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../../app/mocks/incidents.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/incident_card/incident_card.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Incidents list screen.**
///
/// Renders the full incident history from the design-lab mock fixtures (no
/// controller, no network): a page header with a "New incident" action, a
/// counts row, a search + [SegmentedControl] filter, and a scrollable list of
/// [IncidentCard] rows. An [EmptyState] placeholder is shown when the active
/// filter + search query yields zero matches.
///
/// Layout follows the same discipline as [MonitorsListView]: a plain Flutter
/// [Column] scaffolds the page so leaf components receive a bounded
/// full-width constraint from the shared [PageContainer]; Wind utilities only
/// appear on leaf containers, never as the outermost flex-scroll context.
///
/// Composition mirrors `IncidentsListPage.tsx`:
///   header → counts row → search + filter row → incident list or empty state.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/incidents` content (wrapped by the app shell):
/// MagicStarter.view.makeLayout('layout.app', child: const IncidentsListView())
/// ```
@immutable
class IncidentsListView extends StatefulWidget {
  /// Creates the [IncidentsListView].
  const IncidentsListView({super.key});

  @override
  State<IncidentsListView> createState() => _IncidentsListViewState();
}

/// The four incident filter tabs from `IncidentsListPage.tsx:21-31`.
enum _IncidentFilter {
  /// Every incident regardless of lifecycle or ownership.
  all,

  /// Not yet resolved.
  open,

  /// Owned by Uptizm AI.
  ai,

  /// Lifecycle is [IncidentLifecycle.resolved].
  resolved,
}

class _IncidentsListViewState extends State<IncidentsListView> {
  /// The active filter tab; defaults to "All".
  _IncidentFilter _filter = _IncidentFilter.all;

  /// The current search query, matched against title and monitor name.
  String _query = '';

  /// Incidents that satisfy the active filter and search query.
  List<IncidentSummary> get _visible {
    return incidents.where((i) {
      // 1. Filter tab first.
      final bool matchesFilter = switch (_filter) {
        _IncidentFilter.all => true,
        _IncidentFilter.open => i.lifecycle != IncidentLifecycle.resolved,
        _IncidentFilter.ai => i.aiOwned,
        _IncidentFilter.resolved => i.lifecycle == IncidentLifecycle.resolved,
      };
      if (!matchesFilter) return false;

      // 2. Then narrow by the case-insensitive title/monitorName query.
      final String trimmed = _query.trim().toLowerCase();
      if (trimmed.isEmpty) return true;
      return i.title.toLowerCase().contains(trimmed) ||
          i.monitorName.toLowerCase().contains(trimmed);
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    // A plain Flutter Column scaffolds the page body so each descendant gets a
    // proper bounded width from PageContainer (same discipline as MonitorsListView).
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 1. Page header with a "New incident" action button.
          PageHeader(
            title: trans('uptizm.incidents.list_title'),
            subtitle: trans('uptizm.incidents.list_description'),
            actions: [
              Button(
                onPressed: () => MagicRoute.to('/incidents/new'),
                child: WText(trans('uptizm.incidents.new_incident')),
              ),
            ],
          ),
          const SizedBox(height: 24),

          // 2. Counts row: active / critical-open / ai-owned / resolved.
          _buildCountsRow(),
          const SizedBox(height: 32),

          // 3. Search input + filter row.
          _buildSearchRow(),
          const SizedBox(height: 16),
          _buildFilterRow(),
          const SizedBox(height: 16),

          // 4. Incident list, or an empty state when zero rows match.
          _buildList(),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Counts row
  // ---------------------------------------------------------------------------

  /// Builds the four count cards that mirror the React `grid grid-cols-2
  /// lg:grid-cols-4 gap-4` row: active, critical-open, ai-detected, resolved.
  Widget _buildCountsRow() {
    // 1. Derive headline counts from the mock fixtures (mirrors
    //    IncidentsListPage.tsx:28-31).
    final int activeCount = incidents
        .where((i) => i.lifecycle != IncidentLifecycle.resolved)
        .length;
    final int criticalCount = incidents
        .where(
          (i) =>
              i.severity == IncidentSeverity.critical &&
              i.lifecycle != IncidentLifecycle.resolved,
        )
        .length;
    final int aiCount = incidents.where((i) => i.aiOwned).length;
    final int resolvedCount = incidents
        .where((i) => i.lifecycle == IncidentLifecycle.resolved)
        .length;

    // 2. Single-column base; widen to two then four columns at breakpoints.
    return WDiv(
      className: 'grid grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.incidents.count_active'),
          value: '$activeCount',
          delta: activeCount > 0 ? 'ongoing' : null,
          trend: activeCount > 0 ? KpiTrend.down : KpiTrend.neutral,
        ),
        KpiStatCard(
          label: trans('uptizm.incidents.count_critical'),
          value: '$criticalCount',
          trend: criticalCount > 0 ? KpiTrend.down : KpiTrend.neutral,
        ),
        KpiStatCard(
          label: trans('uptizm.incidents.count_ai'),
          value: '$aiCount',
          hint: trans('uptizm.incidents.count_ai_hint'),
        ),
        KpiStatCard(
          label: trans('uptizm.incidents.count_resolved'),
          value: '$resolvedCount',
          trend: KpiTrend.up,
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Search + filter row
  // ---------------------------------------------------------------------------

  /// Builds the search input that narrows the visible list by title or
  /// monitor name.
  Widget _buildSearchRow() {
    return Input(
      value: _query,
      onChanged: (value) => setState(() => _query = value),
      placeholder: trans('uptizm.incidents.search_placeholder'),
    );
  }

  /// Builds the filter row: [SegmentedControl] on the left, a tabular
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
            options: [
              trans('uptizm.incidents.filter_all'),
              trans('uptizm.incidents.filter_open'),
              trans('uptizm.incidents.filter_ai'),
              trans('uptizm.incidents.filter_resolved'),
            ],
            selectedIndex: _filter.index,
            onChanged: (i) =>
                setState(() => _filter = _IncidentFilter.values[i]),
          ),
        ),
        const SizedBox(width: 12),
        WText(
          '${_visible.length} of ${incidents.length}',
          className: 'font-mono text-xs tabular-nums text-fg-muted',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Incident list
  // ---------------------------------------------------------------------------

  /// Builds the scrollable incident list, or an [EmptyState] when the active
  /// filter + query matches no incidents.
  Widget _buildList() {
    final visible = _visible;

    if (visible.isEmpty) {
      return _buildEmptyState();
    }

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        for (final incident in visible)
          IncidentCard(
            incident: incident,
            onTap: () => MagicRoute.to('/incidents/${incident.id}'),
          ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the appropriate [EmptyState] for the current situation:
  ///   - No incidents at all: mirrors "No incidents yet" from React.
  ///   - Filter/query active with no matches: "All clear" + a clear action.
  ///
  /// The dashed-border container mirrors `rounded-xl border-dashed border-border`
  /// from the React source.
  Widget _buildEmptyState() {
    final bool neverHadIncidents = incidents.isEmpty;

    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: EmptyState(
        title: neverHadIncidents
            ? trans('uptizm.incidents.empty_never_had_title')
            : trans('uptizm.incidents.empty_filtered_title'),
        description: neverHadIncidents
            ? trans('uptizm.incidents.empty_never_had_description')
            : trans('uptizm.incidents.empty_filtered_description'),
        action: neverHadIncidents
            ? Button(
                onPressed: () => MagicRoute.to('/incidents/new'),
                child: WText(trans('uptizm.incidents.new_incident')),
              )
            : Button(
                intent: ButtonIntent.secondary,
                onPressed: () => setState(() {
                  _filter = _IncidentFilter.all;
                  _query = '';
                }),
                child: WText(trans('uptizm.incidents.empty_clear_filters')),
              ),
      ),
    );
  }
}
