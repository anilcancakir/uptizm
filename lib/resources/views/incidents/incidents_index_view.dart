import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/incident_controller.dart';
import '../../../app/models/incident.dart';
import '../../../app/enums/incident_status.dart';
import '../../../app/enums/incident_impact.dart';
import '../components/app_page_header.dart';
import '../components/monitors/stat_card.dart';

/// Incidents Index View
///
/// Lists all incidents for the current team with status indicators.
class IncidentsIndexView extends MagicStatefulView<IncidentController> {
  const IncidentsIndexView({super.key});

  @override
  State<IncidentsIndexView> createState() => _IncidentsIndexViewState();
}

class _IncidentsIndexViewState
    extends MagicStatefulViewState<IncidentController, IncidentsIndexView> {
  IncidentStatus? _statusFilter;
  String _searchQuery = '';

  @override
  void onInit() {
    super.onInit();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      controller.loadIncidents();
    });
  }

  void _applyFilter(IncidentStatus? status) {
    setState(() => _statusFilter = status);
  }

  List<Incident> _filterIncidents(List<Incident> incidents) {
    var filtered = incidents;

    // Apply status filter
    if (_statusFilter != null) {
      filtered = filtered.where((i) => i.status == _statusFilter).toList();
    }

    // Apply search filter
    if (_searchQuery.isNotEmpty) {
      final query = _searchQuery.toLowerCase();
      filtered = filtered.where((i) {
        final title = i.title?.toLowerCase() ?? '';
        return title.contains(query);
      }).toList();
    }

    return filtered;
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'overflow-y-auto flex flex-col',
      scrollPrimary: true,
      children: [
        // Header
        AppPageHeader(
          title: trans('incidents.title'),
          subtitle: trans('incidents.list'),
          actions: [
            WButton(
              onTap: () => MagicRoute.to('/incidents/create'),
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
                  WText(trans('incidents.create')),
                ],
              ),
            ),
          ],
        ),

        // Stats Row
        ValueListenableBuilder<List<Incident>>(
          valueListenable: controller.incidentsNotifier,
          builder: (context, incidents, _) {
            return _buildStatsRow(incidents);
          },
        ),

        // Search Bar
        _buildSearchBar(),

        // Filter Tabs
        _buildFilterTabs(),

        // Incidents List
        ValueListenableBuilder<List<Incident>>(
          valueListenable: controller.incidentsNotifier,
          builder: (context, incidents, _) {
            if (controller.isLoading && incidents.isEmpty) {
              return _buildLoadingState();
            }

            final filteredIncidents = _filterIncidents(incidents);

            if (filteredIncidents.isEmpty) {
              return _buildEmptyState();
            }

            return _buildIncidentsList(filteredIncidents);
          },
        ),
      ],
    );
  }

  Widget _buildStatsRow(List<Incident> incidents) {
    final totalCount = incidents.length;
    final activeCount = incidents.where((i) => !i.isResolved).length;
    final resolvedCount = incidents.where((i) => i.isResolved).length;
    final majorCount = incidents
        .where((i) => i.impact == IncidentImpact.majorOutage && !i.isResolved)
        .length;

    return WDiv(
      className:
          'w-full p-4 lg:p-6 border-b border-gray-200 dark:border-gray-700',
      children: [
        WDiv(
          className: 'grid grid-cols-2 md:grid-cols-4 gap-4',
          children: [
            StatCard(
              label: 'TOTAL',
              value: '$totalCount',
              icon: Icons.warning_amber_outlined,
            ),
            StatCard(
              label: 'ACTIVE',
              value: '$activeCount',
              icon: Icons.error_outline,
              valueColor: activeCount > 0 ? 'text-red-500' : null,
            ),
            StatCard(
              label: 'RESOLVED',
              value: '$resolvedCount',
              icon: Icons.check_circle_outline,
              valueColor: 'text-green-500',
            ),
            StatCard(
              label: 'MAJOR OUTAGES',
              value: '$majorCount',
              icon: Icons.dangerous_outlined,
              valueColor: majorCount > 0 ? 'text-red-600' : null,
            ),
          ],
        ),
      ],
    );
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
            placeholder: 'Search incidents...',
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
        overflow-x-auto
      ''',
      children: [
        _buildFilterTab(null, trans('common.all')),
        _buildFilterTab(
          IncidentStatus.investigating,
          trans('incidents.investigating'),
        ),
        _buildFilterTab(
          IncidentStatus.identified,
          trans('incidents.identified'),
        ),
        _buildFilterTab(
          IncidentStatus.monitoring,
          trans('incidents.monitoring'),
        ),
        _buildFilterTab(IncidentStatus.resolved, trans('incidents.resolved')),
      ],
    );
  }

  Widget _buildFilterTab(IncidentStatus? status, String label) {
    final isActive = _statusFilter == status;

    return WButton(
      onTap: () => _applyFilter(status),
      className:
          '''
        px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap
        ${isActive ? 'bg-primary text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'}
        hover:bg-opacity-90
      ''',
      child: WText(label),
    );
  }

  Widget _buildIncidentsList(List<Incident> incidents) {
    return WDiv(
      className: 'w-full grid grid-cols-1 gap-4 p-4 lg:p-6',
      children: incidents
          .map((incident) => _buildIncidentCard(incident))
          .toList(),
    );
  }

  Widget _buildIncidentCard(Incident incident) {
    return WAnchor(
      onTap: () => MagicRoute.to('/incidents/${incident.id}'),
      child: WDiv(
        className: '''
          bg-white dark:bg-gray-800
          border border-gray-100 dark:border-gray-700
          rounded-2xl p-5
          hover:shadow-lg hover:border-primary/50
          transition-all duration-150 cursor-pointer
        ''',
        children: [
          // Header: Status + Title
          WDiv(
            className: 'flex flex-row items-start gap-3',
            children: [
              // Status indicator
              WDiv(
                className: 'mt-1.5',
                child: WDiv(
                  className:
                      'w-3 h-3 rounded-full ${_statusDotColor(incident.status)}',
                ),
              ),
              // Title and meta
              WDiv(
                className: 'flex-1',
                children: [
                  WText(
                    incident.title ?? 'Untitled Incident',
                    className:
                        'text-lg font-semibold text-gray-900 dark:text-white',
                  ),
                  const WSpacer(className: 'h-1'),
                  // Time
                  if (incident.startedAt != null)
                    WText(
                      incident.startedAt!.diffForHumans(),
                      className: 'text-xs text-gray-500 dark:text-gray-500',
                    ),
                ],
              ),
              // Status badge
              _buildStatusBadge(incident.status),
            ],
          ),

          // Meta pills
          const WSpacer(className: 'h-3'),
          WDiv(
            className: 'wrap gap-2',
            children: [
              // Impact pill
              _buildImpactBadge(incident.impact),

              // Duration pill
              WDiv(
                className: '''
                  flex flex-row items-center gap-1
                  px-2 py-1 rounded-md
                  bg-gray-50 dark:bg-gray-800/50
                  text-gray-600 dark:text-gray-400
                  text-xs
                ''',
                children: [
                  WIcon(Icons.timer_outlined, className: 'text-sm'),
                  WText(_formatDuration(incident)),
                ],
              ),

              // Monitors count pill
              WDiv(
                className: '''
                  flex flex-row items-center gap-1
                  px-2 py-1 rounded-md
                  bg-gray-50 dark:bg-gray-800/50
                  text-gray-600 dark:text-gray-400
                  text-xs
                ''',
                children: [
                  WIcon(Icons.monitor_heart_outlined, className: 'text-sm'),
                  WText('${incident.monitorIds.length} monitors'),
                ],
              ),

              // Updates count pill
              if (incident.updates.isNotEmpty)
                WDiv(
                  className: '''
                    flex flex-row items-center gap-1
                    px-2 py-1 rounded-md
                    bg-gray-50 dark:bg-gray-800/50
                    text-gray-600 dark:text-gray-400
                    text-xs
                  ''',
                  children: [
                    WIcon(Icons.history, className: 'text-sm'),
                    WText('${incident.updates.length} updates'),
                  ],
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildStatusBadge(IncidentStatus? status) {
    return WDiv(
      className:
          'px-2.5 py-1 rounded-full text-xs font-medium ${_statusBadgeColor(status)}',
      child: WText(status?.label ?? 'Unknown'),
    );
  }

  Widget _buildImpactBadge(IncidentImpact? impact) {
    return WDiv(
      className:
          'px-2 py-1 rounded-md text-xs font-medium border ${_impactBadgeColor(impact)}',
      child: WText(impact?.label ?? 'Unknown'),
    );
  }

  String _formatDuration(Incident incident) {
    if (incident.isResolved && incident.duration != null) {
      final duration = incident.duration!;
      if (duration.inMinutes < 60) {
        return '${duration.inMinutes}m';
      } else if (duration.inHours < 24) {
        return '${duration.inHours}h ${duration.inMinutes % 60}m';
      } else {
        return '${duration.inDays}d';
      }
    }
    if (incident.startedAt != null) {
      final now = DateTime.now();
      final diff = now.difference(incident.startedAt!.toDateTime);
      if (diff.inMinutes < 60) {
        return '${diff.inMinutes}m ongoing';
      } else if (diff.inHours < 24) {
        return '${diff.inHours}h ongoing';
      } else {
        return '${diff.inDays}d ongoing';
      }
    }
    return 'Ongoing';
  }

  String _statusDotColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'bg-red-500';
      case IncidentStatus.identified:
        return 'bg-orange-500';
      case IncidentStatus.monitoring:
        return 'bg-blue-500';
      case IncidentStatus.resolved:
        return 'bg-green-500';
      default:
        return 'bg-gray-400';
    }
  }

  String _statusBadgeColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
      case IncidentStatus.identified:
        return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300';
      case IncidentStatus.monitoring:
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
      case IncidentStatus.resolved:
        return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
      default:
        return 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
    }
  }

  String _impactBadgeColor(IncidentImpact? impact) {
    switch (impact) {
      case IncidentImpact.majorOutage:
        return 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300';
      case IncidentImpact.partialOutage:
        return 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-800 dark:bg-orange-900/20 dark:text-orange-300';
      case IncidentImpact.degradedPerformance:
        return 'border-yellow-200 bg-yellow-50 text-yellow-700 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300';
      case IncidentImpact.underMaintenance:
        return 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-300';
      default:
        return 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }
  }

  Widget _buildEmptyState() {
    return WDiv(
      className: 'flex flex-col items-center justify-center py-12 px-4',
      children: [
        WIcon(
          Icons.warning_amber_outlined,
          className: 'text-6xl text-gray-400 dark:text-gray-600 mb-4',
        ),
        WText(
          trans('incidents.no_incidents'),
          className:
              'text-xl font-semibold text-gray-700 dark:text-gray-300 mb-2',
        ),
        WText(
          'No incidents have been reported yet.',
          className:
              'text-sm text-gray-600 dark:text-gray-400 mb-6 text-center',
        ),
        WButton(
          onTap: () => MagicRoute.to('/incidents/create'),
          className: '''
            px-6 py-3 rounded-lg
            bg-primary hover:bg-green-600
            text-white font-medium
          ''',
          child: WText(trans('incidents.create')),
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
}
