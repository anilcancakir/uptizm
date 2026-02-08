import 'dart:async';

import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/monitor_check.dart';
import '../components/app_page_header.dart';
import '../components/charts/response_time_chart.dart';
import '../components/monitors/check_status_row.dart';
import '../components/monitors/location_badge.dart';
import '../components/monitors/stat_card.dart';
import '../components/monitors/status_dot.dart';
import '../components/monitors/status_metrics_panel.dart';
import '../components/app_list.dart';

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
  String? _monitorId;
  bool _isRealTimeEnabled = false;
  Timer? _refreshTimer;

  @override
  void onInit() {
    super.onInit();

    // Extract ID from route parameters
    final idParam = MagicRouter.instance.pathParameter('id');

    if (idParam != null) {
      _monitorId = idParam;
      // Schedule after build to avoid setState-during-build
      WidgetsBinding.instance.addPostFrameCallback((_) async {
        // Clear previous monitor state
        controller.selectedMonitorNotifier.value = null;
        controller.checksNotifier.value = [];

        await controller.loadMonitor(_monitorId!);
        await controller.loadChecks(_monitorId!);
        controller.fetchStatusMetrics(_monitorId!);

        // Auto-enable real-time refresh if no checks exist
        // (waiting for first check to complete)
        if (controller.checksNotifier.value.isEmpty &&
            controller.selectedMonitorNotifier.value?.status?.value ==
                'active') {
          setState(() {
            _isRealTimeEnabled = true;
          });
          _startRealTimeRefresh();
        }
      });
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

    final checks = controller.checksNotifier.value;

    // Use aggressive polling (5 seconds) when waiting for first check
    int refreshInterval;
    if (checks.isEmpty && monitor.status?.value == 'active') {
      refreshInterval = 5; // 5 seconds for first check
    } else {
      // Normal polling: monitor's check interval + 4 seconds buffer
      final checkInterval = monitor.checkInterval ?? 60;
      refreshInterval = checkInterval + 4;
    }

    _refreshTimer = Timer.periodic(Duration(seconds: refreshInterval), (_) {
      if (_monitorId != null && _isRealTimeEnabled) {
        final checksBeforeRefresh = controller.checksNotifier.value;
        final wasEmpty = checksBeforeRefresh.isEmpty;

        final currentPage =
            controller.checksPaginationNotifier.value?.currentPage ?? 1;
        controller.loadMonitor(_monitorId!);
        controller.loadChecks(_monitorId!, page: currentPage);
        controller.fetchStatusMetrics(_monitorId!);

        // If we were waiting for first check and now have checks, restart timer with normal interval
        if (wasEmpty) {
          // Give it a moment for the data to load, then check again
          Future.delayed(const Duration(milliseconds: 500), () {
            final checksAfterRefresh = controller.checksNotifier.value;
            if (checksAfterRefresh.isNotEmpty) {
              _startRealTimeRefresh(); // Restart with normal interval
            }
          });
        }
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
          return WDiv(
            className: 'py-12 flex items-center justify-center',
            child: const CircularProgressIndicator(),
          );
        }

        if (monitor == null) {
          return WDiv(
            className: 'py-12 flex items-center justify-center',
            child: WText(
              trans('monitors.not_found'),
              className: 'text-gray-600 dark:text-gray-400',
            ),
          );
        }

        return WDiv(
          className: 'overflow-y-auto flex flex-col gap-4 lg:gap-6 pb-4',
          scrollPrimary: true,
          children: [
            // Header
            _buildHeader(monitor),

            WDiv(
              className: 'flex flex-col px-4 lg:px-6 gap-4 lg:gap-6',
              children: [
                // Stats Section - wrapped in MagicBuilder to react to checks loading
                MagicBuilder<List<MonitorCheck>>(
                  listenable: controller.checksNotifier,
                  builder: (checks) => _buildStatsSection(monitor, checks),
                ),

                // Performance Chart
                MagicBuilder<List<MonitorCheck>>(
                  listenable: controller.checksNotifier,
                  builder: (checks) => _buildPerformanceSection(checks),
                ),

                // Check History Timeline
                _buildCheckHistory(),

                // Metrics Display - wrapped in MagicBuilder to react to checks loading
                if (monitor.metricMappings != null &&
                    monitor.metricMappings!.isNotEmpty)
                  MagicBuilder<List<MonitorCheck>>(
                    listenable: controller.checksNotifier,
                    builder: (_) => _buildMetricsSection(monitor),
                  ),

                // Status Metrics
                MagicBuilder(
                  listenable: controller.statusMetricsNotifier,
                  builder: (statusMetrics) {
                    if (statusMetrics.isEmpty) return const SizedBox.shrink();

                    return StatusMetricsPanel(
                      title: trans('monitor.status_metrics'),
                      metrics: statusMetrics,
                    );
                  },
                ),

                // Response Body Preview
                _buildResponseBodySection(),

                // Configuration Details
                _buildConfigurationSection(monitor),
              ],
            ),
          ],
        );
      },
    );
  }

  Widget _buildHeader(Monitor monitor) {
    return WDiv(
      className: 'flex flex-col',
      children: [
        // App Page Header
        AppPageHeader(
          leading: WButton(
            onTap: () => MagicRoute.to('/monitors'),
            className:
                'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
            child: WIcon(
              Icons.arrow_back,
              className: 'text-xl text-gray-700 dark:text-gray-300',
            ),
          ),
          title: monitor.name ?? trans('monitors.unnamed'),
          subtitle: monitor.url,
          actions: [
            // Edit Button
            WButton(
              onTap: () => MagicRoute.to('/monitors/$_monitorId/edit'),
              className: '''
                px-3 py-2 rounded-lg
                bg-gray-100 dark:bg-gray-700
                text-gray-700 dark:text-gray-200
                hover:bg-gray-200 dark:hover:bg-gray-600
                text-sm font-medium
              ''',
              child: WDiv(
                className: 'flex flex-row items-center sm:gap-2',
                children: [
                  WIcon(Icons.edit_outlined, className: 'text-base'),
                  WText(trans('common.edit'), className: 'hidden sm:block'),
                ],
              ),
            ),

            // Analytics Button
            WButton(
              onTap: () => MagicRoute.to('/monitors/$_monitorId/analytics'),
              className: '''
                px-3 py-2 rounded-lg
                bg-gray-100 dark:bg-gray-700
                text-gray-700 dark:text-gray-200
                hover:bg-gray-200 dark:hover:bg-gray-600
                text-sm font-medium
              ''',
              child: WDiv(
                className: 'flex flex-row items-center sm:gap-2',
                children: [
                  WIcon(Icons.analytics_outlined, className: 'text-base'),
                  WText(
                    trans('monitors.analytics'),
                    className: 'hidden sm:block',
                  ),
                ],
              ),
            ),

            // Alerts Button
            WButton(
              onTap: () => MagicRoute.to('/monitors/$_monitorId/alerts'),
              className: '''
                px-3 py-2 rounded-lg
                bg-amber-50 dark:bg-amber-900/20
                text-amber-700 dark:text-amber-400
                hover:bg-amber-100 dark:hover:bg-amber-900/30
                text-sm font-medium
              ''',
              child: WDiv(
                className: 'flex flex-row items-center sm:gap-2',
                children: [
                  WIcon(
                    Icons.notifications_active_outlined,
                    className: 'text-base',
                  ),
                  WText(trans('alerts.alerts'), className: 'hidden sm:block'),
                ],
              ),
            ),

            // Pause/Resume Button
            WButton(
              onTap: () => _handlePauseResume(monitor),
              className:
                  '''
                px-3 py-2 rounded-lg
                ${monitor.isPaused ? 'bg-primary hover:bg-green-600 text-white' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-900/30'}
                text-sm font-medium
              ''',
              child: WDiv(
                className: 'flex flex-row items-center sm:gap-2',
                children: [
                  WIcon(
                    monitor.isPaused ? Icons.play_arrow : Icons.pause,
                    className: 'text-base',
                  ),
                  WText(
                    monitor.isPaused
                        ? trans('common.resume')
                        : trans('common.pause'),
                    className: 'hidden sm:block',
                  ),
                ],
              ),
            ),
          ],
        ),

        // Real-time Toggle Section (below header)
        WDiv(
          className: '''
            w-full px-4 lg:px-6 py-3
            border-b border-gray-200 dark:border-gray-700
          ''',
          children: [
            WDiv(
              className: '''
                flex flex-col sm:flex-row
                items-start sm:items-center
                justify-between gap-3
              ''',
              children: [
                // Left: Status Info
                WDiv(
                  className: 'flex flex-row items-center gap-2',
                  children: [
                    StatusDot(status: monitor.lastStatus, size: 12),
                    WText(
                      monitor.lastStatus?.label ?? 'Unknown',
                      className:
                          '''
                        text-sm font-medium
                        ${monitor.isUp
                              ? 'text-green-600 dark:text-green-400'
                              : monitor.isDown
                              ? 'text-red-600 dark:text-red-400'
                              : 'text-gray-600 dark:text-gray-400'}
                      ''',
                    ),
                    if (monitor.lastResponseTimeMs != null)
                      WText(
                        '• ${monitor.lastResponseTimeMs}ms',
                        className:
                            'text-sm font-mono text-gray-500 dark:text-gray-500',
                      ),
                  ],
                ),

                // Right: Real-time Toggle
                WButton(
                  onTap: _toggleRealTime,
                  className:
                      '''
                    px-3 py-2 rounded-lg
                    ${_isRealTimeEnabled ? 'bg-primary/10 dark:bg-primary/20' : 'bg-gray-100 dark:bg-gray-700'}
                    border ${_isRealTimeEnabled ? 'border-primary/30' : 'border-transparent'}
                    hover:bg-opacity-90
                    transition-all duration-200
                  ''',
                  child: WDiv(
                    className: 'flex flex-row items-center gap-2',
                    children: [
                      // Pulse indicator when enabled
                      WDiv(
                        className:
                            '''
                          w-2 h-2 rounded-full
                          ${_isRealTimeEnabled ? 'bg-primary animate-pulse' : 'bg-gray-400 dark:bg-gray-500'}
                        ''',
                      ),
                      WText(
                        _isRealTimeEnabled
                            ? trans('monitor.real_time_on')
                            : trans('monitor.real_time_off'),
                        className:
                            '''
                          text-xs font-bold uppercase tracking-wide
                          ${_isRealTimeEnabled ? 'text-primary' : 'text-gray-600 dark:text-gray-400'}
                        ''',
                      ),
                      if (_isRealTimeEnabled)
                        WText(
                          '${monitor.checkInterval ?? 60}s',
                          className:
                              'text-xs font-mono text-gray-500 dark:text-gray-500',
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildStatsSection(Monitor monitor, List<MonitorCheck> checks) {
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
            value: (c.responseTimeMs as num?)?.toInt() ?? 0,
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
              const WSpacer(className: 'w-3'),
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
    return MagicBuilder<Monitor?>(
      listenable: controller.selectedMonitorNotifier,
      builder: (monitor) {
        return MagicBuilder<List<MonitorCheck>>(
          listenable: controller.checksNotifier,
          builder: (checks) {
            final isWaitingForFirstCheck =
                checks.isEmpty &&
                monitor != null &&
                monitor.status?.value == 'active';

            return MagicBuilder(
              listenable: controller.checksPaginationNotifier,
              builder: (pagination) {
                return AppList<MonitorCheck>(
                  items: checks,
                  itemBuilder: (context, check, index) =>
                      CheckStatusRow(check: check),
                  title: trans('monitors.recent_checks'),
                  emptyIcon: Icons.history,
                  emptyText: trans('monitors.no_checks'),
                  emptyState: isWaitingForFirstCheck
                      ? _buildWaitingForFirstCheckState()
                      : null,
                  currentPage: pagination?.currentPage,
                  totalPages: pagination?.lastPage,
                  isPaginationLoading: controller.isLoading,
                  onPageChange: pagination != null && pagination.lastPage > 1
                      ? (page) {
                          if (page > (pagination.currentPage)) {
                            controller.loadNextPage(_monitorId!);
                          } else {
                            controller.loadPreviousPage(_monitorId!);
                          }
                        }
                      : null,
                );
              },
            );
          },
        );
      },
    );
  }

  Widget _buildWaitingForFirstCheckState() {
    return WDiv(
      className: 'p-12 flex flex-col items-center justify-center w-full',
      children: [
        const CircularProgressIndicator(
          valueColor: AlwaysStoppedAnimation<Color>(Color(0xFF009E60)),
        ),
        const WSpacer(className: 'h-4'),
        WText(
          trans('monitors.waiting_for_first_check'),
          className:
              'text-base font-semibold text-gray-900 dark:text-white text-center',
        ),
        const WSpacer(className: 'h-2'),
        WText(
          trans('monitors.first_check_hint'),
          className:
              'text-sm text-gray-600 dark:text-gray-400 text-center max-w-md',
        ),
      ],
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
              const WSpacer(className: 'w-3'),
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
        WDiv(
          className: 'flex flex-row items-center',
          children: [
            StatusDot(status: check.status, size: 8),
            const WSpacer(className: 'w-2'),
            WText(
              check.checkedAt?.diffForHumans() ?? '—',
              className: 'text-xs text-gray-500 dark:text-gray-500',
            ),
            const WDiv(className: 'flex-1'),
            if (check.responseTimeMs != null)
              WText(
                '${check.responseTimeMs}ms',
                className: 'text-xs font-mono text-gray-500 dark:text-gray-500',
              ),
          ],
        ),
        const WSpacer(className: 'h-2.5'),
        // Metrics grid
        WDiv(
          className: 'wrap gap-2',
          children: mappings.map<Widget>((mapping) {
            final label = mapping['label'] as String? ?? 'Metric';
            final path = mapping['path'] as String? ?? '';
            final unit = mapping['unit'] as String? ?? '';
            final metricType = mapping['type'] as String? ?? '';
            final rawValue = check.parsedMetrics?[path];

            // Status type metrics or boolean values → green/red indicator
            final isStatusType =
                metricType == 'status' ||
                rawValue is bool ||
                rawValue == 'true' ||
                rawValue == 'false';

            if (isStatusType) {
              // Truthy: true, "true", "1", non-empty string
              // Falsy: false, "false", "", "0", null
              final boolValue =
                  rawValue == true ||
                  rawValue == 'true' ||
                  rawValue == '1' ||
                  (rawValue is String &&
                      rawValue.isNotEmpty &&
                      rawValue != 'false' &&
                      rawValue != '0');

              return WDiv(
                className:
                    '''
                  flex flex-row items-center gap-1.5 overflow-hidden
                  px-2.5 py-1 rounded-full
                  ${boolValue ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'}
                ''',
                children: [
                  WDiv(
                    className:
                        'w-2 h-2 rounded-full ${boolValue ? 'bg-green-500' : 'bg-red-500'}',
                  ),
                  WText(
                    label,
                    className:
                        'text-xs ${boolValue ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'}',
                  ),
                ],
              );
            }

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
                flex flex-row items-center gap-1.5 overflow-hidden
                px-2.5 py-1 rounded-full
                bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700
              ''',
              children: [
                WText(
                  label,
                  className:
                      'text-xs text-gray-500 dark:text-gray-400 truncate',
                ),
                WText(
                  displayValue,
                  className:
                      'text-xs font-mono font-semibold text-gray-900 dark:text-white truncate',
                ),
              ],
            );
          }).toList(),
        ),
      ],
    );
  }

  Widget _buildResponseBodySection() {
    return MagicBuilder<List<MonitorCheck>>(
      listenable: controller.checksNotifier,
      builder: (checks) {
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
            WDiv(
              className: 'flex flex-row items-center justify-between',
              children: [
                WText(
                  trans('monitors.last_response'),
                  className:
                      'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
                ),
                WText(
                  trans('monitor.chars', {
                    'count': latestCheck.responseBody!.length,
                  }),
                  className:
                      'text-xs font-mono text-gray-500 dark:text-gray-500',
                ),
              ],
            ),
            const WSpacer(className: 'h-3'),
            WDiv(
              className:
                  'overflow-y-auto w-full bg-gray-900 dark:bg-gray-950 rounded-xl p-4 max-h-[300px]',
              child: WText(
                latestCheck.responseBody!,
                selectable: true,
                className:
                    'font-mono text-xs text-green-400 whitespace-pre-wrap',
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
            _buildConfigRow(
              trans('monitor.method'),
              monitor.method?.label ?? 'GET',
            ),
            _buildConfigRow(
              trans('monitor.expected_status'),
              '${monitor.expectedStatusCode ?? 200}',
            ),
            _buildConfigRow(
              trans('monitor.timeout'),
              '${monitor.timeout ?? 30}s',
            ),
            if (monitor.assertionRules != null &&
                monitor.assertionRules!.isNotEmpty)
              _buildConfigRow(
                trans('monitor.assertions'),
                trans('monitor.assertions_count', {
                  'count': monitor.assertionRules!.length,
                }),
              ),
            if (monitor.monitoringLocations != null &&
                monitor.monitoringLocations!.isNotEmpty)
              WDiv(
                className: 'flex flex-row justify-between items-start gap-2',
                children: [
                  WText(
                    trans('monitor.locations').toUpperCase(),
                    className:
                        'text-xs uppercase font-bold tracking-wide text-gray-600 dark:text-gray-400',
                  ),
                  const WSpacer(className: 'h-2'),
                  WDiv(
                    className: 'wrap gap-2',
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
      (sum, c) => sum + ((c.responseTimeMs ?? 0) as num).toInt(),
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
