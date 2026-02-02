import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/monitor_status.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/user.dart';

/// Monitors Index View
///
/// Lists all monitors for the current team with status indicators.
class MonitorsIndexView extends MagicStatefulView<MonitorController> {
  const MonitorsIndexView({super.key});

  @override
  State<MonitorsIndexView> createState() => _MonitorsIndexViewState();
}

class _MonitorsIndexViewState
    extends MagicStatefulViewState<MonitorController, MonitorsIndexView> {
  MonitorStatus? _statusFilter;

  @override
  void onInit() {
    super.onInit();
    // Schedule after build to avoid setState-during-build
    WidgetsBinding.instance.addPostFrameCallback((_) {
      controller.loadMonitors();
    });
  }

  void _applyFilter(MonitorStatus? status) {
    setState(() => _statusFilter = status);
  }

  List<Monitor> _filterMonitors(List<Monitor> monitors) {
    if (_statusFilter == null) return monitors;
    return monitors.where((m) => m.status == _statusFilter).toList();
  }

  @override
  Widget build(BuildContext context) {
    final currentTeam = User.current.currentTeam;

    if (currentTeam == null) {
      return _buildNoTeamState();
    }

    return WDiv(
      className: 'flex flex-col h-full w-full',
      children: [
        // Header
        WDiv(
          className: '''
            w-full
            flex flex-col sm:flex-row items-start sm:items-center justify-between
            gap-4 p-4 lg:p-6
            border-b border-gray-200 dark:border-gray-700
          ''',
          children: [
            WDiv(
              className: 'flex flex-col gap-1',
              children: [
                WText(
                  trans('navigation.monitors'),
                  className: 'text-2xl font-bold text-gray-900 dark:text-white',
                ),
                WText(
                  trans('monitors.welcome_subtitle'),
                  className: 'text-sm text-gray-600 dark:text-gray-400',
                ),
              ],
            ),

            // Add Monitor Button
            WButton(
              onTap: () => MagicRoute.to('/monitors/create'),
              className: '''
                px-4 py-2 rounded-lg
                bg-primary hover:bg-green-600
                text-white font-medium text-sm
                flex flex-row items-center gap-2
              ''',
              child: WDiv(
                className: 'flex flex-row items-center gap-2',
                children: [
                  WIcon(Icons.add, className: 'text-lg text-white'),
                  WText(trans('monitors.add')),
                ],
              ),
            ),
          ],
        ),

        // Filter Tabs
        _buildFilterTabs(),

        // Monitors List
        Expanded(
          child: ValueListenableBuilder<List<Monitor>>(
            valueListenable: controller.monitorsNotifier,
            builder: (context, monitors, _) {
              if (controller.isLoading && monitors.isEmpty) {
                return _buildLoadingState();
              }

              final filteredMonitors = _filterMonitors(monitors);

              if (filteredMonitors.isEmpty) {
                return _buildEmptyState();
              }

              return _buildMonitorsList(filteredMonitors);
            },
          ),
        ),
      ],
    );
  }

  Widget _buildFilterTabs() {
    return WDiv(
      className: '''
        w-full
        flex flex-row gap-2 p-4
        border-b border-gray-200 dark:border-gray-700
      ''',
      children: [
        _buildFilterTab(null, trans('common.all')),
        _buildFilterTab(MonitorStatus.active, trans('monitor_status.active')),
        _buildFilterTab(MonitorStatus.paused, trans('monitor_status.paused')),
        _buildFilterTab(
          MonitorStatus.maintenance,
          trans('monitor_status.maintenance'),
        ),
      ],
    );
  }

  Widget _buildFilterTab(MonitorStatus? status, String label) {
    final isActive = _statusFilter == status;

    return WButton(
      onTap: () => _applyFilter(status),
      className:
          '''
        px-4 py-2 rounded-lg text-sm font-medium
        ${isActive ? 'bg-primary text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'}
        hover:bg-opacity-90
      ''',
      child: WText(label),
    );
  }

  Widget _buildMonitorsList(List<Monitor> monitors) {
    return SingleChildScrollView(
      child: WDiv(
        className: 'w-full grid grid-cols-1 gap-4 p-4 lg:p-6',
        children: monitors
            .map((monitor) => _buildMonitorCard(monitor))
            .toList(),
      ),
    );
  }

  Widget _buildMonitorCard(Monitor monitor) {
    return WAnchor(
      onTap: () => MagicRoute.to('/monitors/${monitor.id}'),
      child: WDiv(
        className: '''
          bg-white dark:bg-gray-800
          border border-gray-200 dark:border-gray-700
          rounded-xl p-4 lg:p-6
          hover:shadow-lg hover:border-primary/50
          transition-all duration-150
          cursor-pointer
        ''',
        children: [
          // Header: Name + Status Badge
          WDiv(
            className: 'flex flex-row items-start justify-between mb-3',
            children: [
              Expanded(
                child: WDiv(
                  className: 'flex flex-col gap-1',
                  children: [
                    WText(
                      monitor.name ?? 'Unnamed Monitor',
                      className:
                          'text-lg font-semibold text-gray-900 dark:text-white',
                    ),
                    WText(
                      monitor.url ?? '',
                      className: '''
                        text-sm text-gray-600 dark:text-gray-400
                        font-mono break-all
                      ''',
                    ),
                  ],
                ),
              ),
              _buildStatusBadge(monitor),
            ],
          ),

          // Meta Info
          WDiv(
            className:
                'flex flex-row flex-wrap gap-4 text-xs text-gray-600 dark:text-gray-400',
            children: [
              // Type
              WDiv(
                className: 'flex flex-row items-center gap-1',
                children: [
                  WIcon(
                    monitor.isHttp ? Icons.http : Icons.wifi_tethering,
                    className: 'text-sm',
                  ),
                  WText(monitor.type?.label ?? ''),
                ],
              ),

              // Check Interval
              WDiv(
                className: 'flex flex-row items-center gap-1',
                children: [
                  WIcon(Icons.schedule, className: 'text-sm'),
                  WText('${monitor.checkInterval ?? 0}s'),
                ],
              ),

              // Locations Count
              if (monitor.monitoringLocations != null)
                WDiv(
                  className: 'flex flex-row items-center gap-1',
                  children: [
                    WIcon(Icons.public, className: 'text-sm'),
                    WText('${monitor.monitoringLocations!.length} regions'),
                  ],
                ),

              // Last Check
              if (monitor.lastCheckedAt != null)
                WDiv(
                  className: 'flex flex-row items-center gap-1',
                  children: [
                    WIcon(Icons.update, className: 'text-sm'),
                    WText(monitor.lastCheckedAt!.diffForHumans()),
                  ],
                ),
            ],
          ),

          // Tags
          if (monitor.tags != null && monitor.tags!.isNotEmpty)
            WDiv(
              className: 'flex flex-row flex-wrap gap-2 mt-3',
              children: monitor.tags!
                  .map(
                    (tag) => WDiv(
                      className: '''
                          px-2 py-1 rounded-md text-xs
                          bg-gray-100 dark:bg-gray-700
                          text-gray-700 dark:text-gray-300
                        ''',
                      child: WText(tag),
                    ),
                  )
                  .toList(),
            ),
        ],
      ),
    );
  }

  Widget _buildStatusBadge(Monitor monitor) {
    String className;
    String statusText;
    IconData icon;

    if (monitor.isUp) {
      className =
          'bg-green-100 dark:bg-green-900/20 text-green-700 dark:text-green-400';
      statusText = trans('check_status.up');
      icon = Icons.check_circle_outline;
    } else if (monitor.isDown) {
      className =
          'bg-red-100 dark:bg-red-900/20 text-red-700 dark:text-red-400';
      statusText = trans('check_status.down');
      icon = Icons.error_outline;
    } else if (monitor.isDegraded) {
      className =
          'bg-amber-100 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400';
      statusText = trans('check_status.degraded');
      icon = Icons.warning_amber;
    } else {
      className =
          'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
      statusText = trans('common.unknown');
      icon = Icons.help_outline;
    }

    return WDiv(
      className:
          '$className px-3 py-1 rounded-full flex flex-row items-center gap-1',
      children: [
        WIcon(icon, className: 'text-sm'),
        WText(statusText, className: 'text-xs font-medium'),
      ],
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: WDiv(
        className: 'flex flex-col items-center justify-center py-12 px-4',
        children: [
          WIcon(
            Icons.monitor_heart_outlined,
            className: 'text-6xl text-gray-400 dark:text-gray-600 mb-4',
          ),
          WText(
            trans('monitors.no_monitors'),
            className:
                'text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2',
          ),
          WText(
            trans('monitors.no_monitors_desc'),
            className:
                'text-sm text-gray-600 dark:text-gray-400 mb-6 text-center',
          ),
          WButton(
            onTap: () => MagicRoute.to('/monitors/create'),
            className: '''
              px-6 py-3 rounded-lg
              bg-primary hover:bg-green-600
              text-white font-medium
            ''',
            child: WText(trans('monitors.add')),
          ),
        ],
      ),
    );
  }

  Widget _buildLoadingState() {
    return const Center(child: CircularProgressIndicator());
  }

  Widget _buildNoTeamState() {
    return Center(
      child: WDiv(
        className: 'flex flex-col items-center justify-center py-12 px-4',
        children: [
          WIcon(
            Icons.group_outlined,
            className: 'text-6xl text-gray-400 dark:text-gray-600 mb-4',
          ),
          WText(
            trans('teams.no_team_selected'),
            className:
                'text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2',
          ),
          WText(
            trans('teams.select_team_to_continue'),
            className: 'text-sm text-gray-600 dark:text-gray-400 text-center',
          ),
        ],
      ),
    );
  }
}
