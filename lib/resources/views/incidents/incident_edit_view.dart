import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/incident_controller.dart';
import '../../../app/enums/incident_impact.dart';
import '../../../app/enums/incident_status.dart';
import '../components/app_page_header.dart';
import '../components/app_card.dart';

class IncidentEditView extends MagicStatefulView<IncidentController> {
  const IncidentEditView({super.key});

  @override
  State<IncidentEditView> createState() => _IncidentEditViewState();
}

class _IncidentEditViewState
    extends MagicStatefulViewState<IncidentController, IncidentEditView> {
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
          title: trans('incidents.edit'),
          leading: WButton(
            onTap: () => MagicRoute.back(),
            child: WIcon(Icons.arrow_back_outlined),
          ),
        ),
        AppCard(
          title: trans('incidents.edit_details'),
          body: WDiv(
            className: 'flex flex-col gap-4',
            children: [
              WDiv(
                className: 'flex flex-col gap-2',
                children: [
                  WText(
                    trans('incidents.title'),
                    className:
                        'text-sm font-medium text-gray-700 dark:text-gray-300',
                  ),
                  WInput(
                    value: incident.title,
                    className:
                        'w-full px-3 py-3 rounded-lg text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700',
                  ),
                ],
              ),
              WDiv(
                className: 'flex flex-col gap-2',
                children: [
                  WText(
                    trans('incidents.impact'),
                    className:
                        'text-sm font-medium text-gray-700 dark:text-gray-300',
                  ),
                  WSelect<IncidentImpact>(
                    value: incident.impact,
                    options: IncidentImpact.values
                        .map((i) => SelectOption(label: i.label, value: i))
                        .toList(),
                    className:
                        'w-full px-3 py-3 rounded-lg text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700',
                  ),
                ],
              ),
              WDiv(
                className: 'flex flex-col gap-2',
                children: [
                  WText(
                    trans('incidents.status'),
                    className:
                        'text-sm font-medium text-gray-700 dark:text-gray-300',
                  ),
                  WSelect<IncidentStatus>(
                    value: incident.status,
                    options: IncidentStatus.values
                        .map((s) => SelectOption(label: s.label, value: s))
                        .toList(),
                    className:
                        'w-full px-3 py-3 rounded-lg text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700',
                  ),
                ],
              ),
              WButton(
                onTap: () {
                  // Handle save
                },
                child: WText(trans('common.save')),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
