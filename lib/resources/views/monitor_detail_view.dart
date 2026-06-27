import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../app/mocks/incidents.dart';
import '../../app/mocks/metrics.dart';
import '../../app/mocks/monitors.dart';
import '../../app/mocks/status.dart';
import '../../ui/components/ai_analysis_card/index.dart';
import '../../ui/components/check_history_table/index.dart';
import '../../ui/components/empty_state/index.dart';
import '../../ui/components/kpi_stat_card/index.dart';
import '../../ui/components/metric_chart/index.dart';
import '../../ui/components/status_badge/index.dart';
import '../../ui/components/uptime_bar/index.dart';
import '../../ui/layouts/page_container.dart';

/// **The Monitor Detail screen.**
///
/// The richest read-only screen in the design lab: a header (name / URL /
/// [StatusBadge]), a responsive KPI row ([KpiStatCard]), the 90-day
/// [UptimeBar], and a two-tab body (Overview / Metrics) reusing the
/// magic_starter [Tabs]. The Overview tab carries a response-time
/// [MetricChart] (series + AI band + anomalies) and the recent
/// [CheckHistoryTable]; the Metrics tab carries the per-monitor [MetricChart]
/// and an [AiAnalysisCard] surfacing the monitor's AI analysis.
///
/// It resolves a monitor [id] to a fixture via [findMonitor]; when no monitor
/// matches it renders a graceful [EmptyState] rather than crashing (the route
/// supplies the id at the routing layer).
///
/// Layout discipline mirrors [DashboardView] / [MonitorsListView]: a plain
/// Flutter [Column] scaffolds the page body so each leaf component receives a
/// bounded, well-formed width constraint from the shared [PageContainer]
/// rather than an unbounded Wind flex-scroll regime. Wind utilities appear
/// only on leaf containers, never as the outermost flex context. This keeps
/// the dense MetricChart + CheckHistoryTable + KPI grid from overflowing on a
/// narrow phone.
///
/// This is a mock screen: it reads the fixtures directly (no controller, no
/// network). The state is local tab selection only, so a plain
/// [StatefulWidget] is intentional.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/monitors/:id` content (wrapped by the shell):
/// MagicStarter.view.makeLayout(
///   'layout.app',
///   child: const MonitorDetailView(id: 'api'),
/// )
/// ```
@immutable
class MonitorDetailView extends StatefulWidget {
  /// The monitor identifier resolved against the fixtures via [findMonitor].
  ///
  /// `null` or an unknown id renders a graceful not-found [EmptyState].
  final String? id;

  /// Creates the [MonitorDetailView] for the given monitor [id].
  const MonitorDetailView({super.key, this.id});

  @override
  State<MonitorDetailView> createState() => _MonitorDetailViewState();
}

// ---------------------------------------------------------------------------
// Tab definition
// ---------------------------------------------------------------------------

/// The two tabs shown for a monitor: Overview and Metrics.
///
/// The React source also carries an Incidents tab; per the step scope that
/// surface is owned by the incident screens, so the detail view keeps the two
/// read-only data tabs (Overview / Metrics) only.
enum _DetailTab {
  /// Response-time chart + recent checks.
  overview,

  /// Per-monitor metric chart + AI analysis.
  metrics,
}

class _MonitorDetailViewState extends State<MonitorDetailView> {
  /// The series descriptors for the response-time chart (p50 / p95 / p99).
  static const List<MetricSeries> _responseSeriesDescriptors =
      apiResponseSeries_;

  /// The currently selected tab index.
  int _tabIndex = _DetailTab.overview.index;

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the monitor; a null / unknown id falls back to a graceful
    //    not-found state so the screen never crashes when the route passes an
    //    id with no fixture behind it.
    final MonitorSummary? monitor = findMonitor(widget.id);
    if (monitor == null) {
      return _buildNotFound();
    }

    // 2. A plain Flutter Column scaffolds the page body so each descendant
    //    receives a bounded width from PageContainer (same discipline as the
    //    sibling views), keeping the dense leaves from overflowing on mobile.
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 3. Header: name + StatusBadge as a title suffix, URL as subtitle.
          PageHeader(
            title: monitor.name,
            subtitle: monitor.url,
            titleSuffix: StatusBadge(monitor.status),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),
          const SizedBox(height: 24),

          // 4. KPI summary row.
          _buildKpiRow(monitor),
          const SizedBox(height: 32),

          // 5. 90-day uptime timeline.
          _buildUptimeSection(monitor),
          const SizedBox(height: 32),

          // 6. Overview / Metrics tabs.
          _buildTabs(monitor),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // KPI row
  // ---------------------------------------------------------------------------

