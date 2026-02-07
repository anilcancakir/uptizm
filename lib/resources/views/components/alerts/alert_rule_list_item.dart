import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/models/alert_rule.dart';
import 'alert_severity_badge.dart';

class AlertRuleListItem extends StatelessWidget {
  final AlertRule rule;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;

  const AlertRuleListItem({
    required this.rule,
    this.onEdit,
    this.onDelete,
    super.key,
  });

  String _getConditionText() {
    switch (rule.type.value) {
      case 'status':
        return trans('alerts.condition_monitor_down');
      case 'threshold':
        final metric = rule.metricKey ?? 'value';
        final op = rule.operator?.value ?? '>';
        final value = rule.thresholdValue ?? 0;
        return '$metric $op ${value.toInt()}';
      case 'anomaly':
        return 'Metric: ${rule.metricKey ?? 'unknown'}';
      default:
        return '';
    }
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'px-4 py-4',
      children: [
        // Mobile layout: name/badges/type on left, actions on right
        WDiv(
          className: 'flex flex-row items-center',
          children: [
            // Main content (name + details)
            WDiv(
              className: 'flex-1 flex flex-col gap-2 min-w-0',
              children: [
                // Name
                WText(
                  rule.name ?? trans('alerts.unnamed_rule'),
                  className:
                      'text-sm font-semibold text-gray-900 dark:text-white',
                ),
                // Badges row
                WDiv(
                  className: 'wrap items-center gap-2',
                  children: [
                    AlertSeverityBadge(severity: rule.severity),
                    if (rule.isTeamLevel)
                      WDiv(
                        className:
                            'px-2 py-0.5 rounded bg-blue-50 dark:bg-blue-900/20',
                        children: [
                          WText(
                            trans('alerts.team_level'),
                            className:
                                'text-xs font-medium text-blue-600 dark:text-blue-400',
                          ),
                        ],
                      ),
                  ],
                ),
                // Type + Condition row
                WDiv(
                  className: 'wrap items-center gap-1',
                  children: [
                    WText(
                      rule.type.label,
                      className: 'text-xs text-gray-500 dark:text-gray-400',
                    ),
                    WText('•', className: 'text-xs text-gray-400'),
                    WText(
                      _getConditionText(),
                      className: 'text-xs text-gray-600 dark:text-gray-400',
                    ),
                  ],
                ),
              ],
            ),

            // Actions (edit, delete) - always on right
            WDiv(
              className: 'flex w-20 flex-row items-center gap-1',
              children: [
                // Edit button
                if (onEdit != null)
                  WButton(
                    onTap: onEdit!,
                    className:
                        'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
                    child: WIcon(
                      Icons.edit_outlined,
                      className: 'text-gray-600 dark:text-gray-400 text-lg',
                    ),
                  ),

                // Delete button
                if (onDelete != null)
                  WButton(
                    onTap: onDelete!,
                    className:
                        'p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20',
                    child: WIcon(
                      Icons.delete_outline,
                      className: 'text-red-600 dark:text-red-400 text-lg',
                    ),
                  ),
              ],
            ),
          ],
        ),
      ],
    );
  }
}
