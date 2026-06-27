// GENERATED: do not edit by hand.
// Regenerate via: dart run magic:artisan previews:refresh
//
// Source: *.preview.dart files discovered under the scan dir.

import 'package:magic_devtools/preview.dart';
import 'dashboard_screen.preview.dart';
import 'foundations.preview.dart';
import 'monitor_detail_screen.preview.dart';
import 'monitors_list_screen.preview.dart';
import '../ui/components/ai_analysis_card/ai_analysis_card.preview.dart';
import '../ui/components/ai_confidence_badge/ai_confidence_badge.preview.dart';
import '../ui/components/ai_inbox_item/ai_inbox_item.preview.dart';
import '../ui/components/ai_insight/ai_insight.preview.dart';
import '../ui/components/assistant/assistant.preview.dart';
import '../ui/components/check_history_table/check_history_table.preview.dart';
import '../ui/components/empty_state/empty_state.preview.dart';
import '../ui/components/error_state/error_state.preview.dart';
import '../ui/components/incident_card/incident_card.preview.dart';
import '../ui/components/incident_timeline/incident_timeline.preview.dart';
import '../ui/components/kpi_stat_card/kpi_stat_card.preview.dart';
import '../ui/components/metric_chart/metric_chart.preview.dart';
import '../ui/components/monitor_list_row/monitor_list_row.preview.dart';
import '../ui/components/notification_center/notification_center.preview.dart';
import '../ui/components/status_badge/status_badge.preview.dart';
import '../ui/components/status_dot/status_dot.preview.dart';
import '../ui/components/uptime_bar/uptime_bar.preview.dart';

List<PreviewEntry> previewEntries() {
  return <PreviewEntry>[
    PreviewEntry(
      label: 'AiAnalysisCard',
      slug: 'ai_analysis_card',
      builder: (_) => const AiAnalysisCardPreview(),
    ),
    PreviewEntry(
      label: 'AiConfidenceBadge',
      slug: 'ai_confidence_badge',
      builder: (_) => const AiConfidenceBadgePreview(),
    ),
    PreviewEntry(
      label: 'AiInboxItem',
      slug: 'ai_inbox_item',
      builder: (_) => const AiInboxItemPreview(),
    ),
    PreviewEntry(
      label: 'AiInsight',
      slug: 'ai_insight',
      builder: (_) => const AiInsightPreview(),
    ),
    PreviewEntry(
      label: 'Assistant',
      slug: 'assistant',
      builder: (_) => const AssistantPreview(),
    ),
    PreviewEntry(
      label: 'CheckHistoryTable',
      slug: 'check_history_table',
      builder: (_) => const CheckHistoryTablePreview(),
    ),
    PreviewEntry(
      label: 'DashboardScreen',
      slug: 'dashboard_screen',
      builder: (_) => const DashboardScreenPreview(),
    ),
    PreviewEntry(
      label: 'EmptyState',
      slug: 'empty_state',
      builder: (_) => const EmptyStatePreview(),
    ),
    PreviewEntry(
      label: 'ErrorState',
      slug: 'error_state',
      builder: (_) => const ErrorStatePreview(),
    ),
    PreviewEntry(
      label: 'Foundations',
      slug: 'foundations',
      builder: (_) => const FoundationsPreview(),
    ),
    PreviewEntry(
      label: 'IncidentCard',
      slug: 'incident_card',
      builder: (_) => const IncidentCardPreview(),
    ),
    PreviewEntry(
      label: 'IncidentTimeline',
      slug: 'incident_timeline',
      builder: (_) => const IncidentTimelinePreview(),
    ),
    PreviewEntry(
      label: 'KpiStatCard',
      slug: 'kpi_stat_card',
      builder: (_) => const KpiStatCardPreview(),
    ),
    PreviewEntry(
      label: 'MetricChart',
      slug: 'metric_chart',
      builder: (_) => const MetricChartPreview(),
    ),
    PreviewEntry(
      label: 'MonitorDetailScreen',
      slug: 'monitor_detail_screen',
      builder: (_) => const MonitorDetailScreenPreview(),
    ),
    PreviewEntry(
      label: 'MonitorListRow',
      slug: 'monitor_list_row',
      builder: (_) => const MonitorListRowPreview(),
    ),
    PreviewEntry(
      label: 'MonitorsListScreen',
      slug: 'monitors_list_screen',
      builder: (_) => const MonitorsListScreenPreview(),
    ),
    PreviewEntry(
      label: 'NotificationCenter',
      slug: 'notification_center',
      builder: (_) => const NotificationCenterPreview(),
    ),
    PreviewEntry(
      label: 'StatusBadge',
      slug: 'status_badge',
      builder: (_) => const StatusBadgePreview(),
    ),
    PreviewEntry(
      label: 'StatusDot',
      slug: 'status_dot',
      builder: (_) => const StatusDotPreview(),
    ),
    PreviewEntry(
      label: 'UptimeBar',
      slug: 'uptime_bar',
      builder: (_) => const UptimeBarPreview(),
    ),
  ];
}