  /// Builds the four-card KPI row from the monitor fixture.
  ///
  /// Single-column base, widening to two columns at `sm:` then four at `lg:`
  /// so the grid never forces a multi-column layout onto a narrow phone.
  Widget _buildKpiRow(MonitorSummary monitor) {
    // 1. Derive the headline metrics directly from the fixtures.
    final bool paused = monitor.status == StatusKey.paused;
    final int openIncidents = incidentsForMonitor(
      monitor.name,
    ).where((i) => i.lifecycle != IncidentLifecycle.resolved).length;
    final String avgResponse = monitor.responseMs != null
        ? '${monitor.responseMs}ms'
        : '—';

    // 2. Single-column base; widen to two then four columns at breakpoints.
    return WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4',
      children: [
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_uptime_24h'),
          value: monitor.uptime,
          delta: paused ? null : '0.01%',
          trend: paused ? KpiTrend.neutral : KpiTrend.up,
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_avg_response'),
          value: avgResponse,
          hint: paused
              ? trans('uptizm.monitors.kpi_hint_paused')
              : trans('uptizm.monitors.kpi_hint_p50'),
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_last_check'),
          value: paused
              ? trans('uptizm.status.paused')
              : trans('uptizm.monitors.kpi_last_check_value'),
          hint: trans('uptizm.monitors.kpi_hint_interval', {
            'interval': monitor.intervalLabel,
          }),
        ),
        KpiStatCard(
          label: trans('uptizm.monitors.kpi_open_incidents_for_monitor'),
          value: '$openIncidents',
          delta: openIncidents > 0
              ? trans('uptizm.monitors.kpi_delta_ongoing')
              : null,
          trend: openIncidents > 0 ? KpiTrend.down : KpiTrend.neutral,
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Uptime timeline
  // ---------------------------------------------------------------------------

  /// Builds the 90-day uptime section: a heading row with the trailing uptime
  /// figure, the [UptimeBar], and the 90-days-ago / today axis labels.
  Widget _buildUptimeSection(MonitorSummary monitor) {
    // Deterministic 90-day history with two degraded days and three down days,
    // matching the design mock's representative outage pattern.
    final segments = uptime90(
      degraded: const [41, 42],
      down: const [58, 73, 74],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // 1. Heading row: section title + trailing uptime figure.
        WDiv(
          className: 'flex flex-row items-center justify-between gap-3',
          children: [
            WText(
              trans('uptizm.monitors.uptime_last_90_days'),
              className: 'text-sm font-medium text-fg',
            ),
            WText(
              monitor.uptime,
              className: 'font-mono text-xs tabular-nums text-fg-muted',
            ),
          ],
        ),
        const SizedBox(height: 8),

        // 2. The 90-day bar (prominent height for the detail header).
        UptimeBar(segments: segments, size: UptimeBarSize.lg),
        const SizedBox(height: 8),

        // 3. Axis labels: 90 days ago (left) and today (right).
        WDiv(
          className: 'flex flex-row items-center justify-between gap-3',
          children: [
            WText(
              trans('uptizm.monitors.uptime_90_days_ago'),
              className: 'font-mono text-xs text-fg-muted',
            ),
            WText(
              trans('uptizm.monitors.uptime_today'),
              className: 'font-mono text-xs text-fg-muted',
            ),
          ],
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Tabs
  // ---------------------------------------------------------------------------

  /// Builds the Overview / Metrics tab strip and its panels.
  ///
  /// The `list` slot overrides the recipe's default `flex flex-row` with `wrap`
  /// so a long or localized tab label wraps to the next line instead of
  /// overflowing the strip on a narrow phone (the recipe's bare flex-row sizes
  /// to content under loose constraints). With the production two-word labels
  /// this stays a single row; the wrap only engages defensively.
  Widget _buildTabs(MonitorSummary monitor) {
    return Tabs(
      tabs: [
        trans('uptizm.monitors.tab_overview'),
        trans('uptizm.monitors.tab_metrics'),
      ],
      selectedIndex: _tabIndex,
      onChanged: (i) => setState(() => _tabIndex = i),
      classNames: const {'list': 'wrap border-b border-color-border'},
      panelBuilder: (index) => index == _DetailTab.metrics.index
          ? _buildMetricsTab(monitor)
          : _buildOverviewTab(monitor),
    );
  }

  /// Builds the Overview panel: a response-time [MetricChart] (or a paused /
  /// no-data state) followed by the recent [CheckHistoryTable].
  Widget _buildOverviewTab(MonitorSummary monitor) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),

        // 1. Response-time section heading.
        WText(
          trans('uptizm.monitors.section_response_time', {'range': '24h'}),
          className: 'text-sm font-medium text-fg',
        ),
        const SizedBox(height: 12),

        // 2. The chart, or a calm bordered state when there is no series.
        _buildResponseSurface(monitor),
        const SizedBox(height: 32),

        // 3. Recent checks heading + table.
        WText(
          trans('uptizm.monitors.section_recent_checks'),
          className: 'text-sm font-medium text-fg',
        ),
        const SizedBox(height: 12),
        const CheckHistoryTable(rows: recentChecks),
      ],
    );
  }

  /// Builds the response-time surface for the Overview tab.
  ///
  /// Renders the [MetricChart] when the monitor reports a response series;
  /// shows a paused or no-data [EmptyState] otherwise so the section never
  /// renders an empty chart frame.
  Widget _buildResponseSurface(MonitorSummary monitor) {
    final List<MetricDatum>? series = _responseSeriesFor(monitor);

    if (series != null) {
      // The Overview response chart mirrors the React source (`MonitorDetailPage`
      // line 256): series + unit + anomalies, but no AI expected-range band. The
      // band belongs to the deeper per-metric history view, which on the Metrics
      // tab does draw it.
      return MetricChart(
        data: series,
        series: _responseSeriesDescriptors,
        unit: 'ms',
        anomalies: apiResponseAnomalies,
      );
    }

    if (monitor.status == StatusKey.paused) {
      return _buildBorderedState(
        trans('uptizm.monitors.paused_title'),
        trans('uptizm.monitors.paused_description'),
      );
    }

    return _buildBorderedState(
      trans('uptizm.monitors.no_response_data_title'),
      trans('uptizm.monitors.no_response_data_description'),
    );
  }

  /// Builds the Metrics panel: the per-monitor metric [MetricChart] and the
  /// monitor's [AiAnalysisCard] when an AI-analyzed incident touches it.
  Widget _buildMetricsTab(MonitorSummary monitor) {
    final List<MetricDatum>? series = _responseSeriesFor(monitor);
    final IncidentAi? ai = _aiForMonitor(monitor);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),

        // 1. System metrics heading.
        WText(
          trans('uptizm.monitors.metrics_system_title'),
          className: 'text-sm font-medium text-fg',
        ),
        const SizedBox(height: 12),

        // 2. The per-monitor metric chart, or a no-data state.
        if (series != null)
          MetricChart(
            data: series,
            series: _responseSeriesDescriptors,
            unit: 'ms',
            band: 'band',
            anomalies: apiResponseAnomalies,
          )
        else
          _buildBorderedState(
            trans('uptizm.monitors.no_response_data_title'),
            trans('uptizm.monitors.no_response_data_description'),
          ),

        // 3. Per-monitor AI analysis, when an analyzed incident touches it.
        if (ai != null) ...[const SizedBox(height: 32), AiAnalysisCard(ai: ai)],
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Not-found
  // ---------------------------------------------------------------------------

  /// Builds the graceful not-found state shown when [findMonitor] returns null.
  ///
  /// Reuses the monitors error-load copy as a calm "couldn't load this
  /// monitor" message rather than crashing on an unknown route id.
  Widget _buildNotFound() {
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          PageHeader(
            title: trans('uptizm.monitors.error_load_title'),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: '/monitors',
          ),
          const SizedBox(height: 24),
          EmptyState(
            title: trans('uptizm.monitors.error_load_title'),
            description: trans('uptizm.monitors.error_load_description'),
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /// The 24-hour response-time series for [monitor], or `null` when the monitor
  /// reports no response data (paused / down monitors with no timing).
  ///
  /// Only the fixtured monitors carry a series; everything else has none, which
  /// drives the no-data / paused surface.
  List<MetricDatum>? _responseSeriesFor(MonitorSummary monitor) {
    if (monitor.responseMs == null) return null;
    return switch (monitor.id) {
      'marketing' => marketingResponseSeries,
      'api' => apiResponseSeries,
      _ => apiResponseSeries,
    };
  }

  /// The AI analysis for [monitor], if any incident that touches it carries an
  /// `.ai` payload. Picks the first such incident (newest-first as fixtured).
  IncidentAi? _aiForMonitor(MonitorSummary monitor) {
    for (final incident in incidentsForMonitor(monitor.name)) {
      final ai = incident.ai;
      if (ai != null) return ai;
    }
    return null;
  }

  /// Builds a calm bordered placeholder for the response section when there is
  /// no chart to show (paused or no-response-data).
  Widget _buildBorderedState(String title, String description) {
    return WDiv(
      className: 'rounded-lg border border-color-border',
      child: EmptyState(title: title, description: description),
    );
  }
}
