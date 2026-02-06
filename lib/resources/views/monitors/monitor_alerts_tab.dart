import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/alert_controller.dart';
import '../../../app/models/alert_rule.dart';
import '../../../app/models/alert.dart';
import '../components/alerts/alert_rule_list_item.dart';
import '../components/alerts/alert_list_item.dart';

/// Monitor Alerts Tab
///
/// Shows alert rules and recent alerts for a specific monitor.
class MonitorAlertsTab extends StatefulWidget {
  final int monitorId;

  const MonitorAlertsTab({required this.monitorId, super.key});

  @override
  State<MonitorAlertsTab> createState() => _MonitorAlertsTabState();
}

class _MonitorAlertsTabState extends State<MonitorAlertsTab> {
  final _alertController = AlertController.instance;

  @override
  void initState() {
    super.initState();
    // Load monitor-specific alert rules and alerts
    _alertController.fetchMonitorAlertRules(widget.monitorId);
    _alertController.fetchMonitorAlerts(widget.monitorId);
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6',
      children: [
        // Alert Rules Section
        _buildAlertRulesSection(),

        // Recent Alerts Section
        _buildRecentAlertsSection(),
      ],
    );
  }

  Widget _buildAlertRulesSection() {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Section Header
        WDiv(
          className: 'p-5 border-b border-gray-100 dark:border-gray-700',
          child: WDiv(
            className: 'flex flex-row items-center justify-between',
            children: [
              WDiv(
                className: 'flex flex-row items-center gap-3',
                children: [
                  WDiv(
                    className: 'p-2 rounded-lg bg-primary/10',
                    child: WIcon(
                      Icons.notifications_active_outlined,
                      className: 'text-primary text-lg',
                    ),
                  ),
                  WText(
                    trans('alerts.alert_rules').toUpperCase(),
                    className:
                        'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
                  ),
                ],
              ),
              WButton(
                onTap: () {
                  // Navigate to create alert rule for this monitor
                  MagicRoute.to(
                    '/monitors/${widget.monitorId}/alert-rules/create',
                  );
                },
                className: '''
                  px-3 py-2 rounded-lg
                  bg-primary hover:bg-green-600
                  text-white font-medium text-sm
                ''',
                child: WDiv(
                  className: 'flex flex-row items-center gap-1',
                  children: [
                    WIcon(Icons.add, className: 'text-base'),
                    WText(trans('common.add')),
                  ],
                ),
              ),
            ],
          ),
        ),

        // Alert Rules List
        ValueListenableBuilder<List<AlertRule>>(
          valueListenable: _alertController.alertRulesNotifier,
          builder: (context, rules, _) {
            if (rules.isEmpty) {
              return WDiv(
                className: 'flex flex-col items-center justify-center w-full py-12',
                children: [
                  WIcon(
                    Icons.notifications_off_outlined,
                    className: 'text-6xl text-gray-400 dark:text-gray-500',
                  ),
                  WText(
                    trans('alerts.no_alert_rules'),
                    className: 'text-gray-600 dark:text-gray-400 mt-4',
                  ),
                  WText(
                    trans('alerts.add_alert_rule_hint'),
                    className: 'text-gray-500 dark:text-gray-500 text-sm mt-2',
                  ),
                ],
              );
            }

            return WDiv(
              children: rules
                  .map(
                    (rule) => AlertRuleListItem(
                      rule: rule,
                      onEdit: () {
                        MagicRoute.to('/alert-rules/${rule.id}/edit');
                      },
                      onDelete: () async {
                        if (await Magic.confirm(
                          title: trans('common.confirm'),
                          message: trans('alerts.delete_rule_confirm'),
                        )) {
                          await _alertController.deleteAlertRule(rule.id!);
                          await _alertController.fetchMonitorAlertRules(
                            widget.monitorId,
                          );
                        }
                      },
                    ),
                  )
                  .toList(),
            );
          },
        ),
      ],
    );
  }

  Widget _buildRecentAlertsSection() {
    return WDiv(
      className: '''
        w-full bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
      ''',
      children: [
        // Section Header
        WDiv(
          className: 'w-full p-5 border-b border-gray-100 dark:border-gray-700',
          child: WDiv(
            className: 'flex flex-row items-center gap-3',
            children: [
              WDiv(
                className: 'p-2 rounded-lg bg-red-100 dark:bg-red-900/20',
                child: WIcon(
                  Icons.warning_amber_outlined,
                  className: 'text-red-600 dark:text-red-400 text-lg',
                ),
              ),
              WText(
                trans('alerts.recent_alerts').toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
        ),

        // Recent Alerts List
        ValueListenableBuilder<List<Alert>>(
          valueListenable: _alertController.alertsNotifier,
          builder: (context, alerts, _) {
            if (alerts.isEmpty) {
              return WDiv(
                className: 'w-full flex flex-col items-center justify-center py-12',
                children: [
                  WIcon(
                    Icons.check_circle_outline,
                    className: 'text-6xl text-green-400 dark:text-green-500',
                  ),
                  WText(
                    trans('alerts.no_recent_alerts'),
                    className: 'text-gray-600 dark:text-gray-400 mt-4',
                  ),
                ],
              );
            }

            // Show only last 10 alerts
            final recentAlerts = alerts.take(10).toList();

            return WDiv(
              children: recentAlerts
                  .map(
                    (alert) => AlertListItem(
                      alert: alert,
                      onTap: () {
                        // Could navigate to alert detail if needed
                      },
                    ),
                  )
                  .toList(),
            );
          },
        ),
      ],
    );
  }
}
