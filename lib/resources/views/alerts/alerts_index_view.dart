import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/alert_status.dart';
import '../../../app/models/alert.dart';
import '../components/app_page_header.dart';
import '../components/alerts/alert_list_item.dart';

class AlertsIndexView extends StatelessWidget {
  final List<Alert>? initialAlerts;
  final bool isLoading;
  final AlertStatus? statusFilter;
  final ValueChanged<AlertStatus?>? onStatusFilterChanged;
  final ValueChanged<Alert>? onAlertTap;

  const AlertsIndexView({
    this.initialAlerts,
    this.isLoading = false,
    this.statusFilter,
    this.onStatusFilterChanged,
    this.onAlertTap,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    final alerts = initialAlerts ?? [];

    return WDiv(
      className: 'overflow-y-auto flex flex-col',
      scrollPrimary: true,
      children: [
        // Page Header
        AppPageHeader(
          title: trans('alerts.alerts_history_title'),
          subtitle: trans('alerts.alerts_history_subtitle'),
          actions: [
            WButton(
              onTap: () => MagicRoute.to('/alert-rules'),
              className: '''
                px-4 py-2 rounded-lg
                bg-primary hover:bg-green-600
                text-white font-medium text-sm
              ''',
              child: WDiv(
                className: 'flex flex-row items-center gap-2',
                children: [
                  WIcon(Icons.rule_outlined, className: 'text-lg text-white'),
                  WText(trans('alerts.manage_rules')),
                ],
              ),
            ),
          ],
        ),

        // Filters
        if (onStatusFilterChanged != null)
          WDiv(
            className: '''
              w-full p-4 lg:px-6
              border-b border-gray-200 dark:border-gray-700
            ''',
            children: [
              WDiv(
                className: 'flex flex-row gap-2',
                children: [
                  _buildFilterChip(
                    trans('common.all'),
                    null,
                    statusFilter == null,
                  ),
                  _buildFilterChip(
                    trans('alerts.status_alerting'),
                    AlertStatus.alerting,
                    statusFilter == AlertStatus.alerting,
                  ),
                  _buildFilterChip(
                    trans('alerts.status_resolved'),
                    AlertStatus.resolved,
                    statusFilter == AlertStatus.resolved,
                  ),
                ],
              ),
            ],
          ),

        // Content Container
        WDiv(
          className: 'p-4 lg:p-6',
          children: [
            // Content
            if (isLoading)
              WDiv(
                className: 'flex items-center justify-center py-12',
                children: const [CircularProgressIndicator()],
              )
            else if (alerts.isEmpty)
              WDiv(
                className:
                    'flex flex-col items-center justify-center w-full py-12',
                children: [
                  WIcon(
                    Icons.notification_important_outlined,
                    className: 'text-6xl text-gray-400 dark:text-gray-500',
                  ),
                  WText(
                    trans('alerts.no_alerts'),
                    className: 'text-gray-600 dark:text-gray-400 mt-4',
                  ),
                ],
              )
            else
              WDiv(
                className: '''
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  rounded-2xl
                ''',
                children: alerts
                    .map(
                      (alert) => AlertListItem(
                        alert: alert,
                        onTap: onAlertTap != null
                            ? () => onAlertTap!(alert)
                            : null,
                      ),
                    )
                    .toList(),
              ),
          ],
        ),
      ],
    );
  }

  Widget _buildFilterChip(
    String label,
    AlertStatus? filterValue,
    bool isSelected,
  ) {
    return WAnchor(
      onTap: () {
        if (onStatusFilterChanged == null) return;
        onStatusFilterChanged!(isSelected ? null : filterValue);
      },
      child: WDiv(
        className:
            '''
          px-3 py-2 rounded-lg border
          ${isSelected ? 'bg-primary border-primary' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700'}
          hover:bg-opacity-90
          duration-150
        ''',
        children: [
          WText(
            label,
            className:
                '''
              text-sm font-medium
              ${isSelected ? 'text-white' : 'text-gray-700 dark:text-gray-300'}
            ''',
          ),
        ],
      ),
    );
  }
}
