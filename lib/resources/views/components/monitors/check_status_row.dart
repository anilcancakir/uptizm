import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import '../../../../app/models/monitor_check.dart';
import 'status_dot.dart';
import 'location_badge.dart';

class CheckStatusRow extends StatelessWidget {
  final MonitorCheck check;

  const CheckStatusRow({super.key, required this.check});

  String _formatRelativeTime() {
    return check.checkedAt?.diffForHumans() ?? '';
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className:
          'w-full flex flex-row justify-between items-center border-b border-gray-100 dark:border-gray-700 py-3 px-4',
      children: [
        // Left: Status dot + Response time + Status code (or error)
        WDiv(
          className: 'flex flex-row items-center gap-2',
          children: [
            StatusDot(status: check.status, size: 8),
            if (check.hasError)
              WText(
                check.errorMessage!,
                className: 'text-sm font-medium text-red-600 dark:text-red-400',
              )
            else ...[
              WDiv(
                className: 'w-[55px]',
                child: WText(
                  check.responseTimeMs != null
                      ? '${check.responseTimeMs}ms'
                      : '—',
                  className:
                      'font-mono text-sm font-medium text-gray-900 dark:text-white',
                ),
              ),
              if (check.statusCode != null)
                WDiv(
                  className: '''
                        px-2 py-0.5 rounded-md
                        bg-gray-100 dark:bg-gray-700
                        font-mono text-xs font-medium
                        text-gray-700 dark:text-gray-300
                      ''',
                  child: WText('${check.statusCode}'),
                ),
              if (check.location != null)
                WDiv(
                  className: 'ml-4',
                  child: LocationBadge(location: check.location!),
                ),
            ],
          ],
        ),

        // Right: Time (fixed width)
        if (check.checkedAt != null)
          WDiv(
            className: 'w-[80px]',
            child: WText(
              _formatRelativeTime(),
              className: 'text-xs text-gray-500 dark:text-gray-500 text-right',
            ),
          ),
      ],
    );
  }
}
