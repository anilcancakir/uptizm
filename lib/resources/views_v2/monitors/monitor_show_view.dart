import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/check_status.dart';
import '../../../app/enums/metric_status_value.dart';
import '../../../app/enums/metric_type.dart';
import '../components/charts/response_time_chart.dart';
import '../components/monitors/metric_detail_sheet.dart';
import '../components/monitors/monitor_check_row.dart';
import '../components/monitors/monitor_header.dart';
import '../components/monitors/monitor_response_metrics.dart';
import '../components/monitors/uptime_bar.dart';
import '../components/ui/config_row.dart';
import '../components/ui/content_section.dart';
import '../components/ui/stat_card.dart';
import 'mock_monitor_data.dart';

/// Monitor detail page (v2 prototype).
///
/// Component-based, mobile-first layout matching uptizm_app reference design.
/// Uses mock data; replace with controller bindings during integration.
class MonitorShowV2View extends StatelessWidget {
  const MonitorShowV2View({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex-1 overflow-y-auto',
      scrollPrimary: true,
      child: WDiv(
        className: 'flex flex-col gap-6 p-4 pb-8',
        children: [
          // Header: nav bar + info badges + actions
          MonitorHeader(
            name: mockMonitor.name,
            url: mockMonitor.url,
            status: mockMonitor.lastStatus,
            responseTime: '${mockMonitor.lastResponseTimeMs}ms',
            interval: 'Every ${mockMonitor.checkInterval}s',
            lastChecked: mockLastCheck,
          ),

          // Stats grid: 2x2 cards with MetricDetailSheet on tap
          _buildStatsSection(context),

          // Uptime bar
          ContentSection(
            title: 'UPTIME',
            icon: Icons.timeline_rounded,
            child: UptimeBar(
              range: UptimeBarRange.days30,
              uptimePercent: 99.95,
              days: _mockUptimeDays(),
            ),
          ),

          // Performance chart
          ContentSection(
            title: 'PERFORMANCE',
            icon: Icons.show_chart_rounded,
            child: ResponseTimeChart(dataPoints: _mockChartData(), height: 200),
          ),

          // Check history
          _buildCheckHistory(),

          // Response metrics
          MonitorResponseMetrics(
            statusMetrics: const [
              ResponseStatusMetric(
                label: 'Database',
                status: MetricStatusValue.up,
                path: 'health.database',
              ),
              ResponseStatusMetric(
                label: 'Cache',
                status: MetricStatusValue.up,
                path: 'health.cache',
              ),
              ResponseStatusMetric(
                label: 'Queue',
                status: MetricStatusValue.down,
                path: 'health.queue',
              ),
            ],
            numericMetrics: const [
              ResponseNumericMetric(
                label: 'CPU Usage',
                value: '42.5',
                unit: '%',
                path: 'system.cpu',
                trend: '-3.2%',
                trendPositive: true,
              ),
              ResponseNumericMetric(
                label: 'Memory',
                value: '67.8',
                unit: '%',
                path: 'system.memory',
                trend: '+1.4%',
                trendPositive: false,
              ),
              ResponseNumericMetric(
                label: 'Active Conns',
                value: '1,247',
                path: 'connections.active',
                trend: '+89',
                trendPositive: false,
              ),
              ResponseNumericMetric(
                label: 'Queue Depth',
                value: '23',
                path: 'queue.pending',
                trend: '-12',
                trendPositive: true,
              ),
            ],
            stringMetrics: const [
              ResponseStringMetric(
                label: 'Version',
                value: 'v2.4.1',
                path: 'app.version',
              ),
              ResponseStringMetric(
                label: 'Environment',
                value: 'production',
                path: 'app.env',
              ),
              ResponseStringMetric(
                label: 'Region',
                value: 'eu-west-1',
                path: 'infra.region',
              ),
            ],
          ),

          // Configuration
          _buildConfiguration(),
        ],
      ),
    );
  }

  // -------  Stats Section  -------

