import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/refetches_on_mount.dart';
import '../../../app/controllers/incident_controller.dart';
import '../../../app/controllers/maintenance_controller.dart';
import '../../../app/models/incident.dart';
import '../../../app/models/scheduled_maintenance.dart';
import '../../../app/enums/incident_lifecycle.dart' show IncidentLifecycle;
import '../../../app/enums/incident_severity.dart' show IncidentSeverity;
import '../../../app/support/formatters.dart' show formatMonthDayTime;
import '../../../ui/components/incident_card/incident_card.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/components/maintenance_card/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Incidents list screen.**
///
/// Renders the full incident history from the design-lab mock fixtures (no
/// controller, no network): a page header with a "New incident" action, a
/// counts row, a search + [SegmentedControl] filter, and a scrollable list of
/// [IncidentCard] rows. An [MSEmptyState] placeholder is shown when the active
/// filter + search query yields zero matches.
///
/// Reads the incident fixtures through [IncidentController]; this is a mock
/// screen with no mutations, so the binding is read-only. The page body is a
/// Wind flex column (`gap-*` carries the section rhythm, not `SizedBox`
/// spacers); the shared [PageContainer] bounds the width.
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
class IncidentsListView extends MagicStatefulView<IncidentController> {
  /// Creates the [IncidentsListView].
  const IncidentsListView({super.key});

  @override
  State<IncidentsListView> createState() => _IncidentsListViewState();
}

/// The incident filter tabs from `IncidentsListPage.tsx:21-31`, plus the
/// maintenance tab this screen grew afterwards.
enum _IncidentFilter {
  /// Every incident regardless of lifecycle or ownership.
  all,

  /// Not yet resolved.
  open,

  /// Owned by Uptizm AI.
  ai,

  /// Lifecycle is [IncidentLifecycle.resolved].
  resolved,

  /// Scheduled maintenance windows, NOT incidents.
  ///
  /// The odd one out on purpose. A window is not an incident: it has no
  /// lifecycle, no severity and no on-call, so it is a different list rather
  /// than a filter over the same one, and it deliberately does not feed the
  /// counts row above (an ACTIVE count that included planned work would say a
  /// team is in trouble when it is doing maintenance).
  ///
  /// It lives here because this is where a window is created (`/incidents/new`
  /// under its maintenance kind) and where a create lands. Before this tab the
  /// backend's index, show, update and destroy endpoints had no caller at all: a
  /// window could be created and then only ever seen on the PUBLIC status page.
  maintenance,
}

