import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Empty state for the monitors list.
///
/// ## Usage
/// ```dart
/// MonitorsEmptyState(
///   isFiltered: true,
///   onAddMonitor: () => MonitorCreateView.show(context),
/// )
/// ```
class MonitorsEmptyState extends StatelessWidget {
  const MonitorsEmptyState({
    super.key,
    required this.isFiltered,
    required this.onAddMonitor,
  });

  final bool isFiltered;
  final VoidCallback onAddMonitor;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        w-full flex flex-col items-center justify-center
        py-12 rounded-xl
        bg-gray-50 dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        WIcon(
          isFiltered ? Icons.search_off_rounded : Icons.monitor_heart_outlined,
          className: 'text-4xl text-gray-300 dark:text-gray-600',
        ),
        WSpacer(className: 'h-3'),
        WText(
          isFiltered
              ? trans('monitors.no_monitors_found')
              : trans('monitors.no_monitors_yet'),
          className: '''
            text-lg font-semibold
            text-gray-400 dark:text-gray-500
          ''',
        ),
        WSpacer(className: 'h-1'),
        WText(
          isFiltered
              ? trans('monitors.try_adjusting_filters')
              : trans('monitors.add_first_monitor'),
          className: 'text-sm text-gray-300 dark:text-gray-600',
        ),
        if (!isFiltered) ...[
          WSpacer(className: 'h-4'),
          WButton(
            onTap: onAddMonitor,
            className: '''
              py-3.5 px-5 rounded-lg
              bg-primary dark:bg-primary-400
            ''',
            child: WText(
              trans('monitors.add_monitor'),
              className:
                  'text-sm font-semibold no-underline text-white dark:text-gray-900',
            ),
          ),
        ],
      ],
    );
  }
}
