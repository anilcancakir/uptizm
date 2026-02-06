import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/alert_severity.dart';

class AlertSeverityBadge extends StatelessWidget {
  final AlertSeverity severity;

  const AlertSeverityBadge({required this.severity, super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className:
          'inline-flex items-center px-2 py-1 rounded-md border ${_getBorderClass()} ${_getBackgroundClass()}',
      children: [
        WText(
          severity.label,
          className: 'font-medium text-xs ${_getTextClass()}',
        ),
      ],
    );
  }

  String _getBorderClass() {
    switch (severity) {
      case AlertSeverity.critical:
        return 'border-red-200 dark:border-red-800/50';
      case AlertSeverity.warning:
        return 'border-yellow-200 dark:border-yellow-800/50';
      case AlertSeverity.info:
        return 'border-blue-200 dark:border-blue-800/50';
    }
  }

  String _getBackgroundClass() {
    switch (severity) {
      case AlertSeverity.critical:
        return 'bg-red-50 dark:bg-red-900/10';
      case AlertSeverity.warning:
        return 'bg-yellow-50 dark:bg-yellow-900/10';
      case AlertSeverity.info:
        return 'bg-blue-50 dark:bg-blue-900/10';
    }
  }

  String _getTextClass() {
    switch (severity) {
      case AlertSeverity.critical:
        return 'text-red-600 dark:text-red-400';
      case AlertSeverity.warning:
        return 'text-yellow-600 dark:text-yellow-500';
      case AlertSeverity.info:
        return 'text-blue-600 dark:text-blue-400';
    }
  }
}
