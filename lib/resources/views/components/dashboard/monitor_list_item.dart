import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Monitor status enum for display purposes.
enum MonitorStatus { up, down, degraded, paused }

/// Monitor List Item
///
/// Single row in the monitors overview list on the dashboard.
/// Shows status dot, name, URL, sparkline placeholder, and response time.
class MonitorListItem extends StatelessWidget {
  final String name;
  final String url;
  final MonitorStatus status;
  final String responseTime;
  final VoidCallback? onTap;

  const MonitorListItem({
    super.key,
    required this.name,
    required this.url,
    required this.status,
    required this.responseTime,
    this.onTap,
  });

  String get _statusColorClass {
    switch (status) {
      case MonitorStatus.up:
        return 'bg-green-500';
      case MonitorStatus.down:
        return 'bg-red-500';
      case MonitorStatus.degraded:
        return 'bg-amber-500';
      case MonitorStatus.paused:
        return 'bg-gray-400';
    }
  }

  @override
  Widget build(BuildContext context) {
    return WAnchor(
      onTap: onTap ?? () {},
      child: WDiv(
        className: '''
          flex flex-row items-center gap-3 px-4 py-3 w-full
          hover:bg-gray-50 dark:hover:bg-gray-800/50
          border-b border-gray-100 dark:border-gray-700
          duration-150
        ''',
        children: [
          // Status dot
          WDiv(
            className: 'w-2.5 h-2.5 rounded-full $_statusColorClass',
            child: const SizedBox.shrink(),
          ),

          // Name + URL
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                name,
                className:
                    'text-sm font-semibold text-gray-900 dark:text-white truncate',
              ),
              WText(
                url,
                className:
                    'text-xs text-gray-500 dark:text-gray-400 truncate font-mono',
              ),
            ],
          ),

          // Response time badge
          WDiv(
            className: '''
              px-2 py-1 rounded-md
              bg-gray-100 dark:bg-gray-700
              border border-gray-200 dark:border-gray-600
            ''',
            child: WText(
              responseTime,
              className:
                  'text-xs font-mono font-medium text-gray-700 dark:text-gray-300',
            ),
          ),
        ],
      ),
    );
  }
}
