import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/dashboard_controller.dart';
import '../../../app/mocks/incidents.dart';
import '../../../ui/components/ai_inbox_item/index.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/incident_card/index.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/components/monitor_list_row/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Dashboard home screen.**
///
/// Composes the whole product at a glance from the backend `api/v1` aggregate
/// endpoints via [DashboardController]: a KPI summary row, the active
/// incidents, a monitor snippet, and the AI inbox (anomalies awaiting the
/// operator's call).
///
/// Section order mirrors the React `DashboardPage.tsx` source (header → AI
/// fleet-summary banner → KPI row → active incidents → monitor snippet → AI
/// inbox), while the column layout respects the in-repo components' real width
/// floors:
///
/// - A `"Right now"` AI fleet-summary banner ([AiInsight] `tone: banner`) sits
///   between the header and the KPI row, matching the React source placement.
/// - The KPI row is a responsive Wind grid: single column on mobile, two on
///   `sm:`, four on `lg:` (single-column base, never a bare multi-column grid).
/// - The lower region stacks full-width sections (active incidents, monitor
///   snippet, AI inbox). The React source places the AI inbox in a narrow `1/3`
///   right column (`lg:grid-cols-3`, content `lg:col-span-2`). That split is
///   NOT reproduced here: at the shared `max-w-6xl` width a `1/3` column lands
///   near ~234px, and the badge rows inside [IncidentCard]'s `StatusBadge` and
///   the [AiInboxItem] header do not shrink below their content floor at that
///   width (both components are outside this step's edit scope). The inbox and
///   incidents therefore keep the full content width; the active incidents
///   still widen to two columns at `sm:` (single-column base).
///
/// Reads all data through [DashboardController]; this screen has no mutable
/// state and no actions, so the controller is data-only (accepted thin
/// controller). The routed app shell wraps this
/// content (sidebar / bottom nav) at the routing layer; this widget only
/// renders the page body inside the shared [PageContainer].
///
/// ### Example
/// ```dart
/// // Registered as the routed `/` content (wrapped by the app shell):
/// MagicStarter.view.makeLayout('layout.app', child: const DashboardView())
/// ```
@immutable
class DashboardView extends MagicStatefulView<DashboardController> {
  /// Creates the [DashboardView].
  const DashboardView({super.key});

  @override
  State<DashboardView> createState() => _DashboardViewState();
}

