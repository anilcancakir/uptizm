import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:intl/intl.dart';

import '../../../../app/models/alert.dart';
import 'alert_severity_badge.dart';

class AlertListItem extends StatelessWidget {
  final Alert alert;
  final VoidCallback? onTap;

  const AlertListItem({
    required this.alert,
    this.onTap,
    super.key,
  });

  String _formatDuration(Duration duration) {
    if (duration.inDays > 0) {
      return '${duration.inDays}d ${duration.inHours % 24}h';
    } else if (duration.inHours > 0) {
      return '${duration.inHours}h ${duration.inMinutes % 60}m';
    } else if (duration.inMinutes > 0) {
      return '${duration.inMinutes}m';
    } else {
      return '${duration.inSeconds}s';
    }
  }

  String _formatDateTime(DateTime dt) {
    return DateFormat('MMM d, HH:mm').format(dt);
  }

  @override
  Widget build(BuildContext context) {
    final alertRule = alert.alertRule;
    final severity = alertRule?.severity;
    final isAlerting = alert.isAlerting;

    final content = WDiv(
      className: 'px-4 py-4',
      children: [
        WDiv(
          className: 'flex flex-row items-start gap-3',
          children: [
            // Status indicator dot
            WDiv(
              className:
                  'w-2.5 h-2.5 rounded-full mt-1.5 ${isAlerting ? 'bg-red-500' : 'bg-green-500'}',
              child: const SizedBox.shrink(),
            ),

            // Main content
            WDiv(
              className: 'flex-1 flex flex-col gap-2 min-w-0',
              children: [
                // Header: Rule name + Badges
                WDiv(
                  className: 'flex flex-row items-center gap-2 flex-wrap',
                  children: [
                    if (alertRule != null)
                      WText(
                        alertRule.name ?? 'Alert',
                        className:
                            'text-sm font-semibold text-gray-900 dark:text-white',
                      ),
                    if (severity != null)
                      AlertSeverityBadge(severity: severity),
                    // Status badge
                    WDiv(
                      className:
                          'px-2 py-0.5 rounded ${isAlerting ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20'}',
                      children: [
                        WText(
                          isAlerting ? 'Alerting' : 'Resolved',
                          className:
                              'text-xs font-medium ${isAlerting ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'}',
                        ),
                      ],
                    ),
                  ],
                ),

                // Trigger message
                if (alert.triggerMessage != null)
                  WText(
                    alert.triggerMessage!,
                    className:
                        'text-sm text-gray-700 dark:text-gray-300 line-clamp-2',
                  ),

                // Time info
                WDiv(
                  className: 'flex flex-row items-center gap-1 flex-wrap',
                  children: [
                    if (alert.triggeredAt != null)
                      WText(
                        _formatDateTime(alert.triggeredAt!),
                        className: 'text-xs text-gray-500 dark:text-gray-400',
                      ),
                    if (alert.duration != null) ...[
                      WText(
                        '•',
                        className: 'text-xs text-gray-400',
                      ),
                      WText(
                        'Duration: ${_formatDuration(alert.duration!)}',
                        className: 'text-xs text-gray-500 dark:text-gray-400',
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ],
        ),
      ],
    );

    if (onTap != null) {
      return WAnchor(
        onTap: onTap!,
        child: content,
      );
    }

    return content;
  }
}
