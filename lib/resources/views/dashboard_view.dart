import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../app/mocks/incidents.dart';
import '../../app/mocks/monitors.dart';
import '../../app/mocks/status.dart';
import '../../ui/components/ai_inbox_item/index.dart';
import '../../ui/components/ai_insight/index.dart';
import '../../ui/components/empty_state/index.dart';
import '../../ui/components/incident_card/index.dart';
import '../../ui/components/kpi_stat_card/index.dart';
import '../../ui/components/monitor_list_row/index.dart';
import '../../ui/layouts/page_container.dart';

/// **The Dashboard home screen.**
///
/// Composes the whole product at a glance from the design-lab mock fixtures
/// (no controller, no network): a KPI summary row, the active incidents, a
/// monitor snippet, and the AI inbox (anomalies awaiting the operator's call).
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
/// It reads the fixtures DIRECTLY: this is a mock screen, so a plain
/// [StatelessWidget] is intentional. The routed app shell wraps this content
/// (sidebar / bottom nav) at the routing layer; this widget only renders the
/// page body inside the shared [PageContainer].
///
/// ### Example
/// ```dart
/// // Registered as the routed `/` content (wrapped by the app shell):
/// MagicStarter.view.makeLayout('layout.app', child: const DashboardView())
/// ```
@immutable
class DashboardView extends StatelessWidget {
  /// Creates the [DashboardView].
  const DashboardView({super.key});

  /// Active incidents are everything not yet resolved, newest-first as fixtured.
  List<IncidentSummary> get _activeIncidents => incidents
      .where((i) => i.lifecycle != IncidentLifecycle.resolved)
      .toList();

  /// AI inbox entries: active incidents that carry an AI analysis payload.
  ///
  /// [AiInboxItem] renders the analysis tl;dr and confidence, so only incidents
  /// with a non-null `ai` payload qualify; the rest stay in the incident list.
  List<IncidentSummary> get _aiSuggestions =>
      _activeIncidents.where((i) => i.ai != null).toList();

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
              PageHeader(
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
          children: [
            _buildActiveIncidents(),
            _buildMonitorSnippet(),
          ],
        ),
        WDiv(
          className: 'lg:flex-1 min-w-0 w-full',
          child: _buildAiInbox(),
        ),
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
        trans('uptizm.dashboard.ai_fleet_summary'),
        className: 'text-sm text-fg',
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // KPI row
  // ---------------------------------------------------------------------------

  /// Builds the KPI stat-card grid from the monitor + incident fixtures.
  Widget _buildKpiRow() {
    // 1. Derive the headline metrics directly from the fixtures.
    final int upCount = monitors.where((m) => m.status == StatusKey.up).length;
    final int downCount = monitors
        .where((m) => m.status == StatusKey.down)
        .length;
    final List<IncidentSummary> open = _activeIncidents;
    final int aiActive = open.where((i) => i.aiOwned).length;
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
      className:
          'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.dashboard.kpi_monitors_up'),
          value: '$upCount / ${monitors.length}',
          delta: trans('uptizm.dashboard.kpi_delta_down', {
            'count': '$downCount',
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
          value: '${open.length}',
          delta: trans('uptizm.dashboard.kpi_delta_new', {'count': '1'}),
          hint: trans('uptizm.dashboard.kpi_hint_ai_detected', {
            'count': '$aiActive',
          }),
          trend: KpiTrend.down,
        ),
        KpiStatCard(
          label: trans('uptizm.dashboard.kpi_avg_response'),
          value: '${avgResponse}ms',
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
        WDiv(
          className: 'grid grid-cols-1 sm:grid-cols-2 gap-3',
          children: [
            for (final incident in _activeIncidents)
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
            for (final monitor in monitors)
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
    final List<IncidentSummary> suggestions = _aiSuggestions;

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
              className: 'flex flex-row items-center justify-between gap-3',
              children: [
                _sectionHeading(trans('uptizm.dashboard.section_ai_inbox')),
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
                  onApprove: () => MagicRoute.to('/incidents/new', query: {'from': suggestion.id}),
                  onDismiss: () {},
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
