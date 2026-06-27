import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../app/mocks/incidents.dart';
import '../../app/mocks/monitors.dart';
import '../../app/mocks/status.dart';
import '../../ui/components/ai_inbox_item/index.dart';
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
/// Layout discipline mirrors the React source while respecting Wind's grid
/// limitations and the existing components' width needs:
///
/// - The KPI row is a responsive Wind grid: single column on mobile, two on
///   `sm:`, four on `lg:` (single-column base, never a bare multi-column grid).
/// - The lower region stacks full-width sections (active incidents, monitor
///   snippet, AI inbox). The React source places the AI inbox in a narrow `1/3`
///   right column, but the in-repo [AiInboxItem] (built in an earlier step,
///   outside this step's edit scope) renders its header / action rows with raw
///   Flutter `Row`s that overflow below ~640px logical width. A 2:1 desktop
///   split of the shared `max-w-6xl` page leaves the right column far below
///   that floor, so the inbox is given the full content width here. The active
///   incidents still widen to two columns at `sm:` (single-column base).
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
    // Compose the page body, stacking the regions with section rhythm. A plain
    // Flutter Column scaffolds the page (not a Wind flex Column) so the leaf
    // components receive a bounded, well-formed width constraint from the
    // shared PageContainer rather than an unbounded flex-overflow context.
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 1. Page header (reused magic_starter PageHeader).
          PageHeader(
            title: trans('uptizm.dashboard.title'),
            subtitle: trans('uptizm.dashboard.description'),
          ),
          const SizedBox(height: 32),

          // 2. KPI summary row.
          _buildKpiRow(),
          const SizedBox(height: 32),

          // 3. Active incidents.
          _buildActiveIncidents(),
          const SizedBox(height: 32),

          // 4. Monitor snippet.
          _buildMonitorSnippet(),
          const SizedBox(height: 32),

          // 5. AI inbox.
          _buildAiInbox(),
        ],
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
      className: 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4',
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

  // ---------------------------------------------------------------------------
  // Lower region: incidents + monitors (left), AI inbox (right)
  // ---------------------------------------------------------------------------

  /// Builds the active-incidents section: a heading and a single-column base
  /// grid that widens to two columns at `sm:`.
  Widget _buildActiveIncidents() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sectionHeading(trans('uptizm.dashboard.section_active_incidents')),
        const SizedBox(height: 12),
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
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sectionHeading(trans('uptizm.dashboard.section_monitors')),
        const SizedBox(height: 12),
        for (final monitor in monitors) ...[
          MonitorListRow(
            monitor: monitor,
            onTap: () => MagicRoute.to('/monitors/${monitor.id}'),
          ),
          if (monitor != monitors.last) const SizedBox(height: 8),
        ],
      ],
    );
  }

  /// Builds the AI inbox section: heading + pending count, a subtitle, then the
  /// suggestion list (or an [EmptyState] when the inbox is clear).
  ///
  /// The list scaffold is a plain Flutter [Column] (not a Wind flex column) so
  /// each [AiInboxItem] receives a bounded full-width constraint from the page,
  /// the width its raw header / action rows need to lay out without overflow.
  Widget _buildAiInbox() {
    final List<IncidentSummary> suggestions = _aiSuggestions;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // 1. Heading row: section title + pending count.
        WDiv(
          className: 'flex flex-row items-center justify-between gap-3',
          children: [
            _sectionHeading(trans('uptizm.dashboard.section_ai_inbox')),
            WText(
              trans('uptizm.dashboard.ai_inbox_pending', {
                'count': '${suggestions.length}',
              }),
              className: 'font-mono text-xs tabular-nums text-fg-muted',
            ),
          ],
        ),
        const SizedBox(height: 4),

        // 2. Inbox subtitle.
        WText(
          trans('uptizm.dashboard.ai_inbox_subtitle'),
          className: 'text-xs text-fg-muted',
        ),
        const SizedBox(height: 12),

        // 3. Suggestions list, or the empty state when inbox-zero.
        if (suggestions.isEmpty)
          EmptyState(title: trans('uptizm.dashboard.ai_inbox_empty'))
        else
          for (final suggestion in suggestions) ...[
            AiInboxItem(
              incident: suggestion,
              onApprove: () => MagicRoute.to('/incidents/new'),
              onDismiss: () {},
            ),
            if (suggestion != suggestions.last) const SizedBox(height: 12),
          ],
      ],
    );
  }

  /// Builds a small uppercase section heading used across the dashboard.
  Widget _sectionHeading(String label) {
    return WText(label, className: 'text-sm font-semibold text-fg');
  }
}
