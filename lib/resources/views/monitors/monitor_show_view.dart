import 'dart:async';

import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/monitor_check.dart';
import '../components/charts/response_time_chart.dart';
import '../components/monitors/check_status_row.dart';
import '../components/monitors/location_badge.dart';
import '../components/monitors/stat_card.dart';
import '../components/monitors/status_dot.dart';
import '../components/pagination_controls.dart';

/// Monitor Show View
///
/// Displays monitor details and check history.
/// TODO: Implement full view with charts and check history.
class MonitorShowView extends MagicStatefulView<MonitorController> {
  const MonitorShowView({super.key});

  @override
  State<MonitorShowView> createState() => _MonitorShowViewState();
}

class _MonitorShowViewState
    extends MagicStatefulViewState<MonitorController, MonitorShowView> {
  int? _monitorId;
  bool _isRealTimeEnabled = false;
  Timer? _refreshTimer;

  @override
  void onInit() {
    super.onInit();
    // Clear previous monitor state
    controller.selectedMonitorNotifier.value = null;
    controller.checksNotifier.value = [];

    // Extract ID from route parameters
    final idParam = MagicRouter.instance.pathParameter('id');
    Log.debug('MonitorShowView onInit - idParam: $idParam');

    if (idParam != null) {
      _monitorId = int.tryParse(idParam);
      if (_monitorId != null) {
        // Schedule after build to avoid setState-during-build
        WidgetsBinding.instance.addPostFrameCallback((_) {
          controller.loadMonitor(_monitorId!);
          controller.loadChecks(_monitorId!);
        });
      }
    }
  }

  @override
  void dispose() {
    _stopRealTimeRefresh();
    super.dispose();
  }

  void _toggleRealTime() {
    setState(() {
      _isRealTimeEnabled = !_isRealTimeEnabled;
    });

    if (_isRealTimeEnabled) {
      _startRealTimeRefresh();
    } else {
      _stopRealTimeRefresh();
    }
  }

  void _startRealTimeRefresh() {
    _stopRealTimeRefresh(); // Cancel any existing timer

    if (_monitorId == null) return;

    final monitor = controller.selectedMonitorNotifier.value;
    if (monitor == null) return;

    // Calculate refresh interval: monitor's check interval + 3-5 seconds
    final checkInterval = monitor.checkInterval ?? 60;
    final refreshInterval = checkInterval + 4; // +4 seconds buffer

    _refreshTimer = Timer.periodic(Duration(seconds: refreshInterval), (_) {
      if (_monitorId != null && _isRealTimeEnabled) {
        final currentPage =
            controller.checksPaginationNotifier.value?.currentPage ?? 1;
        controller.loadMonitor(_monitorId!);
        controller.loadChecks(_monitorId!, page: currentPage);
      }
    });
  }

  void _stopRealTimeRefresh() {
    _refreshTimer?.cancel();
    _refreshTimer = null;
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: controller.selectedMonitorNotifier,
      builder: (context, monitor, _) {
        if (controller.isLoading && monitor == null) {
          return const Center(child: CircularProgressIndicator());
        }

        if (monitor == null) {
          return Center(
            child: WText(
              'Monitor not found',
              className: 'text-gray-600 dark:text-gray-400',
            ),
          );
        }

        return SingleChildScrollView(
          child: WDiv(
            className: 'flex flex-col gap-6 p-4 lg:p-6',
            children: [
              // Header
              _buildHeader(monitor),

              // Stats Section
              _buildStatsSection(monitor),

              // Performance Chart
              ValueListenableBuilder(
                valueListenable: controller.checksNotifier,
                builder: (context, checks, _) =>
                    _buildPerformanceSection(checks),
              ),

              // Check History Timeline
              _buildCheckHistory(),

              // Metrics Display - wrapped in ValueListenableBuilder to react to checks loading
              if (monitor.metricMappings != null &&
                  monitor.metricMappings!.isNotEmpty)
                ValueListenableBuilder(
                  valueListenable: controller.checksNotifier,
                  builder: (context, checks, _) =>
                      _buildMetricsSection(monitor),
                ),

              // Response Body Preview
              _buildResponseBodySection(),

              // Configuration Details
              _buildConfigurationSection(monitor),
            ],
          ),
        );
      },
    );
  }

  Widget _buildHeader(Monitor monitor) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-5
      ''',
      children: [
        // Row 1: Back + Status + Name
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            WButton(
              onTap: () => MagicRoute.to('/monitors'),
              className:
                  'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
              child: WIcon(
                Icons.arrow_back,
                className: 'text-xl text-gray-700 dark:text-gray-300',
              ),
            ),
            const SizedBox(width: 12),
            StatusDot(status: monitor.lastStatus, size: 12),
            const SizedBox(width: 12),
            Expanded(
              child: WText(
                monitor.name ?? 'Unnamed Monitor',
                className: 'text-xl font-bold text-gray-900 dark:text-white',
              ),
            ),
          ],
        ),

        // Row 2: URL
        const SizedBox(height: 12),
        WText(
          monitor.url ?? '',
          selectable: true,
          className:
              'text-sm font-mono text-gray-600 dark:text-gray-400 break-all',
        ),

        // Row 2.5: Real-time Toggle
        const SizedBox(height: 12),
        WButton(
          onTap: _toggleRealTime,
          className:
              '''
            px-3 py-2 rounded-lg
            ${_isRealTimeEnabled ? 'bg-primary/10 dark:bg-primary/20' : 'bg-gray-100 dark:bg-gray-700'}
            border ${_isRealTimeEnabled ? 'border-primary/30' : 'border-transparent'}
            hover:bg-opacity-90
          ''',
          child: WDiv(
            className: 'flex flex-row items-center gap-2',
            children: [
              // Pulse indicator when enabled
              if (_isRealTimeEnabled)
                Container(
                  width: 6,
                  height: 6,
                  decoration: const BoxDecoration(
                    color: Color(0xFF009E60),
                    shape: BoxShape.circle,
                  ),
                )
              else
                Container(
                  width: 6,
                  height: 6,
                  decoration: BoxDecoration(
                    color: Colors.grey.shade400,
                    shape: BoxShape.circle,
                  ),
                ),
              WText(
                'Real-time ${_isRealTimeEnabled ? 'ON' : 'OFF'}',
                className:
                    'text-xs font-bold uppercase tracking-wide ${_isRealTimeEnabled ? 'text-primary' : 'text-gray-600 dark:text-gray-400'}',
              ),
              WText(
                _isRealTimeEnabled
                    ? '(${monitor.checkInterval ?? 60}s + 4s)'
                    : '',
                className: 'text-xs font-mono text-gray-500 dark:text-gray-500',
              ),
            ],
          ),
        ),

        // Row 3: Action Buttons
        const SizedBox(height: 16),
        WDiv(
          className: 'flex flex-col sm:flex-row gap-2',
          children: [
            WButton(
              onTap: () => MagicRoute.to('/monitors/$_monitorId/edit'),
              className: '''
                flex-1 px-4 py-2.5 rounded-xl
                bg-gray-100 dark:bg-gray-700
                text-gray-900 dark:text-gray-100
                hover:bg-gray-200 dark:hover:bg-gray-600
                text-sm font-semibold
              ''',
              child: WDiv(
                className: 'flex flex-row items-center justify-center gap-2',
                children: [
                  WIcon(Icons.edit_outlined, className: 'text-base'),
                  WText(trans('common.edit')),
                ],
              ),
            ),
            WButton(
              onTap: () => _handlePauseResume(monitor),
              className:
                  '''
                flex-1 px-4 py-2.5 rounded-xl
                ${monitor.isPaused ? 'bg-primary hover:bg-green-600 text-white' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-900/30'}
                text-sm font-semibold
              ''',
              child: WDiv(
                className: 'flex flex-row items-center justify-center gap-2',
                children: [
                  WIcon(
                    monitor.isPaused ? Icons.play_arrow : Icons.pause,
                    className: 'text-base',
                  ),
                  WText(
                    monitor.isPaused
                        ? trans('common.resume')
                        : trans('common.pause'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildStatsSection(Monitor monitor) {
    final checks = controller.checksNotifier.value;
    final uptime = _computeUptime(checks);
    final avgResponse = _computeAvgResponse(checks);

    return WDiv(
      className: 'grid grid-cols-2 md:grid-cols-4 gap-4',
      children: [
        StatCard(
          label: trans('monitors.uptime'),
          value: uptime,
          icon: Icons.trending_up,
          valueColor: 'text-green-500',
        ),
        StatCard(
          label: trans('monitors.avg_response_time'),
          value: avgResponse,
          icon: Icons.speed,
          isMono: true,
        ),
        StatCard(
          label: trans('monitors.last_check'),
          value: monitor.lastCheckedAt?.diffForHumans() ?? '—',
          icon: Icons.update,
        ),
        StatCard(
          label: trans('monitors.check_interval_label'),
          value: '${monitor.checkInterval ?? 0}s',
          icon: Icons.schedule,
          isMono: true,
        ),
      ],
    );
  }

  Widget _buildPerformanceSection(List checks) {
    if (checks.isEmpty) {
      return const SizedBox.shrink();
    }

    // Convert checks to chart data points
    final dataPoints = checks
        .where((c) => c.responseTimeMs != null && c.checkedAt != null)
        .map(
          (c) => ChartDataPoint(
            timestamp: DateTime.parse(c.checkedAt.toString()),
            value: c.responseTimeMs as int,
            status: c.status?.value,
          ),
        )
        .toList()
        .reversed
        .toList(); // Reverse to show oldest first (left to right)

    if (dataPoints.isEmpty) {
      return const SizedBox.shrink();
    }

    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Section Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WDiv(
                className: 'p-2 rounded-lg bg-primary/10',
                child: WIcon(
                  Icons.show_chart,
                  className: 'text-primary text-lg',
                ),
              ),
              const SizedBox(width: 12),
              WText(
                trans('monitors.performance').toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),

        // Chart
        WDiv(
          className: 'p-5',
          child: ResponseTimeChart(
            dataPoints: dataPoints,
            height: 200,
            showTooltip: true,
            showGrid: true,
            showDots: true,
          ),
        ),
      ],
    );
  }

  Widget _buildCheckHistory() {
    return ValueListenableBuilder(
      valueListenable: controller.checksNotifier,
      builder: (context, checks, _) {
        return WDiv(
          className: '''
            bg-white dark:bg-gray-800
            border border-gray-100 dark:border-gray-700
            rounded-2xl overflow-hidden
          ''',
          children: [
            // Section Header
            WDiv(
              className:
                  'p-5 w-full border-b border-gray-100 dark:border-gray-700',
              child: WText(
                trans('monitors.recent_checks'),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ),

            // Checks List
            if (checks.isEmpty)
              WDiv(
                className:
                    'p-12 flex flex-col items-center justify-center w-full',
                children: [
                  WIcon(
                    Icons.history,
                    className: 'text-4xl text-gray-400 dark:text-gray-600 mb-2',
                  ),
                  WText(
                    trans('monitors.no_checks'),
                    className: 'text-sm text-gray-600 dark:text-gray-400',
                  ),
                ],
              )
            else
              Column(
                children: [
                  WDiv(
                    children: checks
                        .map((check) => CheckStatusRow(check: check))
                        .toList(),
                  ),
                  // Pagination
                  ValueListenableBuilder(
                    valueListenable: controller.checksPaginationNotifier,
                    builder: (context, pagination, _) {
                      if (pagination == null || pagination.lastPage <= 1) {
                        return const SizedBox.shrink();
                      }
                      return Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 20),
                        child: PaginationControls(
                          currentPage: pagination.currentPage,
                          totalPages: pagination.lastPage,
                          hasPrevious: pagination.hasPreviousPage,
                          hasNext: pagination.hasNextPage,
                          isLoading: controller.isLoading,
                          onPrevious: () =>
                              controller.loadPreviousPage(_monitorId!),
                          onNext: () => controller.loadNextPage(_monitorId!),
                        ),
                      );
                    },
                  ),
                ],
              ),
          ],
        );
      },
    );
  }

  Widget _buildMetricsSection(Monitor monitor) {
    final checks = controller.checksNotifier.value;
    // Filter checks that have parsed metrics
    final checksWithMetrics = checks
        .where((c) => c.parsedMetrics != null && c.parsedMetrics!.isNotEmpty)
        .toList();

    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Section Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: Row(
            children: [
              WDiv(
                className: 'p-2 rounded-lg bg-primary/10',
                child: WIcon(
                  Icons.analytics_outlined,
                  className: 'text-primary text-lg',
                ),
              ),
              const SizedBox(width: 12),
              WText(
                trans('monitors.metrics').toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),

        // Metrics Timeline
        if (checksWithMetrics.isEmpty)
          WDiv(
            className: 'w-full flex flex-col items-center justify-center py-8',
            children: [
              WIcon(
                Icons.hourglass_empty_outlined,
                className: 'text-3xl text-gray-400 dark:text-gray-600 mb-2',
              ),
              WText(
                trans('monitors.waiting_for_metrics'),
                className: 'text-sm text-gray-600 dark:text-gray-400',
              ),
              WText(
                trans('monitors.metrics_hint'),
                className:
                    'text-xs text-gray-500 dark:text-gray-500 mt-1 text-center',
              ),
            ],
          )
        else
          WDiv(
            children: checksWithMetrics.take(5).map((check) {
              return _buildMetricRow(check, monitor.metricMappings!);
            }).toList(),
          ),
      ],
    );
  }

  Widget _buildMetricRow(
    MonitorCheck check,
    List<Map<String, dynamic>> mappings,
  ) {
    return WDiv(
      className: '''
        px-5 py-4
        border-b border-gray-50 dark:border-gray-700/50
        last:border-b-0
      ''',
      children: [
        // Timestamp row
        Row(
          children: [
            StatusDot(status: check.status, size: 8),
            const SizedBox(width: 8),
            WText(
              check.checkedAt?.diffForHumans() ?? '—',
              className: 'text-xs text-gray-500 dark:text-gray-500',
            ),
            const Spacer(),
            if (check.responseTimeMs != null)
              WText(
                '${check.responseTimeMs}ms',
                className: 'text-xs font-mono text-gray-500 dark:text-gray-500',
              ),
          ],
        ),
        const SizedBox(height: 10),
        // Metrics grid - use native Wrap for proper overflow handling
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: mappings.map<Widget>((mapping) {
            final label = mapping['label'] as String? ?? 'Metric';
            final path = mapping['path'] as String? ?? '';
            final unit = mapping['unit'] as String? ?? '';
            final rawValue = check.parsedMetrics?[path];

            String displayValue;
            if (rawValue == null) {
              displayValue = '—';
            } else if (rawValue is String && rawValue.length > 12) {
              displayValue = '${rawValue.substring(0, 12)}…';
            } else {
              displayValue = rawValue.toString();
            }

            // Add unit if present and value is numeric
            if (unit.isNotEmpty && rawValue is num) {
              displayValue = '$displayValue $unit';
            }

            return WDiv(
              className: '''
                flex flex-row items-center gap-1
                px-2 py-1.5 rounded-lg
                bg-gray-50 dark:bg-gray-700/50
                border border-gray-100 dark:border-gray-600
              ''',
              children: [
                WText(
                  label,
                  className: 'text-xs text-gray-500 dark:text-gray-400',
                ),
                WText(
                  displayValue,
                  className:
                      'text-xs font-mono font-semibold text-gray-900 dark:text-white',
                ),
              ],
            );
          }).toList(),
        ),
      ],
    );
  }

  Widget _buildResponseBodySection() {
    return ValueListenableBuilder(
      valueListenable: controller.checksNotifier,
      builder: (context, checks, _) {
        if (checks.isEmpty) return const SizedBox.shrink();

        final latestCheck = checks.first;
        if (latestCheck.responseBody == null) return const SizedBox.shrink();

        return WDiv(
          className: '''
            bg-white dark:bg-gray-800
            border border-gray-100 dark:border-gray-700
            rounded-2xl p-5
          ''',
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                WText(
                  trans('monitors.last_response'),
                  className:
                      'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
                ),
                WText(
                  '${latestCheck.responseBody!.length} chars',
                  className:
                      'text-xs font-mono text-gray-500 dark:text-gray-500',
                ),
              ],
            ),
            const SizedBox(height: 12),
            WDiv(
              className:
                  'w-full bg-gray-900 dark:bg-gray-950 rounded-xl p-4 max-h-[300px]',
              child: SingleChildScrollView(
                child: WText(
                  latestCheck.responseBody!,
                  selectable: true,
                  className:
                      'font-mono text-xs text-green-400 whitespace-pre-wrap',
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _buildConfigurationSection(Monitor monitor) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl p-5
      ''',
      children: [
        WText(
          trans('monitors.configuration'),
          className:
              'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400 mb-4',
        ),
        WDiv(
          className: 'flex flex-col gap-4',
          children: [
            _buildConfigRow('Method', monitor.method?.label ?? 'GET'),
            _buildConfigRow(
              'Expected Status',
              '${monitor.expectedStatusCode ?? 200}',
            ),
            _buildConfigRow('Timeout', '${monitor.timeout ?? 30}s'),
            if (monitor.assertionRules != null &&
                monitor.assertionRules!.isNotEmpty)
              _buildConfigRow(
                'Assertions',
                '${monitor.assertionRules!.length} rules',
              ),
            if (monitor.monitoringLocations != null &&
                monitor.monitoringLocations!.isNotEmpty)
              WDiv(
                className: 'flex flex-row justify-between items-start gap-2',
                children: [
                  WText(
                    'LOCATIONS',
                    className:
                        'text-xs uppercase font-bold tracking-wide text-gray-600 dark:text-gray-400',
                  ),
                  const SizedBox(height: 8),
                  WDiv(
                    className: 'flex flex-wrap gap-2',
                    children: monitor.monitoringLocations!
                        .map((loc) => LocationBadge(location: loc))
                        .toList()
                        .cast<Widget>(),
                  ),
                ],
              ),
          ],
        ),
      ],
    );
  }

  Widget _buildConfigRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        WText(
          label.toUpperCase(),
          className:
              'text-xs uppercase font-bold tracking-wide text-gray-600 dark:text-gray-400',
        ),
        WText(
          value,
          className:
              'text-sm font-mono font-medium text-gray-900 dark:text-white',
        ),
      ],
    );
  }

  String _computeUptime(List checks) {
    if (checks.isEmpty) return '—';
    final upCount = checks.where((c) => c.isUp).length;
    final percentage = (upCount / checks.length * 100).toStringAsFixed(1);
    return '$percentage%';
  }

  String _computeAvgResponse(List checks) {
    final checksWithResponse = checks
        .where((c) => c.responseTimeMs != null)
        .toList();
    if (checksWithResponse.isEmpty) return '—';

    final totalMs = checksWithResponse.fold<int>(
      0,
      (sum, c) => sum + ((c.responseTimeMs ?? 0) as int),
    );
    final avgMs = (totalMs / checksWithResponse.length).round();
    return '${avgMs}ms';
  }

  void _handlePauseResume(Monitor monitor) async {
    if (monitor.isPaused) {
      await controller.resume(_monitorId!);
    } else {
      await controller.pause(_monitorId!);
    }
  }
}
