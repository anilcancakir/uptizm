import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/support/refetches_on_mount.dart';
import '../../../app/controllers/dashboard_controller.dart';
import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/monitor.dart';
import '../../../app/enums/status_key.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/components/monitor_list_row/index.dart';

/// **The Monitors list screen.**
///
/// Renders the full monitor inventory from design-lab mock fixtures (no
/// controller, no network): a page header with a "New monitor" action, a KPI
/// summary row, a [SegmentedControl] status filter, and a scrollable list of
/// [MonitorListRow] cards. An [MSEmptyState] placeholder is shown when the
/// active filter produces zero results.
///
/// Layout follows the same discipline as [DashboardView]: a plain Flutter
/// [Column] scaffolds the page so leaf components receive a bounded
/// full-width constraint from the shared [MSPageContainer]; Wind utilities only
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
    extends MagicStatefulViewState<MonitorController, MonitorsListView>
    with RefetchesOnMount<MonitorController, MonitorsListView> {
  /// The four filter tabs: All, Operational, Degraded, Down.
  ///
  /// A getter (not a `const`) so each tab label resolves through [trans] at the
  /// current locale; the [StatusKey] mapping stays fixed.
  static List<_Filter> get _filters => [
    _Filter(label: trans('uptizm.monitors.filter_all')),
    _Filter(label: trans('uptizm.monitors.filter_operational'), status: StatusKey.up),
    _Filter(label: trans('uptizm.monitors.filter_degraded'), status: StatusKey.degraded),
    _Filter(label: trans('uptizm.monitors.filter_down'), status: StatusKey.down),
  ];

  /// The index of the currently active filter tab (ephemeral, per-screen input).
  int _filterIndex = 0;

  /// Platform-resolved billing service for the entitlement read.
  /// Shared billing entitlement driving the monitor plan gates (the New-monitor
  /// cap and the "monitors used" KPI). Listened to so the gates re-render when
  /// the real plan and usage land from the backend.
  final EntitlementController _entitlement = EntitlementController.instance;

  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(MonitorController.new);
    super.initState();
    _entitlement.addListener(_onEntitlement);
  }

  @override
  void dispose() {
    _entitlement.removeListener(_onEntitlement);
    super.dispose();
  }

  /// Re-render the plan gates when the real entitlement (plan + usage) lands.
  void _onEntitlement() {
    if (mounted) setState(() {});
  }

  /// Whether the team is below its plan's monitor cap. Uses the loaded list
  /// count (the freshest source) against the entitlement's limit; unlimited
  /// (null limit) is always allowed.
  bool get _canCreateMonitor {
    final int? limit = _entitlement.currentLimits.monitors;
    return limit == null || controller.monitors.length < limit;
  }

  /// Nudges to upgrade when the New-monitor action is tapped at the cap,
  /// mirroring the backend's own 422 message so the two never diverge.
  void _nudgeMonitorLimit() {
    final int? limit = _entitlement.currentLimits.monitors;
    // The cap is known client-side, so there is no gated response to read the
    // tier off: resolve the cheapest plan whose monitor cap is above what this
    // team already uses. An empty id means the catalog has not loaded, and
    // [UpgradePrompt] then lands on billing without naming a tier rather than
    // starting checkout for one nobody chose.
    final int used = controller.monitors.length;
    final String requiredPlan = _entitlement.planIdUnlocking(
      (limits) => limits.monitors == null || limits.monitors! > used,
    );

    UpgradePrompt.show(
      PlanUpgradeRequirement(
        message: trans('uptizm.monitors.limit_nudge', {
          'plan': _entitlement.planName,
          'count': '$limit',
          'noun': trans(
            limit == 1
                ? 'uptizm.monitors.noun_one'
                : 'uptizm.monitors.noun_other',
          ),
        }),
        requiredPlan: requiredPlan,
        feature: trans('uptizm.monitors.title'),
      ),
    );
  }

  /// Monitors that satisfy the active filter.
  List<Monitor> get _visible {
    final selected = _filters[_filterIndex].status;
    if (selected == null) return controller.monitors;
    return controller.monitors.where((m) => m.status == selected).toList();
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
    // not SizedBox spacers. The outer gap-8 (32px) separates the header+KPI
    // group from the filter+list group; each inner group nests its own rhythm
    // (gap-6 = 24px header->KPI, gap-4 = 16px filter->list).
    return MSPageContainer(
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
                    // Proactive cap: below the plan's monitor limit this opens
                    // the create flow; at the cap it nudges to upgrade instead
                    // of letting the create form 422 on save.
                    onPressed: _canCreateMonitor
                        ? () => MagicRoute.to('/monitors/new')
                        : _nudgeMonitorLimit,
                    child: WText(trans('uptizm.monitors.new_monitor')),
                  ),
                ],
              ),
              // KPI summary row: monitors used, operational, open incidents,
              // average response time. Mirrors the React grid above the filter.
              //
              // Wrapped in a ListenableBuilder because two of the four cards
              // read [DashboardController], which is NOT this view's backing
              // controller: resolving `.instance` self-triggers its first
              // fetch, but without this listener the row would keep rendering
              // the pre-fetch zeros after that fetch lands, and "OPEN
              // INCIDENTS 0" reads as a fact rather than as data not yet in.
              ListenableBuilder(
                listenable: DashboardController.instance,
                builder: (BuildContext context, Widget? child) =>
                    _buildKpiRow(),
              ),
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
    // 1. Derive headline metrics from the live monitor roster.
    final List<Monitor> allMonitors = controller.monitors;
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
    // The average is a claim about what is being measured now, so a paused
    // monitor's frozen timing is excluded. The backend excludes it from
    // `dashboard/stats` too; including it here made the two pages disagree
    // about one number.
    final List<Monitor> responders = allMonitors
        .where((m) => m.responseMs != null && m.status != StatusKey.paused)
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
          value:
              '${allMonitors.length} / ${_entitlement.currentLimits.monitors ?? '∞'}',
          hint: _entitlement.planName.isEmpty
              ? null
              : trans('uptizm.monitors.kpi_plan_hint', {
                  'plan': _entitlement.planName,
                }),
        ),

        KpiStatCard(
          label: trans('uptizm.monitors.kpi_operational'),
          value: '$upCount / ${allMonitors.length}',
          // A down count of zero is good news: rendering it as a red downward
          // trend made a healthy fleet read as degraded.
          delta: downCount > 0
              ? trans('uptizm.dashboard.kpi_delta_down', {
                  'count': '$downCount',
                })
              : null,
          trend: downCount > 0 ? KpiTrend.down : KpiTrend.neutral,
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_open_incidents'),
          value: '$openIncidentCount',
          hint: trans('uptizm.dashboard.kpi_hint_ai_detected', {
            'count': '$aiActive',
          }),
        ),
        KpiStatCard(
          // No monitor reporting a timing is a no-data state, not 0ms, and
          // there is no prior-window average to compare against: the delta and
          // its "vs. last 24h" hint were a hardcoded 12ms literal.
          label: trans('uptizm.monitors.kpi_avg_response'),
          value: responders.isEmpty ? '—' : '${avgResponse}ms',
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
          trans('uptizm.monitors.count_of', {
            'visible': '${_visible.length}',
            'total': '${controller.monitors.length}',
          }),
          className:
              'hidden md:flex font-mono text-xs tabular-nums text-fg-muted',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Monitor list
  // ---------------------------------------------------------------------------

  /// Builds the scrollable monitor list, a skeleton while the first read is in
  /// flight, or an [MSEmptyState] when the active filter matches no monitors.
  Widget _buildList() {
    final visible = _visible;

    // Loading is not emptiness. Without this branch a populated account opened
    // the page on "No monitors yet", complete with an "add your first endpoint"
    // invitation, and only swapped to its rows when the fetch landed, which
    // reads as "you have none" for as long as the round trip takes.
    if (controller.isFirstLoad) {
      return _buildSkeleton();
    }

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
  // First-load skeleton
  // ---------------------------------------------------------------------------

  /// Builds the first-load placeholder: the monitor list's own shape, in
  /// skeletons.
  ///
  /// Same `gap-2` column rhythm as the real list, three rows deep: enough to
  /// read as a list without implying a specific inventory size (a single row
  /// would suggest the team runs exactly one monitor).
  Widget _buildSkeleton() {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [for (int i = 0; i < 3; i++) _buildSkeletonRow()],
    );
  }

  /// One skeleton row, matching [MonitorListRow]'s frame and internal rhythm:
  /// the same row shell (border, radius, padding, min height) around a
  /// name/URL column, the fixed-width latency slot, and the trailing badge.
  ///
  /// Every text placeholder carries an explicit height, matching the line box of
  /// the text it stands in for (20px for `text-sm`, 16px for `text-xs`). Without
  /// one an [MSSkeleton] collapses: its `WDiv` has no child to measure, so in a
  /// flex column it lays out 0px tall and the placeholder is invisible.
  Widget _buildSkeletonRow() {
    return const WDiv(
      className:
          'flex flex-row items-center gap-3 rounded-lg border '
          'border-color-border bg-surface px-4 py-3 min-h-[44px]',
      children: [
        WDiv(
          className: 'flex flex-col gap-0.5 min-w-0 flex-1',
          children: [
            MSSkeleton(shape: SkeletonShape.text, width: 160, height: 20),
            MSSkeleton(shape: SkeletonShape.text, width: 220, height: 16),
          ],
        ),
        MSSkeleton(width: 48, height: 16),
        MSSkeleton(width: 84, height: 22),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Empty state
  // ---------------------------------------------------------------------------

  /// Builds the appropriate [MSEmptyState] for the current situation:
  ///   - No monitors at all: invite the user to add their first endpoint.
  ///   - Filter active with no matches: invite the user to clear the filter.
  ///
  /// The dashed-border container mirrors `rounded-xl border-dashed border-border`
  /// from the React source.
  Widget _buildEmptyState() {
    final bool noMonitorsAtAll = controller.monitors.isEmpty;

    return WDiv(
      className: 'rounded-xl border border-dashed border-color-border',
      child: MSEmptyState(
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
