import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/monitor_status.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/user.dart';
import '../components/app_page_header.dart';
import '../components/monitors/stat_card.dart';
import '../components/monitors/status_dot.dart';
import '../components/monitors/location_badge.dart';

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
  String _searchQuery = '';

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
    var filtered = monitors;

    // Apply status filter
    if (_statusFilter != null) {
      filtered = filtered.where((m) => m.status == _statusFilter).toList();
    }

    // Apply search filter
    if (_searchQuery.isNotEmpty) {
      final query = _searchQuery.toLowerCase();
      filtered = filtered.where((m) {
        final name = m.name?.toLowerCase() ?? '';
        final url = m.url?.toLowerCase() ?? '';
        return name.contains(query) || url.contains(query);
      }).toList();
    }

    return filtered;
  }

  @override
  Widget build(BuildContext context) {
    final currentTeam = User.current.currentTeam;

    if (currentTeam == null) {
      return _buildNoTeamState();
    }

    return WDiv(
      className: 'overflow-y-auto flex flex-col',
      scrollPrimary: true,
      children: [
        // Header
        AppPageHeader(
          title: trans('nav.monitors'),
          subtitle: trans('monitors.welcome_subtitle'),
          actions: [
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

        // Stats Row
        ValueListenableBuilder<List<Monitor>>(
          valueListenable: controller.monitorsNotifier,
          builder: (context, monitors, _) {
            return _buildStatsRow(monitors);
          },
        ),

        // Search Bar
        _buildSearchBar(),

        // Filter Tabs
        _buildFilterTabs(),

        // Monitors List
        ValueListenableBuilder<List<Monitor>>(
          valueListenable: controller.monitorsNotifier,
          builder: (context, monitors, _) {
            if (controller.isLoading && monitors.isEmpty) {
              return _buildLoadingState();
            }

            if (monitors.isEmpty) {
              return _buildEmptyState();
            }

            final filteredMonitors = _filterMonitors(monitors);

            if (filteredMonitors.isEmpty) {
              return _buildNoResultsState();
            }

            return _buildMonitorsList(filteredMonitors);
          },
        ),
      ],
    );
  }

  Widget _buildStatsRow(List<Monitor> monitors) {
    final totalCount = monitors.length;
    final upCount = _computeUpCount(monitors);
    final downCount = _computeDownCount(monitors);
    final avgResponseTime = _computeAvgResponseTime(monitors);

    return WDiv(
      className:
          'w-full p-4 lg:p-6 border-b border-gray-200 dark:border-gray-700',
      children: [
        WDiv(
          className: 'grid grid-cols-2 md:grid-cols-4 gap-4',
          children: [
            StatCard(
              label: trans('monitors.stats.total'),
              value: '$totalCount',
              icon: Icons.monitor_heart_outlined,
            ),
            StatCard(
              label: trans('monitors.stats.up'),
              value: '$upCount',
              icon: Icons.check_circle_outline,
              valueColor: 'text-green-500',
            ),
            StatCard(
              label: trans('monitors.stats.down'),
              value: '$downCount',
              icon: Icons.error_outline,
              valueColor: 'text-red-500',
            ),
            StatCard(
              label: trans('monitors.stats.avg_response'),
              value: avgResponseTime,
              icon: Icons.speed,
              isMono: true,
            ),
          ],
        ),
      ],
    );
  }

  int _computeUpCount(List<Monitor> monitors) {
    return monitors.where((m) => m.isUp).length;
  }

  int _computeDownCount(List<Monitor> monitors) {
    return monitors.where((m) => m.isDown).length;
  }

  String _computeAvgResponseTime(List<Monitor> monitors) {
    final monitorsWithResponse = monitors
        .where((m) => m.lastResponseTimeMs != null)
        .toList();

    if (monitorsWithResponse.isEmpty) return '—';

    final totalMs = monitorsWithResponse.fold<int>(
      0,
      (sum, m) => sum + (m.lastResponseTimeMs ?? 0),
    );

    final avgMs = (totalMs / monitorsWithResponse.length).round();
    return '${avgMs}ms';
  }

  Widget _buildSearchBar() {
    return WDiv(
      className:
          'w-full p-4 lg:p-6 border-b border-gray-200 dark:border-gray-700',
      children: [
        WDiv(
          className: 'max-w-md',
          child: WInput(
            value: _searchQuery,
            onChanged: (value) {
              setState(() => _searchQuery = value);
            },
            type: InputType.text,
            placeholder: trans('monitors.search_placeholder'),
            className: '''
              w-full px-4 py-2.5 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              text-gray-900 dark:text-white text-sm
              focus:border-primary focus:ring-2 focus:ring-primary/20
              placeholder:text-gray-400 dark:placeholder:text-gray-500
            ''',
            prefix: WIcon(
              Icons.search,
              className: 'text-gray-400 dark:text-gray-500 text-lg',
            ),
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
    return WDiv(
      className: 'w-full grid grid-cols-1 gap-4 p-4 lg:p-6',
      children: monitors.map((monitor) => _buildMonitorCard(monitor)).toList(),
    );
  }

  Widget _buildMonitorCard(Monitor monitor) {
    return WAnchor(
      onTap: () => MagicRoute.to('/monitors/${monitor.id}'),
      child: WDiv(
        className: '''
          bg-white dark:bg-gray-800
          border border-gray-100 dark:border-gray-700
          rounded-2xl p-5
          hover:shadow-lg hover:border-primary/50
          transition-all duration-150 cursor-pointer
        ''',
        children: [
          // Header: Status + Name + Response Time
          WDiv(
            className: 'flex flex-row items-center',
            children: [
              StatusDot(status: monitor.lastStatus, size: 12),
              const WSpacer(className: 'w-3'),
              WText(
                monitor.name ?? trans('monitors.unnamed'),
                className:
                    'flex-1 text-lg font-semibold text-gray-900 dark:text-white',
              ),
              if (monitor.lastResponseTimeMs != null) ...[
                const WSpacer(className: 'w-3'),
                WText(
                  '${monitor.lastResponseTimeMs}ms',
                  className:
                      'font-mono text-sm font-medium text-gray-600 dark:text-gray-400',
                ),
              ],
            ],
          ),

          // URL (truncated on mobile)
          const WSpacer(className: 'h-2'),
          WText(
            monitor.url ?? '',
            className:
                'text-xs font-mono text-gray-500 dark:text-gray-500 line-clamp-1',
          ),

          // Meta Info Row (compact pills)
          const WSpacer(className: 'h-3'),
          WDiv(
            className: 'wrap gap-2',
            children: [
              // Type pill
              WDiv(
                className: '''
                  flex flex-row items-center gap-1
                  px-2 py-1 rounded-md
                  bg-gray-50 dark:bg-gray-800/50
                  text-gray-600 dark:text-gray-400
                  text-xs
                ''',
                children: [
                  WIcon(
                    monitor.isHttp ? Icons.http : Icons.wifi_tethering,
                    className: 'text-sm',
                  ),
                  WText(monitor.type?.label ?? 'Unknown'),
                ],
              ),

              // Check interval pill
              WDiv(
                className: '''
                  flex flex-row items-center gap-1
                  px-2 py-1 rounded-md
                  bg-gray-50 dark:bg-gray-800/50
                  text-gray-600 dark:text-gray-400
                  text-xs
                ''',
                children: [
                  WIcon(Icons.schedule, className: 'text-sm'),
                  WText('${monitor.checkInterval ?? 0}s'),
                ],
              ),

              // Location count pill (mobile only)
              if (monitor.monitoringLocations != null &&
                  monitor.monitoringLocations!.isNotEmpty)
                WDiv(
                  className: '''
                    flex md:hidden flex-row items-center gap-1
                    px-2 py-1 rounded-md
                    bg-gray-50 dark:bg-gray-800/50
                    text-gray-600 dark:text-gray-400
                    text-xs
                  ''',
                  children: [
                    WIcon(Icons.public, className: 'text-sm'),
                    WText('${monitor.monitoringLocations!.length}'),
                  ],
                ),

              // Last check pill
              if (monitor.lastCheckedAt != null)
                WDiv(
                  className: '''
                    flex flex-row items-center gap-1
                    px-2 py-1 rounded-md
                    bg-gray-50 dark:bg-gray-800/50
                    text-gray-600 dark:text-gray-400
                    text-xs
                  ''',
                  children: [
                    WIcon(Icons.update, className: 'text-sm'),
                    WText(monitor.lastCheckedAt!.diffForHumans()),
                  ],
                ),
            ],
          ),

          // Location Badges (desktop only)
          if (monitor.monitoringLocations != null &&
              monitor.monitoringLocations!.isNotEmpty)
            WDiv(
              className: 'hidden md:wrap gap-2 mt-3',
              children: monitor.monitoringLocations!
                  .map((location) => LocationBadge(location: location))
                  .toList(),
            ),

          // Tags (if present)
          if (monitor.tags != null && monitor.tags!.isNotEmpty) ...[
            const WSpacer(className: 'h-3'),
            WDiv(
              className: 'wrap gap-2',
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
        ],
      ),
    );
  }

  Widget _buildEmptyState() {
    return WDiv(
      className: 'w-full flex flex-col items-center justify-center py-12 px-4',
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
          trans('search.no_results_desc'),
          className:
              'text-sm text-gray-600 dark:text-gray-400 mb-6 text-center max-w-sm',
        ),
        WButton(
          onTap: () {
            setState(() {
              _searchQuery = '';
              _statusFilter = null;
            });
          },
          className: '''
            px-4 py-2 rounded-lg
            bg-gray-100 dark:bg-gray-800
            hover:bg-gray-200 dark:hover:bg-gray-700
            text-gray-700 dark:text-gray-300 font-medium
          ''',
          child: WText(trans('common.clear_filters')),
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
    );
  }

  Widget _buildNoResultsState() {
    return WDiv(
      className: 'w-full flex flex-col items-center justify-center py-12 px-4',
      children: [
        WIcon(
          Icons.search_off,
          className: 'text-6xl text-gray-300 dark:text-gray-600 mb-4',
        ),
        WText(
          trans('search.no_results'),
          className:
              'text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2',
        ),
        WText(
          trans('search.no_results_desc'),
          className:
              'text-sm text-gray-600 dark:text-gray-400 mb-6 text-center max-w-sm',
        ),
        WButton(
          onTap: () {
            setState(() {
              _searchQuery = '';
              _statusFilter = null;
            });
          },
          className: '''
            px-4 py-2 rounded-lg
            bg-gray-100 dark:bg-gray-800
            hover:bg-gray-200 dark:hover:bg-gray-700
            text-gray-700 dark:text-gray-300 font-medium
          ''',
          child: WText(trans('common.clear_filters')),
        ),
      ],
    );
  }

  Widget _buildLoadingState() {
    return WDiv(
      className: 'py-12 flex items-center justify-center',
      child: const CircularProgressIndicator(),
    );
  }

  Widget _buildNoTeamState() {
    return WDiv(
      className: 'w-full flex flex-col items-center justify-center py-12 px-4',
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
    );
  }
}
