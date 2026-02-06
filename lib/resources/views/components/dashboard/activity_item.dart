import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Activity type for styling.
enum ActivityType { incident, recovery, warning, info }

/// Activity Item
///
/// Single entry in the recent activity timeline on the dashboard.
class ActivityItem extends StatelessWidget {
  final String title;
  final String description;
  final String timeAgo;
  final ActivityType type;
  final VoidCallback? onTap;

  const ActivityItem({
    super.key,
    required this.title,
    required this.description,
    required this.timeAgo,
    this.type = ActivityType.info,
    this.onTap,
  });

  IconData get _icon {
    switch (type) {
      case ActivityType.incident:
        return Icons.error_outline;
      case ActivityType.recovery:
        return Icons.check_circle_outline;
      case ActivityType.warning:
        return Icons.warning_amber;
      case ActivityType.info:
        return Icons.info_outline;
    }
  }

  String get _iconBgClass {
    switch (type) {
      case ActivityType.incident:
        return 'bg-red-500/10';
      case ActivityType.recovery:
        return 'bg-green-500/10';
      case ActivityType.warning:
        return 'bg-amber-500/10';
      case ActivityType.info:
        return 'bg-blue-500/10';
    }
  }

  String get _iconColorClass {
    switch (type) {
      case ActivityType.incident:
        return 'text-red-500';
      case ActivityType.recovery:
        return 'text-green-500';
      case ActivityType.warning:
        return 'text-amber-500';
      case ActivityType.info:
        return 'text-blue-500';
    }
  }

  @override
  Widget build(BuildContext context) {
    return WAnchor(
      onTap: onTap ?? () {},
      child: WDiv(
        className: '''
          flex flex-row items-start gap-3 px-4 py-3 w-full
          hover:bg-gray-50 dark:hover:bg-gray-800/50
          duration-150
        ''',
        children: [
          // Icon container
          WDiv(
            className:
                'w-9 h-9 rounded-lg $_iconBgClass flex items-center justify-center mt-0.5',
            child: WIcon(_icon, className: 'text-lg $_iconColorClass'),
          ),

          // Content
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                title,
                className: 'text-sm font-medium text-gray-900 dark:text-white',
              ),
              const WSpacer(className: 'h-0.5'),
              WText(
                description,
                className: 'text-xs text-gray-500 dark:text-gray-400 truncate',
              ),
            ],
          ),

          // Timestamp
          WText(
            timeAgo,
            className:
                'text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap',
          ),
        ],
      ),
    );
  }
}
