import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/incident_controller.dart';
import '../../../app/models/incident.dart';
import '../../../app/enums/incident_status.dart';
import '../../../app/enums/incident_impact.dart';
import '../components/app_page_header.dart';
import '../components/app_card.dart';

class IncidentShowView extends MagicStatefulView<IncidentController> {
  const IncidentShowView({super.key});

  @override
  State<IncidentShowView> createState() => _IncidentShowViewState();
}

class _IncidentShowViewState
    extends MagicStatefulViewState<IncidentController, IncidentShowView> {
  @override
  void onInit() {
    super.onInit();
    controller.selectedIncidentNotifier.addListener(_rebuild);
  }

  @override
  void dispose() {
    controller.selectedIncidentNotifier.removeListener(_rebuild);
    super.dispose();
  }

  void _rebuild() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final incident = controller.selectedIncidentNotifier.value;

    if (incident == null) {
      return WDiv(
        className: 'py-12 flex items-center justify-center',
        child: const CircularProgressIndicator(),
      );
    }

    return WDiv(
      className: 'flex flex-col gap-6',
      children: [
        AppPageHeader(
          title: incident.title ?? 'Incident',
          actions: [
            if (!incident.isResolved)
              WButton(
                className: 'text-sm',
                onTap: () => MagicRoute.to('/incidents/${incident.id}/edit'),
                child: WText(trans('common.edit')),
              ),
            WButton(
              className: 'text-sm text-red-600 dark:text-red-400',
              onTap: () => controller.destroy(incident.id!),
              child: WText(trans('common.delete')),
            ),
          ],
        ),
        AppCard(
          title: trans('incidents.details'),
          body: WDiv(
            className: 'flex flex-col gap-4',
            children: [
              WDiv(
                className: 'flex gap-2',
                children: [
                  WDiv(
                    className:
                        'px-3 py-1 rounded-full text-xs font-medium ${_impactColor(incident.impact)}',
                    child: WText(incident.impact?.label ?? 'Unknown'),
                  ),
                  WDiv(
                    className:
                        'px-3 py-1 rounded-full text-xs font-medium ${_statusColor(incident.status)}',
                    child: WText(incident.status?.label ?? 'Unknown'),
                  ),
                ],
              ),
              if (incident.isResolved)
                WText('Duration: ${incident.duration?.inMinutes ?? 0} minutes'),
            ],
          ),
        ),
        if (incident.updates.isNotEmpty)
          AppCard(
            title: trans('incidents.timeline'),
            body: WDiv(
              className: 'flex flex-col gap-4',
              children: incident.updates.map((update) {
                return WDiv(
                  className:
                      'flex flex-col gap-2 pb-4 border-l-2 border-gray-200 dark:border-gray-700 pl-4',
                  children: [
                    WDiv(
                      className:
                          'px-3 py-1 rounded-full text-xs font-medium ${_statusColor(update.status)} w-fit',
                      child: WText(update.status?.label ?? ''),
                    ),
                    if (update.title != null && update.title!.isNotEmpty)
                      WText(update.title!, className: 'font-semibold'),
                    WText(update.message ?? ''),
                    WText(
                      update.createdAt?.format('MMM d, HH:mm') ?? '',
                      className: 'text-xs text-gray-500',
                    ),
                  ],
                );
              }).toList(),
            ),
          ),
        if (!incident.isResolved)
          AppCard(
            title: trans('incidents.add_update'),
            body: WDiv(
              className: 'flex flex-col gap-4',
              children: [
                WFormSelect(
                  label: trans('incidents.status'),
                  options: IncidentStatus.values
                      .map((s) => SelectOption(label: s.label, value: s))
                      .toList(),
                ),
                WFormInput(label: trans('incidents.title_optional')),
                WFormInput(label: trans('incidents.message')),
                WButton(
                  onTap: () {
                    // Handle add update
                  },
                  child: WText(trans('incidents.add_update_button')),
                ),
              ],
            ),
          ),
      ],
    );
  }

  String _impactColor(IncidentImpact? impact) {
    switch (impact) {
      case IncidentImpact.majorOutage:
        return 'bg-red-500 text-white';
      case IncidentImpact.partialOutage:
        return 'bg-orange-500 text-white';
      case IncidentImpact.degradedPerformance:
        return 'bg-yellow-500 text-gray-900';
      case IncidentImpact.underMaintenance:
        return 'bg-blue-500 text-white';
      default:
        return 'bg-gray-500 text-white';
    }
  }

  String _statusColor(IncidentStatus? status) {
    switch (status) {
      case IncidentStatus.investigating:
        return 'bg-gray-500 text-white';
      case IncidentStatus.identified:
        return 'bg-orange-500 text-white';
      case IncidentStatus.monitoring:
        return 'bg-blue-500 text-white';
      case IncidentStatus.resolved:
        return 'bg-green-500 text-white';
      default:
        return 'bg-gray-500 text-white';
    }
  }
}