class _IncidentsListViewState
    extends MagicStatefulViewState<IncidentController, IncidentsListView>
    with RefetchesOnMount<IncidentController, IncidentsListView> {
  /// The active filter tab; defaults to "All".
  _IncidentFilter _filter = _IncidentFilter.all;

  /// The current search query, matched against title and monitor name.
  String _query = '';

  /// The maintenance roster, read as a SECONDARY controller.
  ///
  /// This view is not its backing controller, so magic's `onInit` never fires
  /// for it and nothing else would fetch the roster: the tab rendered "0 of 0"
  /// against a database holding a window, with no request in the log. The first
  /// load is therefore triggered from [initState], and the listener is what
  /// carries it onto the screen when it lands.
  final MaintenanceController _maintenance = MaintenanceController.instance;

  @override
  void initState() {
    Magic.findOrPut(IncidentController.new);
    super.initState();

    // `?tab=maintenance` selects the tab, which is how a create lands on the
    // list that actually contains what it just made. A page builder receives
    // only PATH params, so the query is read from the router here.
    if (MagicRouter.instance.queryParameters['tab'] == 'maintenance') {
      _filter = _IncidentFilter.maintenance;
    }

    _maintenance.addListener(_onMaintenanceChanged);
    // Refetched on EVERY mount, not only the first, mirroring what
    // [RefetchesOnMount] already does for the incident roster: a window created
    // in another tab, by a teammate, or through the API would otherwise stay
    // invisible here until the app restarted. The skeleton is keyed on
    // `resolvedOnce`, so a revisit refreshes in place instead of flashing.
    unawaited(_maintenance.load());
  }

  @override
  void dispose() {
    _maintenance.removeListener(_onMaintenanceChanged);
    super.dispose();
  }

  /// Rebuilds when the maintenance roster lands or changes.
  void _onMaintenanceChanged() {
    if (mounted) setState(() {});
  }

  /// Whether the maintenance tab is the active one.
  bool get _isMaintenanceTab => _filter == _IncidentFilter.maintenance;

  /// Incidents that satisfy the active filter and search query.
  List<Incident> get _visible {
    return controller.incidents.where((i) {
      // 1. Filter tab first.
      final bool matchesFilter = switch (_filter) {
        _IncidentFilter.all => true,
        _IncidentFilter.open => i.lifecycle != IncidentLifecycle.resolved,
        _IncidentFilter.ai => i.aiOwned,
        _IncidentFilter.resolved => i.lifecycle == IncidentLifecycle.resolved,
        // The maintenance tab renders its own list, so this getter is never
        // consulted under it; matching nothing keeps the switch exhaustive
        // without inventing an incident meaning for a window.
        _IncidentFilter.maintenance => false,
      };
      if (!matchesFilter) return false;

      // 2. Then narrow by the case-insensitive title/monitorName query.
      final String trimmed = _query.trim().toLowerCase();
      if (trimmed.isEmpty) return true;
      return i.title.toLowerCase().contains(trimmed) ||
          i.monitorName.toLowerCase().contains(trimmed);
    }).toList();
  }

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so re-entering this route
  /// would otherwise re-render the data fetched the first time it was ever
  /// opened. See [RefetchesOnMount].
  @override
  Future<void> refetch() => controller.reload();

  @override
  Widget build(BuildContext context) {
    // The page body is a Wind flex column: section rhythm is carried by `gap-*`,
    // not `SizedBox` spacers. The outer `gap-8` (32px) separates the header
    // block from the search block; the header block nests its own `gap-6` (24px)
    // between header and counts, and the search block a `gap-4` (16px) between
    // its search input, filter row, and list.
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-8',
        children: [
          // 1. Header block: page header + counts row (24px rhythm).
          WDiv(
            className: 'flex flex-col gap-6',
            children: [
              MSPageHeader(
                title: trans('uptizm.incidents.list_title'),
                subtitle: trans('uptizm.incidents.list_description'),
                actions: [
                  MSButton(
                    onPressed: () => MagicRoute.to('/incidents/new'),
                    child: WText(trans('uptizm.incidents.new_incident')),
                  ),
                ],
              ),
              _buildCountsRow(),
            ],
          ),

          // 2. Search block: search input, filter row, and the list (16px
          //    rhythm), or an empty state when zero rows match.
          WDiv(
            className: 'flex flex-col gap-4',
            children: [_buildSearchRow(), _buildFilterRow(), _buildList()],
          ),
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
    // 1. Derive headline counts from the controller fixtures (mirrors
    //    IncidentsListPage.tsx:28-31). The active count reuses the controller's
    //    shared `activeIncidents` derivation rather than re-filtering here.
    final int activeCount = controller.activeIncidents.length;
    final int criticalCount = controller.incidents
        .where(
          (i) =>
              i.severity == IncidentSeverity.critical &&
              i.lifecycle != IncidentLifecycle.resolved,
        )
        .length;
    final int aiCount = controller.incidents.where((i) => i.aiOwned).length;
    final int resolvedCount = controller.incidents
        .where((i) => i.lifecycle == IncidentLifecycle.resolved)
        .length;

    // 2. Single-column base; widen to two then four columns at breakpoints.
    return WDiv(
      className: 'grid grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.incidents.count_active'),
          value: '$activeCount',
          delta: activeCount > 0
              ? trans('uptizm.monitors.kpi_delta_ongoing')
              : null,
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
    return MSInput(
      value: _query,
      onChanged: (value) => setState(() => _query = value),
      placeholder: trans('uptizm.incidents.search_placeholder'),
    );
  }

  /// Builds the filter row: [SegmentedControl] on the left, a tabular
  /// visible/total count on the right, with a 12px `gap-3`.
  ///
  /// The SegmentedControl stays in a [Flexible] (loose) slot that bounds its
  /// width so its `overflow-x-auto` root scrolls the segments horizontally on
  /// narrow phones rather than forcing the row to overflow; that overflow guard
  /// is structural, so the [Flexible] remains inside the Wind flex row. The
  /// count is hidden below the md breakpoint (mobile) to free width for the tabs.
  Widget _buildFilterRow() {
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        Flexible(
          child: MSSegmentedControl(
            options: [
              trans('uptizm.incidents.filter_all'),
              trans('uptizm.incidents.filter_open'),
              trans('uptizm.incidents.filter_ai'),
              trans('uptizm.incidents.filter_resolved'),
              trans('uptizm.incidents.filter_maintenance'),
            ],
            selectedIndex: _filter.index,
            onChanged: (i) =>
                setState(() => _filter = _IncidentFilter.values[i]),
            classNames: const {'root': 'overflow-x-auto'},
          ),
        ),
        // The count is desktop-only: on mobile it eats width the tabs need, so
        // hide it below the md breakpoint and let the tabs use the full row.
        WText(
          _isMaintenanceTab
              ? trans('uptizm.monitors.count_of', {
                  'visible': '${_maintenance.windows.length}',
                  'total': '${_maintenance.windows.length}',
                })
              : trans('uptizm.monitors.count_of', {
                  'visible': '${_visible.length}',
                  'total': '${controller.incidents.length}',
                }),
          className:
              'hidden md:flex font-mono text-xs tabular-nums text-fg-muted',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Incident list
  // ---------------------------------------------------------------------------

  /// Builds the scrollable incident list, a skeleton while the first read is in
  /// flight, or an [MSEmptyState] when the active filter + query matches no
  /// incidents.
  Widget _buildList() {
    if (_isMaintenanceTab) return _buildMaintenanceList();

    final visible = _visible;

    // Loading is not emptiness. Without this branch a team with open incidents
    // opened the page on "No incidents yet" and only swapped to its cards when
    // the fetch landed, which on an incident screen reads as "nothing is wrong"
    // for as long as the round trip takes.
    if (controller.isFirstLoad) {
      return _buildSkeleton();
    }

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
  // Maintenance list
  // ---------------------------------------------------------------------------

  /// Builds the maintenance-window list for the Maintenance tab.
  ///
  /// A skeleton until the roster has resolved once, because an empty list before
  /// the first fetch is not the same claim as "nothing planned".
  Widget _buildMaintenanceList() {
    if (!_maintenance.resolvedOnce) return _buildSkeleton();

    final List<ScheduledMaintenance> windows = _maintenance.windows;
    if (windows.isEmpty) return _buildMaintenanceEmptyState();

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        for (final ScheduledMaintenance window in windows)
          _buildMaintenanceCard(window),
      ],
    );
  }

  /// One window, rendered by [MaintenanceCard].
  ///
  /// The card is a component rather than markup here, and it is built from the
  /// same parts as [IncidentCard] (a stripe, a badge row, the title, a mono meta
  /// row) because these rows sit in the same list and have to read as the same
  /// kind of object. The first attempt was hand-rolled markup in this view and
  /// looked it: a very tall card with three unweighted lines in the top-left and
  /// Cancel floating in the middle of the empty right half.
  ///
  /// Formatting stays here: the view owns the locale and the timezone decision
  /// (the wire is UTC, an operator reads their own clock), so the card takes the
  /// range already rendered.
  Widget _buildMaintenanceCard(ScheduledMaintenance window) {
    final DateTime? startsAt = window.startsAt;
    final DateTime? endsAt = window.endsAt;
    final MaintenancePhase phase = MaintenancePhase.resolve(startsAt, endsAt);

    return MaintenanceCard(
      title: window.title,
      phase: phase,
      phaseLabel: _maintenancePhaseLabel(phase),
      components: window.monitorNames,
      range: _maintenanceWindowRange(startsAt, endsAt),
      suppressesAlerts: window.suppressAlerts,
      suppressLabel: trans('uptizm.incidents.maintenance_alerts_held'),
      onCancel: () => _maintenance.delete(window.id),
      cancelLabel: trans('uptizm.incidents.maintenance_cancel'),
    );
  }

  /// The phase in the operator's language.
  String _maintenancePhaseLabel(MaintenancePhase phase) => switch (phase) {
    MaintenancePhase.finished => trans(
      'uptizm.incidents.maintenance_phase_finished',
    ),
    MaintenancePhase.upcoming => trans(
      'uptizm.incidents.maintenance_phase_scheduled',
    ),
    MaintenancePhase.active => trans(
      'uptizm.incidents.maintenance_phase_active',
    ),
  };

  /// The window bounds in the operator's own timezone.
  ///
  /// [formatMonthDayTime] rather than a new formatter: it already converts to
  /// local and deliberately avoids month NAMES, which would leak untranslated
  /// English into every non-English locale. Local is the right frame because the
  /// wire carries UTC (`starts_at` / `ends_at` are posted through `toUtc()`)
  /// while an operator planning work reads the clock on their wall.
  String _maintenanceWindowRange(DateTime? startsAt, DateTime? endsAt) {
    if (startsAt == null || endsAt == null) return '';

    return '${formatMonthDayTime(startsAt)} → ${formatMonthDayTime(endsAt)}';
  }

  /// The Maintenance tab's own empty state: no windows planned.
  Widget _buildMaintenanceEmptyState() {
    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
        title: trans('uptizm.incidents.maintenance_empty_title'),
        description: trans('uptizm.incidents.maintenance_empty_description'),
        action: MSButton(
          onPressed: () => MagicRoute.to('/incidents/new'),
          child: WText(trans('uptizm.incidents.maintenance_empty_action')),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // First-load skeleton
  // ---------------------------------------------------------------------------

  /// Builds the first-load placeholder: the incident list's own shape, in
  /// skeletons.
  ///
  /// Same `gap-3` column rhythm as the real list, three cards deep: enough to
  /// read as a list without implying a specific incident count.
  Widget _buildSkeleton() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [for (int i = 0; i < 3; i++) _buildSkeletonCard()],
    );
  }

  /// One skeleton card, matching [IncidentCard]'s frame and internal rhythm:
  /// the same [MSCard] shell and `gap-2 p-4 pl-5` body around a badge row, the
  /// headline title line, and the mono meta line.
  ///
  /// The card's left accent stripe is deliberately absent: it encodes customer
  /// impact, which is exactly what has not been answered yet, and painting one
  /// in any status tone would be the skeleton making a claim.
  ///
  /// Every text placeholder carries an explicit height, matching the line box of
  /// the text it stands in for (20px for `text-sm`, 16px for `text-xs`). Without
  /// one an [MSSkeleton] collapses: its `WDiv` has no child to measure, so in a
  /// flex column it lays out 0px tall and the placeholder is invisible.
  Widget _buildSkeletonCard() {
    return MSCard(
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col gap-2 p-4 pl-5',
        children: const [
          WDiv(
            className: 'flex flex-row items-center gap-2',
            children: [
              MSSkeleton(width: 84, height: 22),
              MSSkeleton(width: 96, height: 22),
            ],
          ),
          MSSkeleton(shape: SkeletonShape.text, width: 260, height: 20),
          MSSkeleton(shape: SkeletonShape.text, width: 200, height: 16),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the appropriate [MSEmptyState] for the current situation:
  ///   - No incidents at all: mirrors "No incidents yet" from React.
  ///   - Filter/query active with no matches: "All clear" + a clear action.
  ///
  /// The dashed-border container mirrors `rounded-xl border-dashed border-border`
  /// from the React source.
  Widget _buildEmptyState() {
    final bool neverHadIncidents = controller.incidents.isEmpty;

    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
        title: neverHadIncidents
            ? trans('uptizm.incidents.empty_never_had_title')
            : trans('uptizm.incidents.empty_filtered_title'),
        description: neverHadIncidents
            ? trans('uptizm.incidents.empty_never_had_description')
            : trans('uptizm.incidents.empty_filtered_description'),
        action: neverHadIncidents
            ? MSButton(
                onPressed: () => MagicRoute.to('/incidents/new'),
                child: WText(trans('uptizm.incidents.new_incident')),
              )
            : MSButton(
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
