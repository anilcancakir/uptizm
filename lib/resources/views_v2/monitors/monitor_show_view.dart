import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/check_status.dart';
import '../../../app/enums/metric_status_value.dart';
import '../../../app/enums/metric_type.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/monitor_check.dart';
import '../../../app/models/response_time_point.dart';
import '../../../app/models/uptime_day.dart';
import '../components/charts/response_time_chart.dart';
import '../components/monitors/metric_detail_sheet.dart';
import '../components/monitors/monitor_check_row.dart';
import '../components/monitors/monitor_header.dart';
import '../components/monitors/monitor_response_metrics.dart';
import '../components/monitors/uptime_bar.dart';
import '../components/ui/config_row.dart';
import '../components/ui/content_section.dart';
import '../components/ui/stat_card.dart';

/// Monitor detail page (v2).
///
/// Wired to [MonitorController] notifiers for real-time data.
/// Fetches monitor, uptime, response times, and checks on init.
class MonitorShowV2View extends StatefulWidget {
  const MonitorShowV2View({super.key, required this.monitorId});

  /// The monitor ID from the route parameter.
  final String monitorId;

  @override
  State<MonitorShowV2View> createState() => _MonitorShowV2ViewState();
}

class _MonitorShowV2ViewState extends State<MonitorShowV2View> {
  final _controller = MonitorController.instance;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  /// Fetches all monitor data in parallel.
  void _loadData() {
    _controller.loadMonitor(widget.monitorId);
    _controller.fetchUptime(widget.monitorId, range: '30d');
    _controller.fetchResponseTimes(widget.monitorId, range: '1h');
    _controller.loadChecks(widget.monitorId);
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Listenable.merge([
        _controller.selectedMonitorNotifier,
        _controller.uptimeNotifier,
        _controller.responseTimesNotifier,
        _controller.checksNotifier,
      ]),
      builder: (context, _) {
        final monitor = _controller.selectedMonitorNotifier.value;

        if (monitor == null) {
          return _buildLoadingOrError();
        }

        return _buildContent(context, monitor);
      },
    );
  }

  // -------  Loading / Error  -------

  Widget _buildLoadingOrError() {
    if (_controller.isLoading) {
      return WDiv(
        className: 'flex-1 flex items-center justify-center w-full',
        child: SizedBox(
          width: 24,
          height: 24,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
      );
    }

    return WDiv(
      className: 'flex-1 flex items-center justify-center w-full',
      child: WText(
        trans('errors.network_error'),
        className: 'text-sm text-gray-500 dark:text-gray-400',
      ),
    );
  }

  // -------  Main Content  -------

  Widget _buildContent(BuildContext context, Monitor monitor) {
    return WDiv(
      className: 'flex-1 overflow-y-auto',
      scrollPrimary: true,
      child: WDiv(
        className: 'flex flex-col gap-6 p-4 pb-8',
        children: [
          _buildHeader(monitor),
          _buildStatsSection(context, monitor),
          _buildUptimeSection(),
          _buildPerformanceSection(),
          _buildCheckHistory(),
          _buildResponseMetrics(monitor),
          _buildConfiguration(monitor),
        ],
      ),
    );
  }

  // -------  Header  -------

  Widget _buildHeader(Monitor monitor) {
    return MonitorHeader(
      name: monitor.name ?? trans('monitors.unnamed'),
      url: monitor.url ?? '--',
      status: monitor.lastStatus?.value ?? 'unknown',
      responseTime: monitor.lastResponseTimeMs != null
          ? '${monitor.lastResponseTimeMs}ms'
          : null,
      interval: monitor.checkInterval != null
          ? '${trans('common.every')} ${monitor.checkInterval}s'
          : null,
      lastChecked: monitor.lastCheckedAt?.diffForHumans(),
    );
  }

  // -------  Stats Section  -------

  Widget _buildStatsSection(BuildContext context, Monitor monitor) {
    final uptimeValue = monitor.uptime24hPercent != null
        ? '${monitor.uptime24hPercent!.toStringAsFixed(2)}%'
        : '--';
    final uptimeTrend = monitor.uptimeTrendPercent != null
        ? '${monitor.uptimeTrendPercent! >= 0 ? '+' : ''}${monitor.uptimeTrendPercent!.toStringAsFixed(2)}%'
        : null;
    final uptimeTrendPositive =
        monitor.uptimeTrendPercent != null && monitor.uptimeTrendPercent! >= 0;

    final avgResponseValue = monitor.avgResponseTimeMs != null
        ? '${monitor.avgResponseTimeMs!.toStringAsFixed(0)}ms'
        : '--';
    final avgResponseTrend = monitor.avgResponseTimeTrendMs != null
        ? '${monitor.avgResponseTimeTrendMs! >= 0 ? '+' : ''}${monitor.avgResponseTimeTrendMs!.toStringAsFixed(0)}ms'
        : null;
    // Lower response time is better, so negative trend is positive
    final avgResponseTrendPositive =
        monitor.avgResponseTimeTrendMs != null &&
        monitor.avgResponseTimeTrendMs! <= 0;

    final lastCheckValue = monitor.lastCheckedAt?.diffForHumans() ?? '--';
    final intervalValue = monitor.checkInterval != null
        ? '${monitor.checkInterval}s'
        : '--';

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WDiv(
          className: 'flex flex-row gap-3',
          children: [
            WDiv(
              className: 'flex-1',
              child: WButton(
                onTap: () => MetricDetailSheet.show(
                  context,
                  label: trans('monitors.uptime'),
                  type: MetricType.numeric,
                  currentValue: uptimeValue,
                  unit: '%',
                ),
                child: V2StatCard(
                  label: trans('monitors.uptime'),
                  value: uptimeValue,
                  icon: Icons.arrow_upward_rounded,
                  trend: uptimeTrend,
                  trendPositive: uptimeTrendPositive,
                ),
              ),
            ),
            WDiv(
              className: 'flex-1',
              child: WButton(
                onTap: () => MetricDetailSheet.show(
                  context,
                  label: trans('monitors.avg_response_time'),
                  type: MetricType.numeric,
                  currentValue: avgResponseValue,
                  unit: 'ms',
                ),
                child: V2StatCard(
                  label: trans('monitors.avg_response_time'),
                  value: avgResponseValue,
                  icon: Icons.speed_rounded,
                  trend: avgResponseTrend,
                  trendPositive: avgResponseTrendPositive,
                ),
              ),
            ),
          ],
        ),
        WDiv(
          className: 'flex flex-row gap-3',
          children: [
            WDiv(
              className: 'flex-1',
              child: WButton(
                onTap: () => MetricDetailSheet.show(
                  context,
                  label: trans('monitors.last_check'),
                  type: MetricType.string,
                  currentValue: lastCheckValue,
                ),
                child: V2StatCard(
                  label: trans('monitors.last_check'),
                  value: lastCheckValue,
                  icon: Icons.schedule_rounded,
                ),
              ),
            ),
            WDiv(
              className: 'flex-1',
              child: WButton(
                onTap: () => MetricDetailSheet.show(
                  context,
                  label: trans('monitors.check_interval_label'),
                  type: MetricType.string,
                  currentValue: intervalValue,
                ),
                child: V2StatCard(
                  label: trans('monitors.check_interval_label'),
                  value: intervalValue,
                  icon: Icons.repeat_rounded,
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }

  // -------  Uptime Section  -------

  Widget _buildUptimeSection() {
    final uptime = _controller.uptimeNotifier.value;
    final days = uptime?.days ?? [];

    final uptimeDays = days.map((UptimeDay day) {
      return UptimeDayData(
        date: day.date,
        status: CheckStatus.fromValue(day.status),
        uptimePercent: day.uptimePercent,
      );
    }).toList();

    return ContentSection(
      title: trans('monitors.uptime'),
      icon: Icons.timeline_rounded,
      child: UptimeBar(
        range: UptimeBarRange.days30,
        uptimePercent:
            _controller.selectedMonitorNotifier.value?.uptime30dPercent,
        days: uptimeDays,
      ),
    );
  }

  // -------  Performance Section  -------

  Widget _buildPerformanceSection() {
    final series = _controller.responseTimesNotifier.value;
    final points = series?.points ?? [];

    final chartPoints = points.map((ResponseTimePoint point) {
      return ResponseTimeDataPoint(
        timestamp: point.timestamp,
        responseTimeMs: point.responseTimeMs,
        status: CheckStatus.fromValue(point.status) ?? CheckStatus.up,
      );
    }).toList();

    return ContentSection(
      title: trans('monitors.performance'),
      icon: Icons.show_chart_rounded,
      child: ResponseTimeChart(dataPoints: chartPoints, height: 200),
    );
  }

  // -------  Check History  -------

  Widget _buildCheckHistory() {
    final checks = _controller.checksNotifier.value;

    if (checks.isEmpty) {
      return ContentSection(
        title: trans('monitors.recent_checks'),
        icon: Icons.history_rounded,
        child: WDiv(
          className: 'flex items-center justify-center w-full py-8',
          child: WText(
            trans('monitors.no_checks'),
            className: 'text-sm text-gray-400 dark:text-gray-500',
          ),
        ),
      );
    }

    return ContentSection(
      title: trans('monitors.recent_checks'),
      icon: Icons.history_rounded,
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: checks
            .map(
              (MonitorCheck check) => MonitorCheckRow(
                status: check.status?.value ?? 'unknown',
                responseTimeMs: check.responseTimeMs,
                statusCode: check.statusCode,
                location: check.location?.label,
                checkedAt: check.checkedAt?.diffForHumans() ?? '--',
                errorMessage: check.errorMessage,
              ),
            )
            .toList(),
      ),
    );
  }

  // -------  Response Metrics  -------

  Widget _buildResponseMetrics(Monitor monitor) {
    final metrics = monitor.latestParsedMetrics;

    if (metrics == null || metrics.isEmpty) {
      return WSpacer(className: 'h-0');
    }

    final statusMetrics = <ResponseStatusMetric>[];
    final numericMetrics = <ResponseNumericMetric>[];
    final stringMetrics = <ResponseStringMetric>[];

    for (final entry in metrics.entries) {
      final value = entry.value;

      if (value is String &&
          const {'up', 'down', 'degraded'}.contains(value.toLowerCase())) {
        statusMetrics.add(
          ResponseStatusMetric(
            label: _formatMetricLabel(entry.key),
            status:
                MetricStatusValue.fromValue(value.toLowerCase()) ??
                MetricStatusValue.unknown,
            path: entry.key,
          ),
        );
      } else if (value is num) {
        numericMetrics.add(
          ResponseNumericMetric(
            label: _formatMetricLabel(entry.key),
            value: value is int ? value.toString() : value.toStringAsFixed(1),
            path: entry.key,
          ),
        );
      } else {
        stringMetrics.add(
          ResponseStringMetric(
            label: _formatMetricLabel(entry.key),
            value: value?.toString() ?? '--',
            path: entry.key,
          ),
        );
      }
    }

    return MonitorResponseMetrics(
      statusMetrics: statusMetrics,
      numericMetrics: numericMetrics,
      stringMetrics: stringMetrics,
    );
  }

  /// Converts a dot-separated metric key to a human-readable label.
  String _formatMetricLabel(String key) {
    final parts = key.split('.');
    final lastPart = parts.last;
    return lastPart
        .replaceAll('_', ' ')
        .split(' ')
        .map(
          (word) => word.isEmpty
              ? ''
              : '${word[0].toUpperCase()}${word.substring(1)}',
        )
        .join(' ');
  }

  // -------  Configuration  -------

  Widget _buildConfiguration(Monitor monitor) {
    return ContentSection(
      title: trans('monitors.configuration'),
      icon: Icons.settings_outlined,
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          ConfigRow(
            label: trans('monitors.method'),
            value: monitor.method?.label ?? '--',
          ),
          ConfigRow(
            label: trans('monitors.expected_status_code'),
            value: monitor.expectedStatusCode?.toString() ?? '--',
          ),
          ConfigRow(
            label: trans('monitors.timeout'),
            value: monitor.timeout != null ? '${monitor.timeout}s' : '--',
          ),
          WDiv(
            className: 'flex flex-row items-center py-3 px-4 gap-3',
            children: [
              WText(
                trans('monitors.locations'),
                className: 'text-sm text-gray-500 dark:text-gray-400',
              ),
              WDiv(
                className: 'flex-1 overflow-x-auto',
                child: WDiv(
                  className: 'flex flex-row gap-1.5 justify-end',
                  children: (monitor.monitoringLocations ?? [])
                      .map((loc) => _buildLocationBadge(loc.label))
                      .toList(),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildLocationBadge(String location) {
    return WDiv(
      className: '''
        flex flex-row items-center gap-1 px-2 py-0.5 rounded-full
        bg-gray-100 dark:bg-gray-800
      ''',
      children: [
        WIcon(
          Icons.public_rounded,
          className: 'text-[12px] text-gray-400 dark:text-gray-500',
        ),
        WText(location, className: 'text-xs text-gray-600 dark:text-gray-400'),
      ],
    );
  }
}
