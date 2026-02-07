import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/models/incident.dart';
import 'package:uptizm/app/enums/incident_status.dart';
import 'package:uptizm/app/enums/incident_impact.dart';

import '../components/app_page_header.dart';

class IncidentsIndexView extends MagicView<IncidentController> {
  const IncidentsIndexView({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col h-full bg-gray-50 dark:bg-gray-900',
      children: [
        // Header
        const AppPageHeader(title: 'Incidents'),

        // Actions & Filters
        WDiv(
          className:
              'px-4 md:px-8 py-4 flex flex-col md:flex-row justify-between items-start md:items-center gap-4',
          children: [
            // Filter
            WDiv(
              className: 'w-full md:w-64',
              child: ValueListenableBuilder<IncidentStatus?>(
                valueListenable: controller.statusFilterNotifier,
                builder: (context, status, _) {
                  return WSelect<IncidentStatus?>(
                    className:
                        'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2',
                    value: status,
                    options: [
                      const SelectOption(value: null, label: 'All Statuses'),
                      ...IncidentStatus.selectOptions,
                    ],
                    onChange: controller.setStatusFilter,
                  );
                },
              ),
            ),

            // New Incident Button
            WButton(
              className:
                  'bg-primary hover:bg-primary/90 text-white px-4 py-2 rounded-lg shadow-sm flex items-center gap-2',
              onTap: () => MagicRoute.to('/incidents/create'),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: const [
                  Icon(Icons.add, size: 18, color: Colors.white),
                  WText('New Incident'),
                ],
              ),
            ),
          ],
        ),

        // List
        Expanded(
          child: ValueListenableBuilder<List<Incident>>(
            valueListenable: controller.incidentsNotifier,
            builder: (context, incidents, _) {
              if (controller.isLoading && incidents.isEmpty) {
                return const Center(child: CircularProgressIndicator());
              }

              if (incidents.isEmpty) {
                return Center(
                  child: WText(
                    'No incidents found.',
                    className: 'text-gray-500 dark:text-gray-400',
                  ),
                );
              }

              return ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: incidents.length,
                separatorBuilder: (_, __) => const SizedBox(height: 16),
                itemBuilder: (context, index) {
                  final incident = incidents[index];
                  return _IncidentCard(incident: incident);
                },
              );
            },
          ),
        ),
      ],
    );
  }
}

class _IncidentCard extends StatelessWidget {
  final Incident incident;

  const _IncidentCard({required this.incident});

  Color _getStatusColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return Colors.orange;
      case IncidentStatus.identified:
        return Colors.red;
      case IncidentStatus.monitoring:
        return Colors.blue;
      case IncidentStatus.resolved:
        return Colors.green;
      default:
        return Colors.grey;
    }
  }

  Color _getImpactColor(IncidentImpact? impact) {
    switch (impact) {
      case IncidentImpact.majorOutage:
        return Colors.red;
      case IncidentImpact.partialOutage:
        return Colors.orange;
      case IncidentImpact.degradedPerformance:
        return Colors.yellow;
      case IncidentImpact.underMaintenance:
        return Colors.blue;
      default:
        return Colors.grey;
    }
  }

  @override
  Widget build(BuildContext context) {
    return WAnchor(
      onTap: () => MagicRoute.to('/incidents/${incident.id}'),
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: [
          WDiv(
            className: 'flex justify-between items-start',
            children: [
              Expanded(
                child: WText(
                  incident.title ?? 'Untitled Incident',
                  className:
                      'text-lg font-semibold text-gray-900 dark:text-white',
                ),
              ),
              _StatusBadge(status: incident.status),
            ],
          ),

          WDiv(
            className:
                'flex flex-wrap gap-2 items-center text-sm text-gray-500 dark:text-gray-400',
            children: [
              _ImpactBadge(impact: incident.impact),
              if (incident.startedAt != null)
                WText(
                  '• Started ${incident.startedAt!.toIso8601String()}',
                ), // We should format this better using carbon/intl
              WText('• ${incident.monitorIds.length} monitors'),
            ],
          ),
        ],
      ),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  final IncidentStatus? status;

  const _StatusBadge({required this.status});

  @override
  Widget build(BuildContext context) {
    String className = 'px-2.5 py-0.5 rounded-full text-xs font-medium ';
    String label = status?.label ?? 'Unknown';

    switch (status) {
      case IncidentStatus.investigating:
        className +=
            'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
        break;
      case IncidentStatus.identified:
        className +=
            'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300';
        break;
      case IncidentStatus.monitoring:
        className +=
            'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
        break;
      case IncidentStatus.resolved:
        className +=
            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
        break;
      default:
        className +=
            'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
    }

    return WDiv(className: className, child: WText(label));
  }
}

class _ImpactBadge extends StatelessWidget {
  final IncidentImpact? impact;

  const _ImpactBadge({required this.impact});

  @override
  Widget build(BuildContext context) {
    String className = 'px-2 py-0.5 rounded-md text-xs font-medium border ';
    String label = impact?.label ?? 'Unknown';

    switch (impact) {
      case IncidentImpact.majorOutage:
        className +=
            'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300';
        break;
      case IncidentImpact.partialOutage:
        className +=
            'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-800 dark:bg-orange-900/20 dark:text-orange-300';
        break;
      case IncidentImpact.degradedPerformance:
        className +=
            'border-yellow-200 bg-yellow-50 text-yellow-700 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300';
        break;
      case IncidentImpact.underMaintenance:
        className +=
            'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-300';
        break;
      default:
        className +=
            'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300';
    }

    return WDiv(className: className, child: WText(label));
  }
}
