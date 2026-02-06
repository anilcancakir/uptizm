import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

class MetricBadge extends StatelessWidget {
  final String label;
  final String value;
  final String? unit;

  const MetricBadge({
    super.key,
    required this.label,
    required this.value,
    this.unit,
  });

  @override
  Widget build(BuildContext context) {
    final displayValue = unit != null ? '$value$unit' : value;

    return WDiv(
      className:
          'rounded-full px-3 py-1 border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800',
      children: [
        WDiv(
          className: 'flex items-center gap-1.5',
          children: [
            // Label
            WText(label, className: 'text-xs text-gray-600 dark:text-gray-400'),
            // Value with monospace
            WText(
              displayValue,
              className:
                  'text-xs font-mono font-medium text-gray-900 dark:text-white',
            ),
          ],
        ),
      ],
    );
  }
}
