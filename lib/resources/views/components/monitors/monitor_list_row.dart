import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/check_status.dart';
import '../../../../app/enums/monitor_status.dart';

/// A single monitor row for the monitors list.
///
/// ## Usage
/// ```dart
/// MonitorListRow(
///   name: 'Production API',
///   url: 'api.example.com',
///   checkStatus: CheckStatus.up,
///   monitorStatus: MonitorStatus.active,
///   responseTime: '245ms',
///   isLast: false,
///   onTap: () => MagicRoute.to('/monitors/123'),
/// )
/// ```
class MonitorListRow extends StatelessWidget {
  const MonitorListRow({
    super.key,
    required this.name,
    required this.url,
    required this.checkStatus,
    required this.monitorStatus,
    this.responseTime,
    required this.isLast,
    required this.onTap,
  });

  final String name;
  final String url;
  final CheckStatus checkStatus;
  final MonitorStatus monitorStatus;
  final String? responseTime;
  final bool isLast;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isUp = checkStatus == CheckStatus.up;
    final isDown = checkStatus == CheckStatus.down;
    final isDegraded = checkStatus == CheckStatus.degraded;
    final isPaused = monitorStatus == MonitorStatus.paused;

    return WButton(
      onTap: onTap,
      states: {if (!isLast) 'bordered'},
      className: '''
        py-3.5 px-4
        bordered:border-b bordered:border-gray-200
        bordered:dark:border-gray-700
      ''',
      child: WDiv(
        className: 'flex flex-row items-center gap-3',
        children: [
          // Status dot
          if (isPaused)
            WIcon(
              Icons.pause_circle_filled_rounded,
              className: 'text-[12px] text-gray-400 dark:text-gray-500',
            )
          else
            WDiv(
              states: {
                if (isUp) 'up',
                if (isDown) 'down',
                if (isDegraded) 'degraded',
              },
              className: '''
                w-2.5 h-2.5 rounded-full
                bg-gray-400 dark:bg-gray-500
                up:bg-green-500 up:dark:bg-green-400
                down:bg-red-500 down:dark:bg-red-400
                degraded:bg-yellow-500 degraded:dark:bg-yellow-400
              ''',
            ),

          // Name + URL
          WDiv(
            className: 'flex-1 flex flex-col',
            children: [
              WDiv(
                className: 'flex flex-row items-center gap-2',
                children: [
                  WDiv(
                    className: 'flex-1',
                    child: WText(
                      name,
                      states: {if (isPaused) 'paused'},
                      className: '''
                        text-sm font-medium truncate no-underline
                        text-gray-900 dark:text-white
                        paused:text-gray-400 dark:paused:text-gray-500
                      ''',
                    ),
                  ),
                  if (isPaused)
                    WDiv(
                      className: '''
                        px-2 py-0.5 rounded-full
                        bg-gray-100 dark:bg-gray-700
                      ''',
                      child: WText(
                        trans('monitors.paused'),
                        className: '''
                          text-[10px] font-medium
                          text-gray-500 dark:text-gray-400
                        ''',
                      ),
                    ),
                ],
              ),
              WText(
                url,
                states: {if (isPaused) 'paused'},
                className: '''
                  text-xs truncate no-underline
                  text-gray-400 dark:text-gray-500
                  paused:text-gray-300 dark:paused:text-gray-600
                ''',
              ),
            ],
          ),

          // Response time
          if (!isPaused)
            WText(
              responseTime ?? '\u2014',
              className: '''
                text-sm font-mono no-underline
                text-gray-500 dark:text-gray-400
              ''',
            ),

          // Chevron
          WIcon(
            Icons.chevron_right_rounded,
            className: 'text-[18px] text-gray-300 dark:text-gray-600',
          ),
        ],
      ),
    );
  }
}