class _DashboardViewState
    extends MagicStatefulViewState<DashboardController, DashboardView> {
  @override
  void initState() {
    Magic.findOrPut(DashboardController.new);
    super.initState();
  }

  @override
  Widget build(BuildContext context) {
    // Compose the page body as a Wind flex column: section rhythm is carried by
    // `gap-*`, not SizedBox spacers. The outer 32px rhythm (`gap-8`) separates
    // the intro block, the KPI row, and the lower region; the intro block nests
    // its own `gap-6` so the header sits 24px above the fleet-summary banner.
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-8',
        children: [
          // 1. Intro block: page header + the "Right now" AI fleet-summary
          //    banner, matching the React source placement (header -> banner
          //    -> KPI row). The inner gap-6 keeps the 24px header rhythm.
          WDiv(
            className: 'flex flex-col gap-6',
            children: [
              MSPageHeader(
                title: trans('uptizm.dashboard.title'),
                subtitle: trans('uptizm.dashboard.description'),
              ),
              _buildFleetSummary(),
            ],
          ),

          // 2. KPI summary row.
          _buildKpiRow(),

          // 3. Lower region: at lg+ the active incidents + monitor snippet
          //    span 2/3 beside the AI inbox in a 1/3 right rail (mirroring the
          //    React `lg:grid-cols-3` + `lg:col-span-2`); below lg they stack.
          _buildLowerRegion(),
        ],
      ),
    );
  }

  /// Builds the lower dashboard region as a single responsive Wind flex.
  ///
  /// On `lg`+ it is a two-column split: the active incidents and the monitor
  /// snippet occupy a 2/3 left column (`lg:flex-2`) beside the AI inbox in a
  /// 1/3 right rail (`lg:flex-1`), matching the design lab's `lg:grid-cols-3`
  /// with the content at `lg:col-span-2`. Below `lg` the direction collapses to
  /// a column (`flex-col`) and the sections stack full-width. The 32px rhythm
  /// (both the vertical stack gap and the horizontal column gap) is `gap-8`, so
  /// no breakpoint branch or SizedBox spacer is needed.
  Widget _buildLowerRegion() {
    return WDiv(
      className: 'flex flex-col lg:flex-row gap-8 items-stretch lg:items-start',
      children: [
        WDiv(
          className: 'lg:flex-2 min-w-0 w-full flex flex-col gap-8',
          children: [_buildActiveIncidents(), _buildMonitorSnippet()],
        ),
        WDiv(className: 'lg:flex-1 min-w-0 w-full', child: _buildAiInbox()),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // AI fleet-summary banner
  // ---------------------------------------------------------------------------

  /// Builds the "Right now" AI fleet-summary banner shown above the KPI row.
  Widget _buildFleetSummary() {
    return AiInsight(
      tone: 'banner',
      label: trans('uptizm.ai.right_now_label'),
      child: WText(
        controller.fleetSummary,
        className: 'text-sm text-fg',
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // KPI row
  // ---------------------------------------------------------------------------

  /// Builds the KPI stat-card grid from [DashboardController]'s derivations.
  Widget _buildKpiRow() {
    // 1. Single-column base; widen to two then four columns at breakpoints.
    return WDiv(
      className:
          'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.dashboard.kpi_monitors_up'),
          value: '${controller.upCount} / ${controller.monitorCount}',
          delta: trans('uptizm.dashboard.kpi_delta_down', {
            'count': '${controller.downCount}',
          }),
          trend: KpiTrend.down,
        ),
        KpiStatCard(
          label: trans('uptizm.dashboard.kpi_uptime_24h'),
          value: '99.97%',
          delta: '0.02%',
          hint: trans('uptizm.dashboard.kpi_hint_vs_yesterday'),
          trend: KpiTrend.up,
        ),
        KpiStatCard(
          label: trans('uptizm.dashboard.kpi_open_incidents'),
          value: '${controller.openIncidentsCount}',
          delta: trans('uptizm.dashboard.kpi_delta_new', {'count': '1'}),
          hint: trans('uptizm.dashboard.kpi_hint_ai_detected', {
            'count': '${controller.aiActiveCount}',
          }),
          trend: KpiTrend.down,
        ),
        KpiStatCard(
          label: trans('uptizm.dashboard.kpi_avg_response'),
          value: '${controller.avgResponseMs}ms',
          delta: '12ms',
          hint: trans('uptizm.dashboard.kpi_hint_vs_24h'),
          trend: KpiTrend.down,
        ),
      ],
    );
  }

  /// Builds the active-incidents section: a heading and a single-column base
  /// grid that widens to two columns at `sm:`.
  Widget _buildActiveIncidents() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        _sectionHeading(trans('uptizm.dashboard.section_active_incidents')),
        if (controller.activeIncidents.isEmpty)
          EmptyState(
            title: trans('uptizm.dashboard.active_incidents_empty'),
          )
        else
          WDiv(
            className: 'grid grid-cols-1 sm:grid-cols-2 gap-3',
            children: [
              for (final incident in controller.activeIncidents)
                IncidentCard(
                  incident: incident,
                  onTap: () => MagicRoute.to('/incidents/${incident.id}'),
                ),
            ],
          ),
      ],
    );
  }

  /// Builds the monitor snippet: a heading and a vertical list of monitor rows.
  Widget _buildMonitorSnippet() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        _sectionHeading(trans('uptizm.dashboard.section_monitors')),
        WDiv(
          className: 'flex flex-col gap-2',
          children: [
            for (final monitor in controller.monitorsSnapshot)
              MonitorListRow(
                monitor: monitor,
                onTap: () => MagicRoute.to('/monitors/${monitor.id}'),
              ),
          ],
        ),
      ],
    );
  }

  /// Builds the AI inbox section: heading + pending count, a subtitle, then the
  /// suggestion list (or an [EmptyState] when the inbox is clear).
  Widget _buildAiInbox() {
    final List<IncidentSummary> suggestions = controller.aiSuggestions;

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        // 1. Heading group: the title row (title left; pending count + "Weekly
        //    digest" link right, React `justify-between`) sitting 4px above the
        //    inbox subtitle (inner gap-1).
        WDiv(
          className: 'flex flex-col gap-1',
          children: [
            WDiv(
              // Title absorbs the slack (flex-1) so the count + digest link sit
              // flush-right; `justify-between` here splits the row into equal
              // Flexible halves and overflows the right group in the narrow
              // lg 1/3 rail (~380px), so grow-the-title is the robust idiom.
              className: 'flex flex-row items-center gap-3',
              children: [
                WDiv(
                  className: 'flex-1 min-w-0',
                  child: _sectionHeading(
                    trans('uptizm.dashboard.section_ai_inbox'),
                  ),
                ),
                WDiv(
                  className: 'flex flex-row items-center gap-3',
                  children: [
                    WText(
                      trans('uptizm.dashboard.ai_inbox_pending', {
                        'count': '${suggestions.length}',
                      }),
                      className: 'font-mono text-xs tabular-nums text-fg-muted',
                    ),
                    WButton(
                      onTap: () => MagicRoute.to('/incidents/digest'),
                      child: WText(
                        trans('uptizm.dashboard.ai_inbox_weekly_digest'),
                        className: 'text-xs text-primary',
                      ),
                    ),
                  ],
                ),
              ],
            ),
            WText(
              trans('uptizm.dashboard.ai_inbox_subtitle'),
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),

        // 2. Suggestions list, or the empty state when inbox-zero. The list
        //    carries its own 12px item rhythm (gap-3).
        if (suggestions.isEmpty)
          EmptyState(title: trans('uptizm.dashboard.ai_inbox_empty'))
        else
          WDiv(
            className: 'flex flex-col gap-3',
            children: [
              for (final suggestion in suggestions)
                AiInboxItem(
                  incident: suggestion,
                  onApprove: () => controller.acceptSuggestion(suggestion.id),
                  onDismiss: () => controller.dismissSuggestion(suggestion.id),
                ),
            ],
          ),
      ],
    );
  }

  /// Builds a small uppercase section heading used across the dashboard.
  Widget _sectionHeading(String label) {
    return WText(label, className: 'text-sm font-semibold text-fg');
  }
}
