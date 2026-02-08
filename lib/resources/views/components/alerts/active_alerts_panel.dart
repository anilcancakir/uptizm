import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/models/alert.dart';
import 'alert_list_item.dart';

class ActiveAlertsPanel extends StatelessWidget {
  final List<Alert> alerts;
  final VoidCallback? onViewAll;
  final int maxDisplayed;

  const ActiveAlertsPanel({
    required this.alerts,
    this.onViewAll,
    this.maxDisplayed = 5,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final displayedAlerts = alerts.take(maxDisplayed).toList();

    return WDiv(
      className: '''
        bg-white dark:bg-gray-800 rounded-2xl shadow-soft
        border border-gray-100 dark:border-gray-700
      ''',
      children: [
        // Header
        WDiv(
          className:
              'flex flex-row items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700',
          children: [
            WDiv(
              className: 'flex flex-row items-center gap-2',
              children: [
                WText(
                  'Active Alerts',
                  className:
                      'text-lg font-semibold text-gray-900 dark:text-white',
                ),
                if (alerts.isNotEmpty)
                  WDiv(
                    className:
                        'px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/20',
                    children: [
                      WText(
                        '${alerts.length}',
                        className:
                            'text-xs font-bold text-red-600 dark:text-red-400',
                      ),
                    ],
                  ),
              ],
            ),
            if (onViewAll != null && alerts.isNotEmpty)
              WAnchor(
                onTap: onViewAll!,
                child: WText(
                  'View All',
                  className:
                      'text-sm font-medium text-primary hover:text-green-600',
                ),
              ),
          ],
        ),

        // Content
        if (alerts.isEmpty)
          WDiv(
            className: 'flex flex-col items-center justify-center py-12 px-6',
            children: [
              WIcon(
                Icons.check_circle_outline,
                className: 'text-6xl text-green-500 dark:text-green-400',
              ),
              WText(
                'No active alerts',
                className: 'text-gray-600 dark:text-gray-400 mt-4',
              ),
            ],
          )
        else
          WDiv(
            className: 'flex flex-col max-h-[400px] overflow-y-auto',
            scrollPrimary: true,
            children: displayedAlerts
                .map((alert) => AlertListItem(alert: alert))
                .toList(),
          ),
      ],
    );
  }
}