  Widget _buildStatsSection(BuildContext context) {
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
                  label: 'Uptime',
                  type: MetricType.numeric,
                  currentValue: '99.95',
                  unit: '%',
                ),
                child: V2StatCard(
                  label: 'Uptime',
                  value: mockUptime,
                  icon: Icons.arrow_upward_rounded,
                  trend: '+0.02%',
                  trendPositive: true,
                ),
              ),
            ),
            WDiv(
              className: 'flex-1',
              child: WButton(
                onTap: () => MetricDetailSheet.show(
                  context,
                  label: 'Avg Response',
                  type: MetricType.numeric,
                  currentValue: '245',
                  unit: 'ms',
                ),
                child: V2StatCard(
                  label: 'Avg Response',
                  value: mockAvgResponse,
                  icon: Icons.speed_rounded,
                  trend: '-12ms',
                  trendPositive: true,
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
                  label: 'Last Check',
                  type: MetricType.string,
                  currentValue: '2m ago',
                ),
                child: V2StatCard(
                  label: 'Last Check',
                  value: mockLastCheck,
                  icon: Icons.schedule_rounded,
                ),
              ),
            ),
            WDiv(
              className: 'flex-1',
              child: WButton(
                onTap: () => MetricDetailSheet.show(
                  context,
                  label: 'Interval',
                  type: MetricType.string,
                  currentValue: '30s',
                ),
                child: V2StatCard(
                  label: 'Interval',
                  value: '${mockMonitor.checkInterval}s',
                  icon: Icons.repeat_rounded,
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }

  // -------  Check History  -------

  Widget _buildCheckHistory() {
    return ContentSection(
      title: 'RECENT CHECKS',
      icon: Icons.history_rounded,
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: mockChecks
            .map(
              (check) => MonitorCheckRow(
                status: check.status,
                responseTimeMs: check.responseTimeMs,
                statusCode: check.statusCode,
                location: check.location,
                checkedAt: check.checkedAt,
                errorMessage: check.errorMessage,
              ),
            )
            .toList(),
      ),
    );
  }

  // -------  Configuration  -------

  Widget _buildConfiguration() {
    return ContentSection(
      title: 'CONFIGURATION',
      icon: Icons.settings_outlined,
      noPadding: true,
      child: WDiv(
        className: 'flex flex-col',
        children: [
          ConfigRow(label: 'Method', value: mockMonitor.method),
          ConfigRow(
            label: 'Expected Status',
            value: '${mockMonitor.expectedStatusCode}',
          ),
          ConfigRow(label: 'Timeout', value: '${mockMonitor.timeout}s'),
          WDiv(
            className: 'flex flex-row items-center py-3 px-4 gap-3',
            children: [
              WText(
                'Locations',
                className: 'text-sm text-gray-500 dark:text-gray-400',
              ),
              WDiv(
                className: 'flex-1 overflow-x-auto',
                child: WDiv(
                  className: 'flex flex-row gap-1.5 justify-end',
                  children: mockMonitor.locations
                      .map((loc) => _buildLocationBadge(loc))
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

  // -------  Mock Data  -------

  List<UptimeDayData> _mockUptimeDays() {
    final now = DateTime.now();
    return List.generate(30, (i) {
      final date = now.subtract(Duration(days: 29 - i));
      CheckStatus status;
      if (i == 14) {
        status = CheckStatus.down;
      } else if (i == 15 || i == 20) {
        status = CheckStatus.degraded;
      } else {
        status = CheckStatus.up;
      }
      return UptimeDayData(
        date: date,
        status: status,
        uptimePercent: status == CheckStatus.up ? 100.0 : 85.0,
      );
    });
  }

  List<ResponseTimeDataPoint> _mockChartData() {
    final now = DateTime.now();
    const values = [
      245, 312, 198, 267, 1245, 356, 289, 234, 278, 301, //
      245, 267, 223, 198, 312, 289, 256, 234, 278, 245,
    ];
    return List.generate(values.length, (i) {
      return ResponseTimeDataPoint(
        timestamp: now.subtract(Duration(minutes: (values.length - i) * 3)),
        responseTimeMs: values[i],
        status: values[i] > 1000 ? CheckStatus.degraded : CheckStatus.up,
      );
    });
  }
}
