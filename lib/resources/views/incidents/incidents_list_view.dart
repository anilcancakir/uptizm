import 'package:flutter/material.dart' show Icons;
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
import '../../../app/support/formatters.dart' show formatMonthDayTime;
import '../../../ui/components/header_action/index.dart';
import '../../../ui/components/incident_card/incident_card.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/components/maintenance_card/index.dart';

/// **The Incidents list screen.**
///
/// Renders the team's incident history live over `api/v1`: a page header with
/// a "New incident" action, a counts row, a search + [SegmentedControl] filter,
/// and a scrollable list of [IncidentCard] rows. An [MSEmptyState] placeholder
/// is shown when the active filter + search query yields zero matches.
///
/// Reads [IncidentController] and [MaintenanceController], and it MUTATES: the
/// maintenance tab cancels a window through a confirm dialog. The docblock here
/// used to say the opposite, calling this a mock screen with no controller, no
/// network and no mutations, on a 500-line file that has all three. On a screen
/// carrying a destructive action that is the most misleading sentence it could
/// have opened with.
///
/// The page body is a Wind flex column (`gap-*` carries the section rhythm, not
/// `SizedBox` spacers); the shared [MSPageContainer] bounds the width.
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

  /// What is in the search box. The SERVER decides what it matches; this is
  /// only what the operator typed, held so the input can render it.
  String _query = '';

  /// Holds a keystroke back from the network until typing pauses.
  ///
  /// Without it every character is a request, and the roster is cursor
  /// paginated, so each one refetches page one and re-renders the list under
  /// the operator's hands.
  Timer? _searchDebounce;

  /// How long typing has to pause before the term goes to the server.
  static const Duration _searchDebounceDelay = Duration(milliseconds: 350);

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

    // The controller outlives this widget, so a term set before navigating into
    // an incident is still narrowing the roster on the way back. Reading it
    // here is what stops a filtered list rendering under an empty search box.
    _query = controller.searchTerm ?? '';

    // `?tab=maintenance` selects the tab, which is how a create lands on the
    // list that actually contains what it just made. A page builder receives
    // only PATH params, so the query is read from the router here.
    if (MagicRouter.instance.queryParameters['tab'] == 'maintenance') {
      _filter = _IncidentFilter.maintenance;
    } else {
      // Same reason the query above is read from the controller: the tab is
      // view state and dies with the widget, the filter behind it is controller
      // state and does not, so a return from an incident detail would otherwise
      // put "All" above a roster still narrowed to "Resolved".
      _filter = _filterFromController();
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
    // A pending debounce would fire into a disposed widget's controller.
    _searchDebounce?.cancel();
    super.dispose();
  }

  /// Records a keystroke and schedules the term for the server.
  void _onQueryChanged(String value) {
    setState(() => _query = value);

    _searchDebounce?.cancel();
    _searchDebounce = Timer(
      _searchDebounceDelay,
      () => unawaited(controller.search(value)),
    );
  }

  /// Clears the search immediately, without waiting out a debounce.
  void _clearQuery() {
    _searchDebounce?.cancel();
    setState(() => _query = '');
    unawaited(controller.search(null));
  }

  /// Rebuilds when the maintenance roster lands or changes.
  void _onMaintenanceChanged() {
    if (mounted) setState(() {});
  }

  /// Whether the maintenance tab is the active one.
  bool get _isMaintenanceTab => _filter == _IncidentFilter.maintenance;

  /// Incidents that satisfy the active filter tab.
  ///
  /// The SEARCH QUERY is deliberately absent here. It goes to the server
  /// through [IncidentController.search], because this roster is paginated and
  /// a Dart filter can only read the page it happens to hold: a term matching
  /// an incident on page four returned nothing at all.
  ///
  /// Moving it there is what forced the backend to stop searching the `title`
  /// column. That column is the pinned ENGLISH sentence, while the operator is
  /// reading `title_key` + `title_params` rendered in their own language, so
  /// the words on screen were not the words being searched.
  /// `incidents.title_search` carries every render at once.
  ///
  /// The TAB is applied by the server too, through
  /// [IncidentController.filterTo]. It had the same page-shaped limit the
  /// search used to: a roster whose only resolved incident sat on page three
  /// showed nothing under "Resolved" until the operator loaded that far, and
  /// the counter beside the tabs agreed with it.
  ///
  /// So the roster the controller holds IS the visible set, and this getter
  /// only answers for the maintenance tab, which renders a different list
  /// entirely.
  List<Incident> get _visible {
    return _isMaintenanceTab ? const [] : controller.incidents;
  }

  /// The tab that matches the filter the controller is currently carrying.
  ///
  /// The inverse of [_applyTabFilter], and checked in the same order the tabs
  /// are exclusive in: only one of the three axes is ever set.
  _IncidentFilter _filterFromController() {
    if (controller.aiOwnedFilter == true) return _IncidentFilter.ai;
    if (controller.lifecycleFilter == IncidentLifecycle.resolved.name) {
      return _IncidentFilter.resolved;
    }
    if (controller.openOnlyFilter) return _IncidentFilter.open;

    return _IncidentFilter.all;
  }

  /// Pushes the active tab onto the controller's server-side filter.
  ///
  /// The maintenance tab deliberately leaves the incident filter untouched: it
  /// renders windows rather than incidents, and refetching the roster to a
  /// narrower shape on the way past would make coming back show less than the
  /// operator left.
  void _applyTabFilter() {
    if (_isMaintenanceTab) return;

    unawaited(switch (_filter) {
      _IncidentFilter.all => controller.filterTo(),
      _IncidentFilter.open => controller.filterTo(openOnly: true),
      _IncidentFilter.ai => controller.filterTo(aiOwned: true),
      _IncidentFilter.resolved => controller.filterTo(
        lifecycle: IncidentLifecycle.resolved.name,
      ),
      _IncidentFilter.maintenance => Future<void>.value(),
    });
  }

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so re-entering this route
  /// would otherwise re-render the data fetched the first time it was ever
  /// opened. See [RefetchesOnMount].
  @override
  Future<void> refetch() => controller.ensureFresh();

  @override
  Widget build(BuildContext context) {
    // The page body is a Wind flex column: section rhythm is carried by `gap-*`,
    // not `SizedBox` spacers. The outer `gap-8` (32px) separates the header
    // block from the search block; the header block nests its own `gap-6` (24px)
    // between header and counts, and the search block a `gap-4` (16px) between
    // its search input, filter row, and list.
    return MSPageContainer(
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
                  HeaderAction(
                    icon: Icons.add,
                    label: trans('uptizm.incidents.new_incident'),
                    onPressed: () => MagicRoute.to('/incidents/new'),
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

  /// One headline count, or the no-data placeholder when the read that would
  /// establish it has not answered.
  String _countLabel(int? count) => count != null ? '$count' : '—';

  /// Builds the four count cards that mirror the React `grid grid-cols-2
  /// lg:grid-cols-4 gap-4` row: active, critical-open, ai-detected, resolved.
  Widget _buildCountsRow() {
    // 1. The four counts come from the SERVER, team-wide, not from the rows on
    //    screen. Deriving them here answered for one page: a team with sixty
    //    incidents reported on its first twenty-five, and once the tabs moved
    //    server-side it got worse, because selecting Resolved narrowed the very
    //    rows the header was counting and it said "0 active" while three were
    //    burning.
    //
    //    Null until a read answers, and rendered as the no-data placeholder
    //    rather than as 0: "no active incidents" is the most reassuring thing
    //    this row can say and the least safe thing to guess.
    final int? activeCount = controller.openTotal;
    final int? criticalCount = controller.criticalTotal;
    final int? aiCount = controller.aiTotal;
    final int? resolvedCount = controller.resolvedTotal;

    // 2. Single-column base; widen to two then four columns at breakpoints.
    return WDiv(
      className: 'grid grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.incidents.count_active'),
          value: _countLabel(activeCount),
          delta: (activeCount ?? 0) > 0
              ? trans('uptizm.monitors.kpi_delta_ongoing')
              : null,
          trend: (activeCount ?? 0) > 0 ? KpiTrend.down : KpiTrend.neutral,
        ),
        KpiStatCard(
          label: trans('uptizm.incidents.count_critical'),
          value: _countLabel(criticalCount),
          trend: (criticalCount ?? 0) > 0 ? KpiTrend.down : KpiTrend.neutral,
        ),
        KpiStatCard(
          label: trans('uptizm.incidents.count_ai'),
          value: _countLabel(aiCount),
          hint: trans('uptizm.incidents.count_ai_hint'),
        ),
        KpiStatCard(
          label: trans('uptizm.incidents.count_resolved'),
          value: _countLabel(resolvedCount),
          trend: KpiTrend.up,
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Search + filter row
  // ---------------------------------------------------------------------------

  /// Builds the search input that narrows the roster by title or monitor name.
  ///
  /// The narrowing happens on the server, a debounce after the last keystroke;
  /// see [_onQueryChanged].
  Widget _buildSearchRow() {
    return MSInput(
      value: _query,
      onChanged: _onQueryChanged,
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
            onChanged: (i) {
              setState(() => _filter = _IncidentFilter.values[i]);
              _applyTabFilter();
            },
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
              // How many are LOADED, not "visible of total": the tab and the
              // query are the server's job now, so those two numbers are the
              // same number and stating one as a fraction of itself said
              // nothing. The roster is cursor paginated and there is no count
              // to divide by, so a further page is marked rather than counted.
              : '${controller.incidents.length}${controller.hasMore ? '+' : ''}',
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
        // The roster pages, and nothing here could reach the second one: the
        // controller has exposed `loadMore` since pagination landed and no
        // screen called it, so a team's twenty-sixth incident was unreachable.
        // A footer rather than an inner scroll view, matching the monitors
        // list: this page already scrolls, and a lazy list needs a bounded
        // height, which would nest a second scroll area inside the first.
        if (controller.hasMore)
          WDiv(
            className: 'flex flex-row justify-center pt-2',
            children: [
              MSButton(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                isLoading: controller.isLoadingMore,
                onPressed: () => unawaited(controller.loadMore()),
                child: WText(trans('uptizm.common.load_more')),
              ),
            ],
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
      onCancel: () => unawaited(_confirmCancel(window)),
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
          // With the kind, because this button says "Plan a window": without it
          // the create view opened on the INCIDENT form, one unnoticed switch
          // away from paging the on-call and publishing a red banner for work
          // the operator meant to ANNOUNCE.
          onPressed: () => MagicRoute.to(
            '/incidents/new',
            query: const {'kind': 'maintenance'},
          ),
          child: WText(trans('uptizm.incidents.maintenance_empty_action')),
        ),
      ),
    );
  }


  /// Opens the cancel [MagicStarterConfirmDialog]; on confirm, fires
  /// [MaintenanceController.delete].
  ///
  /// This was the one destructive action in the app that fired straight from
  /// the card, with no confirmation on either side: the controller does not
  /// confirm either. One mis-tap on a phone list row permanently removed a
  /// window, and there is no undo. An announced window cannot even be recreated
  /// as itself, because the announce-once guard is `announced_at` and a fresh
  /// row does not carry it.
  ///
  /// Mirrors `escalation_policies_view`'s `_confirmDelete`, including the
  /// `if (!mounted) return;` guard after the awaited dialog.
  ///
  /// KNOWN GAP, deliberately left open rather than papered over: a window that
  /// was already announced has had a "maintenance is coming" mail sent to the
  /// page's confirmed subscribers, and cancelling it tells them nothing. That
  /// wants a cancellation announcement, which is its own piece of work.
  Future<void> _confirmCancel(ScheduledMaintenance window) async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.incidents.maintenance_cancel_confirm_title', {
        'title': window.title,
      }),
      description: trans('uptizm.incidents.maintenance_cancel_confirm_description'),
      confirmLabel: trans('uptizm.incidents.maintenance_cancel_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    if (!mounted) return;

    await _maintenance.delete(window.id);
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
    // "You have never had an incident" is a claim about the TEAM, and the rows
    // in hand stopped being able to make it once the tab and the query moved to
    // the server: a search matching nothing empties this list, and reading that
    // as "no incidents yet" offered the operator a Create button where they
    // wanted their filter back.
    //
    // The two team-wide counts partition every incident (open plus resolved is
    // all of them), so their sum answers it. Null until a read lands, and an
    // unanswered read falls to the FILTERED copy on purpose: that wording
    // claims less.
    final int? openTotal = controller.openTotal;
    final int? resolvedTotal = controller.resolvedTotal;
    final bool neverHadIncidents = openTotal != null &&
        resolvedTotal != null &&
        openTotal + resolvedTotal == 0;

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
                onPressed: () {
                  setState(() => _filter = _IncidentFilter.all);
                  // Clears on the server too, not only in the box: the roster
                  // itself is narrowed, so blanking the input alone would leave
                  // the empty state under a cleared search.
                  _clearQuery();
                },
                child: WText(trans('uptizm.incidents.empty_clear_filters')),
              ),
      ),
    );
  }
}
