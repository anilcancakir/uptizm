import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Check history row with status dot, response time, status code, and time.
///
/// ## Usage
/// ```dart
/// MonitorCheckRow(
///   status: 'up',
///   responseTimeMs: 245,
///   statusCode: 200,
///   location: 'EU West',
///   checkedAt: '2m ago',
/// )
/// ```
class MonitorCheckRow extends StatelessWidget {
  const MonitorCheckRow({
    super.key,
    required this.status,
    this.responseTimeMs,
    this.statusCode,
    this.location,
    required this.checkedAt,
    this.errorMessage,
  });

  final String status;
  final int? responseTimeMs;
  final int? statusCode;
  final String? location;
  final String checkedAt;
  final String? errorMessage;

  bool get _hasError => status == 'down' && errorMessage != null;

  Set<String> get _states => switch (status) {
    'up' => {'up'},
    'down' => {'down'},
    'degraded' => {'degraded'},
    _ => {'up'},
  };

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-row items-center py-3.5 px-4 gap-3
        border-b border-gray-200 dark:border-gray-700
      ''',
      children: [
        // Status dot
        WDiv(
          states: _states,
          className: '''
            w-2.5 h-2.5 rounded-full
            up:bg-green-500 dark:up:bg-green-400
            down:bg-red-500 dark:down:bg-red-400
            degraded:bg-yellow-500 dark:degraded:bg-yellow-400
          ''',
        ),

        if (_hasError)
          WDiv(
            className: 'flex-1',
            child: WText(
              errorMessage!,
              className: 'text-sm text-red-600 dark:text-red-400',
            ),
          )
        else ...[
          // Response time
          if (responseTimeMs != null)
            WDiv(
              className: 'w-[60px]',
              child: WText(
                '${responseTimeMs}ms',
                className:
                    'text-sm font-mono font-medium text-gray-900 dark:text-white',
              ),
            ),

          // Status code badge
          if (statusCode != null)
            WDiv(
              className: 'px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800',
              child: WText(
                '$statusCode',
                className: 'text-xs font-mono text-gray-600 dark:text-gray-400',
              ),
            ),

          // Location
          if (location != null)
            WText(
              location!,
              className: 'text-xs text-gray-400 dark:text-gray-500',
            ),

          // Spacer
          WDiv(className: 'flex-1'),
        ],

        // Timestamp
        WText(checkedAt, className: 'text-xs text-gray-400 dark:text-gray-500'),
      ],
    );
  }
}
