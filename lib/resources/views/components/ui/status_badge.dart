import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Status indicator badge with colored dot and label.
///
/// Supports: up, down, degraded, paused, pending states.
///
/// ## Usage
/// ```dart
/// StatusBadge(status: 'up')
/// ```
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status, this.showDot = true});

  final String status;
  final bool showDot;

  String get _label => switch (status) {
    'up' => 'Up',
    'down' => 'Down',
    'degraded' => 'Degraded',
    'maintenance' => 'Maintenance',
    'paused' => 'Paused',
    _ => 'Pending',
  };

  Set<String> get _states => {
    if (status == 'up')
      'up'
    else if (status == 'down')
      'down'
    else if (status == 'degraded')
      'degraded'
    else if (status == 'paused' || status == 'maintenance')
      'paused'
    else
      'pending',
  };

  @override
  Widget build(BuildContext context) {
    return WDiv(
      states: _states,
      className: '''
        flex flex-row items-center gap-1.5 px-2.5 py-1 rounded-full
        up:bg-green-100 dark:up:bg-green-900/30
        down:bg-red-100 dark:down:bg-red-900/30
        degraded:bg-yellow-100 dark:degraded:bg-yellow-900/30
        paused:bg-gray-100 dark:paused:bg-gray-800
        pending:bg-blue-100 dark:pending:bg-blue-900/30
      ''',
      children: [
        if (showDot)
          WDiv(
            states: _states,
            className: '''
              w-2 h-2 rounded-full
              up:bg-green-500 dark:up:bg-green-400
              down:bg-red-500 dark:down:bg-red-400
              degraded:bg-yellow-500 dark:degraded:bg-yellow-400
              paused:bg-gray-400 dark:paused:bg-gray-500
              pending:bg-blue-500 dark:pending:bg-blue-400
            ''',
          ),
        WText(
          _label,
          states: _states,
          className: '''
            text-xs font-semibold
            up:text-green-700 dark:up:text-green-400
            down:text-red-700 dark:down:text-red-400
            degraded:text-yellow-700 dark:degraded:text-yellow-400
            paused:text-gray-500 dark:paused:text-gray-400
            pending:text-blue-700 dark:pending:text-blue-400
          ''',
        ),
      ],
    );
  }
}
