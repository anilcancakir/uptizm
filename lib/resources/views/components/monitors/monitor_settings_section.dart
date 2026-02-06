import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/monitor_location.dart';
import '../../../../app/validation/rules/between_numeric.dart';
import '../app_card.dart';

/// Shared "Monitoring Settings" section: check interval, timeout, regions.
class MonitorSettingsSection extends StatelessWidget {
  final MagicFormData form;
  final List<MonitorLocation> selectedLocations;
  final ValueChanged<List<MonitorLocation>> onLocationsChanged;

  /// Optional FocusNode for check interval input (for keyboard actions)
  final FocusNode? checkIntervalFocusNode;

  /// Optional FocusNode for timeout input (for keyboard actions)
  final FocusNode? timeoutFocusNode;

  const MonitorSettingsSection({
    super.key,
    required this.form,
    required this.selectedLocations,
    required this.onLocationsChanged,
    this.checkIntervalFocusNode,
    this.timeoutFocusNode,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      title: trans('monitor.monitoring_settings'),
      body: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          // Check Interval
          WFormInput(
            label: trans('monitor.check_interval'),
            hint: trans('monitor.check_interval_hint'),
            controller: form['check_interval'],
            focusNode: checkIntervalFocusNode,
            type: InputType.number,
            suffix: WText(
              trans('monitor.seconds_suffix'),
              className: 'text-xs text-gray-500',
            ),
            labelClassName: '''
              text-gray-900 dark:text-gray-200
              mb-2 text-sm font-medium
            ''',
            className: '''
              w-full bg-white dark:bg-gray-800
              text-gray-900 dark:text-white
              rounded-lg
              border border-gray-200 dark:border-gray-700
              px-3 py-3
              text-sm
              focus:border-primary
              focus:ring-2 focus:ring-primary/20
              error:border-red-500
            ''',
            validator: FormValidator.rules([
              Required(),
              BetweenNumeric(10, 300),
            ], field: 'check_interval'),
          ),

          // Timeout
          WFormInput(
            label: trans('monitor.timeout'),
            hint: trans('monitor.timeout_hint'),
            controller: form['timeout'],
            focusNode: timeoutFocusNode,
            type: InputType.number,
            suffix: WText(
              trans('monitor.seconds_suffix'),
              className: 'text-xs text-gray-500',
            ),
            labelClassName: '''
              text-gray-900 dark:text-gray-200
              mb-2 text-sm font-medium
            ''',
            className: '''
              w-full bg-white dark:bg-gray-800
              text-gray-900 dark:text-white
              rounded-lg
              border border-gray-200 dark:border-gray-700
              px-3 py-3
              text-sm
              focus:border-primary
              focus:ring-2 focus:ring-primary/20
              error:border-red-500
            ''',
            validator: FormValidator.rules([
              Required(),
              BetweenNumeric(1, 120),
            ], field: 'timeout'),
          ),

          // Monitoring Locations
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('monitor.regions'),
                className:
                    'text-sm font-medium text-gray-900 dark:text-gray-200',
              ),
              WText(
                trans('monitor.regions_hint'),
                className: 'text-xs text-gray-600 dark:text-gray-400 mb-2',
              ),
              WDiv(
                className: 'grid grid-cols-2 md:grid-cols-3 gap-2',
                children: MonitorLocation.values
                    .map((location) => _buildLocationCheckbox(location))
                    .toList(),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildLocationCheckbox(MonitorLocation location) {
    final isSelected = selectedLocations.contains(location);

    return WButton(
      onTap: () {
        final updated = List<MonitorLocation>.from(selectedLocations);
        if (isSelected) {
          updated.remove(location);
        } else {
          updated.add(location);
        }
        onLocationsChanged(updated);
      },
      className:
          '''
        px-3 py-2 rounded-lg text-xs font-medium
        border
        ${isSelected ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'}
        hover:border-primary/50
        flex flex-row items-center gap-2
      ''',
      child: WDiv(
        className: 'flex flex-row items-center gap-2',
        children: [
          WIcon(
            isSelected ? Icons.check_box : Icons.check_box_outline_blank,
            className: 'text-sm',
          ),
          WText(location.label),
        ],
      ),
    );
  }
}
