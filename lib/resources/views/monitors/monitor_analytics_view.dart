import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/analytics_controller.dart';
import '../../../app/models/analytics_response.dart';
import '../../../app/models/monitor.dart';
import '../components/analytics/date_range_selector.dart';
import '../components/analytics/metric_data_table.dart';
import '../components/analytics/metric_selector.dart';
import '../components/app_page_header.dart';
import '../components/charts/multi_line_chart.dart';
import '../components/charts/status_timeline_chart.dart';
import '../components/monitors/stat_card.dart';

class MonitorAnalyticsView extends MagicStatefulView<AnalyticsController> {
  const MonitorAnalyticsView({super.key});

  @override
  State<MonitorAnalyticsView> createState() => _MonitorAnalyticsViewState();
}

class _MonitorAnalyticsViewState
    extends MagicStatefulViewState<AnalyticsController, MonitorAnalyticsView> {
  String? _monitorId;
  Monitor? _monitor;
  bool _showTable = false; // Toggle between chart and table view

  @override
  void onInit() {
    super.onInit();
    final idParam = MagicRouter.instance.pathParameter('id');
    if (idParam != null) {
      _monitorId = idParam;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _loadMonitorAndAnalytics();
      });
    }
  }

  Future<void> _loadMonitorAndAnalytics() async {
    if (_monitorId == null) return;
    _monitor = await Monitor.find(_monitorId!);
    if (mounted) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) setState(() {});
      });
    }
    controller.setLast24Hours(_monitorId!);
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<AnalyticsResponse?>(
      valueListenable: controller.analyticsNotifier,
      builder: (context, response, _) {
        if (response == null) {
          return _buildSkeleton(context);
        }

        return WDiv(
          className: 'overflow-y-auto flex flex-col',
          scrollPrimary: true,
          children: [
            AppPageHeader(
              leading: WButton(
                onTap: () => MagicRoute.to('/monitors/$_monitorId'),
                className:
                    'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors',
                child: const WIcon(Icons.arrow_back),
              ),
              title: _monitor?.name ?? trans('monitor.fallback_name'),
              subtitle: trans('analytics.title'),
            ),
            WDiv(
              className: 'flex flex-col gap-6 p-4 lg:p-6',
              children: [
                _buildDateFilters(),
                _buildStatCardsGrid(response),
                if (response.series.isEmpty)
                  _buildEmptyState()
                else ...[
                  _buildViewToggle(),
                  _buildPerformanceSection(response),
                  _buildStatusTimeline(response),
                ],
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _buildSkeleton(BuildContext context) {
    // Basic shimmer effect or gray boxes
    return WDiv(
      className: 'overflow-y-auto p-4 lg:p-6 flex flex-col gap-6',
      children: [
        // Header skeleton
        WDiv(
          className: 'flex flex-row items-center gap-4',
          children: [
            WDiv(
              className:
                  'w-10 h-10 rounded-lg bg-gray-200 dark:bg-gray-700 animate-pulse',
            ),
            WDiv(
              className: 'flex flex-col gap-2',
              children: [
                WDiv(
                  className:
                      'w-32 h-5 rounded bg-gray-200 dark:bg-gray-700 animate-pulse',
                ),
                WDiv(
                  className:
                      'w-20 h-3 rounded bg-gray-200 dark:bg-gray-700 animate-pulse',
                ),
              ],
            ),
          ],
        ),
        // Date filter skeleton
        WDiv(
          className: 'flex flex-row gap-2',
          children: [
            WDiv(
              className:
                  'w-16 h-10 rounded-lg bg-gray-200 dark:bg-gray-700 animate-pulse',
            ),
            WDiv(
              className:
                  'w-16 h-10 rounded-lg bg-gray-200 dark:bg-gray-700 animate-pulse',
            ),
            WDiv(
              className:
                  'w-16 h-10 rounded-lg bg-gray-200 dark:bg-gray-700 animate-pulse',
            ),
          ],
        ),
        // Stat cards skeleton
        WDiv(
          className: 'grid grid-cols-2 lg:grid-cols-4 gap-4',
          children: List.generate(
            4,
            (_) => WDiv(
              className:
                  'h-32 rounded-2xl bg-gray-200 dark:bg-gray-700 animate-pulse',
            ),
          ),
        ),
        // Chart skeleton
        WDiv(
          className:
              'h-80 rounded-2xl bg-gray-200 dark:bg-gray-700 animate-pulse',
        ),
      ],
    );
  }

  Widget _buildDateFilters() {
    return ValueListenableBuilder<String?>(
      valueListenable: controller.selectedPresetNotifier,
      builder: (context, preset, _) {
        return ValueListenableBuilder<DateTimeRange?>(
          valueListenable: controller.dateRangeNotifier,
          builder: (context, customRange, _) {
            return DateRangeSelector(
              selectedPreset: preset,
              customRange: customRange,
              onPresetSelected: (val) {
                if (_monitorId == null) return;
                switch (val) {
                  case '24h':
                    controller.setLast24Hours(_monitorId!);
                  case '7d':
                    controller.setLast7Days(_monitorId!);
                  case '30d':
                    controller.setLast30Days(_monitorId!);
                  case '90d':
                    controller.setLast90Days(_monitorId!);
                }
              },
              onCustomRangeSelected: (range) {
                if (_monitorId != null) {
                  controller.setCustomRange(_monitorId!, range);
                }
              },
            );
          },
        );
      },
    );
  }

  Widget _buildStatCardsGrid(AnalyticsResponse response) {
    final uptimeClass = response.summary.uptimePercent >= 99.0
        ? 'text-primary'
        : response.summary.uptimePercent >= 95.0
        ? 'text-amber-500'
        : 'text-red-500';

    return WDiv(
      className: 'grid grid-cols-2 lg:grid-cols-4 gap-4',
      children: [
        StatCard(
          label: trans('analytics.total_checks'),
          value: response.summary.totalChecks.toString(),
          icon: Icons.check_circle_outline,
        ),
        StatCard(
          label: trans('analytics.uptime'),
          value: '${response.summary.uptimePercent.toStringAsFixed(1)}%',
          icon: Icons.timeline,
          valueColor: uptimeClass,
        ),
        StatCard(
          label: trans('analytics.avg_response'),
          value: '${response.summary.avgResponseTime.toStringAsFixed(0)}ms',
          icon: Icons.speed,
        ),
        StatCard(
          label: trans('analytics.metrics'),
          value: response.series.length.toString(),
          icon: Icons.analytics_outlined,
        ),
      ],
    );
  }

  Widget _buildEmptyState() {
    return WDiv(
      className: 'flex flex-col items-center justify-center py-16',
      children: [
        WDiv(
          className:
              'w-20 h-20 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4',
          child: const WIcon(
            Icons.analytics_outlined,
            className: 'text-4xl text-gray-400',
          ),
        ),
        WText(
          trans('analytics.no_data'),
          className:
              'text-lg font-semibold text-gray-700 dark:text-gray-300 mb-2',
        ),
        WText(
          trans('analytics.no_data_hint'),
          className:
              'text-sm text-gray-500 dark:text-gray-400 text-center max-w-xs',
        ),
      ],
    );
  }

  Widget _buildViewToggle() {
    return WDiv(
      className: 'flex flex-row items-center gap-2',
      children: [
        WButton(
          onTap: () => setState(() => _showTable = false),
          className:
              '''
            px-4 py-2 rounded-lg text-sm font-medium border transition-colors
            ${!_showTable ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700'}
          ''',
          child: WDiv(
            className: 'flex flex-row items-center gap-2',
            children: [
              const WIcon(Icons.show_chart, className: 'text-base'),
              WText(trans('analytics.chart_view')),
            ],
          ),
        ),
        WButton(
          onTap: () => setState(() => _showTable = true),
          className:
              '''
            px-4 py-2 rounded-lg text-sm font-medium border transition-colors
            ${_showTable ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700'}
          ''',
          child: WDiv(
            className: 'flex flex-row items-center gap-2',
            children: [
              const WIcon(Icons.table_chart, className: 'text-base'),
              WText(trans('analytics.table_view')),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildPerformanceSection(AnalyticsResponse response) {
    return _WCard(
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            WDiv(
              className: 'flex flex-row items-center gap-2 mb-4',
              children: [
                WDiv(
                  className:
                      'w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center',
                  child: const WIcon(
                    Icons.insights,
                    className: 'text-lg text-primary',
                  ),
                ),
                WText(
                  trans('analytics.performance'),
                  className:
                      'text-lg font-semibold text-gray-900 dark:text-white',
                ),
              ],
            ),
            ValueListenableBuilder<List<String>>(
              valueListenable: controller.selectedMetricsNotifier,
              builder: (context, selectedKeys, _) {
                final selectedSeries = response.numericSeries
                    .where((s) => selectedKeys.contains(s.metricKey))
                    .toList();

                return Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    MetricSelector(
                      availableMetrics: response.numericSeries,
                      selectedKeys: selectedKeys,
                      onToggle: controller.toggleMetric,
                      onSelectAll: controller.selectAllMetrics,
                      onClearAll: controller.clearMetrics,
                    ),
                    const WSpacer(className: 'h-6'),
                    if (_showTable)
                      MetricDataTable(series: selectedSeries)
                    else
                      MultiLineChart(series: selectedSeries, height: 300),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildStatusTimeline(AnalyticsResponse response) {
    final statusSeries = response.statusSeries.firstOrNull;
    if (statusSeries == null) return const SizedBox.shrink();

    return _WCard(
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            WDiv(
              className: 'flex flex-row items-center gap-2 mb-4',
              children: [
                WDiv(
                  className:
                      'w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center',
                  child: const WIcon(
                    Icons.check_circle,
                    className: 'text-lg text-primary',
                  ),
                ),
                WText(
                  trans('analytics.status_over_time'),
                  className:
                      'text-lg font-semibold text-gray-900 dark:text-white',
                ),
              ],
            ),
            StatusTimelineChart(statusSeries: statusSeries, height: 120),
          ],
        ),
      ),
    );
  }
}

class _WCard extends StatelessWidget {
  final Widget child;

  const _WCard({required this.child});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className:
          'bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm',
      child: child,
    );
  }
}
